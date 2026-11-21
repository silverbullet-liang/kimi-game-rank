<?php
/**
 * 自检：登录设备管理（3 台上限 / 远程切换账号 / 风险三档）
 * ------------------------------------------------------------
 * 桩件驱动，不连库、不联网。跑法：php tools/verify_devices.php
 */
declare(strict_types=1);

$GLOBALS['SQL']      = array();
$GLOBALS['ONE_RULES'] = array();   // SQL 子串 => 返回行
$GLOBALS['ALL_ROWS']  = array();   // db_all 返回
$GLOBALS['VAL']       = 1;         // db_val 返回
$GLOBALS['TWOFA']     = false;
$GLOBALS['PWD_OK']    = true;
$GLOBALS['DEV']       = 'devhash-abc';
$GLOBALS['CACHE']     = array();
$GLOBALS['FAIL']      = array();
$GLOBALS['TOKLIST']   = array();

function table_exists($t) { return $t === 'login_devices'; }
function cfg($k, $d = null) { return $d; }
function client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '203.0.113.9'; }
function ip_hash($ip) { return hash('sha256', 'ip|' . $ip); }
function app_log($m) { }
function now_utc() { return gmdate('Y-m-d H:i:s'); }
function cache_get($k, $ttl = 0) { return isset($GLOBALS['CACHE'][$k]) ? $GLOBALS['CACHE'][$k] : null; }
function cache_set($k, $v, $ttl = 0) { $GLOBALS['CACHE'][$k] = $v; return true; }

function _sql($s) { return preg_replace('/\s+/', ' ', trim($s)); }
function db_exec($sql, $a = array()) { $GLOBALS['SQL'][] = array(_sql($sql), $a); return 1; }
function db_insert($t, $a) { $GLOBALS['SQL'][] = array('INSERT ' . $t, $a); return 1; }
function db_val($sql, $a = array()) { $GLOBALS['SQL'][] = array(_sql($sql), $a); return $GLOBALS['VAL']; }
function db_one($sql, $a = array()) {
    $s = _sql($sql);
    $GLOBALS['SQL'][] = array($s, $a);
    foreach ($GLOBALS['ONE_RULES'] as $needle => $row) {
        if (strpos($s, $needle) !== false) { return $row; }
    }
    return null;
}
function db_all($sql, $a = array()) {
    $GLOBALS['SQL'][] = array(_sql($sql), $a);
    return $GLOBALS['ALL_ROWS'];
}

/* ---- 认证桩件 ---- */
function fail($code, $msg) { throw new RuntimeException($msg . '|' . $code); }
function timing_delay() { }
function norm_username($s) { return strtolower(trim((string)$s)); }
function mail_norm($s) { return strtolower(trim((string)$s)); }
function login_locked($k = '') { return false; }
function login_mark($ip, $ok, $k = '') { $GLOBALS['TOKLIST'][] = $ok ? 'ok' : 'fail'; }
function user_row_by_identifier($raw) { return isset($GLOBALS['TARGET']) ? $GLOBALS['TARGET'] : null; }
function password_verify_chain($p, $at, $salt, $hash) { return $GLOBALS['PWD_OK']; }
function password_chain($p, $at, $salt) { return ''; }
function admin_verify($k) { return $GLOBALS['PWD_OK']; }
function admin_uid() { return 1; }
function user_twofa_enabled($u) { return $GLOBALS['TWOFA']; }
function issue_user_token($uid) { return 'TOKEN_U' . $uid; }
function issue_subadmin_token($n, $uid) { return 'TOKEN_S' . $uid; }
function issue_admin_token() { return 'TOKEN_ADMIN'; }
function discipline_dev_of_request() { return $GLOBALS['DEV']; }
function discipline_dev_touch($uid, $dev) { return true; }

require dirname(__DIR__) . '/app/devices.php';

$pass = 0; $failN = 0;
function chk($cond, $name) {
    global $pass, $failN;
    if ($cond) { $pass++; echo "  ok   $name\n"; }
    else { $failN++; echo "  FAIL $name\n"; }
}
function sql_has($needle) {
    foreach ($GLOBALS['SQL'] as $row) {
        if (strpos($row[0], $needle) !== false) { return true; }
    }
    return false;
}
function sql_args($needle) {
    foreach ($GLOBALS['SQL'] as $row) {
        if (strpos($row[0], $needle) !== false) { return $row[1]; }
    }
    return array();
}
function reset_state() {
    $GLOBALS['SQL'] = array(); $GLOBALS['ONE_RULES'] = array(); $GLOBALS['ALL_ROWS'] = array();
    $GLOBALS['CACHE'] = array(); $GLOBALS['TOKLIST'] = array(); $GLOBALS['TWOFA'] = false;
    $GLOBALS['PWD_OK'] = true; unset($GLOBALS['TARGET']);
}

echo "== UA 解析 ==\n";
$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
$r = dev_parse_ua($ua);
chk($r['type'] === 'mobile' && $r['browser'] === 'Safari' && $r['kernel'] === 'WebKit' && $r['brand'] === 'Apple', 'iPhone Safari → mobile/Safari/WebKit/Apple');

$r = dev_parse_ua('Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1');
chk($r['type'] === 'tablet', 'iPad → tablet');

$r = dev_parse_ua('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0');
chk($r['type'] === 'desktop' && $r['browser'] === 'Edge' && $r['kernel'] === 'Blink' && $r['brand'] === 'Windows', 'Windows Edge → desktop/Edge/Blink/Windows');

$r = dev_parse_ua('Mozilla/5.0 (Linux; Android 12; SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0 Safari/537.36');
chk($r['type'] === 'tv', 'Android TV → tv');

$r = dev_parse_ua('Mozilla/5.0 (Linux; Android 11; Tesla) AppleWebKit/537.36 Chrome/95.0 Safari/537.36 Tesla/2023.1');
chk($r['type'] === 'car', 'Tesla → car');

$r = dev_parse_ua('Mozilla/5.0 (Linux; Android 13; HarmonyOS; ALN-AL00 Build/HUAWEIALN-AL00; wv) AppleWebKit/537.36 Chrome/114.0 Mobile Safari/537.36 Kimi/3.1.0');
chk($r['type'] === 'kimi' && $r['browser'] === 'Kimi App WebView', 'Kimi UA → kimi/Kimi App WebView');

$r = dev_parse_ua('Mozilla/5.0 (Linux; Android 13; 2211133C) AppleWebKit/537.36 Chrome/114.0 Mobile Safari/537.36 XiaoMi/MiuiBrowser/17.0');
chk($r['type'] === 'mobile' && $r['browser'] === 'Mi Browser', 'Xiaomi 套壳浏览器 → Mi Browser');

echo "== 网段与国别 ==\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'nl';
$n = dev_net_of_request();
chk($n['net'] === '198.51.0.0/16', 'IPv4 取 /16');
chk($n['country'] === 'NL', '国别归一为大写两位');
$_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12::9';
$n = dev_net_of_request();
chk($n['net'] === '2001:db8::/32', 'IPv6 取前 32 位');
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'X';

echo "== 风险三档 ==\n";
$base = array('first_at' => gmdate('Y-m-d H:i:s', time() - 60), 'pages' => 3, 'last_op_at' => gmdate('Y-m-d H:i:s'), 'ip_nets' => '1.2.0.0/16');
$r = dev_risk($base);
chk($r['level'] === 'ok' && $r['score'] === 0, '一切正常 → ok');
$r = dev_risk(array_merge($base, array('ip_nets' => '1.2.0.0/16,3.4.0.0/16,5.6.0.0/16')));
chk($r['level'] === 'yellow' && $r['score'] === 1, '跨 3 个网段 → yellow');
$r = dev_risk(array_merge($base, array('ip_nets' => '1.2.0.0/16,3.4.0.0/16,5.6.0.0/16', 'first_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'pages' => 0)));
chk($r['level'] === 'orange' && $r['score'] === 2, '再加无前端痕迹 → orange');
$r = dev_risk(array('ip_nets' => '1.2.0.0/16,3.4.0.0/16,5.6.0.0/16', 'first_at' => gmdate('Y-m-d H:i:s', time() - 90000), 'pages' => 0, 'last_op_at' => null));
chk($r['level'] === 'red' && $r['score'] === 3, '三条全中 → red');
$r = dev_risk(array_merge($base, array('first_at' => gmdate('Y-m-d H:i:s', time() - 300), 'pages' => 0)));
chk($r['level'] === 'ok', '刚登录 5 分钟没痕迹不算（未到 10 分钟）');

echo "== 三台上限 ==\n";
reset_state();
$GLOBALS['VAL'] = 11;   // 刚登录那台的行 id
$GLOBALS['ALL_ROWS'] = array(
    array('id' => 11, 'browser' => 'Chrome', 'brand' => 'Windows', 'token_hash' => 'h11'),
    array('id' => 12, 'browser' => 'Safari', 'brand' => 'Apple', 'token_hash' => 'h12'),
    array('id' => 13, 'browser' => 'Edge', 'brand' => 'Windows', 'token_hash' => 'h13'),
    array('id' => 14, 'browser' => 'Firefox', 'brand' => 'Linux', 'token_hash' => 'h14'),
);
$k = dev_enforce_limit(7, 11);
chk(count($k) === 1 && strpos($k[0], 'Firefox') !== false, '第 4 台挤掉最久未活动的那台');
chk(sql_has("UPDATE login_devices SET active = 0, kicked_at = UTC_TIMESTAMP(), kick_reason = ?"), '置为下线并记录原因');
$a = sql_args("UPDATE login_devices SET active = 0");
chk(is_array($a) && $a[0] === 'limit' && (int)$a[1] === 14, '踢的是 id=14、原因 limit');
chk(sql_has("INSERT INTO token_blacklist"), '被踢设备的令牌进黑名单');

reset_state();
$GLOBALS['ALL_ROWS'] = array(
    array('id' => 21, 'browser' => 'Chrome', 'brand' => 'Windows', 'token_hash' => 'h21'),
    array('id' => 22, 'browser' => 'Safari', 'brand' => 'Apple', 'token_hash' => 'h22'),
);
$k = dev_enforce_limit(7, 21);
chk(count($k) === 0 && !sql_has("kick_reason = ?"), '未超限不踢人');

echo "== 远程切换账号 ==\n";
reset_state();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0';
$devRow = array('id' => 31, 'user_id' => 7, 'dev_hash' => 'devhash-abc', 'active' => 1,
    'token_hash' => hash('sha256', 'OLDTOKEN'), 'browser' => 'Chrome', 'brand' => 'Windows');
$GLOBALS['ONE_RULES'] = array(
    'FROM login_devices WHERE id = ? AND user_id = ?' => $devRow,
    'FROM users WHERE id = ?' => array('id' => 42, 'username' => 'alice', 'role' => 'user', 'is_banned' => 0),
);
$GLOBALS['TARGET'] = array('id' => 42, 'username' => 'alice', 'role' => 'user', 'is_banned' => 0,
    'registered_at' => '2026-01-01 00:00:00', 'salt' => 's', 'password_hash' => 'h');
$out = dev_switch_account(7, 31, 'alice', 'rightpass');
chk(isset($out['to']) && $out['to'] === 'alice', '切号成功并返回目标账号');
chk(sql_has("UPDATE login_devices SET user_id = ?"), '设备行改归目标账号');
$a = sql_args("UPDATE login_devices SET user_id = ?");
chk((int)$a[0] === 42 && (int)$a[1] === 42 && $a[2] === 'user', 'switch_uid / switch_role 写对');
chk(sql_has("DELETE FROM login_devices WHERE user_id = ? AND dev_hash = ?"), '先清目标账号下同设备的旧行（防唯一键冲突）');
$a = sql_args("INSERT INTO token_blacklist");
chk(is_array($a) && $a[0] === hash('sha256', 'OLDTOKEN'), '旧令牌进黑名单');

reset_state();
$GLOBALS['ONE_RULES'] = array('FROM login_devices WHERE id = ? AND user_id = ?' => $devRow);
$GLOBALS['TARGET'] = array('id' => 7, 'username' => 'bob', 'role' => 'user', 'is_banned' => 0);
$msg = '';
try { dev_switch_account(7, 31, 'bob', 'p'); } catch (Throwable $e) { $msg = $e->getMessage(); }
chk(strpos($msg, '|400') !== false && strpos($msg, '相同') !== false, '同账号拒绝');

reset_state();
$GLOBALS['ONE_RULES'] = array('FROM login_devices WHERE id = ? AND user_id = ?' => $devRow);
$GLOBALS['TARGET'] = array('id' => 42, 'username' => 'alice', 'role' => 'user', 'is_banned' => 0);
$GLOBALS['TWOFA'] = true;
$msg = '';
try { dev_switch_account(7, 31, 'alice', 'p'); } catch (Throwable $e) { $msg = $e->getMessage(); }
chk(strpos($msg, '|400') !== false && strpos($msg, '双重验证') !== false, '开了双重验证的账号拒绝代切');

reset_state();
$GLOBALS['ONE_RULES'] = array('FROM login_devices WHERE id = ? AND user_id = ?' => $devRow);
$GLOBALS['TARGET'] = array('id' => 42, 'username' => 'alice', 'role' => 'user', 'is_banned' => 0);
$GLOBALS['PWD_OK'] = false;
$msg = '';
try { dev_switch_account(7, 31, 'alice', 'wrong'); } catch (Throwable $e) { $msg = $e->getMessage(); }
chk(strpos($msg, '|401') !== false, '密码错拒绝');

reset_state();
$GLOBALS['ONE_RULES'] = array('FROM login_devices WHERE id = ? AND user_id = ?' => $devRow);
$GLOBALS['TARGET'] = array('id' => 42, 'username' => 'alice', 'role' => 'user', 'is_banned' => 1);
$msg = '';
try { dev_switch_account(7, 31, 'alice', 'p'); } catch (Throwable $e) { $msg = $e->getMessage(); }
chk(strpos($msg, '|403') !== false && strpos($msg, '封停') !== false, '被封停账号拒绝');

echo "== 领取新身份 ==\n";
reset_state();
$GLOBALS['ONE_RULES'] = array(
    'FROM login_devices WHERE token_hash = ? AND switch_uid > 0' => array(
        'id' => 31, 'switch_uid' => 42, 'switch_role' => 'user', 'switch_at' => gmdate('Y-m-d H:i:s', time() - 30)),
    'SELECT id, username, role, is_banned FROM users WHERE id = ?' => array('id' => 42, 'username' => 'alice', 'role' => 'user', 'is_banned' => 0),
    'SELECT id, browser, brand, token_hash FROM login_devices' => array(),
);
$out = dev_claim_switch('OLDTOKEN');
chk(isset($out['token']) && $out['token'] === 'TOKEN_U42', '旧令牌领到目标账号的新令牌');
chk((int)$out['uid'] === 42 && $out['role'] === 'user' && $out['username'] === 'alice', '身份字段齐');
chk(sql_has("switch_uid = 0, switch_role = ''"), '领取后清掉待领取标记');
chk(sql_has("token_hash = ?"), '新令牌哈希落库');

reset_state();
$GLOBALS['ONE_RULES'] = array(
    'FROM login_devices WHERE token_hash = ? AND switch_uid > 0' => array(
        'id' => 31, 'switch_uid' => 42, 'switch_role' => 'user', 'switch_at' => gmdate('Y-m-d H:i:s', time() - 1200)),
);
$out = dev_claim_switch('OLDTOKEN');
chk($out === array(), '超过 10 分钟未领取作废');
chk(sql_has("switch_uid = 0, switch_role = ''"), '并清掉这条待领取');

reset_state();
$GLOBALS['ONE_RULES'] = array(
    'FROM login_devices WHERE token_hash = ? AND switch_uid > 0' => array(
        'id' => 31, 'switch_uid' => 42, 'switch_role' => 'user', 'switch_at' => gmdate('Y-m-d H:i:s', time() - 10)),
    'SELECT id, username, role, is_banned FROM users WHERE id = ?' => array('id' => 42, 'username' => 'alice', 'role' => 'user', 'is_banned' => 1),
);
chk(dev_claim_switch('OLDTOKEN') === array(), '目标账号中途被封 → 不发放');

reset_state();
$GLOBALS['ONE_RULES'] = array(
    'FROM login_devices WHERE token_hash = ? AND switch_uid > 0' => array(
        'id' => 31, 'switch_uid' => 1, 'switch_role' => 'admin', 'switch_at' => gmdate('Y-m-d H:i:s')),
    'SELECT id, username, role, is_banned FROM users WHERE id = ?' => array('id' => 1, 'username' => 'admin', 'role' => 'admin', 'is_banned' => 0),
);
$out = dev_claim_switch('OLDTOKEN');
chk(isset($out['role']) && $out['role'] === 'admin' && (int)$out['uid'] === 0 && $out['token'] === 'TOKEN_ADMIN', '切到管理员 → 按 admin 签发');

echo "== 待领取探测 ==\n";
reset_state();
$GLOBALS['ONE_RULES'] = array('FROM login_devices WHERE token_hash = ? AND switch_uid > 0' => array('id' => 31));
chk(dev_switch_pending('OLDTOKEN') === true, '有切换 → true');
reset_state();
chk(dev_switch_pending('OLDTOKEN') === false, '无切换 → false');
chk(dev_switch_pending('') === false, '空令牌 → false');

echo "== 设备标识缺失时的软指纹 ==\n";
reset_state();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13; wv) Chrome/114.0 Mobile';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zh-CN,zh;q=0.9';
$GLOBALS['DEV'] = '';
$h1 = dev_hash_of_request();
$h2 = dev_hash_of_request();
chk($h1 !== '' && strlen($h1) === 64, '没有 X-Device-Id 也能算出软指纹');
chk($h1 === $h2, '软指纹稳定（同一 UA + 语言 + 网段）');
$GLOBALS['DEV'] = 'strong-dev';
chk(dev_hash_of_request() === 'strong-dev', '带了强指纹就优先用强指纹');

echo "== 惰性补登记（只要登着就算） ==\n";
reset_state();
$GLOBALS['DEV'] = 'h1';
$GLOBALS['VAL'] = 0;
dev_ensure(7, 'TOK');
chk(sql_has('INSERT INTO login_devices'), '未登记的设备被补进列表');
chk(sql_has('ON DUPLICATE KEY UPDATE'), '走的是同一套登记逻辑');

reset_state();
$GLOBALS['DEV'] = 'h1';
$GLOBALS['ONE_RULES'] = array('FROM login_devices WHERE user_id = ? AND dev_hash = ?' =>
    array('id' => 5, 'token_hash' => 'stale', 'active' => 0));
dev_ensure(7, 'TOK');
chk(sql_has('SET active = 1, token_hash = ?'), '令牌换过的老设备置回在线');
$a = sql_args('SET active = 1, token_hash = ?');
chk(is_array($a) && $a[0] === hash('sha256', 'TOK') && (int)$a[1] === 5, '写入的是当前令牌的哈希');

reset_state();
$GLOBALS['DEV'] = 'h1';
$GLOBALS['ONE_RULES'] = array('FROM login_devices WHERE user_id = ? AND dev_hash = ?' =>
    array('id' => 5, 'token_hash' => hash('sha256', 'TOK'), 'active' => 1));
dev_ensure(7, 'TOK');
chk(!sql_has('INSERT INTO login_devices') && !sql_has('SET active = 1'), '已经在线且令牌一致 → 不写库');

reset_state();
$GLOBALS['DEV'] = 'h1';
$k = 'devens_' . substr(dev_hash_of_request(), 0, 16);
$GLOBALS['CACHE'][$k] = array(1);
dev_ensure(7, 'TOK');
chk(count($GLOBALS['SQL']) === 0, '节流期内完全不查库');

reset_state();
$GLOBALS['DEV'] = 'h1';
dev_ensure(0, 'TOK');
dev_ensure(7, '');
chk(count($GLOBALS['SQL']) === 0, '无账号或空令牌直接跳过');

echo "\n通过 $pass 项，失败 $failN 项\n";
exit($failN > 0 ? 1 : 0);
