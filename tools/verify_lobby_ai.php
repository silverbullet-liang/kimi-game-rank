<?php
/**
 * 官方 AI 回归：角色设定关键约束 + 多轮上下文组装（去重 / 角色 / 过滤）
 * 运行：php tools/verify_lobby_ai.php
 * 不联网、不连库。
 */
declare(strict_types=1);
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }

$GLOBALS['ROWS'] = array();

function cfg(string $p, $d = null) { return $d; }
function col_ok(string $t, string $c): bool { return true; }
function db_all(string $sql, array $a = array()) { return $GLOBALS['ROWS']; }
function db_insert(string $sql, array $a = array()) { return 99; }
function app_log(string $m) { }
function rate_limit(string $k, int $n, int $w): bool { return true; }
function ai_quota_check(int $uid): array { return array('ok' => true); }
function ai_usage_record(int $uid, array $u, string $p) { }
function identicon_data_uri(string $s, int $n): string { return ''; }
function to_local(string $t, string $f): string { return ''; }
function now_utc(): string { return '2026-10-04 05:00:00'; }
function db_one(string $sql, array $a = array()) { return null; }
function db_val(string $sql, array $a = array()) { return 0; }
function work_link(array $r) { return ''; }
function norm_username(string $s) { return strtolower(trim($s)); }
function site_announce() { return ''; }

/* zhipu_chat 队列化：按序返回，供工具循环的两轮调用 */
$GLOBALS['ZHIPU'] = array();
function zhipu_chat(array $m, string $model = ''): array
{
    if ($GLOBALS['ZHIPU']) { return array_shift($GLOBALS['ZHIPU']); }
    return array('text' => '好嘞', 'usage' => array());
}

require APP_ROOT . '/app/lunar.php';
require APP_ROOT . '/app/festival.php';
require APP_ROOT . '/app/works_tool.php';
require APP_ROOT . '/app/works_tool_extra.php';
require APP_ROOT . '/app/works_tool_loop.php';
require APP_ROOT . '/app/lobby_ai.php';

$GLOBALS['fail_n'] = 0; $GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want)
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 角色设定 ---------- */
$sys = lobby_ai_system();
ck('系统提示含「防复读」', strpos($sys, '防复读') !== false, true);
ck('系统提示禁客服腔', strpos($sys, '咨询客服') !== false, true);
ck('系统提示含显示名', strpos($sys, LOBBY_AI_NAME) !== false, true);

/* ---------- 2. @ 提及判定 ---------- */
ck('@官方AI 命中', lobby_ai_mentioned('喂 @官方AI 在吗'), true);
ck('不含 @ 不命中', lobby_ai_mentioned('大家好啊'), false);

/* ---------- 3. 多轮组装：去重 / 角色 / 过滤 / 顺序 ---------- */
/* db 侧是 DESC（最新在前）：第 0 行就是本次提问（已落库），必须被去重 */
$GLOBALS['ROWS'] = array(
    array('content' => '来看看新版本', 'msg_type' => 'text', 'is_recalled' => 0, 'username' => '小明'),  // 本次提问
    array('content' => '好嘞',         'msg_type' => 'ai',   'is_recalled' => 0, 'username' => '官方AI'),
    array('content' => '你好',         'msg_type' => 'text', 'is_recalled' => 0, 'username' => '小明'),
    array('content' => '被撤回的',     'msg_type' => 'text', 'is_recalled' => 1, 'username' => '小明'),
    array('content' => '图',           'msg_type' => 'image','is_recalled' => 0, 'username' => '小明'),
);
$m = lobby_ai_messages('来看看新版本', '小明');
ck('消息条数 4（system + 2 历史 + 本次）', count($m), 4);
ck('第 1 条是 system',      $m[0]['role'], 'system');
ck('历史的 user 带用户名',  $m[1]['content'], '小明：你好');
ck('AI 回复当 assistant',   $m[2]['role'], 'assistant');
ck('本次提问在最后',        $m[3]['content'], '小明：来看看新版本');
ck('撤回的没进上下文',      strpos(json_encode($m, JSON_UNESCAPED_UNICODE), '被撤回') === false, true);
ck('图片消息没进上下文',    strpos(json_encode($m, JSON_UNESCAPED_UNICODE), '图') === false, true);
ck('本次提问不重复出现',    substr_count(json_encode($m, JSON_UNESCAPED_UNICODE), '来看看新版本'), 1);

/* ---------- 4. 超长内容截断 ---------- */
$long = str_repeat('长', 200);
$GLOBALS['ROWS'] = array(array('content' => $long, 'msg_type' => 'text', 'is_recalled' => 0, 'username' => '小明'));
$m2 = lobby_ai_messages('短', '小明');
ck('历史长文被截断到 120 字 + 省略号', mb_substr($m2[1]['content'], -1, 1, 'UTF-8'), '…');

/* ---------- 5. 清理函数 ---------- */
ck('清掉工具标签（保留标签内文字）', lobby_ai_clean('<search>abc</search>正文') , 'abc正文');
ck('限长 500', mb_strlen(lobby_ai_clean(str_repeat('x', 800)), 'UTF-8'), 500);

/* ---------- 6. 世界对话接入工具（非流式循环） ---------- */
ck('系统提示含工具协议', strpos(lobby_ai_system(), '可用工具') !== false, true);
ck('工具协议含 calc', strpos(lobby_ai_tools_prompt(), 'calc') !== false, true);
ck('工具协议含 docs', strpos(lobby_ai_tools_prompt(), 'docs') !== false, true);

$GLOBALS['ZHIPU'] = array(
    array('text' => '<Works check>{"action":"calc","expr":"1/3"}</Works check>', 'usage' => array()),
    array('text' => '三分之一，约 0.333', 'usage' => array()),
);
$rep = lobby_ai_reply(7, '@官方AI 1/3 是多少', '小明');
ck('世界对话回复 ok', !empty($rep['ok']), true);
ck('回复是最终文本（标签已剥）', isset($rep['item']['content']) ? $rep['item']['content'] : '', '三分之一，约 0.333');
ck('回复不含工具标签', strpos((string)(isset($rep['item']['content']) ? $rep['item']['content'] : ''), '<') === false, true);
ck('下发工具痕迹（中文）', isset($rep['item']['tools'][0]) ? $rep['item']['tools'][0] : '', '精确计算');

/* user 工具在公共频道被白名单挡下 → 通告不可用，第二轮直接作答 */
$GLOBALS['ZHIPU'] = array(
    array('text' => '<Works check>{"action":"user","name":"张三"}</Works check>', 'usage' => array()),
    array('text' => '这个我不清楚', 'usage' => array()),
);
$rep2 = lobby_ai_reply(7, '@官方AI 张三是谁', '小明');
ck('user 工具被挡下后仍作答', isset($rep2['item']['content']) ? $rep2['item']['content'] : '', '这个我不清楚');
ck('被挡下的工具不留痕迹', empty($rep2['item']['tools']), true);

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
