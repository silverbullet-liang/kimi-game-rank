<?php
/**
 * 评分引擎（v3.40.0 · 层级化 MCDA）
 * ------------------------------------------------------------
 * 三支柱（作品质量 / 作者投入 / 社区认可）→ 六维。
 *   · 权重 = AHP 主观权重（config 判断矩阵，CR<0.1）× CRITIC 客观权重（app/corpus.php 由全站语料算）组合；
 *   · 支柱用**加权几何**聚合 → 任一支柱弱会乘性压制总分（维度互相制约）；
 *   · 口碑维对互动量做**贝叶斯收缩**（样本少 → 向全站均值靠拢）；
 *   · 评级默认按全站总分**分位数**（语料不可用时回退固定阈值）。
 * 设计：extract_features() 只做数据提取；score_*() / aggregate 相关为纯函数，便于测试。
 */
declare(strict_types=1);

function score_weights(): array
{
    static $w = null;
    if ($w === null) { $w = require APP_ROOT . '/config/scoring.php'; }
    return $w;
}

/* ---------- 基础函数 ---------- */
function lns(float $x, float $base): float
{
    $x = max(0.0, $x);
    return log(1 + $x) / log(1 + max(1.0, $base));
}

function bell(float $x, float $a, float $b, float $decay = 10.0): float
{
    if ($x >= $a && $x <= $b) { return 1.0; }
    $d = $x < $a ? $a - $x : $x - $b;
    return max(0.0, 1.0 - $d / max(1.0, $decay));
}

/* ============================================================
 * 特征提取
 * ============================================================ */
/**
 * @param array  $work  含 title/intro/body/like_num/comment_num/collect_num/age_hours/artifact_share_id/care 等
 * @param string $html  作品 HTML 源码（可空）
 * @param array  $titles 库内其它作品标题（用于标题独特性）
 * @param string $code  代码体量文本（页面 + 它引用的外部脚本；空则退化为只算 $html）
 */
function extract_features(array $work, string $html = '', array $titles = array(), string $code = ''): array
{
    $title  = isset($work['title']) ? (string)$work['title'] : '';
    $intro  = isset($work['intro']) ? (string)$work['intro'] : '';
    $body   = isset($work['body']) ? (string)$work['body'] : '';
    $text   = $intro . "\n" . $body;

    $hasHtml = $html !== '';
    $f = array(
        // 互动
        'like'      => isset($work['like_num']) ? (int)$work['like_num'] : 0,
        'comment'   => isset($work['comment_num']) ? (int)$work['comment_num'] : 0,
        'collect'   => isset($work['collect_num']) ? (int)$work['collect_num'] : 0,
        'age_hours' => isset($work['age_hours']) ? (float)$work['age_hours'] : 999.0,
        'has_artifact'  => !empty($work['artifact_share_id']),
        'has_html'      => $hasHtml,
        // 文本
        'title_len' => mb_strlen($title, 'UTF-8'),
        'text_len'  => mb_strlen($text, 'UTF-8'),
        'intro_len' => mb_strlen($intro, 'UTF-8'),
        /* 「用心度」（可选，Jev 判定；缺失为 null，态度维回落到长度分级） */
        'care'      => (isset($work['care']) && $work['care'] !== null) ? (float)$work['care'] : null,
    );

    /* 标题独特性：与库内标题的 2-gram 最大相似度 */
    $f['title_unique'] = 1.0;
    if ($title !== '' && !empty($titles)) {
        $maxSim = 0.0;
        $gt = gram2($title);
        $count = 0;
        foreach ($titles as $t) {
            if ($t === '' || $t === $title) { continue; }
            $count++;
            if ($count > 300) { break; }   // 性能护栏
            $sim = jaccard($gt, gram2((string)$t));
            if ($sim > $maxSim) { $maxSim = $sim; }
        }
        $f['title_unique'] = 1.0 - $maxSim;
    }

    /* 词汇多样性 */
    $f['diversity'] = text_diversity($text);

    /* 结构复杂度：段落 + 列表标记 */
    $structs = preg_match_all('/\n\s*\n/u', $body, $m1);
    $structs += preg_match_all('/^\s*([\-\*\x{2022}]|\d+[\.\x{3001}]|[一二三四五六七八九十]+[、\.])/mu', $body, $m2);
    $f['struct_count'] = (int)$structs;

    /* 玩法说明词覆盖 */
    $w = score_weights();
    $kw = $w['experience']['keywords'];
    $hit = 0;
    foreach ($kw as $k) {
        if (mb_strpos($text, $k, 0, 'UTF-8') !== false) { $hit++; }
    }
    $f['guide_ratio'] = count($kw) > 0 ? $hit / count($kw) : 0.0;

    /* 代码规模（bytes / lines / funcs）：按「页面 + 它引用的外部脚本」计 —— 有些作品
       页面只是个壳，逻辑全在外部打包文件里，只统计页面 HTML 会把代码量严重漏算。
       $code 为空时退化为只看页面本身。结构特征（交互标签）仍只看页面。 */
    $codeTxt = ($code !== '') ? $code : $html;
    $f['bytes'] = $codeTxt !== '' ? strlen($codeTxt) : 0;
    $f['lines'] = $codeTxt !== '' ? substr_count($codeTxt, "\n") + 1 : 0;
    $f['funcs'] = $codeTxt !== '' ? (int)preg_match_all('/\b(function\s+\w+|=>\s*\{|function\s*\()/i', $codeTxt, $mf) : 0;
    if ($hasHtml) {
        $f['tags_interactive'] = (int)preg_match_all('/<(button|input|select|textarea|canvas|svg)\b/i', $html, $mt);
    } else {
        $f['tags_interactive'] = 0;
    }
    return $f;
}

/**
 * 由持久化的特征快照（works.feat）重建特征，并用库内最新字段覆盖随时间变化的项
 * （互动数、上架时长）——供「评分体系变更后离线重算」使用。无快照返回空数组。
 */
function features_from_stored(array $row): array
{
    if (empty($row['feat'])) { return array(); }
    $f = json_decode((string)$row['feat'], true);
    if (!is_array($f) || empty($f)) { return array(); }

    $defaults = array(
        'like' => 0, 'comment' => 0, 'collect' => 0, 'age_hours' => 999.0,
        'has_artifact' => false, 'has_html' => false,
        'title_len' => 0, 'text_len' => 0, 'intro_len' => 0,
        'title_unique' => 1.0, 'diversity' => 0.0, 'struct_count' => 0,
        'guide_ratio' => 0.0, 'bytes' => 0, 'lines' => 0, 'funcs' => 0,
        'tags_interactive' => 0, 'care' => null,
    );
    foreach ($defaults as $k => $v) { if (!array_key_exists($k, $f)) { $f[$k] = $v; } }

    if (isset($row['like_num']))    { $f['like']    = (int)$row['like_num']; }
    if (isset($row['comment_num'])) { $f['comment'] = (int)$row['comment_num']; }
    if (isset($row['collect_num'])) { $f['collect'] = (int)$row['collect_num']; }
    if (isset($row['created_at'])) {
        $ts = strtotime((string)$row['created_at']);
        if ($ts !== false) { $f['age_hours'] = max(0.0, (time() - $ts) / 3600.0); }
    }
    return $f;
}

/* ---------- 小工具 ---------- */
function gram2(string $s): array
{
    $s = preg_replace('/\s+/u', '', mb_strtolower($s, 'UTF-8'));
    $len = mb_strlen($s, 'UTF-8');
    $out = array();
    for ($i = 0; $i + 1 < $len; $i++) {
        $out[] = mb_substr($s, $i, 2, 'UTF-8');
    }
    if (empty($out) && $len === 1) { $out[] = $s; }
    return array_unique($out);
}

function jaccard(array $a, array $b): float
{
    if (empty($a) || empty($b)) { return 0.0; }
    $inter = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union === 0 ? 0.0 : $inter / $union;
}

function text_diversity(string $s): float
{
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $tokens = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($tokens)) { return 0.0; }
    $uniq = array_unique($tokens);
    return count($uniq) / count($tokens);
}

/* ============================================================
 * 各维计算
 * ============================================================ */
/**
 * 口碑：互动量对数压缩 + 贝叶斯收缩。$prior_s 为全站口碑先验（<0 表示用配置默认）。
 * 贝叶斯收缩 s' = (C·s_prior + n·s) / (C + n)，n 为互动总量 —— 样本少则向全站均值靠拢，
 * 杜绝「1 个赞封神」。
 */
function score_heat(array $f, array $w, float $prior_s = -1.0): float
{
    $s = $w['w_like']    * lns((float)$f['like'], (float)$w['base_like'])
       + $w['w_comment'] * lns((float)$f['comment'], (float)$w['base_comment'])
       + $w['w_collect'] * lns((float)$f['collect'], (float)$w['base_collect']);
    $s = min(1.0, max(0.0, $s));

    if ($prior_s < 0) { $prior_s = (float)$w['prior_s']; }
    $n = (float)$f['like'] + (float)$f['comment'] + (float)$f['collect'];
    $C = max(1.0, (float)$w['bayes_c']);
    $sp = ($C * $prior_s + $n * $s) / ($C + $n);

    $v = $w['max'] * $sp;
    if ($f['age_hours'] < $w['new_hours']) {
        $v *= 1 + $w['new_bonus'] * (1 - $f['age_hours'] / $w['new_hours']);
    }
    return min((float)$w['max'], max(0.0, $v));
}

function score_creativity(array $f, array $w): float
{
    $s4 = 0.0;
    if ($f['has_artifact']) { $s4 += $w['artifact_share']; }
    if ($f['has_html'])     { $s4 += $w['artifact_html']; }
    $s4 = min(0.25, $s4) / 0.25;

    $s = $w['w_unique']    * clamp01((float)$f['title_unique'])
       + $w['w_title']     * bell((float)$f['title_len'], (float)$w['title_min'], (float)$w['title_max'], (float)$w['bell_decay'])
       + $w['w_diversity'] * clamp01((float)$f['diversity'])
       + $w['w_artifact']  * $s4;
    return $w['max'] * clamp01($s);
}

function score_experience(array $f, array $w): float
{
    /* 可运行 = 有作品文件或 artifactShareId（此前只判作品文件，漏了 artifact） */
    $runnable = (!empty($f['has_html']) || !empty($f['has_artifact']));
    $e1 = $runnable ? 1.0 : 0.0;
    $e2 = min(1.0, (float)$f['tags_interactive'] / max(1.0, (float)$w['interact_ref']));
    $e4 = clamp01((float)$f['guide_ratio']);
    $den = (float)$w['w_runnable'] + (float)$w['w_interact'] + (float)$w['w_guide'];
    $s  = $w['w_runnable'] * $e1 + $w['w_interact'] * $e2 + $w['w_guide'] * $e4;
    $v  = $w['max'] * clamp01($den > 0 ? $s / $den : 0);
    if (!$runnable) { $v *= (float)$w['penalty_no_html']; }
    return $v;
}

function score_depth(array $f, array $w): float
{
    $d1 = min(1.0, (float)$f['text_len'] / max(1.0, (float)$w['text_ref']));
    $d2 = min(1.0, (float)$f['struct_count'] / max(1.0, (float)$w['struct_ref']));
    $d3 = max(
        min(1.0, (float)$f['lines'] / max(1.0, (float)$w['lines_ref'])),
        min(1.0, (float)$f['funcs'] / max(1.0, (float)$w['funcs_ref']))
    );
    /* 讨论深度：只看评论量；楼中楼无数据来源（恒 0），不再虚占权重 */
    $d4 = clamp01(lns((float)$f['comment'], (float)$w['comment_ref']));
    $s = $w['w_text'] * $d1 + $w['w_struct'] * $d2 + $w['w_code'] * $d3 + $w['w_discuss'] * $d4;
    return $w['max'] * clamp01($s);
}

function score_cost(array $f, array $w): float
{
    $c4 = min(1.0, (float)$f['text_len'] / max(1.0, (float)$w['text_ref']));

    if ($f['has_html']) {
        $c1 = min(1.0, (float)$f['bytes'] / max(1.0, (float)$w['bytes_ref']));
        $c2 = min(1.0, (float)$f['lines'] / max(1.0, (float)$w['lines_ref']));
        $s = $w['w_bytes'] * $c1 + $w['w_lines'] * $c2 + $w['w_text'] * $c4;
        $den = $w['w_bytes'] + $w['w_lines'] + $w['w_text'];
    } else {
        // 无作品文件：只按文本量计
        $s = $w['w_text'] * $c4;
        $den = $w['w_text'];
    }
    return $w['max'] * clamp01($den > 0 ? $s / $den : 0);
}

/**
 * 态度（依据成本判定）：以「成本」维的归一分（由 compute_scores 注入 cost_norm）为基线，
 * 再叠加「交代用心」与「交付完成」。三项都来自真实数据，不使用任何恒空的信号。
 */
function score_attitude(array $f, array $w): float
{
    $base = isset($f['cost_norm']) ? clamp01((float)$f['cost_norm']) : 0.0;

    if (isset($f['care']) && $f['care'] !== null) {
        $care = clamp01((float)$f['care']);
    } else {
        $care = 0.0;
        if ($f['intro_len'] >= $w['intro_full']) { $care = 1.0; }
        elseif ($f['intro_len'] >= $w['intro_half']) { $care = 0.5; }
    }
    $deliver = (!empty($f['has_html']) || !empty($f['has_artifact'])) ? 1.0 : 0.0;

    $s = $w['w_cost'] * $base + $w['w_care'] * $care + $w['w_deliver'] * $deliver;
    return $w['max'] * clamp01($s);
}

/* ============================================================
 * 层级聚合（支柱 → 总分）
 * ============================================================ */
/** 六维 → AHP 主观权重（支柱权重 × 支柱内权重；和为 1） */
function ahp_dim_weights(): array
{
    $w = score_weights();
    $ahp = $w['ahp'];
    $out = array();
    foreach ($w['pillars'] as $p => $members) {
        $pw = isset($ahp['pillars'][$p]) ? (float)$ahp['pillars'][$p] : 0.0;
        $sub = isset($ahp[$p]) && is_array($ahp[$p]) ? $ahp[$p] : array();
        $sum = 0.0;
        foreach ($members as $d) { if (isset($sub[$d])) { $sum += (float)$sub[$d]; } }
        $each = count($members) > 0 ? 1.0 / count($members) : 0.0;
        foreach ($members as $d) {
            $sv = ($sum > 0 && isset($sub[$d])) ? ((float)$sub[$d] / $sum) : $each;
            $out[$d] = $pw * $sv;
        }
    }
    return $out;
}

/** 主观(AHP) × 客观(CRITIC) 组合维度权重；缺客观则纯 AHP（和为 1） */
function combined_dim_weights(array $stats = array()): array
{
    $w = score_weights();
    $ahp = ahp_dim_weights();
    $critic = (isset($stats['critic']) && is_array($stats['critic'])) ? $stats['critic'] : array();
    if (empty($critic)) { return $ahp; }

    $lam = (float)$w['combine_lambda'];
    $out = array();
    foreach ($ahp as $d => $av) {
        $cv = isset($critic[$d]) ? (float)$critic[$d] : 0.0;
        $out[$d] = $lam * $av + (1.0 - $lam) * $cv;
    }
    $sum = array_sum($out);
    if ($sum <= 0) { return $ahp; }
    foreach ($out as $d => $v) { $out[$d] = $v / $sum; }
    return $out;
}

/** 支柱归一值（0–1）与支柱权重（来自组合维度权重） */
function pillar_norms(array $dims, array $wd): array
{
    $w = score_weights();
    $out = array();
    foreach ($w['pillars'] as $p => $members) {
        $pw = 0.0; $acc = 0.0;
        foreach ($members as $d) {
            $ww = isset($wd[$d]) ? (float)$wd[$d] : 0.0;
            $pw += $ww;
            $acc += $ww * (float)(isset($dims[$d]) ? $dims[$d] : 0) / 200.0;
        }
        $out[$p] = array('w' => $pw, 'v' => ($pw > 0 ? $acc / $pw : 0.0));
    }
    return $out;
}

/** 支柱 → 总分：加权几何（带下限），$pillars = pillar_norms() 输出 */
function aggregate_total(array $pillars): int
{
    $w = score_weights();
    $floor = max(0.0, min(1.0, (float)$w['aggregate']['floor']));
    $max = (float)$w['max_total'];
    $logsum = 0.0; $wsum = 0.0;
    foreach ($pillars as $pv) {
        $alpha = (float)$pv['w'];
        if ($alpha <= 0) { continue; }
        $v = $floor + (1.0 - $floor) * max(0.0, min(1.0, (float)$pv['v']));
        $logsum += $alpha * log($v);
        $wsum += $alpha;
    }
    if ($wsum <= 0) { return 0; }
    return (int)round($max * exp($logsum / $wsum));
}

/** 由六维直接算总分（供语料统计 / 阈值计算复用）；$wd 为 null 时用组合权重 */
function total_from_dims(array $dims6, $wd = null): int
{
    if ($wd === null) { $wd = combined_dim_weights(); }
    return aggregate_total(pillar_norms($dims6, $wd));
}

/** 评级：默认按全站总分分位数（$stats['thresholds']）；否则回退固定阈值 */
function rating_of(int $total, array $stats = array()): string
{
    $w = score_weights();
    $r = $w['rating'];
    $mode = isset($r['mode']) ? (string)$r['mode'] : 'fixed';
    if ($mode === 'quantile'
        && isset($stats['thresholds']) && is_array($stats['thresholds']) && !empty($stats['thresholds'])) {
        foreach ($r['quantile'] as $row) {
            $g = $row['grade'];
            if (isset($stats['thresholds'][$g]) && $total >= (int)$stats['thresholds'][$g]) { return (string)$g; }
        }
        return 'C';
    }
    foreach ($r['fixed'] as $row) {
        if ($total >= (int)$row['min']) { return (string)$row['grade']; }
    }
    return 'C';
}

/* ============================================================
 * 汇总
 * ============================================================ */
function compute_scores(array $features, array $stats = array()): array
{
    $w = score_weights();
    $prior = isset($stats['prior_s']) ? (float)$stats['prior_s'] : -1.0;

    $dims = array(
        'creativity' => (int)round(score_creativity($features, $w['creativity'])),
        'experience' => (int)round(score_experience($features, $w['experience'])),
        'depth'      => (int)round(score_depth($features, $w['depth'])),
        'cost'       => (int)round(score_cost($features, $w['cost'])),
        'heat'       => (int)round(score_heat($features, $w['heat'], $prior)),
    );
    /* 态度依据成本判定：注入成本归一分再算 */
    $features['cost_norm'] = $dims['cost'] / 200.0;
    $dims['attitude'] = (int)round(score_attitude($features, $w['attitude']));

    $wd = combined_dim_weights($stats);
    $pillars = pillar_norms($dims, $wd);
    $total = aggregate_total($pillars);

    $out = $dims;
    $out['total']  = $total;
    $out['rating'] = rating_of($total, $stats);
    $out['pillars'] = array(
        'quality'     => (int)round($pillars['quality']['v'] * 200),
        'effort'      => (int)round($pillars['effort']['v'] * 200),
        'recognition' => (int)round($pillars['recognition']['v'] * 200),
    );
    return $out;
}

/**
 * 评分总开关。管理员会按实际情况手动改分，自动重算会把这些改动覆盖掉，
 * 所以「已收录作品的评分是否重算」由这个设置统一决定（面板可切换、可取消）。
 * **默认「只更新信息」（不重算已有作品的评分）**；新收录的作品一律照常评分。
 */
function score_auto_enabled(): bool
{
    return setting_get('score_auto', '0') === '1';
}
