<?php
/**
 * 深度思考 + 文档工具：离线回归（提示词 / 请求参数 / 工具注册）。
 * 运行：php tools/verify_thinking.php
 * 不连库、不联网。
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/* 最小桩件：zhipu.php 只用到这几项（避免拉起 db 层） */
function cfg(string $p, $d = null) { return $d; }
function setting_get(string $k, $d = null) { return $d; }
function app_log(string $m) { }

require APP_ROOT . '/app/zhipu.php';
require APP_ROOT . '/app/works_tool.php';

$GLOBALS['fail_n'] = 0; $GLOBALS['pass_n'] = 0;
function ck(string $name, $got, $want): void
{
    $ok = ($got === $want);
    $ok ? $GLOBALS['pass_n']++ : $GLOBALS['fail_n']++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $name);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($want, true), var_export($got, true)); }
}

/* ---------- 1. 思考参数 ---------- */
$p1 = zhipu_payload(array(), '', true);
ck('开启思考 → thinking.type = enabled', isset($p1['thinking']['type']) ? $p1['thinking']['type'] : null, 'enabled');
$p2 = zhipu_payload(array(), '', false);
ck('未开启 → 不带 thinking', isset($p2['thinking']), false);
ck('默认（不传参）→ 不带 thinking', isset(zhipu_payload(array())['thinking']), false);
ck('payload 仍带 messages', isset($p1['messages']), true);

/* ---------- 2. 提示词：文档工具 + 思考链 ---------- */
$prompt = build_system_prompt();
ck('提示词含 docs 工具说明', strpos($prompt, 'docs 查站内文档') !== false, true);
ck('提示词含文档类硬性要求', strpos($prompt, '必须调用 docs 工具') !== false, true);
ck('提示词含思考链【思考方式】', strpos($prompt, '【思考方式】') !== false, true);
ck('思考链含「判断问题类型」', strpos($prompt, '先判断问题类型') !== false, true);
ck('思考链含「核验工具结果」', strpos($prompt, '先核验') !== false, true);
ck('思考链含「不得编造」', strpos($prompt, '绝不凭印象编造') !== false, true);
ck('思考链含「多轮补调」', strpos($prompt, '最多两轮') !== false, true);

/* ---------- 3. 工具注册：docs 已并入统一执行器 ---------- */
ck('工具名清单含 docs', strpos(WORKS_TOOL_NAMES, 'docs') !== false, true);
ck('工具名正则含 docs', strpos(works_tool_names_re(), 'docs') !== false, true);
ck('别名 doc → docs', works_tool_norm_name('doc'), 'docs');
ck('别名 document → docs', works_tool_norm_name('document'), 'docs');
ck('{"name":...} 判为 docs', works_tool_guess_action(array('name' => '用户协议')), 'docs');
ck('{"doc":...} 判为 docs', works_tool_guess_action(array('doc' => '隐私政策')), 'docs');
ck('{"query":...} 仍判为 search', works_tool_guess_action(array('query' => '解谜')), 'search');
ck('{"url":...} 仍判为 web_open', works_tool_guess_action(array('url' => 'https://x')), 'web_open');

printf("\n通过 %d，失败 %d\n", $GLOBALS['pass_n'], $GLOBALS['fail_n']);
exit($GLOBALS['fail_n'] === 0 ? 0 : 1);
