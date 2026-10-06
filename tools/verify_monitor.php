<?php
/**
 * 网页异常监测（Web APM）自检 —— 桩件驱动，不连数据库。
 *   php tools/verify_monitor.php
 * 覆盖：脱敏、时间窗口、Apdex 口径、告警去重、明细清理与保留天数钳制。
 */
declare(strict_types=1);

/* ---------------- 桩件 ---------------- */
$GLOBALS['TBL']  = array('web_events' => true, 'web_alerts' => true, 'web_srv' => true);
$GLOBALS['SET']  = array('monitor.keep_days' => '7');
$GLOBALS['AROW'] = null;          // db_val 返回值（模拟「近期已有告警」）
$GLOBALS['INS']  = array();        // 收集 db_insert
$GLOBALS['EXEC'] = array();        // 收集 db_exec

function table_exists($t) { return !empty($GLOBALS['TBL'][$t]); }
function setting_get($k, $d = '') { return isset($GLOBALS['SET'][$k]) ? $GLOBALS['SET'][$k] : $d; }
function setting_set($k, $v) { $GLOBALS['SET'][$k] = $v; return true; }
function db_val($sql, $a = array()) { return $GLOBALS['AROW']; }
function db_insert($sql, $a = array()) { $GLOBALS['INS'][] = array($sql, $a); return 1; }
function db_exec($sql, $a = array()) { $GLOBALS['EXEC'][] = array($sql, $a); return 3; }
function now_utc() { return gmdate('Y-m-d H:i:s'); }
function app_log($m) { }

require __DIR__ . '/../app/monitor.php';

$pass = 0; $fail = 0;
function check($n, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }
function ago($s) { return time() - $s; }   // 便于比较时间参数

echo "== 1) 脱敏 mon_clean ==\n";
check('邮箱打码', strpos(mon_clean('联系 me@example.com 谢谢'), '[email]') !== false);
check('手机号打码', strpos(mon_clean('手机 13812345678 丢了'), '[phone]') !== false);
check('敏感参数打码', strpos(mon_clean('url?a=1&token=abcdEF123&b=2'), 'token=[redacted]') !== false);
check('换行/制表转空格', strpos(mon_clean("a\nb\tc"), "\n") === false && strpos(mon_clean("a\nb\tc"), "\t") === false);
check('去除控制字符', mon_clean("a\x01\x02b") === 'ab');
check('两端去空白', mon_clean("  hi  ") === 'hi');

echo "== 2) 时间窗口 mon_window ==\n";
list($h1, $n1) = mon_window('1h');
check('1h 长度=1', $n1 === 1);
check('1h 起点≈1 小时前', abs(strtotime($h1 . ' UTC') - ago(3600)) <= 5);
list(, $n6) = mon_window('6h');
check('6h 长度=6', $n6 === 6);
list(, $n24) = mon_window('24h');
check('24h 长度=24', $n24 === 24);
list(, $n7) = mon_window('7d');
check('7d 长度=168', $n7 === 168);
list(, $n30) = mon_window('30d');
check('30d 长度=720', $n30 === 720);
list(, $nU) = mon_window('zzz');
check('未知范围兜底=168', $nU === 168);

echo "== 3) Apdex 口径（T=1200ms）==\n";
check('空集=1.0', mon_apdex(array(), 1200) === 1.0);
check('全部满意=1.0', mon_apdex(array(100, 200, 300), 1200) === 1.0);
check('全部容忍=0.5', mon_apdex(array(2000, 2000), 1200) === 0.5);
check('全部超时=0.0', mon_apdex(array(6000, 9000), 1200) === 0.0);
check('混合 1满意1容忍1超时=0.5', mon_apdex(array(1000, 2000, 6000), 1200) === 0.5);

echo "== 4) 告警去重 mon_alert ==\n";
$GLOBALS['AROW'] = null; $GLOBALS['INS'] = array();
check('无近期告警 → 写入', mon_alert('error_rate', 'error', 'JS 错误率超阈', 9, 5) === true);
check('写入 1 条', count($GLOBALS['INS']) === 1);
check('落库字段正确', $GLOBALS['INS'][0][1][0] === 'error_rate' && $GLOBALS['INS'][0][1][3] === 9 && $GLOBALS['INS'][0][1][4] === 5);
$GLOBALS['AROW'] = array('id' => 1); $GLOBALS['INS'] = array();
check('1 小时内已有 → 不写', mon_alert('error_rate', 'error', 'x', 9, 5) === false);
check('确实没写', count($GLOBALS['INS']) === 0);
$GLOBALS['AROW'] = null; $GLOBALS['TBL']['web_alerts'] = false;
check('告警表缺失 → 安全返回 false', mon_alert('error_rate', 'error', 'x', 9, 5) === false);
$GLOBALS['TBL']['web_alerts'] = true;

echo "== 5) 明细清理 mon_cleanup ==\n";
$GLOBALS['SET']['monitor.keep_days'] = '7'; $GLOBALS['EXEC'] = array();
check('返回删除行数（两张表各 3）', mon_cleanup() === 6);
check('执行 2 条 DELETE（事件明细 + 服务端指标）', count($GLOBALS['EXEC']) === 2);
$arg = $GLOBALS['EXEC'][0][1][0];
check('删除阈值≈7 天前', abs(strtotime($arg . ' UTC') - ago(7 * 86400)) <= 5);
$GLOBALS['SET']['monitor.keep_days'] = '200'; $GLOBALS['EXEC'] = array();
mon_cleanup();
check('保留天数上钳到 90', abs(strtotime($GLOBALS['EXEC'][0][1][0] . ' UTC') - ago(90 * 86400)) <= 5);
$GLOBALS['SET']['monitor.keep_days'] = '0'; $GLOBALS['EXEC'] = array();
mon_cleanup();
check('保留天数下钳到 1', abs(strtotime($GLOBALS['EXEC'][0][1][0] . ' UTC') - ago(86400)) <= 5);
$GLOBALS['TBL']['web_events'] = false; $GLOBALS['TBL']['web_srv'] = false; $GLOBALS['EXEC'] = array();
check('明细表缺失 → 0 且不执行', mon_cleanup() === 0 && count($GLOBALS['EXEC']) === 0);

echo "\n监测自检：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail > 0 ? 1 : 0);
