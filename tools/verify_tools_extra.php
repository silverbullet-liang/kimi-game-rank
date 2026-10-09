<?php
/**
 * 扩展工具组（stats/categories/announce/calc/random/lunar/user）：离线回归。
 * 运行：php tools/verify_tools_extra.php     不连库、不联网（DB 走桩件）。
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/* ---------- 最小桩件 ---------- */
$GLOBALS['ROWS'] = array();
$GLOBALS['ONE']  = null;
$GLOBALS['VALS'] = array();
function db_all($sql, $args = array()) { return $GLOBALS['ROWS']; }
function db_one($sql, $args = array()) { return $GLOBALS['ONE']; }
function db_val($sql, $args = array())
{
    $s = strtolower((string)$sql);
    foreach ((array)$GLOBALS['VALS'] as $k => $v) { if (strpos($s, $k) !== false) { return $v; } }
    return 0;
}
function col_ok($t, $c) { return true; }
function cfg($p, $d = null) { return $d; }
function work_link(array $r) { $k = isset($r['community_id']) ? $r['community_id'] : (isset($r['id']) ? $r['id'] : ''); return 'https://x/' . $k; }
function norm_username($s) { return strtolower(trim((string)$s)); }
function site_announce() { return ''; }

require APP_ROOT . '/app/lunar.php';
require APP_ROOT . '/app/festival.php';
require APP_ROOT . '/app/works_tool.php';
require APP_ROOT . '/app/works_tool_extra.php';

$PASS = 0; $FAIL = 0;
function ck(string $name, $got, $want): void
{
    global $PASS, $FAIL;
    $ok = ($got === $want);
    $ok ? $PASS++ : $FAIL++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 注册表 ---------- */
$extra = works_tool_extra_names();
ck('扩展组共 7 个工具', count($extra), 7);
foreach (array('stats', 'categories', 'announce', 'calc', 'random', 'lunar', 'user') as $n) {
    ck("WORKS_TOOL_NAMES 含 $n", strpos(WORKS_TOOL_NAMES, $n) !== false, true);
    ck("names_re 含 $n", strpos(works_tool_names_re(), $n) !== false, true);
}
$pub = works_tool_public_names();
ck('公共工具集不含 user', in_array('user', $pub, true), false);
ck('公共工具集含 calc', in_array('calc', $pub, true), true);
ck('公共工具集含 docs', in_array('docs', $pub, true), true);
ck('公共工具集 = 全部 - 1', count($pub), count(explode(',', WORKS_TOOL_NAMES)) - 1);
ck('norm_name 保持 calc', works_tool_norm_name('calc'), 'calc');
ck('类别中文名 literature → 文学', works_tool_cat_name('literature'), '文学');

/* ---------- 2. 调用识别（解析器 + 键归属） ---------- */
ck('{"expr":..} 判为 calc', works_tool_guess_action(array('expr' => '1+1')), 'calc');
ck('{"expression":..} 判为 calc', works_tool_guess_action(array('expression' => '1+1')), 'calc');
ck('{"lunar":..} 判为 lunar', works_tool_guess_action(array('lunar' => '9-9')), 'lunar');
ck('{"action":"random"} 认作 random', works_tool_guess_action(array('action' => 'random')), 'random');
ck('{"url":..} 仍判 web_open', works_tool_guess_action(array('url' => 'https://x')), 'web_open');
ck('{"query":..} 仍判 search', works_tool_guess_action(array('query' => '解谜')), 'search');

$p1 = works_tool_parse('<calc expr="1+1"/>');
ck('属性式 <calc expr> 识别', isset($p1['calls'][0]['action']) ? $p1['calls'][0]['action'] : '', 'calc');
ck('属性式 <calc expr> 取到参数', isset($p1['calls'][0]['expr']) ? $p1['calls'][0]['expr'] : '', '1+1');
ck('属性式 <calc> 清理干净', trim($p1['clean']), '');
$p2 = works_tool_parse('<Works check>{"action":"random","category":"game"}</Works check>');
ck('JSON 标签 random 识别', isset($p2['calls'][0]['action']) ? $p2['calls'][0]['action'] : '', 'random');
ck('JSON 标签清理干净', trim($p2['clean']), '');
$p3 = works_tool_parse("<lunar date=\"2026-10-10\"/>\n你好");
ck('属性式 <lunar date> 识别', isset($p3['calls'][0]['action']) ? $p3['calls'][0]['action'] : '', 'lunar');
ck('lunar 标签外的正文保留', trim($p3['clean']), '你好');

/* ---------- 3. calc：精确求值 ---------- */
function cv(string $e) { $r = works_tool_extra_execute(array('action' => 'calc', 'expr' => $e)); return isset($r['result']) ? $r['result'] : ('ERR:' . (isset($r['error']) ? $r['error'] : '?')); }
ck('(1+2)*3 = 9', cv('(1+2)*3'), '9');
ck('1+2*3^2 = 19', cv('1+2*3^2'), '19');
ck('2^10 = 1024', cv('2^10'), '1024');
ck('7%3 = 1', cv('7%3'), '1');
ck('10/4 = 2.5', cv('10/4'), '2.5');
ck('0.1+0.2 无浮点噪声', cv('0.1+0.2'), '0.3');
ck('-(-3) = 3', cv('-(-3)'), '3');
ck('3.5*2 = 7', cv('3.5*2'), '7');
ck('负数 -5+2 = -3', cv('-5+2'), '-3');
$f1 = works_tool_extra_execute(array('action' => 'calc', 'expr' => '1/3'));
ck('1/3 给出分数 1/3', isset($f1['fraction']) ? $f1['fraction'] : '', '1/3');
$f2 = works_tool_extra_execute(array('action' => 'calc', 'expr' => '10/4'));
ck('10/4 给出分数 5/2', isset($f2['fraction']) ? $f2['fraction'] : '', '5/2');
ck('整数不给分数', isset(works_tool_extra_execute(array('action' => 'calc', 'expr' => '6/2'))['fraction']), false);
ck('除以零 → 失败', empty(works_tool_extra_execute(array('action' => 'calc', 'expr' => '1/0'))['ok']), true);
ck('括号不配对 → 失败', empty(works_tool_extra_execute(array('action' => 'calc', 'expr' => '(1+2'))['ok']), true);
ck('非法字符 → 失败', empty(works_tool_extra_execute(array('action' => 'calc', 'expr' => 'abc'))['ok']), true);
ck('空表达式 → 失败', empty(works_tool_extra_execute(array('action' => 'calc', 'expr' => ''))['ok']), true);
ck('中文乘号 × 归一', cv('3×4'), '12');

/* ---------- 4. lunar：往返一致性（全表抽样，强验证） ---------- */
$bad = 0; $n = 0;
for ($y = 1990; $y <= 2035; $y++) {
    for ($m = 1; $m <= 12; $m++) {
        foreach (array(1, 15, 29) as $d) {
            $ts = lunar_to_solar($y, $m, $d);
            if ($ts === null) { continue; }
            $n++;
            $g = lunar_from_solar((int)gmdate('Y', $ts), (int)gmdate('n', $ts), (int)gmdate('j', $ts));
            if (!$g || $g['y'] !== $y || $g['m'] !== $m || $g['d'] !== $d || $g['leap'] !== false) { $bad++; }
        }
    }
}
ck("农历↔公历往返一致（$n 项）", $bad, 0);

$l1 = works_tool_extra_execute(array('action' => 'lunar', 'date' => '2026-10-10'));
ck('2026-10-10 → 农历九月初一', $l1['lunar_text'], '丙午年九月初一');
ck('2026-10-10 → 干支丙午', $l1['ganzhi'], '丙午');
ck('2026-10-10 → 生肖马', $l1['zodiac'], '马');
ck('2026-10-10 → 辛亥纪念（站点节日）', isset($l1['festival']['key']) ? $l1['festival']['key'] : '', 'xinhai');
$l2 = works_tool_extra_execute(array('action' => 'lunar', 'date' => '2026-10-18'));
ck('2026-10-18 → 农历九月初九（重阳）', $l2['lunar_text'], '丙午年九月初九');
ck('2026-10-18 → 重阳节', isset($l2['festival']['key']) ? $l2['festival']['key'] : '', 'chongyang');
$l3 = works_tool_extra_execute(array('action' => 'lunar', 'lunar' => '2026-9-9'));
ck('农历 2026-9-9 → 公历 2026-10-18', $l3['solar'], '2026-10-18');
ck('农历反查也带节日', isset($l3['festival']['key']) ? $l3['festival']['key'] : '', 'chongyang');
ck('不存在的日期 → 失败', empty(works_tool_extra_execute(array('action' => 'lunar', 'date' => '2026-02-30'))['ok']), true);
ck('超出范围 → 失败', empty(works_tool_extra_execute(array('action' => 'lunar', 'date' => '1899-01-01'))['ok']), true);

/* ---------- 5. 桩件驱动的其它工具 ---------- */
$GLOBALS['VALS'] = array('from works' => 12, 'from users' => 5, 'from comments' => 30, 'from messages' => 88);
$st = works_tool_extra_execute(array('action' => 'stats'));
ck('stats 作品数', $st['works'], 12);
ck('stats 用户数', $st['users'], 5);
ck('stats 摘要含作品数', strpos(works_tool_summary($st), '12') !== false, true);

$GLOBALS['ROWS'] = array(array('category' => 'game', 'n' => 7), array('category' => 'literature', 'n' => 2));
$ca = works_tool_extra_execute(array('action' => 'categories'));
ck('categories 条目数', $ca['count'], 2);
ck('categories 合计', $ca['total'], 9);
ck('categories 中文名', $ca['items'][0]['name'], '游戏');
ck('categories 文本含文学', strpos(works_tool_extra_result_text($ca), '文学') !== false, true);

$GLOBALS['ROWS'] = array(array('content' => '服务器维护通知', 'updated_at' => '2026-10-09 12:00:00'));
$an = works_tool_extra_execute(array('action' => 'announce'));
ck('announce 条目数', $an['count'], 1);
ck('announce 摘要', works_tool_extra_summary($an), '读取公告 1 条');
$GLOBALS['ROWS'] = array();
ck('announce 空 → ok 且 0 条', works_tool_extra_execute(array('action' => 'announce'))['count'], 0);

$GLOBALS['ROWS'] = array(array('id' => 1, 'community_id' => 'c1', 'title' => '解谜', 'author_name' => '小李', 'category' => 'game', 'total_score' => 900, 'rating' => 'S', 'like_num' => 3, 'html_url' => '', 'share_link' => ''));
$ra = works_tool_extra_execute(array('action' => 'random', 'n' => 3));
ck('random 结果数', $ra['count'], 1);
ck('random 补上了 link', isset($ra['works'][0]['link']) ? $ra['works'][0]['link'] : '', 'https://x/c1');
ck('random 非法分类 → 失败', empty(works_tool_extra_execute(array('action' => 'random', 'category' => 'music'))['ok']), true);

$GLOBALS['ONE'] = array('id' => 3, 'username' => '张三', 'role' => 'user', 'registered_at' => '2026-03-15 08:00:00');
$GLOBALS['VALS'] = array('from works' => 4);
$us = works_tool_extra_execute(array('action' => 'user', 'name' => '张三'));
ck('user 命中', $us['user']['name'], '张三');
ck('user 身份中文', $us['user']['role'], '普通用户');
ck('user 注册月份', $us['user']['joined'], '2026-03');
ck('user 作品数', $us['user']['works'], 4);
ck('user 不回邮箱/封禁字段', isset($us['user']['email']) || isset($us['user']['is_banned']), false);
$GLOBALS['ONE'] = null;
ck('user 查不到 → 失败', empty(works_tool_extra_execute(array('action' => 'user', 'name' => '无此人'))['ok']), true);
ck('user 缺参 → 失败', empty(works_tool_extra_execute(array('action' => 'user'))['ok']), true);

/* ---------- 6. 文本/摘要健壮性 ---------- */
$all = array('stats' => $st, 'categories' => $ca, 'announce' => $an, 'random' => $ra);
foreach ($all as $k => $r) {
    ck("$k 的 result_text 闭合", substr(works_tool_extra_result_text($r), -14), '</tool_result>');
    ck("$k 的 summary 非空", works_tool_extra_summary($r) !== '', true);
}
ck('失败结果文本提示重试', strpos(works_tool_extra_result_text(array('ok' => false, 'action' => 'calc', 'error' => 'x')), '执行失败') !== false, true);
ck('未知工具 → 失败', empty(works_tool_extra_execute(array('action' => 'nope'))['ok']), true);
ck('工具中文标签 calc', works_tool_label('calc'), '精确计算');
ck('未知标签原样返回', works_tool_label('zzz'), 'zzz');

printf("\n通过 %d，失败 %d\n", $PASS, $FAIL);
exit($FAIL === 0 ? 0 : 1);
