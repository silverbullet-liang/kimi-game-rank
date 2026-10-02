<?php
/**
 * 中国农历换算（1900–2099）
 * ------------------------------------------------------------
 * 只解决一件事：把「农历某月某日」换算成公历日期 —— 限时节日里的重阳节（九月初九）
 * 靠它定位。完全离线计算，不依赖任何第三方接口：外部农历 API 一是不稳定，
 * 二是会把访问行为暴露给第三方，为了一个日期不值得。
 *
 * 数据表 LUNAR_YEAR_INFOS：一年一个 16 位值，编码该农历年的闰月与十二个月的大小月
 * （经典 lunarInfo 表，首值 0x04bd8 对应 1900 年）。与权威实现交叉验证过：
 * 2024 重阳 10-11、2025 10-29、2026 10-18、2027 10-08、2028 10-26 全部一致。
 */
declare(strict_types=1);

/** 农历数据表：索引 = 年份 - 1900，覆盖 1900–2099 */
define('LUNAR_YEAR_INFOS', array(
    0x04bd8, 0x04ae0, 0x0a570, 0x054d5, 0x0d260, 0x0d950, 0x16554, 0x056a0,
    0x09ad0, 0x055d2, 0x04ae0, 0x0a5b6, 0x0a4d0, 0x0d250, 0x1d255, 0x0b540,
    0x0d6a0, 0x0ada2, 0x095b0, 0x14977, 0x04970, 0x0a4b0, 0x0b4b5, 0x06a50,
    0x06d40, 0x1ab54, 0x02b60, 0x09570, 0x052f2, 0x04970, 0x06566, 0x0d4a0,
    0x0ea50, 0x06e95, 0x05ad0, 0x02b60, 0x186e3, 0x092e0, 0x1c8d7, 0x0c950,
    0x0d4a0, 0x1d8a6, 0x0b550, 0x056a0, 0x1a5b4, 0x025d0, 0x092d0, 0x0d2b2,
    0x0a950, 0x0b557, 0x06ca0, 0x0b550, 0x15355, 0x04da0, 0x0a5d0, 0x14573,
    0x052b0, 0x0a9a8, 0x0e950, 0x06aa0, 0x0aea6, 0x0ab50, 0x04b60, 0x0aae4,
    0x0a570, 0x05260, 0x0f263, 0x0d950, 0x05b57, 0x056a0, 0x096d0, 0x04dd5,
    0x04ad0, 0x0a4d0, 0x0d4d4, 0x0d250, 0x0d558, 0x0b540, 0x0b5a0, 0x195a6,
    0x095b0, 0x049b0, 0x0a974, 0x0a4b0, 0x0b27a, 0x06a50, 0x06d40, 0x0af46,
    0x0ab60, 0x09570, 0x04af5, 0x04970, 0x064b0, 0x074a3, 0x0ea50, 0x06b58,
    0x05ac0, 0x0ab60, 0x096d5, 0x092e0, 0x0c960, 0x0d954, 0x0d4a0, 0x0da50,
    0x07552, 0x056a0, 0x0abb7, 0x025d0, 0x092d0, 0x0cab5, 0x0a950, 0x0b4a0,
    0x0baa4, 0x0ad50, 0x055d9, 0x04ba0, 0x0a5b0, 0x15176, 0x052b0, 0x0a930,
    0x07954, 0x06aa0, 0x0ad50, 0x05b52, 0x04b60, 0x0a6e6, 0x0a4e0, 0x0d260,
    0x0ea65, 0x0d530, 0x05aa0, 0x076a3, 0x096d0, 0x04afb, 0x04ad0, 0x0a4d0,
    0x1d0b6, 0x0d250, 0x0d520, 0x0dd45, 0x0b5a0, 0x056d0, 0x055b2, 0x049b0,
    0x0a577, 0x0a4b0, 0x0aa50, 0x1b255, 0x06d20, 0x0ada0, 0x14b63, 0x09370,
    0x049f8, 0x04970, 0x064b0, 0x168a6, 0x0ea50, 0x06aa0, 0x1a6c4, 0x0aae0,
    0x092e0, 0x0d2e3, 0x0c960, 0x0d557, 0x0d4a0, 0x0da50, 0x05d55, 0x056a0,
    0x0a6d0, 0x055d4, 0x052d0, 0x0a9b8, 0x0a950, 0x0b4a0, 0x0b6a6, 0x0ad50,
    0x055a0, 0x0aba4, 0x0a5b0, 0x052b0, 0x0b273, 0x06930, 0x07337, 0x06aa0,
    0x0ad50, 0x14b55, 0x04b60, 0x0a570, 0x054e4, 0x0d160, 0x0e968, 0x0d520,
    0x0daa0, 0x16aa6, 0x056d0, 0x04ae0, 0x0a9d4, 0x0a2d0, 0x0d150, 0x0f252,
));

define('LUNAR_FIRST_YEAR', 1900);   // 表覆盖的首年（该年正月初一 = 公历 1900-01-31）
define('LUNAR_LAST_YEAR', 2099);

/** 农历 $y 年的闰月（0 = 无闰月） */
function lunar_leap_month(int $y): int
{
    return LUNAR_YEAR_INFOS[$y - LUNAR_FIRST_YEAR] & 0xf;
}

/** 农历 $y 年 $m 月（非闰）的天数：29 或 30 */
function lunar_month_days(int $y, int $m): int
{
    return (LUNAR_YEAR_INFOS[$y - LUNAR_FIRST_YEAR] & (0x10000 >> $m)) ? 30 : 29;
}

/** 农历 $y 年闰月的天数；无闰月返回 0 */
function lunar_leap_days(int $y): int
{
    if (lunar_leap_month($y) === 0) { return 0; }
    return (LUNAR_YEAR_INFOS[$y - LUNAR_FIRST_YEAR] & 0x10000) ? 30 : 29;
}

/** 农历 $y 年的总天数 */
function lunar_year_days(int $y): int
{
    $sum = 348;   // 12 月 × 29 天
    for ($i = 0x8000; $i > 0x8; $i >>= 1) {
        if (LUNAR_YEAR_INFOS[$y - LUNAR_FIRST_YEAR] & $i) { $sum++; }
    }
    return $sum + lunar_leap_days($y);
}

/**
 * 农历（$y 年 $m 月 $d 日）→ 公历 UTC 零点时间戳；超出表范围返回 null。
 * 闰月只影响其之后的月份序数，这里按「逐月累加、遇到闰月补一个月」处理。
 *
 * 注意失败用 null 而不是 0：1900–1969 年的 UTC 时间戳本身是负数，
 * 用 0 当哨兵会把它们一并误判成失败。
 */
function lunar_to_solar(int $y, int $m, int $d)
{
    if ($y < LUNAR_FIRST_YEAR || $y > LUNAR_LAST_YEAR) { return null; }
    if ($m < 1 || $m > 12 || $d < 1 || $d > 30) { return null; }

    $days = 0;
    for ($yy = LUNAR_FIRST_YEAR; $yy < $y; $yy++) { $days += lunar_year_days($yy); }

    $leap = lunar_leap_month($y);
    for ($mm = 1; $mm < $m; $mm++) {
        $days += lunar_month_days($y, $mm);
        if ($mm === $leap) { $days += lunar_leap_days($y); }
    }
    $days += $d - 1;

    /* 基准：1900-01-31（农历 1900 年正月初一） */
    return gmmktime(0, 0, 0, 1, 31, 1900) + $days * 86400;
}

/** 农历（$y 年 $m 月 $d 日）在公历里是几月几日；返回 array(月, 日)，超范围返回空数组 */
function lunar_to_solar_md(int $y, int $m, int $d): array
{
    $ts = lunar_to_solar($y, $m, $d);
    if ($ts === null) { return array(); }
    return array((int)gmdate('n', $ts), (int)gmdate('j', $ts));
}
