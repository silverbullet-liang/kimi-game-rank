<?php
/**
 * 六维评分自检（v3.39.0：态度依据成本判定 + 缺失信号重归一化）
 * 跑法：php tools/verify_scoring.php   （离线，不联网）
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
if (!function_exists('clamp01')) { function clamp01($x) { return max(0.0, min(1.0, (float)$x)); } }
require dirname(__DIR__) . '/app/scoring.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond) { global $pass, $fail; $cond ? $pass++ : $fail++; printf("%s %s\n", $cond ? 'OK  ' : 'FAIL', $name); }

$W = score_weights();

/* ---------- 权重结构 ---------- */
ok('态度权重之和为 1', abs(($W['attitude']['w_cost'] + $W['attitude']['w_care'] + $W['attitude']['w_deliver']) - 1.0) < 1e-9);
ok('态度已无恒空信号权重（author_reply / ongoing）', !isset($W['attitude']['w_reply']) && !isset($W['attitude']['w_ongoing']));
ok('深度已无无来源的楼中楼权重', !isset($W['depth']['discuss_w_sub']) && !isset($W['depth']['sublayer_ref']));

/* ---------- 代码量：外部脚本并入 ---------- */
$page = '<html><body><canvas></canvas></body></html>';
$code = $page . str_repeat("function f(){}\n", 100);
$fA = extract_features(array('title' => 't'), $page, array());
$fB = extract_features(array('title' => 't'), $page, array(), $code);
ok('代码量随外部脚本增大', $fB['bytes'] > $fA['bytes'] && $fB['lines'] > $fA['lines'] && $fB['funcs'] > $fA['funcs']);
ok('结构特征仍只看页面（交互标签不随脚本变）', $fA['tags_interactive'] === $fB['tags_interactive']);

/* ---------- 态度 = 依据成本 ---------- */
$base = array('title' => '作品', 'intro' => str_repeat('介绍', 40), 'comment_num' => 3, 'images' => 2, 'artifact_share_id' => 'x');
$sc = compute_scores(extract_features($base, $page, array(), $code));
$expect = (int)round(200 * (0.6 * ($sc['cost'] / 200) + 0.25 * 1 + 0.15 * 1));
ok('态度 = 0.6×成本归一 + 0.25×用心 + 0.15×交付', $sc['attitude'] === $expect);
ok('态度不再"天生封顶 90"（可 > 90）', $sc['attitude'] > 90);

$lo = compute_scores(extract_features($base, $page, array(), $page));
$hi = compute_scores(extract_features($base, $page, array(), $code . str_repeat("function g(){}\n", 400)));
ok('投入更大 → 成本更高 → 态度更高', $hi['attitude'] >= $lo['attitude'] && $hi['cost'] > $lo['cost']);

/* ---------- 体验：artifact 也算可运行 ---------- */
$withArt = compute_scores(extract_features(array('title' => 't', 'artifact_share_id' => 'a'), '', array()));
ok('体验 e1：有 artifactShareId 即算可运行', $withArt['experience'] >= 100);

/* ---------- 深度：评论量正常生效 ---------- */
$d0  = compute_scores(extract_features(array('title' => 't', 'comment_num' => 0), '', array()));
$d30 = compute_scores(extract_features(array('title' => 't', 'comment_num' => 30), '', array()));
ok('深度：评论越多讨论分越高', $d30['depth'] > $d0['depth']);

/* ---------- 用心度：无 care 时回落长度分级 ---------- */
$fc = extract_features($base, '', array());
ok('无 care 时 care 为 null（回落长度分级）', $fc['care'] === null);
$wn = array('max' => 200, 'w_cost' => 0.6, 'w_care' => 0.25, 'w_deliver' => 0.15, 'intro_full' => 60, 'intro_half' => 20);
$fNo  = array('cost_norm' => 0.5, 'care' => null, 'intro_len' => 10, 'has_html' => true);
$fYes = array('cost_norm' => 0.5, 'care' => 1.0, 'intro_len' => 10, 'has_html' => true);
ok('care=1 的用心分高于「care 缺失 + 短配文」', score_attitude($fYes, $wn) > score_attitude($fNo, $wn));

echo "\n通过 $pass / 失败 $fail\n";
exit($fail > 0 ? 1 : 0);
