<?php
/**
 * 安全加固回归：客户端 IP 取值与网段判定
 * 运行：php tools/verify_security.php
 */
declare(strict_types=1);
require __DIR__ . '/../app/helpers.php';

$GLOBALS['fail_n'] = 0;
$GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want): void
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 网段判定 ---------- */
$R = array('10.0.0.0/8', '192.168.1.0/24', '203.0.113.7', '2001:db8::/32');
ck('单地址精确命中',            ip_in_ranges('203.0.113.7', $R), true);
ck('单地址不命中',              ip_in_ranges('203.0.113.8', $R), false);
ck('/8 网段内',                 ip_in_ranges('10.255.255.254', $R), true);
ck('/8 网段外（相邻网段）',      ip_in_ranges('11.0.0.1', $R), false);
ck('/24 网段内',                ip_in_ranges('192.168.1.255', $R), true);
ck('/24 网段外',                ip_in_ranges('192.168.2.0', $R), false);
ck('IPv6 /32 命中',             ip_in_ranges('2001:db8:ffff::1', $R), true);
ck('IPv6 /32 不命中',           ip_in_ranges('2001:db9::1', $R), false);
ck('IPv4 地址不匹配 IPv6 网段',  ip_in_ranges('10.0.0.1', array('::/0')), false);
ck('/0 命中任意地址',            ip_in_ranges('8.8.8.8', array('0.0.0.0/0')), true);
ck('/32 等价单地址（命中）',     ip_in_ranges('8.8.8.8', array('8.8.8.8/32')), true);
ck('/32 等价单地址（不命中）',   ip_in_ranges('8.8.8.9', array('8.8.8.8/32')), false);
ck('/25 边界：下半段命中',       ip_in_ranges('192.168.1.127', array('192.168.1.0/25')), true);
ck('/25 边界：上半段不命中',     ip_in_ranges('192.168.1.128', array('192.168.1.0/25')), false);
ck('非法 IP 直接返回 false',     ip_in_ranges('not-an-ip', $R), false);
ck('越界前缀长度被忽略',         ip_in_ranges('10.0.0.1', array('10.0.0.0/99')), false);
ck('空网段列表',                ip_in_ranges('10.0.0.1', array()), false);

/* ---------- 2. client_ip：默认零信任 ---------- */
function set_env(string $remote, array $headers = array()): void
{
    $_SERVER = array('REMOTE_ADDR' => $remote);
    foreach ($headers as $k => $v) { $_SERVER[$k] = $v; }
}
function set_trusted(array $ranges): void
{
    $GLOBALS['APP_CONFIG'] = array('security' => array('trusted_proxies' => $ranges));
}

set_trusted(array());
set_env('198.51.100.9', array('HTTP_X_FORWARDED_FOR' => '1.2.3.4'));
ck('默认：忽略 X-Forwarded-For',        client_ip(), '198.51.100.9');
set_env('198.51.100.9', array('HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'HTTP_X_REAL_IP' => '5.6.7.8'));
ck('默认：忽略全部转发头',              client_ip(), '198.51.100.9');
set_env('198.51.100.9', array(
    'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.0.0.1, 10.0.0.2',
    'HTTP_X_REAL_IP' => '9.9.9.9'));
ck('默认：伪造转发链无效',              client_ip(), '198.51.100.9');

/* ---------- 3. client_ip：受信代理时才解析，并从最右取 ---------- */
set_trusted(array('10.0.0.0/8'));
set_env('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '1.2.3.4'));
ck('受信代理：取 XFF 唯一条目',         client_ip(), '1.2.3.4');
set_env('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.0.0.9'));
ck('受信代理：跳过受信网段取最右',       client_ip(), '1.2.3.4');
set_env('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '6.6.6.6, 1.2.3.4, 10.0.0.9'));
ck('受信代理：伪造前缀被忽略',           client_ip(), '1.2.3.4');
set_env('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '10.0.0.9'));
ck('受信代理：全链受信则回落直连地址',   client_ip(), '10.0.0.5');
set_env('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => 'garbage, 1.2.3.4'));
ck('受信代理：非法条目被跳过',           client_ip(), '1.2.3.4');
set_env('10.0.0.5', array());
ck('受信代理：无转发头则用直连地址',     client_ip(), '10.0.0.5');

/* 直连方不在受信网段 → 即使带了 XFF 也不采信 */
set_env('198.51.100.9', array('HTTP_X_FORWARDED_FOR' => '1.2.3.4'));
ck('非受信直连：转发头依然无效',         client_ip(), '198.51.100.9');

/* ---------- 4. ip_hash 稳定性 ---------- */
$GLOBALS['APP_CONFIG'] = array();
ck('ip_hash 同输入同输出',  ip_hash('1.2.3.4') === ip_hash('1.2.3.4'), true);
ck('ip_hash 不同输入不同输出', ip_hash('1.2.3.4') === ip_hash('1.2.3.5'), false);
ck('ip_hash 长度 64',       strlen(ip_hash('::1')), 64);

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
