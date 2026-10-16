<?php
/**
 * 全站语料统计（v3.40.0）
 * ------------------------------------------------------------
 * 为评分体系提供「客观」部分：
 *   · CRITIC 客观权重 —— 用指标离散度(std) 与 指标间相关系数 赋权：
 *       C_j = σ_j × Σ_k (1 − r_jk)   ，权重 ∝ C_j
 *     （指标间相关性越大 → 信息重叠越多 → 权重越小；这正是"维度关联"的数学化。）
 *   · 口碑维的贝叶斯先验 s_prior（全站口碑分均值）。
 *   · 各评级的总分分位数阈值（评级随生态动态化）。
 * 结果按天缓存；数据不足 / 出错一律回退空数组（评分侧退回纯 AHP + 固定阈值）。
 */
declare(strict_types=1);

define('CORPUS_MIN_SAMPLES', 20);

function corpus_cache_path(): string
{
    return APP_ROOT . '/storage/cache/score_stats.json';
}

/** 读取全站统计（默认 24h 缓存；$force=true 强制重算） */
function corpus_stats(bool $force = false): array
{
    $f = corpus_cache_path();
    if (!$force && is_file($f) && (time() - (int)@filemtime($f)) < 86400) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && isset($j['critic'])) { return $j; }
    }
    $s = corpus_stats_build();
    if (!empty($s)) { @file_put_contents($f, json_encode($s), LOCK_EX); }
    return $s;
}

/** 从 works 表重建全站统计；不足/失败返回空数组 */
function corpus_stats_build(): array
{
    try {
        $rows = db_all('SELECT score, like_num, comment_num, collect_num FROM works WHERE is_hidden = 0');
    } catch (Throwable $e) {
        return array();
    }
    if (count($rows) < CORPUS_MIN_SAMPLES) { return array(); }

    $W = score_weights();
    $dims = array('creativity', 'experience', 'depth', 'cost', 'attitude', 'heat');
    $matrix = array(); $sList = array();
    foreach ($rows as $r) {
        $j = json_decode((string)$r['score'], true);
        if (!is_array($j)) { continue; }
        $row = array();
        foreach ($dims as $d) { $row[$d] = isset($j[$d]) ? (float)$j[$d] : 0.0; }
        $matrix[] = $row;
        $h = $W['heat'];
        $s = $h['w_like'] * lns((float)$r['like_num'], (float)$h['base_like'])
           + $h['w_comment'] * lns((float)$r['comment_num'], (float)$h['base_comment'])
           + $h['w_collect'] * lns((float)$r['collect_num'], (float)$h['base_collect']);
        $sList[] = min(1.0, max(0.0, $s));
    }
    $n = count($matrix);
    if ($n < CORPUS_MIN_SAMPLES) { return array(); }

    $critic = critic_weights($matrix);
    if (empty($critic)) { return array(); }

    /* 总分分布 → 分位数阈值（用组合权重算，与线上一致） */
    $wd = combined_dim_weights(array('critic' => $critic));
    $totals = array();
    foreach ($matrix as $row) { $totals[] = total_from_dims($row, $wd); }
    sort($totals);
    $th = array();
    foreach ($W['rating']['quantile'] as $qr) {
        $top = isset($qr['top']) ? (float)$qr['top'] : (1.0 - (float)$qr['q']);
        $idx = (int)floor((1.0 - $top) * ($n - 1));
        $th[$qr['grade']] = (int)$totals[max(0, min($n - 1, $idx))];
    }

    return array(
        'n' => $n,
        'critic' => $critic,
        'prior_s' => round(array_sum($sList) / max(1, count($sList)), 4),
        'thresholds' => $th,
        /* 全站总分升序序列：评级按「累计名次」判定（并列同分同档、每档不超配额） */
        'totals' => $totals,
    );
}

/**
 * CRITIC 客观权重。$matrix：行=作品，列=六维得分（0–200）。
 * 返回 array(维度 => 权重)（和为 1）；样本不足 / 退化时返回空数组。
 */
function critic_weights(array $matrix): array
{
    $n = count($matrix);
    if ($n < 3 || !isset($matrix[0])) { return array(); }
    $keys = array_keys($matrix[0]);

    /* 列取出 + min-max 归一（消除量纲） */
    $norm = array();
    foreach ($keys as $k) {
        $col = array();
        foreach ($matrix as $row) { $col[] = (float)(isset($row[$k]) ? $row[$k] : 0); }
        $mn = min($col); $mx = max($col); $rng = $mx - $mn;
        $norm[$k] = array();
        foreach ($col as $v) { $norm[$k][] = ($rng > 0) ? (($v - $mn) / $rng) : 0.0; }
    }

    /* C_j = σ_j × Σ_k (1 − r_jk) */
    $C = array();
    foreach ($keys as $j) {
        $sum = 0.0;
        foreach ($keys as $k) {
            $r = ($j === $k) ? 1.0 : pearson($norm[$j], $norm[$k]);
            $sum += (1.0 - $r);
        }
        $C[$j] = stddev($norm[$j]) * $sum;
    }
    $tot = array_sum($C);
    if ($tot <= 0) { return array(); }
    $out = array();
    foreach ($C as $k => $v) { $out[$k] = $v / $tot; }
    return $out;
}

function stddev(array $v): float
{
    $n = count($v);
    if ($n < 2) { return 0.0; }
    $m = array_sum($v) / $n; $s = 0.0;
    foreach ($v as $x) { $s += ($x - $m) * ($x - $m); }
    return sqrt($s / ($n - 1));
}

function pearson(array $a, array $b): float
{
    $n = count($a);
    if ($n < 2 || count($b) !== $n) { return 0.0; }
    $ma = array_sum($a) / $n; $mb = array_sum($b) / $n;
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = $a[$i] - $ma; $y = $b[$i] - $mb;
        $num += $x * $y; $da += $x * $x; $db += $y * $y;
    }
    if ($da <= 0 || $db <= 0) { return 0.0; }
    $r = $num / sqrt($da * $db);
    return max(-1.0, min(1.0, $r));
}
