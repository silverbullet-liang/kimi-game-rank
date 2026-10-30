<?php
declare(strict_types=1);
/**
 * 作品分类判定（Jev 零样本四分类）
 * ------------------------------------------------------------
 * 站内分类固定四种（与 works_cats() 一一对应）：
 *   game 游戏类 / tool 工具类 / literature 文学类 / fanart 二创类
 *
 * 输入取「作品名 + 简介」：这两样是作品对自己的描述，比页面里的
 * 标签数量、按钮个数这类结构特征更能说明它到底是什么。
 *
 * 判定口径：
 *   conf >= works_cat_min_conf()  → 采用判定结果
 *   conf <  阈值                  → 视为不确定，保留原分类（回落到 game），
 *                                   并把 via 记成 low 供人工复核
 *   Jev 不可用（无 curl / 冷却中 / 限流） → 不改库，via = offline
 *
 * 与审核链路共用同一个 classifier.dev 通道，因此同样受 net_channel_cooling('jev')
 * 约束，避免审核被分类任务拖慢。
 */

/** 四分类的语义标签：顺序即索引，便于把模型返回的 label 映射回 key */
function works_cat_labels(): array
{
    return array(
        'game'       => 'a playable game or interactive experience with rules, levels, goals or scores, which the user operates to progress',
        'tool'       => 'a practical utility that solves a concrete problem, such as a calculator, converter, generator, script or productivity helper',
        'literature' => 'a text-based reading work, such as a novel, prose, poem, script, worldbuilding document or long-form article',
        'fanart'     => 'a derivative work based on an existing work, character or IP, such as fan illustration, fan fiction, adaptation or remake',
    );
}

/** 采用阈值：低于此值说明模型自己也不确定，宁可不动原分类 */
function works_cat_min_conf(): float
{
    $v = (float)cfg('scoring.cat_min_conf', 0.40);
    if ($v < 0.05) { $v = 0.05; }
    if ($v > 0.95) { $v = 0.95; }
    return $v;
}

/** 是否自动判定（关闭时新入库一律沿用接口给的分类，批量重判也跳过） */
function works_cat_enabled(): bool
{
    return (bool)cfg('feed.auto_cat', 1);
}

/** 简介可能是 HTML（作品正文摘录），去掉标签与多余空白，并压到 600 字 */
function works_cat_plain(string $s): string
{
    $s = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim((string)$s);
    return mb_substr($s, 0, 600, 'UTF-8');
}

/** 拼装送审文本：标题单列一行，简介随后 */
function works_cat_input(string $title, string $intro): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $title));
    $i = works_cat_plain($intro);
    $t = mb_substr((string)$t, 0, 200, 'UTF-8');
    if ($t === '' && $i === '') { return ''; }
    return ($t !== '' ? '标题：' . $t : '') . ($i !== '' ? "\n简介：" . $i : '');
}

/**
 * 判定一个作品的分类。
 * @return array cat(分类 key)|conf(置信度)|via(jev|low|offline|empty)|scores(各标签概率)
 */
function works_cat_classify(string $title, string $intro): array
{
    $text = works_cat_input($title, $intro);
    if ($text === '') { return array('cat' => '', 'conf' => 0.0, 'via' => 'empty', 'scores' => array()); }
    if (!works_cat_enabled()) { return array('cat' => '', 'conf' => 0.0, 'via' => 'offline', 'scores' => array()); }

    $ck = 'wcat_' . md5($text);
    $hit = cache_get($ck);
    if (is_array($hit)) { return $hit; }

    $labels = works_cat_labels();
    $r = jev_labels_classify($text, array_values($labels));
    if (empty($r['ok'])) {
        return array('cat' => '', 'conf' => 0.0, 'via' => 'offline', 'scores' => array());
    }

    /* 模型回传的 label 是标签原文，按原文反查 key；找不到就用概率最高的那一项 */
    $cat = '';
    foreach ($labels as $k => $label) {
        if ($label === (string)$r['label']) { $cat = $k; break; }
    }
    $scores = isset($r['scores']) && is_array($r['scores']) ? $r['scores'] : array();
    if ($cat === '' && $scores) {
        $best = ''; $bv = -1.0;
        foreach ($labels as $k => $label) {
            $v = isset($scores[$label]) ? (float)$scores[$label] : 0.0;
            if ($v > $bv) { $bv = $v; $best = $k; }
        }
        if ($best !== '') { $cat = $best; }
    }
    $conf = isset($r['conf']) ? (float)$r['conf'] : 0.0;
    if ($conf <= 0 && $scores && $cat !== '') {
        $conf = isset($scores[$labels[$cat]]) ? (float)$scores[$labels[$cat]] : 0.0;
    }

    /* 该通道当前只回「最贴切的一个」，confidence 与 scores 为空（服务端换后端后如此）。
       此时不存在「置信度不足」这一档：拿到合法类别就采用，拿不到才算不确定。
       若将来重新给出置信度，则仍按阈值把关。 */
    $via = ($cat !== '' && ($conf <= 0 || $conf >= works_cat_min_conf())) ? 'jev' : 'low';
    $out = array('cat' => ($via === 'jev' ? $cat : ''), 'conf' => round($conf, 3), 'via' => $via,
                 'scores' => $scores);
    /* 判定结果缓存 7 天：批量重判与人工复核会重复问同一段文本 */
    cache_set($ck, $out, 604800);
    return $out;
}

/**
 * 一个新作品的分类与来源：接口给了合法值就沿用（src=api），否则按标题与简介判定（src=jev）。
 * 判定不出来时回落 game、来源留空（供批量重判再试）。
 * @return array(分类 key, 来源 api|jev|'')
 */
function works_cat_for_new_pair($given, string $title, string $intro): array
{
    $cats = works_cats();
    $g = is_string($given) ? strtolower(trim($given)) : '';
    if ($g !== '' && isset($cats[$g])) { return array($g, 'api'); }
    if (!works_cat_enabled()) { return array('game', ''); }
    $r = works_cat_classify($title, $intro);
    if ($r['cat'] !== '') { return array($r['cat'], 'jev'); }
    return array('game', '');
}

/** 只取分类（老调用点用） */
function works_cat_for_new($given, string $title, string $intro): string
{
    $p = works_cat_for_new_pair($given, $title, $intro);
    return $p[0];
}
