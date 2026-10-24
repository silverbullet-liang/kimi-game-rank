<?php
/**
 * 限时节日皮肤
 * ------------------------------------------------------------
 * 每个节日一套**独立主题**（配色、装饰、动效都不共用），只在对应日期窗口内生效，
 * 过窗口自动回退到用户自选皮肤。判定一律用北京时间，与访问者本机时区无关。
 *
 * 本文件是节日规则的唯一数据源：index.php 把结果下发给前端，
 * theme.js 与「我的 → 外观设置」都从这里取，避免两处规则各写一份而漂移。
 *
 * 'dark' => true 的节日会强制暗色（不论用户偏好的深浅色）。
 * 窗口有三种：'day'/'from'+'to' 走公历（直接比月日）；'lunar' 走农历（如重阳九月初九），
 * 由 app/lunar.php 换算成公历再比 —— 农历节日的公历日期每年都在变，不能写死；
 * 'lunar' 再加 'span' => N 表示从该农历日起连续 N 天（春节用，正月初一至初九）；
 * 'nth' => array(月, 星期几, 第几个) 表示「某月第 N 个星期 X」（感恩节 = 11 月第 4 个星期四）；
 * 'lunar_end' => 月 表示该农历月的最后一天（除夕 = 腊月最后一天）。
 */
declare(strict_types=1);

/** 节日目录：窗口用北京时间的「月/日」表示。day 表示仅当日；from/to 表示跨日窗口。 */
function festival_catalog(): array
{
    return array(
        'newyear' => array(
            'name' => '元旦',
            'desc' => '新年第一缕光 · 1 月 1 日',
            'dark' => false,
            'day' => array(1, 1),
        ),
        'police' => array(
            'name' => '警察节',
            'desc' => '八角警徽与警戒带 · 1 月 10 日',
            'dark' => false,
            'day' => array(1, 10),
        ),
        'laba' => array(
            'name' => '腊八',
            'desc' => '七粒谷物与一碗粥 · 腊月初八',
            'dark' => false,
            'lunar' => array(12, 8),
        ),
        'weiya' => array(
            'name' => '尾牙',
            'desc' => '宴席将散 · 腊月十六',
            'dark' => false,
            'lunar' => array(12, 16),
        ),
        'xiaonian_n' => array(
            'name' => '北方小年',
            'desc' => '糖瓜与剪纸窗格 · 腊月廿三',
            'dark' => false,
            'lunar' => array(12, 23),
        ),
        'xiaonian_s' => array(
            'name' => '南方小年',
            'desc' => '掸尘与竹帚 · 腊月廿四',
            'dark' => false,
            'lunar' => array(12, 24),
        ),
        'chuxi' => array(
            'name' => '除夕',
            'desc' => '守岁到最后一格 · 腊月最后一天',
            'dark' => false,
            'lunar_end' => 12,
        ),
        'spring' => array(
            'name' => '春节',
            'desc' => '年味红金 · 正月初一起九天',
            'dark' => false,
            'lunar' => array(1, 1), 'span' => 9,
        ),
        'valentine' => array(
            'name' => '情人节',
            'desc' => '一支线描玫瑰 · 2 月 14 日',
            'dark' => false,
            'day' => array(2, 14),
        ),
        'yuanxiao' => array(
            'name' => '元宵',
            'desc' => '灯会与汤圆 · 正月十五',
            'dark' => false,
            'lunar' => array(1, 15),
        ),
        'aprilfool' => array(
            'name' => '愚人节',
            'desc' => '倒置的问号 · 4 月 1 日',
            'dark' => false,
            'day' => array(4, 1),
        ),
        'qingming' => array(
            'name' => '清明',
            'desc' => '斜雨与垂柳 · 4 月 5 日',
            'dark' => false,
            'day' => array(4, 5),
        ),
        'shangsi' => array(
            'name' => '上巳节',
            'desc' => '曲水流觞 · 三月初三',
            'dark' => false,
            'lunar' => array(3, 3),
        ),
        'earthday' => array(
            'name' => '地球日',
            'desc' => '球体的边缘与一道云带 · 4 月 22 日',
            'dark' => false,
            'day' => array(4, 22),
        ),
        'bookday' => array(
            'name' => '读书日',
            'desc' => '台灯下的光锥 · 4 月 23 日',
            'dark' => false,
            'day' => array(4, 23),
        ),
        'labor' => array(
            'name' => '劳动节',
            'desc' => '扳手与锤 · 5 月 1 日',
            'dark' => false,
            'day' => array(5, 1),
        ),
        'youth' => array(
            'name' => '青年节',
            'desc' => '一只向上的纸飞机 · 5 月 4 日',
            'dark' => false,
            'day' => array(5, 4),
        ),
        'smile' => array(
            'name' => '微笑日',
            'desc' => '一条笑弧与两点 · 5 月 8 日',
            'dark' => false,
            'day' => array(5, 8),
        ),
        /* 5/12 这一天有两个身份：汶川地震纪念日，与程序员自嘲的 512（1024 ÷ 2，半天）。
           合成一套主题 —— 庄重为主，「半天」只留一条停在 50% 的细线。 */
        'may12' => array(
            'name' => '5·12 纪念日',
            'desc' => '静默灰蓝 · 半日',
            'dark' => false,
            'day' => array(5, 12),
        ),
        'mothersday' => array(
            'name' => '母亲节',
            'desc' => '三瓣康乃馨 · 五月第二个星期日',
            'dark' => false,
            'nth' => array(5, 0, 2),
        ),
        'museum' => array(
            'name' => '博物馆日',
            'desc' => '展柜反光与陶罐 · 5 月 18 日',
            'dark' => false,
            'day' => array(5, 18),
        ),
        'children' => array(
            'name' => '儿童节',
            'desc' => '三块积木与纸飞机 · 6 月 1 日',
            'dark' => false,
            'day' => array(6, 1),
        ),
        'environment' => array(
            'name' => '环境日',
            'desc' => '一片叶的中脉 · 6 月 5 日',
            'dark' => false,
            'day' => array(6, 5),
        ),
        'duanwu' => array(
            'name' => '端午',
            'desc' => '一枚棱锥粽与水纹 · 五月初五',
            'dark' => false,
            'lunar' => array(5, 5),
        ),
        'fathersday' => array(
            'name' => '父亲节',
            'desc' => '一件旧外套的宽肩 · 六月第三个星期日',
            'dark' => false,
            'nth' => array(6, 0, 3),
        ),
        'olympic' => array(
            'name' => '奥林匹克日',
            'desc' => '五枚细线圆环 · 6 月 23 日',
            'dark' => false,
            'day' => array(6, 23),
        ),
        'antidrug' => array(
            'name' => '禁毒日',
            'desc' => '断开的锁链 · 6 月 26 日',
            'dark' => false,
            'day' => array(6, 26),
        ),
        'party' => array(
            'name' => '建党节',
            'desc' => '一枚五角星 · 7 月 1 日',
            'dark' => false,
            'day' => array(7, 1),
        ),
        'july7' => array(
            'name' => '七七事变',
            'desc' => '带缺口的城墙 · 7 月 7 日',
            'dark' => false,
            'day' => array(7, 7),
        ),
        'army' => array(
            'name' => '建军节',
            'desc' => '军绿织物的质感 · 8 月 1 日',
            'dark' => false,
            'day' => array(8, 1),
        ),
        'qixi' => array(
            'name' => '七夕',
            'desc' => '一条星河与两岸的星 · 七月初七',
            'dark' => false,
            'lunar' => array(7, 7),
        ),
        'surrender' => array(
            'name' => '日本投降',
            'desc' => '半降的旗 · 8 月 15 日',
            'dark' => false,
            'day' => array(8, 15),
        ),
        'zhongyuan' => array(
            'name' => '中元节',
            'desc' => '暗水上的一盏河灯 · 七月十五',
            'dark' => false,
            'lunar' => array(7, 15),
        ),
        'doctor' => array(
            'name' => '医师节',
            'desc' => '一道心电脉波 · 8 月 19 日',
            'dark' => false,
            'day' => array(8, 19),
        ),
        'victory' => array(
            'name' => '抗战胜利',
            'desc' => '地平线与一线曙光 · 9 月 3 日',
            'dark' => false,
            'day' => array(9, 3),
        ),
        'teacher' => array(
            'name' => '教师节',
            'desc' => '一支粉笔与三点粉笔灰 · 9 月 10 日',
            'dark' => false,
            'day' => array(9, 10),
        ),
        'midautumn' => array(
            'name' => '中秋',
            'desc' => '满月与一片薄云 · 八月十五',
            'dark' => false,
            'lunar' => array(8, 15),
        ),
        'nineone' => array(
            'name' => '九一八',
            'desc' => '撕开的日历格 · 9 月 18 日',
            'dark' => false,
            'day' => array(9, 18),
        ),
        /* 9/30 与国庆窗口首日重合：单日节日优先，这天显示烈士纪念 */
        'martyr' => array(
            'name' => '烈士纪念',
            'desc' => '一面纪念墙与一枚小星 · 9 月 30 日',
            'dark' => false,
            'day' => array(9, 30),
        ),
        'festival' => array(
            'name' => '国庆专版',
            'desc' => '盛世红金 · 限时呈现',
            'dark' => false,
            'from' => array(9, 30), 'to' => array(10, 8),
        ),
        'xinhai' => array(
            'name' => '辛亥纪念',
            'desc' => '铁血首义 · 双十纪念',
            'dark' => false,
            'day' => array(10, 10),
        ),
        'code1024' => array(
            'name' => '1024 程序员节',
            'desc' => '二进制绿 · 当日限定',
            'dark' => false,
            'day' => array(10, 24),
        ),
        'kmyc' => array(
            'name' => '抗美援朝纪念',
            'desc' => '雄赳赳 · 当日主题',
            'dark' => false,
            'day' => array(10, 25),
        ),
        'halloween' => array(
            'name' => '万圣夜',
            'desc' => '暗夜惊魂 · 强制暗色',
            'dark' => true,
            'day' => array(10, 31),
        ),
        'chongyang' => array(
            'name' => '重阳节',
            'desc' => '登高赏菊 · 九月初九',
            'dark' => false,
            'lunar' => array(9, 9),
        ),
        'journalist' => array(
            'name' => '记者节',
            'desc' => '铅字与朱批 · 11 月 8 日',
            'dark' => false,
            'day' => array(11, 8),
        ),
        /* 感恩节：11 月第 4 个星期四（美国惯例，日期逐年变） */
        'thanksgiving' => array(
            'name' => '感恩节',
            'desc' => '南瓜与枫叶 · 十一月第四个星期四',
            'dark' => false,
            'nth' => array(11, 4, 4),
        ),
        /* 寒衣节：农历十月初一，与清明、中元并称三大鬼节 —— 肃穆为主，不做喜庆装饰 */
        'hanyi' => array(
            'name' => '寒衣节',
            'desc' => '霜色寄衣 · 十月初一',
            'dark' => false,
            'lunar' => array(10, 1),
        ),
    );
}
/**
 * 某年某月的第 N 个「星期 X」是几号。星期用 0=周日 … 6=周六。
 * 该月不足 N 个时取最后一个（例如某月只有 4 个周四而要求第 5 个）。
 */
function festival_nth_weekday_md(int $y, int $month, int $weekday, int $nth)
{
    if ($month < 1 || $month > 12 || $weekday < 0 || $weekday > 6 || $nth < 1 || $nth > 5) { return null; }
    $first = (int)gmdate('w', gmmktime(0, 0, 0, $month, 1, $y));      // 该月 1 号是星期几
    $day   = 1 + (($weekday - $first + 7) % 7) + ($nth - 1) * 7;
    $last  = (int)gmdate('t', gmmktime(0, 0, 0, $month, 1, $y));      // 该月天数
    if ($day > $last) { $day -= 7; }
    return $day >= 1 ? array($month, $day) : null;
}

/**
 * 给定公历（北京时间的 年/月/日）落在哪个节日窗口；没有则空串。抽出来便于单测。
 * 年份只在农历节日里用得到（需要先算出那一年九月初九是公历几号）。
 */
function festival_key_for_date(int $y, int $m, int $d): string
{
    $md = $m * 100 + $d;

    /* 分组：单日（固定日 / 农历日 / 农历月末 / 某月第 N 个星期 X）先判，跨日窗口后判。
       理由：国庆是 9 天窗口，而重阳、烈士纪念日在某些年份会落进这个窗口 ——
       那几天应当显示单日节日，而不是被长假盖住。 */
    $single = array(); $ranged = array();
    foreach (festival_catalog() as $key => $f) {
        $isWindow = isset($f['from']) || (isset($f['lunar']) && isset($f['span']));
        if ($isWindow) { $ranged[$key] = $f; } else { $single[$key] = $f; }
    }

    foreach (array($single, $ranged) as $group) {
        foreach ($group as $key => $f) {
            /* 公历固定日 */
            if (isset($f['day'])) {
                if ($md === (int)$f['day'][0] * 100 + (int)$f['day'][1]) { return $key; }
                continue;
            }
            /* 「某月第 N 个星期 X」：母亲节、父亲节、感恩节 */
            if (isset($f['nth'])) {
                $nd = festival_nth_weekday_md($y, (int)$f['nth'][0], (int)$f['nth'][1], (int)$f['nth'][2]);
                if ($nd && $nd[0] === $m && $nd[1] === $d) { return $key; }
                continue;
            }
            /* 农历日 / 农历月末 / 农历起 + span。
               农历年跨公历年：腊月与正月的日子会落在公历 1~2 月，属于上一个农历年，
               所以两个年份都试一遍，命中即返回。 */
            if (isset($f['lunar']) || isset($f['lunar_end'])) {
                $lmMonth = isset($f['lunar_end']) ? (int)$f['lunar_end'] : (int)$f['lunar'][0];
                $span    = isset($f['span']) ? max(1, (int)$f['span']) : 1;
                foreach (array($y, $y - 1) as $ly) {
                    $lday = isset($f['lunar_end']) ? lunar_month_days($ly, $lmMonth) : (int)$f['lunar'][1];
                    $lm   = lunar_to_solar_md($ly, $lmMonth, $lday);
                    if (!$lm) { continue; }
                    if ($span > 1) {
                        $start = gmmktime(0, 0, 0, (int)$lm[0], (int)$lm[1], $ly);
                        $cur   = gmmktime(0, 0, 0, $m, $d, $y);
                        $diff  = (int)round(($cur - $start) / 86400);
                        if ($diff >= 0 && $diff < $span) { return $key; }
                    } elseif ((int)$lm[0] === $m && (int)$lm[1] === $d) {
                        return $key;
                    }
                }
                continue;
            }
            /* 公历跨日窗口（国庆） */
            $from = (int)$f['from'][0] * 100 + (int)$f['from'][1];
            $to   = (int)$f['to'][0]   * 100 + (int)$f['to'][1];
            if ($from <= $to) {
                if ($md >= $from && $md <= $to) { return $key; }
            } else {
                if ($md >= $from || $md <= $to) { return $key; }
            }
        }
    }
    return '';
}

/** 当前生效的节日 key（北京时间）；没有则空串 */
function festival_now(): string
{
    $t = time() + 8 * 3600;
    return festival_key_for_date((int)gmdate('Y', $t), (int)gmdate('n', $t), (int)gmdate('j', $t));
}

/** 下发给前端的节日数据（供 theme.js / index.php 的首屏脚本使用） */
function festival_client(): array
{
    return array('now' => festival_now(), 'list' => festival_catalog());
}
