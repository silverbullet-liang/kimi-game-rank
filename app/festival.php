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
 * 窗口有两种：'day'/'from'+'to' 走公历（直接比月日）；'lunar' 走农历（如重阳九月初九），
 * 由 app/lunar.php 换算成公历再比 —— 农历节日的公历日期每年都在变，不能写死。
 */
declare(strict_types=1);

/** 节日目录：窗口用北京时间的「月/日」表示。day 表示仅当日；from/to 表示跨日窗口。 */
function festival_catalog(): array
{
    return array(
        'festival' => array(
            'name' => '国庆专版',
            'desc' => '盛世红金 · 限时呈现',
            'dark' => false,
            'from' => array(9, 30),
            'to'   => array(10, 8),
        ),
        'xinhai' => array(
            'name' => '辛亥纪念',
            'desc' => '铁血首义 · 双十纪念',
            'dark' => false,
            'day'  => array(10, 10),
        ),
        'kmyc' => array(
            'name' => '抗美援朝纪念',
            'desc' => '雄赳赳 · 当日主题',
            'dark' => false,
            'day'  => array(10, 25),
        ),
        'halloween' => array(
            'name' => '万圣夜',
            'desc' => '暗夜惊魂 · 强制暗色',
            'dark' => true,
            'day'  => array(10, 31),
        ),
        /* 农历节日放最后：与公历纪念日同日时，优先让固定纪念日生效 */
        'chongyang' => array(
            'name' => '重阳节',
            'desc' => '登高赏菊 · 九月初九',
            'dark' => false,
            'lunar' => array(9, 9),
        ),
    );
}

/**
 * 给定公历（北京时间的 年/月/日）落在哪个节日窗口；没有则空串。抽出来便于单测。
 * 年份只在农历节日里用得到（需要先算出那一年九月初九是公历几号）。
 */
function festival_key_for_date(int $y, int $m, int $d): string
{
    $md = $m * 100 + $d;

    /* 分两组：单日节日（固定日 / 农历日）先判，跨日窗口后判。
       理由：国庆是 9 天的窗口，而重阳（农历九月初九）偶尔会落在这个窗口内
       （例如 2027-10-08）—— 那天应当显示重阳，而不是被长假盖住。 */
    $single = array(); $ranged = array();
    foreach (festival_catalog() as $key => $f) {
        if (isset($f['day']) || isset($f['lunar'])) { $single[$key] = $f; }
        else { $ranged[$key] = $f; }
    }

    foreach (array($single, $ranged) as $group) {
        foreach ($group as $key => $f) {
            /* 农历节日：换算成公历再比 */
            if (isset($f['lunar'])) {
                $lm = lunar_to_solar_md($y, (int)$f['lunar'][0], (int)$f['lunar'][1]);
                if ($lm && (int)$lm[0] === $m && (int)$lm[1] === $d) { return $key; }
                continue;
            }
            if (isset($f['day'])) {
                if ($md === (int)$f['day'][0] * 100 + (int)$f['day'][1]) { return $key; }
                continue;
            }
            $from = (int)$f['from'][0] * 100 + (int)$f['from'][1];
            $to   = (int)$f['to'][0]   * 100 + (int)$f['to'][1];
            if ($from <= $to) {
                if ($md >= $from && $md <= $to) { return $key; }
            } else {                              // 跨年窗口（预留）
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
