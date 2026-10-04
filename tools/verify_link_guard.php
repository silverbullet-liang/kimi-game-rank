<?php
/**
 * 链接守卫回归：规律域名判据 + 域名提取 + 规则解析 + 父域逐级查
 * 运行：php tools/verify_link_guard.php
 *
 * 规则集已改为「运行时在线拉取、本地不保存」，因此这里不再读取任何本地分片，
 * 只用合成规则集验证查询逻辑；不联网、不依赖外部源。
 */
declare(strict_types=1);
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }

/* 桩件：把在线规则源置空，让本测试完全离线。
   否则在装了 curl 的执行环境里，link_guard_ruleset() 会真的联网去拉规则。 */
function cfg(string $p, $d = null) { return ($p === 'adblock.sources') ? array() : $d; }

require __DIR__ . '/../app/link_guard.php';

$GLOBALS['fail_n'] = 0;
$GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want)
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 规律域名：命中的 ---------- */
foreach (array('111111.com', '121212.com', '125125.com', '125521.cc', '123456.com',
               '654321.cc', '66666.com', '123123123.com', '00000.cc',
               '1234567.com') as $h) {
    ck('命中规律域名 ' . $h, link_guard_is_pattern_host($h), true);
}

/* ---------- 2. 规律域名：不该命中的 ---------- */
foreach (array('163.com', '360.com', '51.com', '12306.com', 'qq.com', 'abc.com',
               '111111.cn', '111111.net', 'a12345.com') as $h) {
    ck('不命中 ' . $h, link_guard_is_pattern_host($h), false);
}

/* ---------- 3. 域名提取 ---------- */
$t = '看这个 www.abc123.com 还有 HTTP://Sub.Example.COM/path 以及 1.2.3.4 和 a@mail.com';
$got = link_guard_extract_hosts($t);
ck('提取到 2 个域名', count($got), 2);
ck('含 www.abc123.com（整段域名）', in_array('www.abc123.com', $got, true), true);
ck('含 sub.example.com（已转小写）', in_array('sub.example.com', $got, true), true);
ck('不含 IP 片段 2.3.4', in_array('2.3.4', $got, true), false);
ck('不含邮箱域名 mail.com', in_array('mail.com', $got, true), false);

/* ---------- 4. 规则解析 ---------- */
$rules = "! 注释行\n||ads.example.com^\n@@||safe.example.com^\n||a.b.c^d\n/^regex\\.rule/\n||wild.*card^\n||dup.example.com^\n||dup.example.com^\n";
$p = link_guard_parse_rules($rules);
ck('解析出 2 条（去重且跳过例外/正则/通配）', count($p), 2);
ck('含 ads.example.com', in_array('ads.example.com', $p, true), true);
ck('含 dup.example.com', in_array('dup.example.com', $p, true), true);

/* ---------- 5. 逐级查父域（合成规则集，不联网） ---------- */
$set = array('ads.example.com' => 1, 'tracker.net' => 1);
ck('精确命中', link_guard_host_in_set('ads.example.com', $set), 'ads.example.com');
ck('子域命中（父域逐级查）', link_guard_host_in_set('a.b.ads.example.com', $set), 'ads.example.com');
ck('另一条命中', link_guard_host_in_set('x.tracker.net', $set), 'tracker.net');
ck('未命中返回空串', link_guard_host_in_set('clean.example.org', $set), '');
ck('空集直接返回空串', link_guard_host_in_set('ads.example.com', array()), '');

/* ---------- 6. 规则集接口形态（不联网时退化为空集，不报错） ---------- */
ck('在线规则集返回数组', is_array(link_guard_ruleset()), true);
ck('rule_hit 始终返回字符串', is_string(link_guard_rule_hit('example.com')), true);

/* ---------- 7. 综合判定 ---------- */
ck('综合判定命中规律域名', count(link_guard_check('来 111111.com 看看')) > 0, true);
ck('综合判定对干净文本返回空', link_guard_check('今天天气不错，我们在 163.com 看了新闻'), array());

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
