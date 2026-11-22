<?php
/**
 * 自检：多维哨兵（单来源速率 / 登录撞库 / 管理接口探测 / 批量注册）
 * 桩件驱动，不连库、不联网；计数文件写在临时目录。
 * 跑法：php tools/verify_sentinel.php
 */
declare(strict_types=1);

$TMP = sys_get_temp_dir() . '/kimgr_sen_' . getmypid();
define('APP_ROOT', $TMP);
@mkdir($TMP . '/storage/cache', 0775, true);

$GLOBALS['IP'] = '203.0.113.5';
$GLOBALS['DEV'] = '';
$GLOBALS['IDENT'] = null;

function cfg($k, $d = null) { return $d; }
function client_ip() { return $GLOBALS['IP']; }
function discipline_dev_of_request() { return $GLOBALS['DEV']; }
function is_api_request() { return true; }
function fail($c, $m) { throw new RuntimeException($m . '|' . $c); }
function current_identity() { return $GLOBALS['IDENT']; }
function app_log($m) { }

require dirname(__DIR__) . '/app/sentinel.php';

$pass = 0; $bad = 0;
function chk($c, $n) { global $pass, $bad; if ($c) { $pass++; echo "  ok   $n\n"; } else { $bad++; echo "  FAIL $n\n"; } }

echo "== 计数与处置 ==\n";
chk(sen_bump('t', 'k', 60) === 1, '计数从 1 开始');
chk(sen_bump('t', 'k', 60) === 2, '同桶递增');
chk(sen_sum('t', 'k', 60, 2) >= 2, '窗口累计能取到');
sen_pen('x', 'h', 120);
chk(sen_pen_left('x', 'h') > 0, '处置写入并生效');
sen_pen('x', 'h', -1);
chk(sen_pen_left('x', 'h') === 0, '过期的处置自动清掉');

echo "== 撞库：同一设备试多个账号 ==\n";
$GLOBALS['DEV'] = 'devAAA';
chk(sen_dev_accounts('devAAA', 'acct1') === 1, '第一个账号');
for ($i = 2; $i <= 8; $i++) { sen_dev_accounts('devAAA', 'acct' . $i); }
chk(sen_dev_accounts('devAAA', 'acct8') === 8, '重复尝试不重复计数');
chk(sen_dev_accounts('devAAA', 'acct9') === 9, '换新账号继续累计');

echo "== 登录失败：按 IP 与设备分别处置 ==\n";
$GLOBALS['IP'] = '198.51.100.7';
$GLOBALS['DEV'] = '';
for ($i = 0; $i < 30; $i++) { sen_auth_fail('u' . $i, ''); }
chk(sen_pen_left('auth_ip', sen_ip_hash()) > 0, '单 IP 30 次失败后被处置');
chk(sen_login_blocked()['block'] === true, '登录入口被挡');
sen_auth_ok();
chk(sen_login_blocked()['block'] === false, '登录成功后清账');
chk(sen_pen_left('auth_ip', sen_ip_hash()) === 0, '清账后不再拦截');

$GLOBALS['IP'] = '198.51.100.8';
$GLOBALS['DEV'] = 'devBBB';
for ($i = 1; $i <= 8; $i++) { sen_auth_fail('acct' . $i, 'devBBB'); }
chk(sen_pen_left('auth_dev', substr('devBBB', 0, 32)) > 0, '同一设备试 8 个账号被处置（换 IP 也躲不掉）');
chk(sen_login_blocked()['block'] === true, '该设备登录被挡');

echo "== 单来源请求速率 ==\n";
$GLOBALS['IP'] = '198.51.100.9';
$GLOBALS['DEV'] = '';
$fh = sen_dir() . '/sen_rq_' . sen_tag(sen_ip_hash()) . '_' . (int)floor(time() / 60) . '.txt';
@file_put_contents($fh, '599', LOCK_EX);
$_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/api/rank.php';
chk(sen_verdict() !== '', '第 600 个请求被拒');
chk(sen_verdict() !== '', '冷却期内继续被拒');

echo "== 管理接口探测 ==\n";
$GLOBALS['IP'] = '198.51.100.10';
$GLOBALS['IDENT'] = array('uid' => 7, 'role' => 'user');
$_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/api/admin.php';
for ($i = 0; $i < 19; $i++) { sen_verdict(); }
chk(sen_pen_left('admin_probe', sen_ip_hash()) === 0, '19 次探测还没到阈值（不误伤）');
chk(sen_verdict() === '请求被拒绝', '第 20 次探测被直接拒绝');
chk(sen_pen_left('admin_probe', sen_ip_hash()) > 0, '该来源被处置 1 小时');

echo "== 管理员不受影响 ==\n";
$GLOBALS['IP'] = '198.51.100.11';
$GLOBALS['IDENT'] = array('uid' => 0, 'role' => 'admin');
$hit = 0;
for ($i = 0; $i < 30; $i++) { if (sen_verdict() !== '') { $hit++; } }
chk($hit === 0, '管理员撞管理接口 30 次不被拦');
chk(sen_pen_left('admin_probe', sen_ip_hash()) === 0, '也不会被记处置');

echo "== 全站登录失败率信号 ==\n";
$GLOBALS['IP'] = '198.51.100.12';
$GLOBALS['DEV'] = '';
for ($i = 0; $i < 320; $i++) { sen_auth_try(); sen_auth_fail('bulk', ''); }
chk(sen_pen_left('auth_all', 'all') > 0, '失败量异常时记下「登录承压」信号');

echo "== 熔断的多信号判定 ==\n";
require dirname(__DIR__) . '/app/breaker.php';
@unlink(brk_dir() . '/breaker.off');
sen_pen('auth_all', 'all', 900);
sen_pen('admin_probe', 'sig1', 900);
chk(brk_signals() >= 2, '能从缓存里读到「撞库 / 探测」两类信号');

$bucket = (int)floor(time() / 60);
@file_put_contents(brk_dir() . '/breaker_hb.json', json_encode(array('m' => $bucket, 'n' => 1200)));
$d = brk_decide();
chk($d['rate'] >= 1200 && $d['trip'] === true, '速率越线 + 有信号 → 熔断');

/* 清掉信号：同样的速率不应熔断（正常高峰不该被误伤） */
foreach ((array)@scandir(sen_dir()) as $f) {
    if (is_string($f) && strpos($f, 'pen_') === 0) { @unlink(sen_dir() . '/' . $f); }
}
@file_put_contents(brk_dir() . '/breaker_hb.json', json_encode(array('m' => $bucket, 'n' => 1200)));
$d = brk_decide();
chk($d['signals'] === 0 && $d['trip'] === false, '只有速率、没有信号 → 不熔断');

/* 超过硬阈值：不要信号也熔断 */
@file_put_contents(brk_dir() . '/breaker_hb.json', json_encode(array('m' => $bucket, 'n' => 2500)));
$d = brk_decide();
chk($d['trip'] === true, '超过两倍硬阈值 → 直接熔断');

// 清理
function sen_rmdir($d) {
    if (!is_dir($d)) { return; }
    foreach ((array)scandir($d) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $d . '/' . $f;
        if (is_dir($p)) { sen_rmdir($p); } else { @unlink($p); }
    }
    @rmdir($d);
}
sen_rmdir($TMP);

echo "\n通过 $pass 项，失败 $bad 项\n";
exit($bad > 0 ? 1 : 0);
