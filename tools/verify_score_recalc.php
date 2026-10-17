<?php
/**
 * 评分体系自动重算自检（v3.43.0）—— 离线，桩件驱动，不联网、不连库。
 * 跑法：php tools/verify_score_recalc.php
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$GLOBALS['SET'] = array('score_ver' => '0');
$GLOBALS['ROWS'] = array(); $GLOBALS['SQL'] = array();
$GLOBALS['TOKEN'] = ''; $GLOBALS['TOTAL'] = 0; $GLOBALS['DONE'] = 0; $GLOBALS['MISS'] = 0;
$GLOBALS['UPSERTED'] = array(); $GLOBALS['FETCHED_URLS'] = array();

function score_weights() { return array('version' => 3); }
function setting_get($k, $d = '') { return array_key_exists($k, $GLOBALS['SET']) ? $GLOBALS['SET'][$k] : $d; }
function setting_set($k, $v) { $GLOBALS['SET'][$k] = $v; return true; }
function db_all($sql, $a = array()) { return $GLOBALS['ROWS']; }
function db_val($sql, $a = array()) {
    if (strpos($sql, 'id <= ?') !== false) { return $GLOBALS['DONE']; }
    if (strpos($sql, 'feat IS NULL') !== false) { return $GLOBALS['MISS']; }
    if (strpos($sql, 'COUNT(*) FROM works WHERE is_hidden = 0') !== false) { return $GLOBALS['TOTAL']; }
    return 0;   // MAX(total_score)
}
function db_exec($sql, $a = array()) { $GLOBALS['SQL'][] = array($sql, $a); return 1; }
function col_ok($t, $c) { return true; }
function cache_get($k, $t = 0) { return null; }
function cache_set($k, $v, $t = 0) { return true; }
function cfg($k, $d = null) { return $d; }
function now_utc() { return '2026-10-16 00:00:00'; }
function app_log($m) {}
function aes_decrypt($s) { return $GLOBALS['TOKEN']; }
function find_work_by_id($t, $id) { return array('community_id' => $id); }
function work_upsert($item, $html = '', $auto = null) { $GLOBALS['UPSERTED'][] = $auto; return array('status' => 'updated'); }
function fetch_work_html($u) { $GLOBALS['FETCHED_URLS'][] = $u; return '<html>x</html>'; }
function work_code_blob($h, $b) { return $h; }
function extract_features($w, $h = '', $t = array(), $c = '') { return array('ok' => 1); }
function compute_scores($f, $s = array()) {
    return array('creativity' => 100, 'experience' => 100, 'depth' => 100, 'cost' => 100,
                 'attitude' => 100, 'heat' => 50, 'total' => 600, 'rating' => 'B');
}
function corpus_stats($force = false) { return array(); }
function features_from_stored($row) { return empty($row['feat']) ? array() : array('like' => 1); }

require dirname(__DIR__) . '/app/score_recalc.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond) { global $pass, $fail; $cond ? $pass++ : $fail++; printf("%s %s\n", $cond ? 'OK  ' : 'FAIL', $name); }
function reset_env() { $GLOBALS['ROWS'] = array(); $GLOBALS['SQL'] = array(); $GLOBALS['UPSERTED'] = array(); $GLOBALS['FETCHED_URLS'] = array(); $GLOBALS['SET'] = array('score_ver' => '0'); $GLOBALS['TOKEN'] = ''; }
function wrote_score() { foreach ($GLOBALS['SQL'] as $s) { if (strpos($s[0], 'SET score = ?') !== false) { return true; } } return false; }

/* ---------- 1. 版本判定 ---------- */
reset_env();
ok('版本不一致 → 需要重算', score_recalc_needed() === true);
$GLOBALS['SET']['score_ver'] = '3';
ok('版本一致 → 不需要重算', score_recalc_needed() === false);

/* ---------- 2. 有快照 → 离线重算（不联网） ---------- */
reset_env();
$GLOBALS['ROWS'] = array(array('id' => 7, 'community_id' => 'c7', 'feat' => '{"like":1}',
    'html_url' => 'https://x/7', 'title' => 't', 'intro' => '', 'like_num' => 1,
    'comment_num' => 0, 'collect_num' => 0, 'created_at' => '2026-10-01 00:00:00'));
$r = score_recalc_tick(5);
ok('有快照：计 1 条离线重算', $r['ran'] === true && $r['offline'] === 1 && $r['fetched'] === 0);
ok('有快照：写出评分', wrote_score());
ok('有快照：不联网抓页面', empty($GLOBALS['FETCHED_URLS']));
ok('游标推进到该条', (string)$GLOBALS['SET']['score_recalc_cursor'] === '7');

/* ---------- 3. 无快照 + 有社区凭证 → 联网补齐 ---------- */
reset_env();
$GLOBALS['SET']['kimi_token'] = 'enc';      // 已保存社区凭证（解密桩返回 $GLOBALS['TOKEN']）
$GLOBALS['TOKEN'] = 'tok';
$GLOBALS['ROWS'] = array(array('id' => 9, 'community_id' => 'c9', 'feat' => '',
    'html_url' => 'https://x/9', 'title' => 't', 'intro' => '', 'like_num' => 0,
    'comment_num' => 0, 'collect_num' => 0, 'created_at' => '2026-10-01 00:00:00'));
$r = score_recalc_tick(5);
ok('无快照+凭证：走联网补齐（work_upsert 传 auto=true）',
    $r['fetched'] === 1 && $GLOBALS['UPSERTED'] === array(true));

/* ---------- 4. 无快照 + 无凭证 + 有链接 → 抓页面尽力重算 ---------- */
reset_env();
$GLOBALS['ROWS'] = array(array('id' => 11, 'community_id' => 'c11', 'feat' => '',
    'html_url' => 'https://x/11', 'title' => 't', 'intro' => '', 'like_num' => 0,
    'comment_num' => 0, 'collect_num' => 0, 'created_at' => '2026-10-01 00:00:00'));
$r = score_recalc_tick(5);
ok('无快照+无凭证：抓作品页重算', $r['fetched'] === 1 && $GLOBALS['FETCHED_URLS'] === array('https://x/11'));
ok('无快照+无凭证：写出评分', wrote_score());

/* ---------- 5. 无快照 + 无链接 → 跳过 ---------- */
reset_env();
$GLOBALS['ROWS'] = array(array('id' => 13, 'community_id' => 'c13', 'feat' => '', 'html_url' => '',
    'title' => 't', 'intro' => '', 'like_num' => 0, 'comment_num' => 0, 'collect_num' => 0,
    'created_at' => '2026-10-01 00:00:00'));
$r = score_recalc_tick(5);
ok('无快照+无链接：跳过但不中断', $r['skipped'] === 1 && $r['ran'] === true);

/* ---------- 6. 名单走完 → 落版本号 ---------- */
reset_env();
$GLOBALS['SET']['score_recalc_cursor'] = '50';
$GLOBALS['ROWS'] = array();
$r = score_recalc_tick(5);
ok('走完：done=true', $r['done'] === true);
ok('走完：写入已应用版本 3', (string)$GLOBALS['SET']['score_ver'] === '3');
ok('走完：游标归零', (string)$GLOBALS['SET']['score_recalc_cursor'] === '0');
ok('走完：不再需要重算', score_recalc_needed() === false);

/* ---------- 7. 状态概览 ---------- */
reset_env();
$GLOBALS['TOTAL'] = 100; $GLOBALS['DONE'] = 40; $GLOBALS['MISS'] = 12;
$GLOBALS['SET']['score_recalc_cursor'] = '80';
$st = score_recalc_status();
ok('状态：needed/ver/applied', $st['needed'] === true && $st['ver'] === 3 && $st['applied'] === 0);
ok('状态：总数/已完成/剩余', $st['total'] === 100 && $st['done'] === 40 && $st['left'] === 60);
ok('状态：待补齐数', $st['missing'] === 12);

/* ---------- 8. 未到版本变更时零开销 ---------- */
reset_env();
$GLOBALS['SET']['score_ver'] = '3';
$GLOBALS['ROWS'] = array(array('id' => 1, 'community_id' => 'c1', 'feat' => '{"like":1}'));
ok('版本已应用：tick 不动作', score_recalc_tick(5)['ran'] === false && empty($GLOBALS['SQL']));

/* ---------- 9. 手动启动闸门（v3.44.0：部署后不再自动重算） ---------- */
reset_env();
ok('默认未启动（部署后不自动跑）', score_recalc_armed() === false);
$GLOBALS['ROWS'] = array(array('id' => 3, 'community_id' => 'c3', 'feat' => '{"like":1}',
    'html_url' => 'https://x/3', 'title' => 't', 'intro' => '', 'like_num' => 0,
    'comment_num' => 0, 'collect_num' => 0, 'created_at' => '2026-10-01 00:00:00'));
ok('未启动：bootstrap 不推进', score_recalc_tick_if_due() === array() && empty($GLOBALS['SQL']));

$GLOBALS['SET']['score_recalc_cursor'] = '99';
$st = score_recalc_start();
ok('手动启动：armed=1 且游标归零', score_recalc_armed() === true && (string)$GLOBALS['SET']['score_recalc_cursor'] === '0');
ok('手动启动：状态回传 armed', isset($st['armed']) && $st['armed'] === true);
$r = score_recalc_tick_if_due();
ok('启动后：bootstrap 推进一批', $r['ran'] === true && $r['offline'] === 1);

reset_env();
$GLOBALS['SET']['score_recalc_armed'] = '1';
$GLOBALS['SET']['score_recalc_cursor'] = '99';
$GLOBALS['ROWS'] = array();
$r = score_recalc_tick(5);
ok('走完：自动解除启动标记', $r['done'] === true && score_recalc_armed() === false);

echo "\n通过 $pass / 失败 $fail\n";
exit($fail === 0 ? 0 : 1);
