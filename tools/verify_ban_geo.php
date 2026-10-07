<?php
/**
 * 封禁 IP 地区判定自检 —— 桩件驱动，不连数据库。
 *   php tools/verify_ban_geo.php
 * 覆盖：球面距离、容差、锚点写入、四种判定组合（容差 30km / 未授权仍封）。
 */
declare(strict_types=1);

$GLOBALS['ROW']  = null;
$GLOBALS['EXEC'] = array();
$GLOBALS['SET']  = array();
$GLOBALS['TBL']  = array('banned_ips' => true);

function db_one($sql, $a = array()) { return $GLOBALS['ROW']; }
function db_all($sql, $a = array()) { return array(); }
function db_val($sql, $a = array()) { return null; }
function db_exec($sql, $a = array()) { $GLOBALS['EXEC'][] = array($sql, $a); return 1; }
function db_insert($sql, $a = array()) { return 1; }
function table_exists($t) { return !empty($GLOBALS['TBL'][$t]); }
function column_exists($t, $c) { return true; }
function setting_get($k, $d = '') { return isset($GLOBALS['SET'][$k]) ? $GLOBALS['SET'][$k] : $d; }
function setting_set($k, $v) { $GLOBALS['SET'][$k] = $v; return true; }
function now_utc() { return gmdate('Y-m-d H:i:s'); }
function app_log($m) { }
function cfg($k, $d = '') { return $d; }
function client_ip() { return '127.0.0.1'; }
function ip_hash($ip) { return str_repeat('f', 64); }
function current_identity() { return array('uid' => 0, 'role' => 'user'); }
function user_register($u, $p) { return array('token' => 't', 'uid' => 1, 'username' => $u); }

require __DIR__ . '/../app/discipline.php';

$pass = 0; $fail = 0;
function check($n, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n\n"; } }

echo "== 1) 球面距离（Haversine）==\n";
check('同点距离 = 0', discipline_geo_dist(30.0, 120.0, 30.0, 120.0) === 0.0);
$d = discipline_geo_dist(0.0, 0.0, 0.0, 1.0);
check('赤道 1 经度 ≈ 111.2 km（实际 ' . round($d, 2) . '）', abs($d - 111.19) < 0.5);
$d2 = discipline_geo_dist(39.9042, 116.4074, 31.2304, 121.4737);   // 北京 → 上海
check('北京→上海 ≈ 1067 km（实际 ' . round($d2, 1) . '）', abs($d2 - 1067) < 25);

echo "== 2) 容差 ==\n";
check('默认容差 30 km', discipline_geo_tol_km() === 30.0);
$GLOBALS['SET']['discipline.geo_tol_km'] = '100';
check('可配置为 100 km', discipline_geo_tol_km() === 100.0);
$GLOBALS['SET']['discipline.geo_tol_km'] = '0';
check('非法值回落到 30', discipline_geo_tol_km() === 30.0);
$GLOBALS['SET']['discipline.geo_tol_km'] = '99999';
check('过大值钳到 2000', discipline_geo_tol_km() === 2000.0);
unset($GLOBALS['SET']['discipline.geo_tol_km']);

echo "== 3) 判定组合 ==\n";
/* 3.1 该 IP 不在黑名单 → 放行 */
$GLOBALS['ROW'] = null;
$v = discipline_geo_verdict('hash', array(30.0, 120.0));
check('IP 不在黑名单 → 放行', $v['blocked'] === false);

/* 3.2 无锚点 + 带坐标 → 记锚点并拦截 */
$GLOBALS['ROW'] = array('geo_lat' => null, 'geo_lng' => null);
$GLOBALS['EXEC'] = array();
$v = discipline_geo_verdict('hash', array(30.0, 120.0));
check('无锚点 + 有坐标 → 拦截', $v['blocked'] === true && $v['anchored'] === false);
check('并写入锚点（1 条 UPDATE）', count($GLOBALS['EXEC']) === 1 && strpos($GLOBALS['EXEC'][0][0], 'geo_lat') !== false);
check('锚点坐标正确', (float)$GLOBALS['EXEC'][0][1][0] === 30.0 && (float)$GLOBALS['EXEC'][0][1][1] === 120.0);

/* 3.3 无锚点 + 无坐标 → 拦截 */
$GLOBALS['EXEC'] = array();
$v = discipline_geo_verdict('hash', null);
check('无锚点 + 无坐标 → 拦截（保守）', $v['blocked'] === true);
check('且不写锚点', count($GLOBALS['EXEC']) === 0);

/* 3.4 有锚点 + 很近（10km）→ 拦截 */
$GLOBALS['ROW'] = array('geo_lat' => 30.0, 'geo_lng' => 120.0);
$v = discipline_geo_verdict('hash', array(30.09, 120.0));   // ≈10km
check('有锚点 + 10km → 拦截', $v['blocked'] === true && $v['anchored'] === true && $v['distance'] < 15);

/* 3.5 有锚点 + 很远（约 111km）→ 放行（同 IP 的另一个人） */
$v = discipline_geo_verdict('hash', array(31.0, 120.0));
check('有锚点 + 约 111km → 放行', $v['blocked'] === false && $v['distance'] > 100);

/* 3.6 有锚点 + 无坐标 → 拦截（不留拒绝授权即解封的口子） */
$v = discipline_geo_verdict('hash', null);
check('有锚点 + 无坐标 → 拦截', $v['blocked'] === true);

/* 3.7 容差放大到 200km 后，111km 变为拦截 */
$GLOBALS['SET']['discipline.geo_tol_km'] = '200';
$v = discipline_geo_verdict('hash', array(31.0, 120.0));
check('容差 200km 时 111km → 拦截', $v['blocked'] === true);
unset($GLOBALS['SET']['discipline.geo_tol_km']);

echo "== 4) 请求头坐标解析 ==\n";
unset($_SERVER['HTTP_X_GEO_LAT'], $_SERVER['HTTP_X_GEO_LNG']);
check('无头 → null', discipline_req_geo() === null);
$_SERVER['HTTP_X_GEO_LAT'] = '0'; $_SERVER['HTTP_X_GEO_LNG'] = '0';
check('0,0（无效值）→ null', discipline_req_geo() === null);
$_SERVER['HTTP_X_GEO_LAT'] = '91'; $_SERVER['HTTP_X_GEO_LNG'] = '120';
check('纬度越界 → null', discipline_req_geo() === null);
$_SERVER['HTTP_X_GEO_LAT'] = '30.5'; $_SERVER['HTTP_X_GEO_LNG'] = '120.5';
$g = discipline_req_geo();
check('合法坐标 → array', is_array($g) && $g[0] === 30.5 && $g[1] === 120.5);
unset($_SERVER['HTTP_X_GEO_LAT'], $_SERVER['HTTP_X_GEO_LNG']);

echo "\n封禁地区判定自检：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail > 0 ? 1 : 0);
