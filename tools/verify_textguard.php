<?php
/**
 * 文本守卫回归：提示词注入抬档 + 拆字上下文拼接 + 刷屏检测
 * 运行：php tools/verify_textguard.php
 *
 * 不联网、不连库：用桩件替换数据访问层与 cfg，只测这几处「判据本身」。
 */
declare(strict_types=1);
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }

$GLOBALS['ROWS'] = array();
$GLOBALS['SQL']  = array();

function cfg(string $p, $d = null) { return $d; }
function table_exists(string $t): bool { return true; }
function app_log(string $m) { }
function db_all(string $sql, array $a = array()) { return $GLOBALS['ROWS']; }
function db_exec(string $sql, array $a = array()) { $GLOBALS['SQL'][] = $sql; return 1; }
function col_ok(string $t, string $c): bool { return true; }
/* 指纹桩件：真实实现为 sha256(去空白→小写)，此处只保留「同文同指纹、异文异指纹」这一契约 */
function dup_content_norm(string $c): string { return hash('sha256', strtolower(preg_replace('/\s+/u', '', $c))); }
function now_utc(): int { return time(); }
$GLOBALS['CACHE'] = array();
function cache_get(string $key, int $ttl) { return isset($GLOBALS['CACHE'][$key]) ? $GLOBALS['CACHE'][$key] : null; }
function cache_set(string $key, array $val, int $ttl) { $GLOBALS['CACHE'][$key] = $val; return true; }

require APP_ROOT . '/app/jev.php';
require APP_ROOT . '/app/moderation.php';

$GLOBALS['fail_n'] = 0;
$GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want)
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 提示词注入：命中只标注，不拦截 ---------- */
ck('注入 0.9 + 非技术 → 标注',            jev_inject_flag(0.9, 0.0), true);
ck('注入 0.9 + 纯技术讨论 0.9 → 不标注',  jev_inject_flag(0.9, 0.9), false);
ck('注入 0.5（低于阈值）→ 不标注',        jev_inject_flag(0.5, 0.0), false);
ck('注入概率阈值默认 0.85',               jev_inject_prob(), 0.85);
ck('技术讨论豁免阈值默认 0.5',            jev_inject_benign(), 0.5);
ck('讽刺标注阈值默认 0.95',               jev_sarcasm_at(), 0.95);
ck('intent 维度 3 项',                    count(jev_intent_labels()), 3);

/* ---------- 2. 拆字：上下文拼接不加分隔符 ---------- */
$GLOBALS['ROWS'] = array(
    array('id' => 3, 'content' => '逼'),   // 最新在前（db 侧 DESC）
    array('id' => 2, 'content' => '傻'),
);
ck('拼接为「傻逼」+新内容', moderation_context_text(7, '。'), '傻逼。');
ck('上下文条目取到 2 条',   count(moderation_context_rows(7)), 2);

$GLOBALS['ROWS'] = array();
ck('无历史 → 空串',         moderation_context_text(7, '你好'), '');

/* ---------- 3. 连坐撤回：把参与的消息标记为已撤回 ---------- */
$GLOBALS['ROWS'] = array(array('id' => 11, 'content' => '傻'), array('id' => 12, 'content' => '逼'));
$GLOBALS['SQL'] = array();
ck('撤回 2 条', moderation_recall_context(7), 2);
$sqlAll = implode("\n", $GLOBALS['SQL']);
ck('置 is_recalled = 1', strpos($sqlAll, '`is_recalled` = 1') !== false, true);

/* ---------- 4. 刷屏检测 ---------- */
$GLOBALS['ROWS'] = array(array('content' => '来了'), array('content' => '来了'));
ck('同句第 3 次 → 刷屏', moderation_flood(7, '来了'), true);
ck('不同内容 → 不刷屏',  moderation_flood(7, '走了'), false);
$GLOBALS['ROWS'] = array_fill(0, 8, array('content' => 'x'));
ck('窗口内已 8 条 → 刷屏', moderation_flood(7, '随便'), true);
$GLOBALS['ROWS'] = array();
ck('全新用户 → 不刷屏',    moderation_flood(7, '你好'), false);

/* ---------- 5. 空内容不产生误判 ---------- */
ck('空串不刷屏', moderation_flood(7, '   '), false);

/* ---------- 6. 重审凭证：必须按「落库 uid」绑定 ----------
   历史事故：api/recheck.php 写 (int)require_member()，把身份数组强转成 1，
   凭证签发给 uid=1，而发送接口按 actor_uid 查键 → 永远取不到 →
   「AI 复核通过了，消息还是发不出去」。下面两头都钉住。 */
$ident = array('uid' => 42, 'role' => 'user');
ck('(int)身份数组 == 1（故不可直接强转）', (int)$ident, 1);

$GLOBALS['CACHE'] = array();
moderation_pass_issue(42, '你好呀', 'middle');
$p = moderation_pass_take(42, '你好呀');
ck('凭证按 uid 绑定：本人可取到且带标注', is_array($p) && $p['flag'] === 'middle', true);
ck('凭证不跨用户：换个 uid 取不到',       moderation_pass_take(43, '你好呀'), null);
ck('凭证绑定内容：换一段文字取不到',     moderation_pass_take(42, '你好'), null);

$rc = (string)file_get_contents(APP_ROOT . '/api/recheck.php');
ck('recheck.php 走 actor_uid 取 uid',        strpos($rc, 'actor_uid(') !== false, true);
ck('recheck.php 不再直接强转身份数组',        strpos($rc, '(int)require_member') === false, true);

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
