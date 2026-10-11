<?php
/**
 * 控制面板「从社区搜索并一键收录」：源码闸门（离线，不连库、不联网）。
 * 运行：php tools/verify_panel_search.php
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

$admin = (string)file_get_contents(APP_ROOT . '/api/admin.php');
$panel = (string)file_get_contents(APP_ROOT . '/assets/js/src/pages/panel.js');

/* ---------- 后端 ---------- */
ck('admin.php 有 community_search 动作', strpos($admin, "case 'community_search'") !== false, true);
ck('副管理员白名单含 community_search', strpos($admin, "'community_search', 'sync'") !== false, true);
ck('新增 admin_actor_token 辅助', strpos($admin, 'function admin_actor_token()') !== false, true);
ck('community_search 先做 require_panel', (bool)preg_match("#case 'community_search'.{0,160}require_panel\(\)#s", $admin), true);
ck('community_search 未保存 token 时报错', strpos($admin, '请先在上方「社区凭证」里填写并保存') !== false, true);
ck('community_search 调 kimi_search', strpos($admin, 'kimi_search($token, $q') !== false, true);
ck('返回条目带 exists 标记', strpos($admin, "'exists'       => false") !== false, true);
ck('返回翻页游标 next_page_token', strpos($admin, "'next_page_token' => \$next") !== false, true);
ck('已收录标记用一次 IN 查询', strpos($admin, 'FROM works WHERE community_id IN (') !== false, true);

/* ---------- 前端 ---------- */
ck('panel.js 有 bindCommunitySearch', strpos($panel, 'function bindCommunitySearch(container)') !== false, true);
ck('在 bindAddWork 内挂载', strpos($panel, "bindCommunitySearch(container);") !== false, true);
ck('搜索块在收录区（addWorkBlock）', strpos($panel, '从社区搜索并一键收录') !== false, true);
ck('调 community_search 接口', strpos($panel, "'admin.php', 'community_search'") !== false, true);
ck('每页 10 条', strpos($panel, 'const PAGE = 10;') !== false, true);
ck('滚到底自动加载', strpos($panel, 'atEnd()') !== false, true);
ck('添加复用 add_work 手动分支', strpos($panel, "mode: 'manual', work_id: id") !== false, true);
ck('已收录条目按钮禁用', strpos($panel, "it.exists ? ' disabled'") !== false, true);
ck('收录成功后刷新作品列表', strpos($panel, 'loadWorks(container,') !== false, true);

/* ---------- 我的页入口（控制面板旁）与美化 ---------- */
$mine = (string)file_get_contents(APP_ROOT . '/assets/js/src/pages/mine.js');
ck('我的页有异常监测入口按钮', strpos($mine, 'id="monitorBtn"') !== false, true);
ck('与「控制面板」并排（adm-actions）', strpos($mine, 'class="adm-actions"') !== false, true);
ck('monitorBtn 绑定到 #/monitor', strpos($mine, "navigate('#/monitor')") !== false, true);

/* ---------- 样式 ---------- */
$css = (string)file_get_contents(APP_ROOT . '/assets/css/app.css');
ck('app.css 有 .cs-list 样式', strpos($css, '.cs-list {') !== false, true);
ck('app.css 有 .cs-item 样式', strpos($css, '.cs-item {') !== false, true);
ck('app.css 有 .adm-actions 样式', strpos($css, '.adm-actions {') !== false, true);
ck('监测页已美化（标题竖条）', strpos($css, '.mon-title::before') !== false, true);
ck('监测页已重构（KPI 状态色条）', strpos($css, '.mon-kpi.mk-bad::before') !== false, true);
ck('监测页已重构（健康总览条）', strpos($css, '.mon-health {') !== false, true);

printf("\n通过 %d，失败 %d\n", $P, $F);
exit($F === 0 ? 0 : 1);
