<?php
/**
 * 站点互通回归：外键形态判定 + 单行容错 + 内容表覆盖。
 * 运行：php tools/verify_peer_sync.php
 *
 * 不连库、不联网：只测判据本身与源码闸门。
 *
 * 历史事故：导入端用 (int)$v === 0 判断「这个外键是不是 0」——
 * gid 是十六进制，以 0 开头时 (int) 得到 0，于是被当成「引用本来就是 0」直接落库，
 * 两条真实外键都写成 0，撞上 work_votes 的 uk_work_user，整个 apply 抛异常，
 * 后面几张表（feedback / discipline_reports / banned_ips）全都没跑到。
 */
declare(strict_types=1);
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }

function cfg(string $p, $d = null) { return $d; }
function now_utc(): int { return time(); }
function app_log(string $m) { }
function table_exists(string $t): bool { return true; }
function column_exists(string $t, string $c, bool $r = false): bool { return false; }
function db_val(string $s, array $a = array()) { return null; }
function db_one(string $s, array $a = array()) { return null; }
function db_all(string $s, array $a = array()) { return array(); }
function db_exec(string $s, array $a = array()) { return 0; }
function db_insert(string $s, array $a = array()) { return 0; }

require APP_ROOT . '/app/peer_sync.php';

$GLOBALS['pass_n'] = 0;
$GLOBALS['fail_n'] = 0;
function ck(string $name, $got, $want)
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 外键形态判定 ---------- */
$z = '0' . str_repeat('a', 31);          // 以 0 开头的 32 位 gid —— 正是当初误判的那类
ck('gid 以 0 开头 → 仍按 gid 解析',      sync_ref_gid($z), $z);
ck('正常 gid → 原样返回',                sync_ref_gid('a' . str_repeat('0', 31)), 'a' . str_repeat('0', 31));
ck('数字 0 → 本来就是 0',                sync_ref_gid(0), null);
ck('字符串 "0" → 本来就是 0',            sync_ref_gid('0'), null);
ck('空串 → 本来就是 0',                  sync_ref_gid(''), null);
ck('null → 本来就是 0',                  sync_ref_gid(null), null);
ck('长度不足 32 → 形态不合法',           sync_ref_gid('0abc'), '');
ck('含大写 → 形态不合法',                sync_ref_gid(strtoupper($z)), '');
ck('含非十六进制字符 → 形态不合法',      sync_ref_gid(str_repeat('z', 32)), '');

/* ---------- 2. 内容表覆盖：除对话与凭证外，内容一律参与互通 ---------- */
$plan = array_keys(sync_plan());
$content = array('users', 'works', 'announcements', 'comments', 'work_votes', 'comment_votes',
                 'checkins', 'work_score_history', 'feedback', 'discipline_reports', 'banned_ips');
foreach ($content as $t) { ck('计划包含内容表 ' . $t, in_array($t, $plan, true), true); }
ck('世界对话 messages 不参与',            in_array('messages', $plan, true), false);
ck('AI 对话 ai_messages 不参与',          in_array('ai_messages', $plan, true), false);
ck('管理员凭证 admin_credentials 不出站', in_array('admin_credentials', $plan, true), false);
ck('副管理员凭证 sub_admins 不出站',      in_array('sub_admins', $plan, true), false);
ck('互通自身表 peers 不参与',             in_array('peers', $plan, true), false);

/* ---------- 3. 源码闸门：不许退回旧写法、必须逐行容错 ---------- */
$src = (string)file_get_contents(APP_ROOT . '/app/peer_sync.php');
ck('不再用 (int)$v 判断「是否为 0」',      strpos($src, "(int)\$v === 0) { \$v = 0; }") === false, true);
ck('导入逐行 try/catch（单行失败不带崩整轮）', strpos($src, '} catch (Throwable $e) {') !== false, true);
ck('导入返回 failed 计数',                 strpos($src, "failed' => \$failed") !== false, true);
$api = (string)file_get_contents(APP_ROOT . '/api/peer.php');
ck('端点把 failed 回传给调用方',           strpos($api, "\$reply['failed']") !== false, true);

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
