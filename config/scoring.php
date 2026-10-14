<?php
/**
 * 六维评分权重表
 * ------------------------------------------------------------
 * 全部阈值集中在此，改权重不动代码。详细推导见 docs/六维算法设计.md
 */
declare(strict_types=1);

return array(

    /* ---------- 热度 ---------- */
    'heat' => array(
        'max'      => 200,
        'w_like'   => 0.45,   'base_like'   => 100,
        'w_comment'=> 0.35,   'base_comment'=> 50,
        'w_collect'=> 0.20,   'base_collect'=> 30,
        'new_hours'=> 24,     'new_bonus'   => 0.20,  // 新作品加成上限
    ),

    /* ---------- 创意 ---------- */
    'creativity' => array(
        'max'        => 200,
        'w_unique'   => 0.35,
        'w_title'    => 0.15, 'title_min' => 4, 'title_max' => 20,
        'w_diversity'=> 0.30,
        'w_artifact' => 0.20, 'artifact_share' => 0.15, 'artifact_html' => 0.10,
        'bell_decay' => 10,
    ),

    /* ---------- 体验 ---------- */
    'experience' => array(
        'max'      => 200,
        'w_runnable'=> 0.50,
        'w_interact'=> 0.20, 'interact_ref' => 12,
        'w_media'   => 0.15, 'media_ref'    => 6,
        'w_guide'   => 0.15,
        'keywords'  => array('操作','点击','选择','输入','按键','拖动','关卡','难度','模式'),
        'penalty_no_html' => 0.8,
    ),

    /* ---------- 深度 ---------- */
    'depth' => array(
        'max'        => 200,
        'w_text'     => 0.30, 'text_ref'   => 800,
        'w_struct'   => 0.20, 'struct_ref' => 6,
        'w_code'     => 0.30, 'lines_ref'  => 400, 'funcs_ref' => 30,
        'w_discuss'  => 0.20, 'comment_ref'=> 30,
    ),

    /* ---------- 成本 ---------- */
    'cost' => array(
        'max'       => 200,
        'w_bytes'   => 0.35, 'bytes_ref' => 153600,   // 150KB
        'w_lines'   => 0.25, 'lines_ref' => 600,
        'w_media'   => 0.20, 'img_each' => 0.15, 'media_audio' => 0.20, 'media_video' => 0.20,
        'w_text'    => 0.20, 'text_ref'  => 1500,
    ),

    /* ---------- 态度（依据成本判定）----------
       不另立主观信号：以「成本」维的客观投入为基线，再叠加作者「交代用心」与「交付完成」。
       成本高说明作者上心；配文/正文是否交代清楚、是否交付了可运行成品，补足「用心」差异。 */
    'attitude' => array(
        'max'        => 200,
        'w_cost'     => 0.60,   // 基线：该作品的成本维得分（归一）
        'w_care'     => 0.25,   // 交代用心：配文/正文长度分级（或 Jev 用心度）
        'w_deliver'  => 0.15,   // 交付完成：有可运行文件或 artifactShareId
        'intro_full' => 60,     // 用心度退化为长度分级时的锚点
        'intro_half' => 20,
        'use_jev'    => 0,      // 1 = 用 Jev 判「用心度」；0 = 用长度分级（默认关）
    ),

    /* ---------- 评级 ---------- */
    'ratings' => array(
        array('grade' => 'SSS', 'min' => 1100),
        array('grade' => 'SS',  'min' => 800),
        array('grade' => 'S',   'min' => 700),
        array('grade' => 'A',   'min' => 550),
        array('grade' => 'B',   'min' => 480),
        array('grade' => 'C',   'min' => 0),
    ),
);
