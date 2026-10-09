<?php
/**
 * 文档查询工具回归：清单 / 名称归一 / 检索 / 读取 / 截断 / 路径安全。
 * 运行：php tools/verify_docs_tool.php （离线，只读 assets/docs）
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/docs_tool.php';

$GLOBALS['fail_n'] = 0; $GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want): void
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 清单 ---------- */
$files = docs_tool_files();
ck('文档数量 = 8', count($files), 8);
foreach (array('用户协议', '隐私政策', '社区公约', '功能说明', '评分标准', '入榜规则', '更新日志', 'AI 使用说明') as $d) {
    ck('清单含《' . $d . '》', isset($files[$d]), true);
}
ck('导读非空（用户协议）', mb_strlen(docs_tool_intro($files['用户协议']), 'UTF-8') > 5, true);

/* ---------- 2. 名称归一与定位 ---------- */
ck('归一：去书名号与空格', docs_tool_norm('《用户协议》'), '用户协议');
$f1 = docs_tool_find('用户协议');
ck('精确定位用户协议', isset($f1['name']) && $f1['name'] === '用户协议', true);
$f2 = docs_tool_find('《隐私政策》');
ck('带书名号也能定位', isset($f2['name']) && $f2['name'] === '隐私政策', true);
$f3 = docs_tool_find('ai使用说明');
ck('忽略大小写与空格', isset($f3['name']) && $f3['name'] === 'AI 使用说明', true);
ck('不存在的文档 → 空', docs_tool_find('不存在的文档'), array());
/* 路径安全：白名单来自目录扫描，外部路径一律找不到 */
ck('路径穿越被拒', docs_tool_find('../../config/config.php'), array());
ck('绝对路径被拒', docs_tool_find('/etc/passwd'), array());

/* ---------- 3. 检索 ---------- */
$s1 = docs_tool_search('封禁');
ck('检索「封禁」有命中', count($s1) > 0, true);
$names = array_map(function ($h) { return $h['name']; }, $s1);
ck('命中里含《社区公约》或《用户协议》',
   in_array('社区公约', $names, true) || in_array('用户协议', $names, true), true);
foreach ($s1 as $h) { ck('《' . $h['name'] . '》返回了段落', count($h['excerpts']) > 0, true); }
ck('检索无结果 → 空数组', docs_tool_search('这个词一定不存在zzz9'), array());

/* ---------- 4. 读取与截断 ---------- */
$r1 = docs_tool_read('AI 使用说明');
ck('读取成功', !empty($r1['ok']), true);
ck('返回正文非空', mb_strlen((string)$r1['text'], 'UTF-8') > 100, true);
ck('mode=read', $r1['mode'], 'read');

$r2 = docs_tool_read('更新日志');       // 90KB 的大文档
ck('大文档读取成功', !empty($r2['ok']), true);
ck('大文档被截断（正文 ≤ 6200 字）', mb_strlen((string)$r2['text'], 'UTF-8') <= 6200, true);
ck('大文档长度字段为全文', (int)$r2['chars'] > 6000, true);

$r3 = docs_tool_read('不存在的文档');
ck('读取不存在的文档 → 失败', empty($r3['ok']), true);
ck('失败提示列出可用文档', strpos((string)$r3['hint'], '用户协议') !== false, true);

/* ---------- 5. execute 三种用法 ---------- */
$e1 = docs_tool_execute(array());
ck('无参数 → 清单模式', $e1['mode'], 'list');
ck('清单条数 = 8', $e1['count'], 8);

$e2 = docs_tool_execute(array('query' => '评分'));
ck('query → 检索模式', $e2['mode'], 'search');
ck('检索命中 ≥1 篇', $e2['count'] > 0, true);

$e3 = docs_tool_execute(array('name' => '入榜规则'));
ck('name → 读取模式', $e3['mode'], 'read');

$e4 = docs_tool_execute(array('doc' => '功能说明'));
ck('doc 作为 name 别名', $e4['mode'], 'read');

$e5 = docs_tool_execute(array('query' => 'zzz不存在zzz'));
ck('检索无结果 → ok=false', empty($e5['ok']), true);

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
