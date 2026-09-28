<?php
/**
 * 每日签到与 AI 额度加成
 * ------------------------------------------------------------
 * - 签到按「站点本地日期」（site.timezone，Asia/Shanghai）记 DATE，
 *   与 AI 额度的自然日 / 自然月周期同一基准（库内其余时间仍为 UTC）
 * - 当日签到 → 当日 AI 日额度 +1000
 * - 连续签到每满 7 天 → 当月 AI 月额度 +100000（可叠加，一个月最多 4 次）
 * - 月加成是「已发放事实」：断签不回收已拓宽的额度，重新攒满 7 天可再得
 * - 防刷：主键 (user_id, checkin_date) 幂等，一天最多一条
 */
declare(strict_types=1);

define('CHECKIN_DAILY_BONUS', 1000);    // 签到当日额度加成
define('CHECKIN_WEEK_BONUS', 100000);   // 连续满 7 天的月额度加成
define('CHECKIN_WEEK_DAYS', 7);

/** 站点本地今天（Y-m-d） */
function checkin_local_today(): string
{
    try {
        return (new DateTime('now', new DateTimeZone((string)cfg('site.timezone', 'Asia/Shanghai'))))->format('Y-m-d');
    } catch (Exception $e) {
        return gmdate('Y-m-d');
    }
}

/** 站点本地本月 1 号（Y-m-d） */
function checkin_local_month_start(): string
{
    return substr(checkin_local_today(), 0, 8) . '01';
}

/** 今天是否已签到 */
function checkin_today_done(int $uid): bool
{
    return (bool)db_val('SELECT user_id FROM checkins WHERE user_id = ? AND checkin_date = ?',
        array($uid, checkin_local_today()));
}

/**
 * 当前连续签到天数：
 * - 今天已签 → 昨天记录上的 streak_after（签到时已算好）
 * - 今天未签、昨天签了 → 昨天的 streak（连击保持，尚未加今日）
 * - 更早 → 连击已断，0
 */
function checkin_streak(int $uid): int
{
    $last = db_one('SELECT checkin_date, streak_after FROM checkins WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1',
        array($uid));
    if ($last === null) { return 0; }
    $today = checkin_local_today();
    if ((string)$last['checkin_date'] === $today) { return (int)$last['streak_after']; }

    $yest = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    return ((string)$last['checkin_date'] === $yest) ? (int)$last['streak_after'] : 0;
}

/** 本月已获得的「满 7 天」加成次数（streak 恰为 7 的倍数的签到记录数） */
function checkin_month_weeks(int $uid): int
{
    return (int)db_val(
        'SELECT COUNT(*) FROM checkins WHERE user_id = ? AND streak_after > 0 AND streak_after % 7 = 0 AND checkin_date >= ?',
        array($uid, checkin_local_month_start())
    );
}

/** 执行签到；返回 array(ok, reason, streak, daily_bonus, week_bonus) */
function checkin_do(int $uid): array
{
    $today = checkin_local_today();
    if (checkin_today_done($uid)) {
        return array('ok' => false, 'reason' => '今天已经签到过了', 'streak' => checkin_streak($uid));
    }

    /* 连击计算：昨天有记录则在其基础上 +1，否则从 1 开始 */
    $yest = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $prev = db_one('SELECT checkin_date, streak_after FROM checkins WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1',
        array($uid));
    $streak = ($prev !== null && (string)$prev['checkin_date'] === $yest) ? ((int)$prev['streak_after'] + 1) : 1;

    /* INSERT IGNORE 兜底并发双击：第二条静默落空，靠 affected 行数判定 */
    $n = db_exec('INSERT IGNORE INTO checkins (user_id, checkin_date, streak_after, created_at) VALUES (?,?,?,UTC_TIMESTAMP())',
        array($uid, $today, $streak));
    if ((int)$n === 0) {
        return array('ok' => false, 'reason' => '今天已经签到过了', 'streak' => checkin_streak($uid));
    }

    $weekHit = ($streak > 0 && $streak % CHECKIN_WEEK_DAYS === 0);
    return array(
        'ok'          => true,
        'streak'      => $streak,
        'daily_bonus' => CHECKIN_DAILY_BONUS,
        'week_bonus'  => $weekHit ? CHECKIN_WEEK_BONUS : 0,
    );
}

/** 动态 AI 限额：基础值 + 签到加成（供 ai_quota_check 与前端展示使用） */
function ai_quota_limits(int $uid): array
{
    $base = cfg('ai_limits', array());
    $day = isset($base['daily_tokens']) ? (int)$base['daily_tokens'] : 100000;
    $mon = isset($base['monthly_tokens']) ? (int)$base['monthly_tokens'] : 3000000;

    if (checkin_today_done($uid)) { $day += CHECKIN_DAILY_BONUS; }
    $mon += checkin_month_weeks($uid) * CHECKIN_WEEK_BONUS;

    return array('daily_tokens' => $day, 'monthly_tokens' => $mon);
}
