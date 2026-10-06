<?php
/**
 * API：网页异常监测（Web APM）
 * ------------------------------------------------------------
 * actions:
 *   collect                             上报（免登录、静默、failsafe）
 *   stats | events | alerts | settings  仅总管理员（需面板密钥验证）
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** 面板二次验证（与 admin.php 同口径；副管理员豁免） */
function mon_panel()
{
    $ident = current_identity();
    if ($ident && $ident['role'] === 'subadmin') { return; }
    $t = isset($_SESSION['panel_ok']) ? (int)$_SESSION['panel_ok'] : 0;
    if ($t <= 0 || time() - $t > 3600) { fail(403, '请先在控制面板完成密钥验证'); }
}

$action = param_str('action', 'collect');

/* ==================== 上报（免登录，静默） ==================== */
if ($action === 'collect') {
    try {
        if (setting_get('monitor.enabled', '1') !== '1') { ok(array('n' => 0)); }
        if (!rate_limit('mon_' . substr(ip_hash(client_ip()), 0, 16), 150, 60)) { ok(array('n' => 0)); }

        $p = param('data', null);
        if (!is_array($p)) { $p = req_json(); }
        if (!is_array($p) || empty($p['events']) || !is_array($p['events']) || !table_exists('web_events')) {
            ok(array('n' => 0));
        }

        /* 采样：采样率 < 100 时，低于概率的上报整体丢弃 */
        $sample = max(1, min(100, (int)setting_get('monitor.sample', '100')));
        if ($sample < 100 && random_int(1, 100) > $sample) { ok(array('n' => 0)); }

        $sid     = substr((string)preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($p['sid'] ?? '')), 0, 40);
        $page    = mb_substr(mon_clean((string)($p['page'] ?? '')), 0, 191, 'UTF-8');
        $browser = mb_substr(mon_clean((string)($p['browser'] ?? '')), 0, 32, 'UTF-8');
        $os      = mb_substr(mon_clean((string)($p['os'] ?? '')), 0, 32, 'UTF-8');
        $screen  = mb_substr(mon_clean((string)($p['screen'] ?? '')), 0, 16, 'UTF-8');
        $net     = mb_substr(mon_clean((string)($p['net'] ?? '')), 0, 16, 'UTF-8');
        $uid     = max(0, (int)($p['uid'] ?? 0));
        $ipHash  = ip_hash(client_ip());
        $now     = now_utc();
        $allowed = array('js' => 1, 'api' => 1, 'resource' => 1, 'perf' => 1, 'pv' => 1, 'custom' => 1, 'error' => 1);

        $ins = 0;
        foreach (array_slice($p['events'], 0, 80) as $e) {
            if (!is_array($e)) { continue; }
            $kind = (string)($e['kind'] ?? 'js');
            if (!isset($allowed[$kind])) { $kind = 'js'; }
            $level = (string)($e['level'] ?? 'error');
            if (!in_array($level, array('info', 'warn', 'error'), true)) { $level = 'error'; }
            try {
                db_exec('INSERT INTO web_events (kind, level, name, page, msg, stack, v1, v2, status, browser, os, screen, net, sid, uid, ip_hash, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array(
                        $kind, $level,
                        mb_substr(mon_clean((string)($e['name'] ?? $page)), 0, 191, 'UTF-8'),
                        $page,
                        mb_substr(mon_clean((string)($e['msg'] ?? '')), 0, 255, 'UTF-8'),
                        mb_substr((string)($e['stack'] ?? ''), 0, 2000, 'UTF-8'),
                        (int)($e['v1'] ?? 0), (int)($e['v2'] ?? 0),
                        mb_substr(mon_clean((string)($e['status'] ?? '')), 0, 16, 'UTF-8'),
                        $browser, $os, $screen, $net, $sid, $uid, $ipHash, $now,
                    ));
                $ins++;
            } catch (Throwable $ex) { /* 单条失败不影响其余 */ }
        }
        ok(array('n' => $ins));
    } catch (Throwable $e) {
        app_log('monitor collect: ' . $e->getMessage());
        ok(array('n' => 0));
    }
}

/* ==================== 以下仅总管理员 ==================== */
require_admin();
mon_panel();

/* ---------- 总览统计 ---------- */
if ($action === 'stats') {
    list($from, ) = mon_window(param_str('range', '7d'));

    $pv    = (int)db_val("SELECT COUNT(*) FROM web_events WHERE kind='pv' AND created_at >= ?", array($from));
    $uv    = (int)db_val("SELECT COUNT(DISTINCT sid) FROM web_events WHERE kind='pv' AND sid<>'' AND created_at >= ?", array($from));
    $jsErr = (int)db_val("SELECT COUNT(*) FROM web_events WHERE kind='js' AND created_at >= ?", array($from));
    $apiT  = (int)db_val("SELECT COUNT(*) FROM web_events WHERE kind='api' AND created_at >= ?", array($from));
    $apiF  = (int)db_val("SELECT COUNT(*) FROM web_events WHERE kind='api' AND status='fail' AND created_at >= ?", array($from));
    $apiS  = (int)db_val("SELECT COUNT(*) FROM web_events WHERE kind='api' AND v1>1000 AND created_at >= ?", array($from));
    $loadAvg = (int)round((float)db_val("SELECT AVG(v1) FROM web_events WHERE kind='perf' AND name='load' AND v1>0 AND created_at >= ?", array($from)));

    /* Apdex（对接口耗时取 T=1.2s） */
    $msList = array();
    foreach (db_all("SELECT v1 FROM web_events WHERE kind='api' AND v1>=0 AND created_at >= ? LIMIT 5000", array($from)) as $r) {
        $msList[] = (int)$r['v1'];
    }
    $apdex = mon_apdex($msList, 1200);

    /* 趋势（按天） */
    $trend = db_all("SELECT DATE(created_at) d,
        SUM(kind='pv') pv, SUM(kind='js') js, SUM(kind='api') api,
        SUM(kind='api' AND status='fail') apifail, SUM(kind='api' AND v1>1000) apislow
        FROM web_events WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d ASC", array($from));

    /* TOP */
    $topErr = db_all("SELECT name, msg, COUNT(*) n, COUNT(DISTINCT sid) uv, MAX(created_at) last
        FROM web_events WHERE kind='js' AND created_at >= ?
        GROUP BY name, msg ORDER BY n DESC LIMIT 5", array($from));
    $topSlowApi = db_all("SELECT name, COUNT(*) n, ROUND(AVG(v1)) avg_ms, SUM(status='fail') fails
        FROM web_events WHERE kind='api' AND created_at >= ?
        GROUP BY name ORDER BY avg_ms DESC LIMIT 5", array($from));
    $topSlowPage = db_all("SELECT COALESCE(NULLIF(p.name,''), p.page) name, ROUND(AVG(p.v1)) avg_ms, COUNT(*) n
        FROM web_events p WHERE p.kind='perf' AND p.name='load' AND p.v1>0 AND p.created_at >= ?
        GROUP BY COALESCE(NULLIF(p.name,''), p.page) ORDER BY avg_ms DESC LIMIT 5", array($from));
    $topPv = db_all("SELECT name, COUNT(*) n FROM web_events WHERE kind='pv' AND created_at >= ?
        GROUP BY name ORDER BY n DESC LIMIT 5", array($from));

    /* 分布 */
    $browsers = db_all("SELECT browser name, COUNT(DISTINCT sid) n FROM web_events
        WHERE browser<>'' AND created_at >= ? GROUP BY browser ORDER BY n DESC LIMIT 8", array($from));
    $systems = db_all("SELECT os name, COUNT(DISTINCT sid) n FROM web_events
        WHERE os<>'' AND created_at >= ? GROUP BY os ORDER BY n DESC LIMIT 8", array($from));
    $nets = db_all("SELECT net name, COUNT(DISTINCT sid) n FROM web_events
        WHERE net<>'' AND created_at >= ? GROUP BY net ORDER BY n DESC LIMIT 8", array($from));
    $resFail = db_all("SELECT name, COUNT(*) n, MAX(created_at) last FROM web_events
        WHERE kind='resource' AND created_at >= ? GROUP BY name ORDER BY n DESC LIMIT 10", array($from));

    /* 告警检测（顺手评估阈值） */
    $thErr  = (int)setting_get('monitor.alert_error_rate', '5');
    $thSlow = (int)setting_get('monitor.alert_slow_ratio', '20');
    $errRate  = $pv > 0 ? round($jsErr / $pv * 100, 1) : 0.0;
    $slowRate = $apiT > 0 ? round($apiS / $apiT * 100, 1) : 0.0;
    if ($pv >= 20 && $errRate > $thErr) {
        mon_alert('error_rate', 'error', 'JS 错误率 ' . $errRate . '% 超过阈值 ' . $thErr . '%', (int)$errRate, $thErr);
    }
    if ($apiT >= 20 && $slowRate > $thSlow) {
        mon_alert('slow_api_ratio', 'warn', '慢接口占比 ' . $slowRate . '% 超过阈值 ' . $thSlow . '%', (int)$slowRate, $thSlow);
    }

    ok(array(
        'range' => param_str('range', '7d'),
        'kpi' => array(
            'pv' => $pv, 'uv' => $uv, 'js' => $jsErr,
            'js_rate' => $pv > 0 ? round($jsErr / $pv * 100, 1) : 0,
            'load' => $loadAvg,
            'api_total' => $apiT, 'api_fail' => $apiF, 'api_slow' => $apiS,
            'api_succ' => $apiT > 0 ? round(($apiT - $apiF) / $apiT * 100, 1) : 100,
            'slow_ratio' => $slowRate,
            'apdex' => $apdex,
        ),
        'trend' => $trend,
        'top_error' => $topErr, 'top_slow_api' => $topSlowApi, 'top_slow_page' => $topSlowPage, 'top_pv' => $topPv,
        'browsers' => $browsers, 'systems' => $systems, 'nets' => $nets, 'res_fail' => $resFail,
    ));
}

/* ---------- 事件列表（按 kind） ---------- */
if ($action === 'events') {
    list($from, ) = mon_window(param_str('range', '7d'));
    $kind = param_str('kind', 'js');
    $allowed = array('js', 'api', 'resource', 'perf', 'custom', 'pv', 'error');
    if (!in_array($kind, $allowed, true)) { $kind = 'js'; }
    $size = 30;
    $page = max(1, param_int('page', 1));
    $off  = ($page - 1) * $size;

    $rows = db_all("SELECT id, kind, level, name, page, msg, stack, v1, v2, status, browser, os, screen, net, sid, created_at
        FROM web_events WHERE kind = ? AND created_at >= ? ORDER BY id DESC LIMIT $size OFFSET $off", array($kind, $from));
    $total = (int)db_val("SELECT COUNT(*) FROM web_events WHERE kind = ? AND created_at >= ?", array($kind, $from));

    /* 聚合分组（错误按 名称+消息，接口按 名称） */
    if ($kind === 'api') {
        $groups = db_all("SELECT name, COUNT(*) n, COUNT(DISTINCT sid) uv, ROUND(AVG(v1)) avg_ms,
            SUM(v1>1000) slow, SUM(status='fail') fails, MAX(created_at) last
            FROM web_events WHERE kind='api' AND created_at >= ? GROUP BY name ORDER BY n DESC LIMIT 30", array($from));
    } elseif ($kind === 'perf') {
        $groups = db_all("SELECT name, COUNT(*) n, ROUND(AVG(v1)) avg_ms, MAX(v1) max_ms
            FROM web_events WHERE kind='perf' AND created_at >= ? GROUP BY name ORDER BY n DESC LIMIT 30", array($from));
    } elseif ($kind === 'custom') {
        $groups = db_all("SELECT name, COUNT(*) n, ROUND(AVG(v1)) avg_v, MAX(v1) max_v
            FROM web_events WHERE kind='custom' AND created_at >= ? GROUP BY name ORDER BY n DESC LIMIT 30", array($from));
    } else {
        $groups = db_all("SELECT name, msg, COUNT(*) n, COUNT(DISTINCT sid) uv, MAX(created_at) last
            FROM web_events WHERE kind = ? AND created_at >= ? GROUP BY name, msg ORDER BY n DESC LIMIT 30", array($kind, $from));
    }

    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'level' => (string)$r['level'],
            'name' => mon_clean((string)$r['name']), 'page' => (string)$r['page'],
            'msg' => (string)$r['msg'], 'stack' => (string)$r['stack'],
            'v1' => (int)$r['v1'], 'v2' => (int)$r['v2'], 'status' => (string)$r['status'],
            'browser' => (string)$r['browser'], 'os' => (string)$r['os'],
            'screen' => (string)$r['screen'], 'net' => (string)$r['net'], 'sid' => (string)$r['sid'],
            'time' => to_local((string)$r['created_at']),
        );
    }
    ok(array('kind' => $kind, 'items' => $items, 'groups' => $groups, 'total' => $total,
             'page' => $page, 'has_more' => ($off + count($rows)) < $total));
}

/* ---------- 告警列表 ---------- */
if ($action === 'alerts') {
    $rows = db_all("SELECT id, rule, level, message, value, threshold, acked, created_at FROM web_alerts ORDER BY id DESC LIMIT 50");
    ok(array('items' => array_map(function ($r) {
        return array('id' => (int)$r['id'], 'rule' => (string)$r['rule'], 'level' => (string)$r['level'],
            'message' => (string)$r['message'], 'value' => (int)$r['value'], 'threshold' => (int)$r['threshold'],
            'acked' => (int)$r['acked'] === 1, 'time' => to_local((string)$r['created_at']));
    }, $rows)));
}

/* ---------- 设置 ---------- */
if ($action === 'settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || param('save', '') !== '') {
        csrf_verify();
        setting_set('monitor.enabled', param_str('enabled', '1') === '1' ? '1' : '0');
        setting_set('monitor.sample', (string)max(1, min(100, param_int('sample', 100))));
        setting_set('monitor.keep_days', (string)max(1, min(90, param_int('keep_days', 7))));
        setting_set('monitor.alert_error_rate', (string)max(0, min(100, param_int('alert_error_rate', 5))));
        setting_set('monitor.alert_slow_ratio', (string)max(0, min(100, param_int('alert_slow_ratio', 20))));
        ok(null, '已保存');
    }
    ok(array(
        'enabled' => setting_get('monitor.enabled', '1'),
        'sample' => (int)setting_get('monitor.sample', '100'),
        'keep_days' => (int)setting_get('monitor.keep_days', '7'),
        'alert_error_rate' => (int)setting_get('monitor.alert_error_rate', '5'),
        'alert_slow_ratio' => (int)setting_get('monitor.alert_slow_ratio', '20'),
    ));
}

/* ---------- 确认告警 ---------- */
if ($action === 'alert_ack') {
    csrf_verify();
    $id = param_int('id', 0);
    if ($id > 0) { db_exec('UPDATE web_alerts SET acked = 1 WHERE id = ?', array($id)); }
    ok(null, '已确认');
}

/* ---------- 清理过期明细 ---------- */
if ($action === 'purge') {
    csrf_verify();
    $days = max(1, min(90, (int)setting_get('monitor.keep_days', '7')));
    $n = db_exec('DELETE FROM web_events WHERE created_at < ?', array(gmdate('Y-m-d H:i:s', time() - $days * 86400)));
    ok(array('deleted' => $n), '已清理');
}

fail(400, '未知操作');
