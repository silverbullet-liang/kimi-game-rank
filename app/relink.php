<?php
/**
 * 跳转链接自动重识别（v3.41.0）
 * ------------------------------------------------------------
 * 识别逻辑升级后，此前入库的作品可能仍指向一张「跳转页」。这里让已上线作品
 * 重新走一遍识别：取出 → 按当前逻辑判断 → 命中真实地址就替换（标题一并纠正）。
 *
 * 触发方式（共享主机没有可靠 cron，故三处并存）：
 *   · 按请求抽样   works_relink_tick()，见 app/bootstrap.php
 *   · 定时任务     cron.php?task=relink
 *   · 控制面板按钮 api/admin.php 动作 relink
 * 全部受同一出站预算约束（feed.fetch_max_per_hour），超限即停，绝不报错。
 */
declare(strict_types=1);

/**
 * 待重识别扫描。
 * @param int $limit 本轮最多处理的作品数
 * @param int $days  距上次检查超过该天数才复查
 * @return array{scanned:int,changed:int,skipped:int}
 */
function works_relink_scan(int $limit = 3, int $days = 7): array
{
    $out = array('scanned' => 0, 'changed' => 0, 'skipped' => 0);
    if (!function_exists('smart_link_resolve') || !smart_link_enabled()) { return $out; }
    if (!col_ok('works', 'link_checked_at')) { return $out; }   // 迁移未就绪则不动

    $limit  = max(1, min(50, $limit));
    $days   = max(1, $days);
    $before = gmdate('Y-m-d H:i:s', time() - $days * 86400);

    /* 未检查过的优先，其次检查时间最早的；多取一些用于跳过「已是作品宿主」的行 */
    $rows = db_all(
        'SELECT id, html_url FROM works WHERE html_url <> \'\''
        . ' AND (link_checked_at IS NULL OR link_checked_at < ?)'
        . ' ORDER BY (link_checked_at IS NULL) DESC, link_checked_at ASC, id ASC LIMIT ' . ($limit * 8),
        array($before)
    );

    foreach ($rows as $r) {
        if ($out['scanned'] >= $limit) { break; }
        $wid = (int)$r['id'];
        $url = trim((string)$r['html_url']);
        if ($url === '') { continue; }

        /* 来源本身就是作品宿主（github.io / vercel.app / 各 AI 平台作者子域…）→ 永不替换 */
        if (smart_link_host_trusted($url)) { works_relink_mark($wid); continue; }

        /* 出站预算用尽：本轮停止，且不标记，留待下次 */
        if (!net_budget_allow('work_html', (int)cfg('feed.fetch_max_per_hour', 150), 3600)) { break; }

        $html = fetch_work_html($url);
        if ($html === '') { works_relink_mark($wid); $out['skipped']++; $out['scanned']++; continue; }

        $real = smart_link_resolve($html, $url);
        if ($real !== '' && smart_link_norm($real) !== smart_link_norm($url)) {
            $realHtml = fetch_work_html($real);
            works_relink_apply($wid, $real, $realHtml !== '' ? html_title($realHtml) : '');
            $out['changed']++;
        }
        works_relink_mark($wid);
        $out['scanned']++;
    }
    return $out;
}

/** 标记「已检查」（无论是否替换），使其退出待办队列 */
function works_relink_mark(int $wid)
{
    try { db_exec('UPDATE works SET link_checked_at = ? WHERE id = ?', array(now_utc(), $wid)); }
    catch (Throwable $e) { /* 忽略 */ }
}

/** 替换为真实地址（标题非空才覆盖，避免把已有好标题清掉） */
function works_relink_apply(int $wid, string $real, string $title)
{
    try {
        $now = now_utc();
        if ($title !== '') {
            db_exec('UPDATE works SET title = ?, html_url = ?, has_html = 1, link_checked_at = ?, updated_at = ? WHERE id = ?',
                array($title, $real, $now, $now, $wid));
        } else {
            db_exec('UPDATE works SET html_url = ?, has_html = 1, link_checked_at = ?, updated_at = ? WHERE id = ?',
                array($real, $now, $now, $wid));
        }
    } catch (Throwable $e) { app_log('relink apply fail: ' . $e->getMessage()); }
}

/**
 * 抽样触发：由 bootstrap 在部分请求上调用。
 * 以 $feed.relink_interval 秒为最小间隔（cache 锁），每次最多处理 $feed.relink_batch 条，
 * 避免拖慢单个请求；整体节奏由 $feed.relink_days 控制复查周期。
 */
function works_relink_tick(): array
{
    if (!function_exists('smart_link_enabled') || !smart_link_enabled()) { return array(); }
    $gap = max(60, (int)cfg('feed.relink_interval', 600));
    $last = cache_get('relink_tick', 86400);
    if (is_array($last) && isset($last['at']) && (time() - (int)$last['at']) < $gap) { return array(); }
    cache_set('relink_tick', array('at' => time()), 86400);
    return works_relink_scan(max(1, (int)cfg('feed.relink_batch', 1)), (int)cfg('feed.relink_days', 7));
}
