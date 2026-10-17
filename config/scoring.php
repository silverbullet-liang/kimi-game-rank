<?php
/**
 * 评分体系配置（v3.40.0 · 层级化 MCDA）
 * ------------------------------------------------------------
 * 结构：三支柱（作品质量 / 作者投入 / 社区认可）→ 六维。
 * 权重：AHP 主观权重（判断矩阵见下，CR<0.1）× CRITIC 客观权重（由全站语料算，见 app/corpus.php）。
 * 聚合：支柱加权几何（互相制约）；口碑维贝叶斯收缩；评级按全站总分分位数。
 * 全部阈值集中在此，改权重不动代码。详细推导见 docs/六维算法设计.md。
 */
declare(strict_types=1);

return array(

    'max_total' => 1200,

    /* 评分体系版本号：**每次改动评分规则就 +1** —— 部署后面板提示「评分体系已更新」，
       由管理员在控制面板点「开始重算」后才按新算法重算全站（见 app/score_recalc.php）。 */
    'version' => 4,

    /* ---------- 归一化锚点与各维子权重 ---------- */
    'heat' => array(
        'max' => 200,
        'w_like' => 0.45, 'base_like' => 100,
        'w_comment' => 0.35, 'base_comment' => 50,
        'w_collect' => 0.20, 'base_collect' => 30,
        /* 贝叶斯收缩：样本少的口碑向先验收拢（bayes_c 为先验强度） */
        'bayes_c' => 25, 'prior_s' => 0.15,
        /* 新作品曝光加成（随时间线性衰减到 0） */
        'new_hours' => 24, 'new_bonus' => 0.20,
    ),
    'creativity' => array(
        'max' => 200,
        'w_unique' => 0.20, 'w_title' => 0.10, 'title_min' => 4, 'title_max' => 20,
        'w_diversity' => 0.40, 'w_artifact' => 0.30, 'artifact_share' => 0.15, 'artifact_html' => 0.10,
        'bell_decay' => 10,
    ),
    'experience' => array(
        'max' => 200,
        'w_runnable' => 0.40, 'w_interact' => 0.40, 'interact_ref' => 12,
        'w_guide' => 0.20,
        'keywords' => array('操作','点击','选择','输入','按键','拖动','关卡','难度','模式'),
        'penalty_no_html' => 0.8,
    ),
    'depth' => array(
        'max' => 200,
        'w_text' => 0.30, 'text_ref' => 1200,
        'w_struct' => 0.20, 'struct_ref' => 8,
        'w_code' => 0.30, 'lines_ref' => 800, 'funcs_ref' => 60,
        'w_discuss' => 0.20, 'comment_ref' => 30,
    ),
    'cost' => array(
        'max' => 200,
        'w_bytes' => 0.44, 'bytes_ref' => 307200,
        'w_lines' => 0.31, 'lines_ref' => 1200,
        'w_text' => 0.25, 'text_ref' => 3000,
    ),
    'attitude' => array(
        'max' => 200,
        'w_cost' => 0.35, 'w_care' => 0.45, 'w_deliver' => 0.20,
        'intro_full' => 60, 'intro_half' => 20, 'use_jev' => 0,
    ),

    /* ---------- 支柱 ↔ 维度 归属 ---------- */
    'pillars' => array(
        'quality'     => array('experience', 'creativity', 'depth'),
        'effort'      => array('cost', 'attitude'),
        'recognition' => array('heat'),
    ),

    /* ---------- 层级权重（AHP，Saaty 1980）----------
       支柱判断矩阵 A：
             质量   投入   认可
       质量    1      2      3
       投入   1/2     1      2
       认可   1/3    1/2     1
       → 权重 [0.5396, 0.2970, 0.1634]；λmax≈3.009，CI=0.0046，CR=0.008 < 0.1（通过一致性）
       支柱内（质量）：体验 / 创意 / 深度 = 0.45 / 0.25 / 0.30
       支柱内（投入）：体量 / 用心     = 0.55 / 0.45 */
    'ahp' => array(
        'pillars' => array('quality' => 0.5396, 'effort' => 0.2970, 'recognition' => 0.1634),
        'quality' => array('experience' => 0.45, 'creativity' => 0.25, 'depth' => 0.30),
        'effort'  => array('cost' => 0.55, 'attitude' => 0.45),
    ),

    /* 主观(AHP) × 客观(CRITIC) 组合系数：w = λ·AHP + (1−λ)·CRITIC */
    'combine_lambda' => 0.5,

    /* 支柱聚合：加权几何 + 下限（避免某支柱为 0 时整体归零，同时保留"短板压制"） */
    'aggregate' => array('floor' => 0.10),

    /* 评级：按「累计名次百分比」定级 —— 并列同分算同档，且**每档不超过配额**
       （top = 处于该档的名次占比上限：SSS 前 2%、SS 前 10%、S 前 25%、A 前 50%、B 前 75%、其余 C）。
       此前按「分数阈值」判定，分数并列严重时 SSS/SS 会远超配额（实测 SSS 4.7%、SS 26.3%）。 */
    'rating' => array(
        'mode' => 'quantile',
        'quantile' => array(
            array('grade' => 'SSS', 'top' => 0.02),
            array('grade' => 'SS',  'top' => 0.10),
            array('grade' => 'S',   'top' => 0.25),
            array('grade' => 'A',   'top' => 0.50),
            array('grade' => 'B',   'top' => 0.75),
        ),
        'fixed' => array(
            array('grade' => 'SSS', 'min' => 1000),
            array('grade' => 'SS',  'min' => 870),
            array('grade' => 'S',   'min' => 780),
            array('grade' => 'A',   'min' => 660),
            array('grade' => 'B',   'min' => 540),
        ),
    ),

    /* CRITIC 客观赋权：样本下限 */
    'critic' => array('min_samples' => 20),
);
