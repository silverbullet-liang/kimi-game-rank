<?php
/**
 * 六维评分引擎
 * ------------------------------------------------------------
 * 与 docs/六维算法设计.md 完全一致；权重取自 config/scoring.php。
 * 设计：extract_features() 只做数据提取；score_*() 为纯函数，便于测试。
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
 * @param array  $work  含 title/intro/body/like_num/comment_num/collect_num/images/age_hours/artifact_share_id/care 等
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
        'images'    => isset($work['images']) ? (int)$work['images'] : 0,
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
       $code 为空时退化为只看页面本身。结构 / 媒体特征（交互标签、音视频）仍只看页面。 */
    $codeTxt = ($code !== '') ? $code : $html;
    $f['bytes'] = $codeTxt !== '' ? strlen($codeTxt) : 0;
    $f['lines'] = $codeTxt !== '' ? substr_count($codeTxt, "\n") + 1 : 0;
    $f['funcs'] = $codeTxt !== '' ? (int)preg_match_all('/\b(function\s+\w+|=>\s*\{|function\s*\()/i', $codeTxt, $mf) : 0;
    if ($hasHtml) {
        $f['tags_interactive'] = (int)preg_match_all('/<(button|input|select|textarea|canvas|audio|video|svg)\b/i', $html, $mt);
        $f['has_audio'] = preg_match('/<audio\b/i', $html) === 1;
        $f['has_video'] = preg_match('/<video\b/i', $html) === 1;
    } else {
        $f['tags_interactive'] = 0; $f['has_audio'] = false; $f['has_video'] = false;
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
function score_heat(array $f, array $w): float
{
    $s = $w['w_like']    * lns((float)$f['like'], (float)$w['base_like'])
       + $w['w_comment'] * lns((float)$f['comment'], (float)$w['base_comment'])
       + $w['w_collect'] * lns((float)$f['collect'], (float)$w['base_collect']);
    $v = $w['max'] * min(1.0, $s);
    if ($f['age_hours'] < $w['new_hours']) {
        $v *= 1 + $w['new_bonus'] * (1 - $f['age_hours'] / $w['new_hours']);
    }
    return min((float)$w['max'], $v);
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
    $e3 = min(1.0, (float)$f['images'] / max(1.0, (float)$w['media_ref']));
    $e4 = clamp01((float)$f['guide_ratio']);
    $s  = $w['w_runnable'] * $e1 + $w['w_interact'] * $e2 + $w['w_media'] * $e3 + $w['w_guide'] * $e4;
    $v  = $w['max'] * clamp01($s);
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
    $c3 = min(1.0, $w['img_each'] * (float)$f['images']
        + ($f['has_audio'] ? $w['media_audio'] : 0)
        + ($f['has_video'] ? $w['media_video'] : 0));
    $c4 = min(1.0, (float)$f['text_len'] / max(1.0, (float)$w['text_ref']));

    if ($f['has_html']) {
        $c1 = min(1.0, (float)$f['bytes'] / max(1.0, (float)$w['bytes_ref']));
        $c2 = min(1.0, (float)$f['lines'] / max(1.0, (float)$w['lines_ref']));
        $s = $w['w_bytes'] * $c1 + $w['w_lines'] * $c2 + $w['w_media'] * $c3 + $w['w_text'] * $c4;
        $den = $w['w_bytes'] + $w['w_lines'] + $w['w_media'] + $w['w_text'];
    } else {
        // 无作品文件：剔除 c1/c2 后重归一化
        $s = $w['w_media'] * $c3 + $w['w_text'] * $c4;
        $den = $w['w_media'] + $w['w_text'];
    }
    return $w['max'] * clamp01($den > 0 ? $s / $den : 0);
}

/**
 * 态度（依据成本判定）：以「成本」维的归一分（0–1，由 compute_scores 注入 cost_norm）为基线，
 * 再叠加「交代用心」与「交付完成」。三项都来自真实数据，不再使用任何恒空的信号。
 */
function score_attitude(array $f, array $w): float
{
    $base = isset($f['cost_norm']) ? clamp01((float)$f['cost_norm']) : 0.0;

    /* 交代用心：优先用 Jev 判定的用心度（0–1）；缺失则由配文/正文长度分级兜底 */
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
 * 汇总
 * ============================================================ */
function compute_scores(array $features): array
{
    $w = score_weights();
    $out = array(
        'creativity' => (int)round(score_creativity($features, $w['creativity'])),
        'experience' => (int)round(score_experience($features, $w['experience'])),
        'depth'      => (int)round(score_depth($features, $w['depth'])),
        'cost'       => (int)round(score_cost($features, $w['cost'])),
    );
    /* 「态度」依据「成本」判定：把成本归一后交给 score_attitude */
    $features['cost_norm'] = $out['cost'] / (float)max(1, (int)$w['cost']['max']);
    $out['attitude'] = (int)round(score_attitude($features, $w['attitude']));
    $out['heat']     = (int)round(score_heat($features, $w['heat']));
    $out['total']  = $out['creativity'] + $out['experience'] + $out['depth'] + $out['cost'] + $out['attitude'] + $out['heat'];
    $out['rating'] = rating_of($out['total']);
    return $out;
}

function rating_of(int $total): string
{
    $w = score_weights();
    foreach ($w['ratings'] as $r) {
        if ($total >= (int)$r['min']) { return (string)$r['grade']; }
    }
    return 'C';
}

/**
 * 评分总开关。管理员会按实际情况手动改分，自动重算会把这些改动覆盖掉，
 * 所以「已收录作品的评分是否重算」由这个设置统一决定（面板可切换、可取消）。
 * **默认「只更新信息」（不重算已有作品的评分）**，与站长的手动调分习惯一致；
 * 注意：它只影响已收录作品的更新——新收录的作品一律照常评分。
 */
function score_auto_enabled(): bool
{
    return setting_get('score_auto', '0') === '1';
}
