<?php
/**
 * api/ai.php 流式分支 $aiProvider 作用域的回归校验（tools/ 不进发布包）
 * 跑法：php tools/verify_ai_provider_scope.php
 *
 * 线上崩溃：ai_usage_record() 第 3 参收到 null。
 * 根因：$aiProvider 被声明在闭包内，外层从未初始化；闭包按值捕获时回填传不出去。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

$pass = 0; $fail = 0;
function ok($t, $c) { global $pass, $fail; if ($c) { $pass++; echo "  ✔ $t\n"; } else { $fail++; echo "  ✗ $t\n"; } }

/** 模拟 ai_respond_stream：必定回填 provider */
function fake_stream(string &$provider = ''): string { $provider = 'glm'; return 'hi'; }

echo "【1】复现：闭包内声明 + 按值捕获 → 外层拿不到（= 线上崩溃）\n";
$outer1 = null;
$bad = function () use ($outer1) {
    $outer1 = '';                       // 闭包局部
    fake_stream($outer1);               // 回填进了局部
    return $outer1;
};
$bad();
ok('外层仍为 null（证明这个 bug 真实存在）', $outer1 === null);

echo "\n【2】修复：按引用捕获 + 外层声明 → 回填能传出来\n";
$outer2 = '';
$good = function () use (&$outer2) {
    $outer2 = '';                       // 每轮重置（写穿到外层引用）
    fake_stream($outer2);
    return $outer2;
};
$good();
ok('外层得到 string 的 glm', $outer2 === 'glm');

echo "\n【3】多轮以最后一轮为准\n";
$outer3 = '';
$multi = function (array $seq) use (&$outer3) {
    foreach ($seq as $p) { $outer3 = ''; $outer3 = $p; }
};
$multi(array('gateway', 'glm'));
ok('末轮值 glm 胜出', $outer3 === 'glm');

echo "\n【4】源码接线核对（真实文件）\n";
$src = (string)file_get_contents(APP_ROOT . '/api/ai.php');
ok('闭包按引用捕获 $aiProvider', strpos($src, '&$finalUsage, &$aiProvider, $model') !== false);
ok('外层存在 $aiProvider 初始化', preg_match('/^\$aiProvider = \'\';/m', $src) === 1);
ok('记账调用点已存在', strpos($src, 'ai_usage_record($uid, $finalUsage, $aiProvider)') !== false);
ok('不存在"闭包内声明却期望外层可见"的写法', preg_match(
    '/\$runRound = function[^{]*\{.*?\n    \$aiProvider = \'\';\n    \$answer = ai_respond_stream/s',
    $src) === 1 && strpos($src, '&$finalUsage, &$aiProvider') !== false);

echo "\n结果：通过 " . $pass . "，失败 " . $fail . "\n";
exit($fail === 0 ? 0 : 1);
