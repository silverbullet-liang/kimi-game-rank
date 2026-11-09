<?php
/**
 * 监测采集指纹校验 · 离线自检
 * ------------------------------------------------------------
 * 跑法：php tools/verify_mon_guard.php
 * 覆盖：mon_collect_trust 的放行/拒绝边界、5xx 告警分类、上报额度口径。
 * 不连库、不联网（DB 与设置读取全部走桩）。
 */
declare(strict_types=1);

/* ---------- 桩：monitor.php / mon_srv.php 依赖的最小环境 ---------- */
$GLOBALS['__ins'] = array();
$GLOBALS['__set'] = array('srv_slow_alert_ms' => '6000');

function table_exists(string $t) { return true; }
function db_val(string $sql, array $p = array()) { return null; }          // 无去重命中
function db_all(string $sql, array $p = array()) { return array(); }       // 设置表为空
function db_insert(string $sql, array $p = array()) { $GLOBALS['__ins'][] = $p; return 1; }
function now_utc() { return '2026-11-09 00:00:00'; }
function app_log(string $s) { $GLOBALS['__log'][] = $s; }
function setting_get(string $k, $d = '') {
    $short = strpos($k, 'monitor.') === 0 ? substr($k, 8) : $k;
    return isset($GLOBALS['__set'][$short]) ? $GLOBALS['__set'][$short] : $d;
}

require_once dirname(__DIR__) . '/app/monitor.php';
require_once dirname(__DIR__) . '/app/mon_srv.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   " . $name . "\n"; }
    else { $fail++; echo "  FAIL " . $name . "\n"; }
}

$chromeUA = 'Mozilla/5.0 (Linux; Android 13; 2211133C) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36';
$safariUA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';
$wxUA     = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 MicroMessenger/8.0.44(0x18002c2c) NetType/WIFI Language/zh_CN';

$good       = array('sid' => 'smw7q48cqrzxaqv', 'browser' => 'Chrome 156.0.0.0');
$goodSafari = array('sid' => 'smw7q48cqrzxaqv', 'browser' => 'Safari 17.2');
$goodWx     = array('sid' => 'smw7q48cqrzxaqv', 'browser' => 'WeChat 8.0.44');
$fake       = array('sid' => 's_test123', 'browser' => '', 'version' => '3.60.5');

echo "== 放行（真实 SDK 形态）\n";
t('Chrome 上报', mon_collect_trust($good, $chromeUA));
t('Safari 上报', mon_collect_trust($goodSafari, $safariUA));
t('微信上报', mon_collect_trust($goodWx, $wxUA));
t('Other + iOS Chrome UA', mon_collect_trust(
    array('sid' => 'smw7q48cqrzxaqv', 'browser' => 'Other'),
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 CriOS/120.0.0.0 Mobile/15E148'
));
t('缓存旧版页面仍上报（不按 version 拒绝）', mon_collect_trust(
    array('sid' => 'smw7q48cqrzxaqv', 'browser' => 'Chrome 144.0.0.0', 'version' => '3.60.5'), $chromeUA
));

echo "\n== 拒绝（伪造形态）\n";
t('本次灌水原始样本（sid=s_test123 / browser 空）', !mon_collect_trust($fake, ''));
t('sid 过短', !mon_collect_trust(array('sid' => 's123', 'browser' => 'Chrome 156.0.0.0'), $chromeUA));
t('sid 含非法字符', !mon_collect_trust(array('sid' => 'smw7q48c!rzxaqv', 'browser' => 'Chrome 156.0.0.0'), $chromeUA));
t('browser 为空', !mon_collect_trust(array('sid' => 'smw7q48cqrzxaqv', 'browser' => ''), $chromeUA));
t('UA 为空', !mon_collect_trust($good, ''));
t('curl 冒充 Chrome', !mon_collect_trust($good, 'curl/8.5.0'));
t('python 冒充 Chrome', !mon_collect_trust($good, 'python-requests/2.31.0'));
t('UA 是 Firefox 却报 Chrome', !mon_collect_trust(
    $good, 'Mozilla/5.0 (Windows NT 10.0; rv:121.0) Gecko/20100101 Firefox/121.0'
));
t('报 Safari 但 UA 无 Version/', !mon_collect_trust($goodSafari, $chromeUA));
t('报微信但 UA 无 MicroMessenger', !mon_collect_trust($goodWx, $chromeUA));

echo "\n== 5xx 告警分类\n";
function alert_last()
{
    $ins = $GLOBALS['__ins'];
    return $ins ? $ins[count($ins) - 1] : array();
}
$_SERVER['SCRIPT_NAME'] = '/api/auth.php';
mon_srv_alert(200, 502);
$a = alert_last();
t('邮件 502 → 外部依赖 warn', ($a[0] ?? '') === 'srv_dep' && ($a[1] ?? '') === 'warn');

$_SERVER['SCRIPT_NAME'] = '/api/works.php';
mon_srv_alert(200, 500);
$a = alert_last();
t('代码 500 → 内部错误 error', ($a[0] ?? '') === 'srv_5xx' && ($a[1] ?? '') === 'error');

$_SERVER['SCRIPT_NAME'] = '/api/ai.php';
$n0 = count($GLOBALS['__ins']);
mon_srv_alert(9000, 500);
$ins = $GLOBALS['__ins'];
t('AI 慢请求单独报 srv_slow_req', ($ins[$n0][0] ?? '') === 'srv_slow_req');
t('AI 500 → 外部依赖 warn', ($ins[$n0 + 1][0] ?? '') === 'srv_dep');

echo "\n通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail > 0 ? 1 : 0);
