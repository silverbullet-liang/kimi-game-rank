<?php
/**
 * 分区自动判定
 * ------------------------------------------------------------
 * 依据作品的标题与简介文本，推断它应该归入哪个分区。
 *
 * 判定优先级（关键）：
 *   二创 > 文学 > 工具 > 其余归游戏
 * 一部「改编自某小说的同人游戏」首先是二创，其次才是文学；
 * 反过来若先按游戏判定，这些作品会被大量错分到游戏榜。
 *
 * 游戏不单独判定：它作为兜底分区——三个规则都不命中就归游戏，
 * 因为游戏是本站的主力分区，说不清的作品放这里最不容易出错。
 */

/** 关键词表：按优先级从高到低排列 */
function reclassify_rules(): array
{
    return array(
        /* 二创：强调「改编自…」「原作者是…」这类明确的从属关系 */
        'fanart' => array(
            '/(改编|改写|翻拍|翻唱|翻填|搬运|仿写|致敬|重制|重绘)\s*(自|于|的)?/u',
            '/(原作者|原著|原设|原曲|原作)\s*(是|为|：|:)/u',
            '/(二创|二次创作|同人|番外|外传|衍生|if\s*线|平行世界|设定集|二设|au向|联动)/iu',
            '/(基于|取自|借用).{0,12}(设定|世界观|角色|剧情|改编)/u',
        ),
        /* 文学：以文字叙事为主体 */
        'literature' => array(
            '/(小说|文学|散文|诗歌|诗集|词牌|古文|文言)/u',
            '/(连载|短篇|中篇|长篇|章节|第[一二三四五六七八九十百\d]+章)/u',
            '/(随笔|文集|故事集|作品集|传记|寓言|童话|剧本|叙事|文风)/u',
        ),
        /* 工具：面向效率与实用 */
        'tool' => array(
            '/(工具|插件|脚本|助手|效率|自动化|批处理|一键)/u',
            '/(生成器|转换器|计算器|管理器|编辑器|解析器|监控|面板|模板)/u',
            '/(软件|程序|客户端|扩展|插件|应用)/u',
            '/(查询|统计|整理|汇总|导出|导入|批量)/u',
        ),
    );
}

/**
 * 推断分区。返回 'game' / 'tool' / 'literature' / 'fanart' / ''（判定不出）
 */
function reclassify_guess(string $title, string $intro): string
{
    $text = trim($title . "\n" . $intro);
    if ($text === '') { return ''; }

    /* 过长的简介截断：关键词都出现在前段，没必要扫全文 */
    if (mb_strlen($text, 'UTF-8') > 2000) {
        $text = mb_substr($text, 0, 2000, 'UTF-8');
    }

    foreach (reclassify_rules() as $cat => $patterns) {
        foreach ($patterns as $re) {
            if (preg_match($re, $text) === 1) { return $cat; }
        }
    }
    return 'game';   // 兜底：二创 / 文学 / 工具都不命中，即归游戏
}

/** 分区中文名 */
function reclassify_label(string $cat): string
{
    $m = array('game' => '游戏类', 'tool' => '工具类', 'literature' => '文学类', 'fanart' => '二创类');
    return isset($m[$cat]) ? $m[$cat] : $cat;
}

/**
 * 扫描作品并计算分区变更计划。
 * 返回 array(items => array(array(id,title,from,to)) 全部待变更项, counts => array)
 * 只读，不写库 —— 供预览与执行共用，保证「预览所见 = 执行所做」。
 */
function reclassify_plan(): array
{
    $rows = db_all('SELECT id, title, intro, category FROM works WHERE is_hidden = 0 ORDER BY id ASC');
    $items = array();
    $counts = array('total' => 0, 'changed' => 0, 'keep' => 0, 'default_game' => 0);

    foreach ($rows as $r) {
        $counts['total']++;
        $from = (string)$r['category'];
        $to = reclassify_guess((string)$r['title'], (string)$r['intro']);

        if ($to === 'game' && $from !== 'game') { $counts['default_game']++; }   // 靠兜底归入游戏
        if ($to === $from) { $counts['keep']++; continue; }

        $counts['changed']++;
        $items[] = array(
            'id'    => (int)$r['id'],
            'title' => (string)$r['title'],
            'from'  => $from,
            'to'    => $to,
            'from_label' => reclassify_label($from),
            'to_label'   => reclassify_label($to),
        );
    }
    return array('items' => $items, 'counts' => $counts);
}
