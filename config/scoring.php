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
        'w_discuss'  => 0.20, 'comment_ref'=> 30,  'sublayer_ref' => 10,
        'discuss_w_comment' => 0.7, 'discuss_w_sub' => 0.3,
    ),

    /* ---------- 成本 ---------- */
    'cost' => array(
        'max'       => 200,
        'w_bytes'   => 0.35, 'bytes_ref' => 153600,   // 150KB
        'w_lines'   => 0.25, 'lines_ref' => 600,
        'w_media'   => 0.20, 'img_each' => 0.15, 'media_audio' => 0.20, 'media_video' => 0.20,
        'w_text'    => 0.20, 'text_ref'  => 1500,
    ),

    /* ---------- 态度 ---------- */
    'attitude' => array(
        'max'         => 200,
        'w_reply'     => 0.35,
        'w_maint'     => 0.25,
        'w_intro'     => 0.20, 'intro_full' => 50, 'intro_half' => 20,
        'w_ongoing'   => 0.20,
        'maint_words' => array('更新','修复','优化','感谢','反馈','抱歉','调整'),
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
