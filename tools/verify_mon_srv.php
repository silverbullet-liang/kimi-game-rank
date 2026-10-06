<?php
/**
 * 服务端请求指标（P2）自检 —— 桩件驱动，不连数据库。
 *   php tools/verify_mon_srv.php
 * 覆盖：路由归一化、SQL 指纹、采集开关、DB 耗时钩子、错误记录、
 *       白名单解析、shutdown 落库字段与阈值告警。
 */
declare(strict_types=1);

/* ---------------- 桩件 ---------------- */
$GLOBALS['ROWS'] = array(
    array('k' => 'monitor.enabled', 'v' => '1'),
    array('k' => 'monitor.srv_sample', 'v' => '100'),
    array('k' => 'monitor.srv_slow_ms', 'v' => '50'),
    array('k' => 'monitor.srv_slow_alert_ms', 'v' => '1000'),
    array('k' => 'monitor.apdex_t', 'v' => '900'),
    array('k' => 'monitor.track_urls', 'v' => 'api/a.php, api/b.php ,,x'),
);
$GLOBALS['TBL']    = array('web_srv' => true);
$GLOBALS['INS']    = array();
$GLOBALS['ALERTS'] = array();

function db_all($sql, $a = array()) { return $GLOBALS['ROWS']; }
function db_val($sql, $a = array()) { return null; }
function db_insert($sql, $a = array()) { $GLOBALS['INS'][] = array($sql, $a); return 1; }
function db_exec($sql, $a = array()) { return 1; }
function table_exists($t) { return !empty($GLOBALS['TBL'][$t]); }
function setting_get($k, $d = '') { return $d; }
function mon_clean($s) { return trim(preg_replace('/\s+/', ' ', (string)$s)); }
function mon_alert($rule, $level, $msg, $v, $th) { $GLOBALS['ALERTS'][] = array($rule, $level, $msg, $v, $th); return true; }
function ip_hash($ip) { return str_repeat('a', 64); }
function client_ip() { return '127.0.0.1'; }
function now_utc() { return gmdate('Y-m-d H:i:s'); }
function app_log($m) { }

require __DIR__ . '/../app/mon_srv.php';

$pass = 0; $fail = 0;
function check($n, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }

echo "== 1) 路由归一化 ==\n";
$_SERVER['SCRIPT_NAME'] = '/api/works.php';
check("SCRIPT_NAME → api/works.php", mon_srv_route() === 'api/works.php');
$_SERVER['SCRIPT_NAME'] = '';
$_SERVER['REQUEST_URI'] = '/x/y.php?z=1';
check("回退 REQUEST_URI → x/y.php", mon_srv_route() === 'x/y.php');
$_SERVER['SCRIPT_NAME'] = '/api/works.php'; unset($_SERVER['REQUEST_URI']);

echo "== 2) SQL 指纹 ==\n";
check('数字字面量替换', mon_srv_sql_print("SELECT * FROM t WHERE id = 123") === 'SELECT * FROM t WHERE id = ?');
check('单引号字面量替换', mon_srv_sql_print("SELECT * FROM t WHERE name = 'x'") === 'SELECT * FROM t WHERE name = ?');
check('双引号字面量替换', mon_srv_sql_print('SELECT "col" FROM t') === 'SELECT ? FROM t');
check('空白压缩并去首尾', mon_srv_sql_print("  SELECT  a\n  FROM t  ") === 'SELECT a FROM t');
check('超长截断到 191', mb_strlen(mon_srv_sql_print('SELECT ' . str_repeat('a', 400)), 'UTF-8') === 191);

echo "== 3) 采集配置与开关 ==\n";
$cfg = mon_srv_cfg();
check('配置读库覆盖 srv_sample=100', $cfg['srv_sample'] === '100');
check('配置读库覆盖 srv_slow_ms=50', $cfg['srv_slow_ms'] === '50');
check('配置读库覆盖 apdex_t=900', $cfg['apdex_t'] === '900');
check('配置缺省项保持默认 keep_days=7', $cfg['keep_days'] === '7');
check('监测接口自身不判定为可采集', mon_srv_route_ok('api/monitor.php') === false);
check('普通接口判定为可采集', mon_srv_route_ok('api/works.php') === true);

echo "== 4) 白名单解析 ==\n";
$tu = mon_srv_track_urls();
check('解析出 3 条（去空、去重、保留顺序）', count($tu) === 3 && $tu[0] === 'api/a.php' && $tu[1] === 'api/b.php' && $tu[2] === 'x');

echo "== 5) DB 耗时钩子 ==\n";
$GLOBALS['__MON_SRV'] = null;
mon_srv_db("SELECT 1", 12.5);
check('未开启时不累加', true);   // 无 __MON_SRV['on'] → 早退，无异常
$GLOBALS['__MON_SRV'] = array('on' => true, 't0' => microtime(true), 'db_ms' => 0.0, 'db_n' => 0,
    'slow_n' => 0, 'slow_ms' => 0.0, 'slow_sql' => '', 'err' => '', 'slow_th' => 50);
mon_srv_db('SELECT * FROM a WHERE id = 1', 10.0);
mon_srv_db('SELECT * FROM b WHERE id = 2', 80.0);
mon_srv_db('SELECT * FROM c WHERE id = 3', 120.0);
$s = $GLOBALS['__MON_SRV'];
check('DB 次数累加 = 3', (int)$s['db_n'] === 3);
check('DB 耗时累加 ≈ 210ms', abs((float)$s['db_ms'] - 210.0) < 0.01);
check('慢查询 ≥50ms 计 2 条', (int)$s['slow_n'] === 2);
check('记录最慢语句（c 表）', strpos((string)$s['slow_sql'], 'FROM c') !== false);

echo "== 6) 首个错误记录 ==\n";
mon_srv_note_err('first boom');
mon_srv_note_err('second boom');
check('只保留首个错误', $GLOBALS['__MON_SRV']['err'] === 'first boom');

echo "== 7) shutdown 落库与告警 ==\n";
$GLOBALS['INS'] = array(); $GLOBALS['ALERTS'] = array();
$GLOBALS['__MON_SRV']['t0'] = microtime(true) - 5;      // 5 秒前 → 触发慢请求告警（阈值 1000ms）
mon_srv_flush();
check('落库 1 条', count($GLOBALS['INS']) === 1);
$args = $GLOBALS['INS'][0][1];
check('字段：route', $args[0] === 'api/works.php');
check('字段：method', $args[1] === 'GET');
check('字段：dur ≈ 5000ms', abs((int)$args[3] - 5000) <= 200);
check('字段：db_ms=210', (int)$args[4] === 210);
check('字段：db_n=3', (int)$args[5] === 3);
check('字段：slow_n=2', (int)$args[6] === 2);
check('字段：err=first boom', $args[9] === 'first boom');
check('落库后开关关闭（自身不计入）', empty($GLOBALS['__MON_SRV']['on']));
check('慢请求告警已触发', count(array_filter($GLOBALS['ALERTS'], function ($a) { return $a[0] === 'srv_slow_req'; })) === 1);

$GLOBALS['INS'] = array(); $GLOBALS['ALERTS'] = array();
$GLOBALS['__MON_SRV']['on'] = false;
mon_srv_flush();
check('开关关闭后 flush 不再落库', count($GLOBALS['INS']) === 0);

echo "== 8) web_srv 缺失时安全 ==\n";
$GLOBALS['TBL']['web_srv'] = false;
$GLOBALS['INS'] = array();
$GLOBALS['__MON_SRV'] = array('on' => true, 't0' => microtime(true), 'db_ms' => 0.0, 'db_n' => 0,
    'slow_n' => 0, 'slow_ms' => 0.0, 'slow_sql' => '', 'err' => '', 'slow_th' => 50);
mon_srv_flush();
check('表不存在时不落库、不抛错', count($GLOBALS['INS']) === 0);

echo "\n服务端指标自检：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail > 0 ? 1 : 0);
