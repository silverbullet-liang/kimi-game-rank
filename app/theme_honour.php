<?php
/**
 * 主题征集：三阶荣誉
 * ------------------------------------------------------------
 * 一阶「勇于参与」：全站第一个提交主题的人（管理员与副管理员不参与），授予即永久，全站唯一
 * 二阶「积微成著」：累计 N 套主题通过审核（theme.volume_min，默认 2）
 * 三阶「匠心独运」：累计 M 套通过（theme.mastery_min，默认 3），且其中至少 1 套被标为推荐
 *
 * 只有一阶需要落库（全站唯一，谁先谁得）；二三阶按投稿实时统计，不写表，
 * 因此撤回后自然回落，无需回收逻辑。
 */
declare(strict_types=1);

require_once __DIR__ . '/oid.php';

define('TH_KIND_FIRST', 'first');
define('TH_KIND_VOLUME', 'volume');
define('TH_KIND_MASTERY', 'mastery');

/** 二阶门槛：通过审核的套数（2~9） */
function th_volume_min(): int
{
    $n = (int)cfg('theme.volume_min', 2);
    return $n < 2 ? 2 : ($n > 9 ? 9 : $n);
}

/** 三阶门槛：通过审核的套数（必须高于二阶，3~20） */
function th_mastery_min(): int
{
    $n = (int)cfg('theme.mastery_min', 3);
    $min = th_volume_min() + 1;
    if ($n < $min) { $n = $min; }
    return $n > 20 ? 20 : $n;
}

/** 三阶定义（前端与文档共用同一份措辞） */
function th_catalog(): array
{
    return array(
        array(
            'key'   => TH_KIND_FIRST,
            'name'  => '勇于参与',
            'desc'  => '全站第一个提交主题的人（管理员与副管理员之外）',
        ),
        array(
            'key'   => TH_KIND_VOLUME,
            'name'  => '积微成著',
            'desc'  => '累计 ' . th_volume_min() . ' 套主题通过审核',
        ),
        array(
            'key'   => TH_KIND_MASTERY,
            'name'  => '匠心独运',
            'desc'  => '累计 ' . th_mastery_min() . ' 套通过审核，且其中至少 1 套被标为推荐',
        ),
    );
}

/** 一阶现有得主（没有则为 null） */
function th_first_row()
{
    if (!table_exists('theme_honours')) { return null; }
    $r = db_one("SELECT user_id, username, created_at FROM theme_honours WHERE kind = 'first' LIMIT 1", array());
    return $r ? $r : null;
}

/**
 * 授予一阶：只在「全站还没有」且提交者不是管理员时生效。
 * 并发下靠 uk_kind 唯一索引兜底：两个请求同时进来只会有一条插入成功。
 */
function th_award_first(array $ident): bool
{
    $role = isset($ident['role']) ? (string)$ident['role'] : 'user';
    if ($role !== 'user') { return false; }                 // 管理员与副管理员不参与
    if (!table_exists('theme_honours')) { return false; }
    if (th_first_row() !== null) { return false; }
    try {
        db_insert('INSERT INTO `theme_honours` (oid, user_id, username, kind, created_at) VALUES (?, ?, ?, ?, ?)',
            array(oid_new('theme_honours'), (int)$ident['uid'], (string)(isset($ident['username']) ? $ident['username'] : ''),
                  TH_KIND_FIRST, now_utc()));
        return true;
    } catch (Throwable $e) {
        app_log('th award first skipped: ' . $e->getMessage());
        return false;
    }
}

/** 按用户统计已通过套数与其中的推荐套数（username 取现用名，改名后徽标跟着走） */
function th_stats(): array
{
    if (!table_exists('theme_submissions')) { return array(); }
    $rows = db_all("SELECT s.user_id, MAX(u.username) AS username, COUNT(*) AS approved, SUM(s.featured) AS featured
                    FROM `theme_submissions` s LEFT JOIN `users` u ON u.id = s.user_id
                    WHERE s.status = 'approved' GROUP BY s.user_id", array());
    $out = array();
    foreach ((array)$rows as $r) {
        $uid = (int)$r['user_id'];
        if ($uid <= 0) { continue; }
        $name = (string)$r['username'];
        if ($name === '') { continue; }
        $out[$uid] = array(
            'username' => $name,
            'approved' => (int)$r['approved'],
            'featured' => (int)$r['featured'],
        );
    }
    return $out;
}

/** 某用户当前的荣誉（按一阶 → 三阶排序） */
function th_kinds_of(int $uid, array $stats)
{
    $kinds = array();
    $first = th_first_row();
    if ($first && (int)$first['user_id'] === $uid) { $kinds[] = TH_KIND_FIRST; }
    if (isset($stats[$uid])) {
        $ok = (int)$stats[$uid]['approved'];
        $feat = (int)$stats[$uid]['featured'];
        if ($ok >= th_volume_min()) { $kinds[] = TH_KIND_VOLUME; }
        if ($ok >= th_mastery_min() && $feat >= 1) { $kinds[] = TH_KIND_MASTERY; }
    }
    return $kinds;
}

/**
 * 全站荣誉映射：用户名 → 荣誉列表。
 * 前端只维护一份映射，用户名渲染处按名字查表即可，不必改各接口。
 * 60 秒缓存：一次进入站点只多一次查询。
 */
function th_map(): array
{
    $hit = cache_get('th_map', 60);
    if (is_array($hit)) { return $hit; }

    $map = array();
    $first = th_first_row();
    if ($first && (string)$first['username'] !== '') {
        $map[(string)$first['username']] = array(TH_KIND_FIRST);
    }
    foreach (th_stats() as $uid => $row) {
        $kinds = th_kinds_of((int)$uid, array($uid => $row));
        if (!$kinds) { continue; }
        $name = (string)$row['username'];
        $cur = isset($map[$name]) ? $map[$name] : array();
        foreach ($kinds as $k) {
            if (!in_array($k, $cur, true)) { $cur[] = $k; }
        }
        $map[$name] = $cur;
    }
    cache_set('th_map', $map, 60);
    return $map;
}

/** 荣誉映射缓存失效：审核、撤回、新投稿后立即重算，避免 60 秒内的徽标滞后 */
function th_map_flush()
{
    $f = cache_dir() . '/th_map.json';
    if (is_file($f)) { @unlink($f); }
}

/** 「我的」一处：当前用户已获得与下一阶进度 */
function th_progress(int $uid): array
{
    $stats = th_stats();
    $kinds = th_kinds_of($uid, $stats);
    $row = isset($stats[$uid]) ? $stats[$uid] : array('approved' => 0, 'featured' => 0);
    return array(
        'kinds'    => $kinds,
        'approved' => (int)$row['approved'],
        'featured' => (int)$row['featured'],
        'catalog'  => th_catalog(),
        'first'    => th_first_row() ? true : false,
    );
}
