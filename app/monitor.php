<?php
/**
 * 网页异常监测（Web APM）：脱敏、时间窗口与告警辅助
 * ------------------------------------------------------------
 * 供 api/monitor.php 与面板复用。纯函数，不依赖 DB。
 */
declare(strict_types=1);

/** 上报文本脱敏：去控制字符、邮箱/手机号/敏感参数打码 */
function mon_clean(string $s): string
{
    $s = str_replace(array("\r", "\n", "\t"), ' ', $s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    $s = (string)preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $s);
    $s = (string)preg_replace('/(?<!\d)1[3-9]\d{9}(?!\d)/', '[phone]', $s);
    $s = (string)preg_replace('/((?:token|key|sign|password|pwd|secret|auth)=)[A-Za-z0-9._\-]+/i', '$1[redacted]', $s);
    return trim($s);
}

/** 时间范围键 → array(起始 UTC 时刻字符串, 小时数) */
function mon_window(string $range): array
{
    $map = array('1h' => 1, '6h' => 6, '24h' => 24, '7d' => 168, '30d' => 720);
    $h = isset($map[$range]) ? $map[$range] : 168;
    return array(gmdate('Y-m-d H:i:s', time() - $h * 3600), $h);
}

/**
 * 记录一条告警（同规则 1 小时内只记一次，避免刷屏）。
 * 返回 true 表示本次新写入。
 */
function mon_alert(string $rule, string $level, string $message, int $value, int $threshold): bool
{
    try {
        if (!table_exists('web_alerts')) { return false; }
        $recent = db_val('SELECT id FROM web_alerts WHERE rule = ? AND created_at >= ? LIMIT 1',
            array($rule, gmdate('Y-m-d H:i:s', time() - 3600)));
        if ($recent !== null) { return false; }
        db_insert('INSERT INTO web_alerts (rule, level, message, value, threshold, acked, created_at) VALUES (?,?,?,?,?,0,?)',
            array($rule, $level, $message, $value, $threshold, now_utc()));
        return true;
    } catch (Throwable $e) {
        app_log('mon_alert failed: ' . $e->getMessage());
        return false;
    }
}

/** 计算 Apdex（T 毫秒阈值）：满意数 + 容忍数/2 占总数的比例 */
function mon_apdex(array $msList, int $t = 1200): float
{
    $n = count($msList);
    if ($n === 0) { return 1.0; }
    $sat = 0; $tol = 0;
    foreach ($msList as $v) {
        $v = (int)$v;
        if ($v <= $t) { $sat++; }
        elseif ($v <= $t * 4) { $tol++; }
    }
    return round(($sat + $tol / 2) / $n, 3);
}

/**
 * 明细清理：删除超过保留天数的 web_events。
 * 共享主机没有 cron，由 storage_gc 抽样触发（见 app/helpers.php）。
 * 返回删除行数；表不存在或失败返回 0。
 */
function mon_cleanup(): int
{
    try {
        $days = max(1, min(90, (int)setting_get('monitor.keep_days', '7')));
        $cut  = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $n = 0;
        if (table_exists('web_events')) { $n += (int)db_exec('DELETE FROM web_events WHERE created_at < ?', array($cut)); }
        if (table_exists('web_srv'))    { $n += (int)db_exec('DELETE FROM web_srv WHERE created_at < ?', array($cut)); }
        return $n;
    } catch (Throwable $e) {
        app_log('mon_cleanup failed: ' . $e->getMessage());
        return 0;
    }
}
