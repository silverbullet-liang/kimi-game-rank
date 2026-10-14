<?php
/**
 * 跳转链接重识别自检（v3.41.0）—— 离线，桩件驱动，不联网。
 * 跑法：php tools/verify_relink.php
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$GLOBALS['ROWS'] = array(); $GLOBALS['SQL'] = array();
$GLOBALS['TRUSTED'] = array(); $GLOBALS['RESOLVE'] = array(); $GLOBALS['HTML'] = array(); $GLOBALS['TITLE'] = array();
$GLOBALS['BUDGET'] = true; $GLOBALS['ENABLED'] = true; $GLOBALS['CHECKED_COL'] = true;

function smart_link_enabled() { return $GLOBALS['ENABLED']; }
function col_ok($t, $c) { return $GLOBALS['CHECKED_COL']; }
function db_all($sql, $a = array()) { return $GLOBALS['ROWS']; }
function db_exec($sql, $a = array()) { $GLOBALS['SQL'][] = array($sql, $a); return 1; }
function cfg($k, $d = null) { return $d; }
function net_budget_allow($k, $n, $w) { return $GLOBALS['BUDGET']; }
function fetch_work_html($u) { return isset($GLOBALS['HTML'][$u]) ? $GLOBALS['HTML'][$u] : ''; }
function smart_link_host_trusted($u) { return isset($GLOBALS['TRUSTED'][$u]); }
function smart_link_resolve($html, $base) { return isset($GLOBALS['RESOLVE'][$base]) ? $GLOBALS['RESOLVE'][$base] : ''; }
function smart_link_norm($u) { return (string)$u; }
function html_title($h) { return isset($GLOBALS['TITLE'][$h]) ? $GLOBALS['TITLE'][$h] : ''; }
function now_utc() { return '2026-10-15 00:00:00'; }
function app_log($m) {}
function cache_get($k, $t) { return null; }
function cache_set($k, $v, $t) { return true; }

require dirname(__DIR__) . '/app/relink.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond) { global $pass, $fail; $cond ? $pass++ : $fail++; printf("%s %s\n", $cond ? 'OK  ' : 'FAIL', $name); }
function reset_env() { $GLOBALS['ROWS'] = array(); $GLOBALS['SQL'] = array(); $GLOBALS['TRUSTED'] = array(); $GLOBALS['RESOLVE'] = array(); $GLOBALS['HTML'] = array(); $GLOBALS['TITLE'] = array(); $GLOBALS['BUDGET'] = true; }
/** $GLOBALS['SQL'] 里是否含「换链接」语句 */
function has_apply() { foreach ($GLOBALS['SQL'] as $s) { if (strpos($s[0], 'html_url = ?') !== false) { return true; } } return false; }
function apply_args() { foreach ($GLOBALS['SQL'] as $s) { if (strpos($s[0], 'html_url = ?') !== false) { return $s[1]; } } return array(); }
function has_mark($id = null) {
    foreach ($GLOBALS['SQL'] as $s) {
        if (strpos($s[0], 'SET link_checked_at') !== false && strpos($s[0], 'html_url = ?') === false) {
            if ($id === null) { return true; }
            $last = end($s[1]);
            if ((int)$last === (int)$id) { return true; }
        }
    }
    return false;
}

/* ---------- 1. 跳转页 → 换成真实地址（标题一并纠正） ---------- */
reset_env();
$hop = 'https://share.x.net/p/1';
$GLOBALS['ROWS'] = array(array('id' => 1, 'html_url' => $hop));
$GLOBALS['HTML'][$hop] = '<html>正在前往…</html>';
$GLOBALS['RESOLVE'][$hop] = 'https://abc.appmiaoda.com/';
$GLOBALS['HTML']['https://abc.appmiaoda.com/'] = '<title>真作品</title>';
$GLOBALS['TITLE']['<title>真作品</title>'] = '真作品';
$r = works_relink_scan(3, 7);
ok('跳转页：计 1 条已检查', $r['scanned'] === 1);
ok('跳转页：计 1 条已替换', $r['changed'] === 1);
ok('跳转页：写出换链接语句', has_apply());
$a = apply_args();
ok('跳转页：新地址即真实地址', in_array('https://abc.appmiaoda.com/', $a, true));
ok('跳转页：标题已纠正', in_array('真作品', $a, true));

/* ---------- 2. 已是作品宿主 → 只标记、不替换 ---------- */
reset_env();
$gh = 'https://me.github.io/x/';
$GLOBALS['ROWS'] = array(array('id' => 2, 'html_url' => $gh));
$GLOBALS['TRUSTED'][$gh] = true;
$GLOBALS['RESOLVE'][$gh] = 'https://should.not.be.used/';
$r = works_relink_scan(3, 7);
ok('作品宿主：不替换', $r['changed'] === 0 && $r['scanned'] === 0);
ok('作品宿主：仍然标记已检查', has_mark(2));
ok('作品宿主：无换链接语句', !has_apply());

/* ---------- 3. 直接页（识别为空）→ 只标记 ---------- */
reset_env();
$direct = 'https://app-dx.appmiaoda.com/';
$GLOBALS['ROWS'] = array(array('id' => 3, 'html_url' => $direct));
$GLOBALS['HTML'][$direct] = '<html>真页面</html>';   // resolve 未配置 → 返回 ''
$r = works_relink_scan(3, 7);
ok('直接页：计 1 条已检查、0 替换', $r['scanned'] === 1 && $r['changed'] === 0);
ok('直接页：已标记，不会反复重抓', has_mark(3));

/* ---------- 4. 抓取空（失败/超限）→ 标记并计 skipped ---------- */
reset_env();
$bad = 'https://share.x.net/p/9';
$GLOBALS['ROWS'] = array(array('id' => 4, 'html_url' => $bad));   // HTML 未配置 → fetch 返回 ''
$r = works_relink_scan(3, 7);
ok('抓取为空：scanned=1、skipped=1', $r['scanned'] === 1 && $r['skipped'] === 1);
ok('抓取为空：已标记', has_mark(4));

/* ---------- 5. 出站预算耗尽 → 停止且不标记 ---------- */
reset_env();
$GLOBALS['BUDGET'] = false;
$GLOBALS['ROWS'] = array(array('id' => 5, 'html_url' => 'https://share.x.net/p/5'));
$r = works_relink_scan(3, 7);
ok('预算耗尽：不处理、不留痕', $r['scanned'] === 0 && empty($GLOBALS['SQL']));

/* ---------- 6. limit 生效 ---------- */
reset_env();
$rows = array(); $map = array();
for ($i = 0; $i < 5; $i++) {
    $u = 'https://share.x.net/p/l' . $i;
    $rows[] = array('id' => 100 + $i, 'html_url' => $u);
    $GLOBALS['HTML'][$u] = '<html>hop</html>';
    $GLOBALS['RESOLVE'][$u] = 'https://real' . $i . '.appmiaoda.com/';
}
$GLOBALS['ROWS'] = $rows;
$r = works_relink_scan(2, 7);
ok('limit=2 → 只处理 2 条', $r['scanned'] === 2 && $r['changed'] === 2);

/* ---------- 7. 迁移未就绪（无 link_checked_at 列）→ 直接返回 ---------- */
reset_env();
$GLOBALS['CHECKED_COL'] = false;
$GLOBALS['ROWS'] = array(array('id' => 9, 'html_url' => 'https://share.x.net/p/9'));
$r = works_relink_scan(3, 7);
ok('无列时不动作', $r['scanned'] === 0 && empty($GLOBALS['SQL']));
$GLOBALS['CHECKED_COL'] = true;

/* ---------- 8. 开关关闭 → 直接返回 ---------- */
reset_env();
$GLOBALS['ENABLED'] = false;
$GLOBALS['ROWS'] = array(array('id' => 7, 'html_url' => 'https://share.x.net/p/7'));
$r = works_relink_scan(3, 7);
ok('开关关闭时不动作', $r['scanned'] === 0 && empty($GLOBALS['SQL']));
$GLOBALS['ENABLED'] = true;

echo "\n通过 $pass / 失败 $fail\n";
exit($fail === 0 ? 0 : 1);
