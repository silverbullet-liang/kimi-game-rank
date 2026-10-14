<?php
/**
 * 评分体系自检（v3.40.0：层级化 MCDA —— AHP×CRITIC、加权几何聚合、贝叶斯口碑、分位数评级）
 * 跑法：php tools/verify_scoring.php   （离线，不联网）
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
if (!function_exists('clamp01')) { function clamp01($x) { return max(0.0, min(1.0, (float)$x)); } }
if (!function_exists('setting_get')) { function setting_get($k, $d = '') { return $d; } }
if (!function_exists('db_all')) { function db_all($sql) { return isset($GLOBALS['FAKE_ROWS']) ? $GLOBALS['FAKE_ROWS'] : array(); } }
require dirname(__DIR__) . '/app/scoring.php';
require dirname(__DIR__) . '/app/corpus.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond) { global $pass, $fail; $cond ? $pass++ : $fail++; printf("%s %s\n", $cond ? 'OK  ' : 'FAIL', $name); }

$W = score_weights();

/* ---------- 层级权重 ---------- */
$ahp = ahp_dim_weights();
ok('AHP 六维权重之和为 1', abs(array_sum($ahp) - 1.0) < 1e-6);
ok('六维均有 AHP 权重', count($ahp) === 6
    && isset($ahp['experience'], $ahp['creativity'], $ahp['depth'], $ahp['cost'], $ahp['attitude'], $ahp['heat']));
ok('支柱权重：质量 > 投入 > 认可', $W['ahp']['pillars']['quality'] > $W['ahp']['pillars']['effort']
    && $W['ahp']['pillars']['effort'] > $W['ahp']['pillars']['recognition']);

/* ---------- 客观(CRITIC) 组合 ---------- */
$critic = array('creativity' => 0.05, 'experience' => 0.05, 'depth' => 0.05, 'cost' => 0.05, 'attitude' => 0.05, 'heat' => 0.75);
$comb = combined_dim_weights(array('critic' => $critic));
ok('组合权重之和为 1', abs(array_sum($comb) - 1.0) < 1e-6);
ok('组合权重被客观权重拉高（heat 上升）', $comb['heat'] > $ahp['heat']);
ok('无客观权重时 = 纯 AHP', combined_dim_weights(array()) === $ahp);

/* ---------- 支柱归一 + 加权几何聚合 ---------- */
$all200 = array('creativity' => 200, 'experience' => 200, 'depth' => 200, 'cost' => 200, 'attitude' => 200, 'heat' => 200);
$heat0  = $all200; $heat0['heat'] = 0;
$tHigh = total_from_dims($all200, $ahp);
$tLow  = total_from_dims($heat0, $ahp);
ok('六维满分 → 接近满分', $tHigh >= 1150);
ok('短板压制：口碑为 0 → 总分明显低于满分', $tLow < $tHigh - 100);
ok('下限生效：口碑为 0 也不归零', $tLow > 0);
$pv = pillar_norms($all200, $ahp);
ok('支柱归一：满分 → 各支柱 1.0', abs($pv['quality']['v'] - 1.0) < 1e-9 && abs($pv['recognition']['v'] - 1.0) < 1e-9);

/* ---------- 口碑维：贝叶斯收缩 ---------- */
$wH = $W['heat'];
$few  = score_heat(array('like' => 1, 'comment' => 0, 'collect' => 0, 'age_hours' => 720), $wH, 0.0);
$many = score_heat(array('like' => 100, 'comment' => 50, 'collect' => 30, 'age_hours' => 720), $wH, 0.0);
ok('口碑：互动多 > 互动少', $many > $few);
ok('贝叶斯：零互动时向先验收拢（> 0）',
    score_heat(array('like' => 0, 'comment' => 0, 'collect' => 0, 'age_hours' => 720), $wH, 0.5) > 0);

/* ---------- 评级：分位数 / 回退 ---------- */
$st = array('thresholds' => array('SSS' => 900, 'SS' => 800, 'S' => 700, 'A' => 600, 'B' => 500));
ok('分位数评级：950 → SSS', rating_of(950, $st) === 'SSS');
ok('分位数评级：850 → SS', rating_of(850, $st) === 'SS');
ok('分位数评级：10 → C', rating_of(10, $st) === 'C');
ok('无 stats 回退固定阈值：1200 → SSS', rating_of(1200) === 'SSS' && rating_of(10) === 'C');

/* ---------- 代码量：并入引用脚本 ---------- */
$page = '<html><body><canvas></canvas></body></html>';
$code = $page . str_repeat("function f(){}\n", 100);
$fa = extract_features(array('title' => 't'), $page, array());
$fb = extract_features(array('title' => 't'), $page, array(), $code);
ok('代码量随引用脚本增大', $fb['bytes'] > $fa['bytes'] && $fb['funcs'] > $fa['funcs']);
ok('结构特征仍只看页面', $fa['tags_interactive'] === $fb['tags_interactive']);

/* ---------- 态度依据成本 / 体验 artifact / 输出结构 ---------- */
$base = array('title' => '作品', 'intro' => str_repeat('介绍', 40), 'comment_num' => 3, 'images' => 2, 'artifact_share_id' => 'x');
$sc = compute_scores(extract_features($base, $page, array(), $code));
ok('态度 = 依据成本推导（> 90）', $sc['attitude'] > 90);
ok('compute_scores 输出三支柱', isset($sc['pillars']['quality'], $sc['pillars']['effort'], $sc['pillars']['recognition']));
$art = compute_scores(extract_features(array('title' => 't', 'artifact_share_id' => 'a'), '', array()));
ok('体验：有 artifact 即算可运行', $art['experience'] >= 100);

/* ---------- 统计工具与语料构建 ---------- */
ok('pearson：完全正相关 ≈ 1', abs(pearson(array(1, 2, 3, 4), array(2, 4, 6, 8)) - 1.0) < 1e-9);
ok('stddev 正确', abs(stddev(array(2, 4, 4, 4, 5, 5, 7, 9)) - 2.1381) < 0.001);
$cw = critic_weights(array(array('a' => 1, 'b' => 1), array('a' => 2, 'b' => 4), array('a' => 3, 'b' => 9), array('a' => 4, 'b' => 16)));
ok('CRITIC 权重之和为 1', $cw && abs(array_sum($cw) - 1.0) < 1e-6);

$rows = array();
for ($i = 0; $i < 25; $i++) {
    /* 故意让各维互不共线（若完全线性相关，CRITIC 会正确退化为 0 权重） */
    $rows[] = array(
        'score' => json_encode(array(
            'creativity' => 60 + (($i * 37) % 120),
            'experience' => 60 + (($i * 53) % 120),
            'depth'      => 60 + (($i * 29) % 120),
            'cost'       => 40 + (($i * 71) % 150),
            'attitude'   => 50 + (($i * 17) % 140),
            'heat'       => 30 + (($i * 97) % 160),
        )),
        'like_num' => (10 * $i) % 200, 'comment_num' => (2 * $i) % 40, 'collect_num' => $i % 30,
    );
}
$GLOBALS['FAKE_ROWS'] = $rows;
$st2 = corpus_stats_build();
ok('语料统计：算出 6 维 CRITIC 权重', isset($st2['critic']) && count($st2['critic']) === 6);
ok('语料统计：含分位数阈值', isset($st2['thresholds']['SSS'], $st2['thresholds']['A']));
ok('语料统计：含口碑先验 prior_s', isset($st2['prior_s']) && $st2['prior_s'] >= 0 && $st2['prior_s'] <= 1);
ok('语料统计：样本不足则不启用（<20）', corpus_stats_build_stub_small() === array());

echo "\n通过 $pass / 失败 $fail\n";
exit($fail > 0 ? 1 : 0);

/* 小样本（<20）应返回空数组 */
function corpus_stats_build_stub_small(): array
{
    $GLOBALS['FAKE_ROWS'] = array(array('score' => '{}', 'like_num' => 1, 'comment_num' => 0, 'collect_num' => 0));
    $r = corpus_stats_build();
    $GLOBALS['FAKE_ROWS'] = array();
    return $r;
}
