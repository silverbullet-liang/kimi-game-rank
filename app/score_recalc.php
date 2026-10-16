<?php
/**
 * 评分体系自动重算（v3.43.0）
 * ------------------------------------------------------------
 * 评分体系（权重 / 聚合 / 维度定义）一变，全站作品分数就必须按新算法重算，
 * 不能依赖站长手动点「全部更新」。做法：
 *   · 评分体系带版本号（config/scoring.php 的 version）；
 *   · settings 记录「已应用版本」score_ver 与重算游标 score_recalc_cursor；
 *   · 每次请求抽样推进一小批：
 *       - 有特征快照（works.feat）的作品 → **离线重算**（不联网，瞬间完成）；
 *       - 没有快照的存量作品 → 用社区凭证重新拉取一次（顺带存下快照），
 *         拉不到时退回「抓作品页 + 库内字段」尽力重算。
 *   · 游标走完即把 score_ver 置为当前版本，本轮结束。
 * 于是：每次改评分体系，上线后都会**自动全量重算**；第二次起（已有快照）完全不耗出站额度。
 */
declare(strict_types=1);

function score_recalc_version(): int
{
    $w = score_weights();
    return isset($w['version']) ? (int)$w['version'] : 1;
}

/** 是否需要重算（已应用版本 ≠ 当前评分体系版本） */
function score_recalc_needed(): bool
{
    return (int)setting_get('score_ver', '0') !== score_recalc_version();
}

/** 进度概览（供面板显示） */
function score_recalc_status(): array
{
    $total = 0; $done = 0; $miss = -1;
    try {
        $total = (int)db_val('SELECT COUNT(*) FROM works WHERE is_hidden = 0');
        $cur   = (int)setting_get('score_recalc_cursor', '0');
        if ($cur > 0) { $done = (int)db_val('SELECT COUNT(*) FROM works WHERE is_hidden = 0 AND id <= ?', array($cur)); }
        if (col_ok('works', 'feat')) {
            $miss = (int)db_val("SELECT COUNT(*) FROM works WHERE is_hidden = 0 AND (feat IS NULL OR feat = '')");
        }
    } catch (Throwable $e) { }
    return array(
        'needed'  => score_recalc_needed(),
        'ver'     => score_recalc_version(),
        'applied' => (int)setting_get('score_ver', '0'),
        'total'   => $total,
        'done'    => $done,
        'left'    => max(0, $total - $done),
        'missing' => $miss,          // 尚无特征快照（需联网补齐）的作品数，-1 表示列未就绪
    );
}

/** 把评分写入作品行，并同步峰值与分数曲线 */
function score_recalc_write(int $wid, array $scores)
{
    $now = now_utc();
    $json = json_encode(array(
        'creativity' => $scores['creativity'], 'experience' => $scores['experience'],
        'depth' => $scores['depth'], 'cost' => $scores['cost'],
        'attitude' => $scores['attitude'], 'heat' => $scores['heat'],
    ), JSON_UNESCAPED_UNICODE);
    try {
        db_exec('UPDATE works SET score = ?, total_score = ?, heat_score = ?, rating = ?, updated_at = ? WHERE id = ?',
            array($json, (int)$scores['total'], (int)$scores['heat'], (string)$scores['rating'], $now, $wid));
    } catch (Throwable $e) { app_log('score recalc write fail #' . $wid . ': ' . $e->getMessage()); return; }

    if (col_ok('works', 'peak_score')) {
        try { db_exec('UPDATE works SET peak_score = GREATEST(peak_score, total_score) WHERE id = ?', array($wid)); }
        catch (Throwable $e) { }
    }
    try {
        $peak = (int)db_val('SELECT MAX(total_score) FROM work_score_history WHERE work_id = ?', array($wid));
        if ((int)$scores['total'] > $peak) {
            db_exec('INSERT INTO work_score_history (work_id, total_score, created_at) VALUES (?, ?, ?)',
                array($wid, (int)$scores['total'], $now));
        }
    } catch (Throwable $e) { }
}

/** 离线重算一条：用特征快照 + 库内最新可变量。无快照返回 false */
function score_recalc_row_offline(array $row): bool
{
    $f = features_from_stored($row);
    if (empty($f)) { return false; }
    score_recalc_write((int)$row['id'], compute_scores($f, corpus_stats()));
    return true;
}

/** 由库内字段 + 重新抓取的作品页尽力重算（无社区凭证时用），并补写特征快照 */
function score_recalc_row_refetch(array $row): bool
{
    $url = trim((string)(isset($row['html_url']) ? $row['html_url'] : ''));
    if ($url === '') { return false; }
    $html = fetch_work_html($url);
    if ($html === '') { return false; }
    $code = work_code_blob($html, $url);
    $work = array(
        'title' => (string)$row['title'], 'intro' => (string)$row['intro'], 'body' => '',
        'like_num' => (int)$row['like_num'], 'comment_num' => (int)$row['comment_num'],
        'collect_num' => (int)$row['collect_num'], 'age_hours' => score_recalc_age($row),
    );
    $f = extract_features($work, $html, array(), $code);
    score_recalc_write((int)$row['id'], compute_scores($f, corpus_stats()));
    if (col_ok('works', 'feat')) {
        db_exec('UPDATE works SET feat = ? WHERE id = ?', array(json_encode($f, JSON_UNESCAPED_UNICODE), (int)$row['id']));
    }
    return true;
}

function score_recalc_age(array $row): float
{
    $ts = isset($row['created_at']) ? strtotime((string)$row['created_at']) : false;
    return ($ts !== false) ? max(0.0, (time() - $ts) / 3600.0) : 999.0;
}

/**
 * 推进一小批。返回 array(ran,offline,fetched,skipped,done)。
 * $batch 为该轮处理的作品数。
 */
function score_recalc_tick(int $batch = 3): array
{
    $out = array('ran' => false, 'offline' => 0, 'fetched' => 0, 'skipped' => 0, 'done' => false);
    if (!score_recalc_needed()) { return $out; }
    $batch  = max(1, min(50, $batch));
    $cursor = (int)setting_get('score_recalc_cursor', '0');

    try {
        $rows = db_all('SELECT * FROM works WHERE is_hidden = 0 AND id > ? ORDER BY id ASC LIMIT ' . $batch, array($cursor));
    } catch (Throwable $e) { return $out; }

    if (empty($rows)) {
        setting_set('score_ver', (string)score_recalc_version());
        setting_set('score_recalc_cursor', '0');
        $out['done'] = true;
        return $out;
    }
    $out['ran'] = true;

    /* 社区凭证（有则优先联网补齐，拿到的数据最完整） */
    $token = '';
    try {
        $saved = setting_get('kimi_token', '');
        if ($saved !== '') { $token = aes_decrypt((string)$saved); }
    } catch (Throwable $e) { $token = ''; }

    $lastId = $cursor;
    foreach ($rows as $row) {
        $wid = (int)$row['id']; $lastId = $wid;
        if (!empty($row['feat']) && score_recalc_row_offline($row)) { $out['offline']++; continue; }
        if ($token !== '' && trim((string)$row['community_id']) !== '') {
            try {
                $item = find_work_by_id($token, (string)$row['community_id']);
                work_upsert($item, '', true);
                $out['fetched']++;
                continue;
            } catch (Throwable $e) {
                app_log('score recalc fetch fail #' . $wid . ': ' . $e->getMessage());
            }
        }
        if (score_recalc_row_offline($row)) { $out['offline']++; }
        elseif (score_recalc_row_refetch($row)) { $out['fetched']++; }
        else { $out['skipped']++; }
    }
    setting_set('score_recalc_cursor', (string)$lastId);
    return $out;
}

/** 按最小间隔抽样触发（bootstrap 调用；未到版本变更时零开销） */
function score_recalc_tick_if_due(): array
{
    if (!function_exists('score_recalc_needed') || !score_recalc_needed()) { return array(); }
    $gap = max(15, (int)cfg('feed.recalc_interval', 60));
    $last = cache_get('score_recalc_tick', 86400);
    if (is_array($last) && isset($last['at']) && (time() - (int)$last['at']) < $gap) { return array(); }
    cache_set('score_recalc_tick', array('at' => time()), 86400);
    return score_recalc_tick(max(1, (int)cfg('feed.recalc_batch', 3)));
}
