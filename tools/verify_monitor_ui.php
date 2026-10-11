<?php
/**
 * 监测页可视性回归闸门（离线）。
 * 教训：v3.29.0 的「美化」曾把 `.mon-app { --mbg:… }` 变量定义整段替换掉，
 * 导致监测页背景/文字色全部 undefined → 界面几乎不可读。这里把它钉死。
 * 运行：php tools/verify_monitor_ui.php
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$P = 0; $F = 0;
function ck(string $n, $g, $w): void
{
    global $P, $F;
    $ok = ($g === $w);
    $ok ? $P++ : $F++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $n);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($w, true), var_export($g, true)); }
}

$css = (string)file_get_contents(APP_ROOT . '/assets/css/app.css');
$js  = (string)file_get_contents(APP_ROOT . '/assets/js/src/pages/monitor.js');

/* ---------- 生命线：变量定义必须在 ---------- */
ck('CSS 仍定义 .mon-app 变量组', strpos($css, '.mon-app { --mbg:var(--bg-1)') !== false, true);
foreach (array('--mbg', '--mfg', '--mmut', '--mline', '--mcard', '--macc', '--msoft') as $v) {
    ck("变量 $v 已定义", strpos($css, $v . ':') !== false, true);
}
ck('状态色变量已定义', strpos($css, '--mok:#15803d') !== false && strpos($css, '--mbad:#c02626') !== false, true);
ck('深色主题下状态色有覆盖', strpos($css, 'html[data-theme="dark"] .mon-app { --mok:') !== false, true);
ck('大括号平衡', substr_count($css, '{') === substr_count($css, '}'), true);

/* ---------- 可视度要素 ---------- */
ck('健康总览条样式', strpos($css, '.mon-health {') !== false, true);
ck('健康条三种状态', strpos($css, '.mon-health.bad') !== false && strpos($css, '.mon-health.warn') !== false, true);
ck('指标分组样式', strpos($css, '.mon-kgroup') !== false, true);
ck('KPI 状态色（左色条）', strpos($css, '.mon-kpi.mk-bad::before') !== false, true);
ck('数值放大到 23px', strpos($css, 'font-size:23px') !== false, true);
ck('数字右对齐类', strpos($css, 'td.mon-n { text-align:right') !== false, true);
ck('表格斑马纹', strpos($css, 'tr:nth-child(even)') !== false, true);
ck('异常行整行高亮', strpos($css, 'tr:has(.mon-bad)') !== false, true);
ck('表头对比（用主文字色）', strpos($css, 'color:var(--mfg); font-weight:800; font-size:12px') !== false, true);
ck('异常/警告数值色', strpos($css, '.mon-bad {') !== false && strpos($css, '.mon-warnv {') !== false, true);
ck('图表网格线', strpos($css, '.mon-grid {') !== false, true);
ck('窄屏两列', strpos($css, 'grid-template-columns:repeat(2,1fr)') !== false, true);

/* ---------- 渲染层要素 ---------- */
ck('monitor.js 有健康条', strpos($js, 'function monHealth(') !== false, true);
ck('monitor.js 有分组', strpos($js, 'function monKpiGroup(') !== false, true);
ck('monitor.js 有阈值判定', strpos($js, 'function toneOf(') !== false, true);
ck('monitor.js 有面积图', strpos($js, 'function monAreaChart(') !== false, true);
ck('健康条内联标记着色', strpos($js, "'mh-bad'") !== false && strpos($js, "'mh-warn'") !== false, true);
ck('总览/服务端均用分组', substr_count($js, 'monKpiGroup(') >= 6, true);
ck('总览/服务端均有健康条', substr_count($js, 'monHealth([') >= 2, true);
ck('表格数值右对齐类已用', substr_count($js, 'class="mon-n"') >= 8, true);
ck('异常值标红已用', strpos($js, 'mon-bad') !== false, true);

/* ---------- 滑动自动分页与防溢出（对副管理员可见、列表可无限滚动） ---------- */
ck('宽表横向滚动容器样式', strpos($css, '.mon-scroll {') !== false, true);
ck('滚动容器最小宽度', strpos($css, '.mon-scroll .table { min-width') !== false, true);
ck('自动加载进度条样式', strpos($css, '.mon-moreline {') !== false, true);
ck('会话元信息样式', strpos($css, '.mon-sess-meta {') !== false, true);
ck('monitor.js 会话列表按键分页', strpos($js, "'sessions', { range: st.range, only: only, page: page }") !== false, true);
ck('monitor.js 事件明细按键分页', strpos($js, "'events', { kind: kind, range: st.range, page: page }") !== false, true);
ck('monitor.js 会话展示用户与版本', strpos($js, 'sv.uid8') !== false && strpos($js, 'sv.version') !== false, true);
ck('monitor.js 明细含版本列', strpos($js, "it.version ? 'v'") !== false, true);
ck('monitor.js 滑动监听自动加载', substr_count($js, 'scrollHeight - 900') >= 2, true);
ck('monitor.js 会话详情含用户与版本', strpos($js, 'mon-sess-meta') !== false, true);

printf("\n通过 %d，失败 %d\n", $P, $F);
exit($F === 0 ? 0 : 1);
