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

/* ==================== 以下仅总管理员（独立页面，无需面板二次验证） ==================== */
require_admin();

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
    $screens = db_all("SELECT screen name, COUNT(DISTINCT sid) n FROM web_events
        WHERE screen<>'' AND created_at >= ? GROUP BY screen ORDER BY n DESC LIMIT 8", array($from));
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
        'screens' => $screens,
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

/* ---------- 会话追踪：会话列表 ---------- */
if ($action === 'sessions') {
    list($from, ) = mon_window(param_str('range', '7d'));
    $only = param_str('only', 'all') === 'bad';
    $having = $only ? ' HAVING bad > 0' : '';
    $rows = db_all("SELECT sid,
            MIN(created_at) first_t, MAX(created_at) last_t,
            COUNT(*) total,
            SUM(kind='pv') pv,
            SUM(kind='js') js,
            SUM(kind='api') api,
            SUM(kind='api' AND status='fail') apifail,
            SUM(kind='api' AND v1>1000) apislow,
            MAX(CASE WHEN kind='perf' AND name='load' AND v1>0 THEN v1 ELSE 0 END) load_ms,
            MAX(browser) browser, MAX(os) os, MAX(screen) screen, MAX(net) net, MAX(uid) uid,
            (SUM(kind='js') + SUM(kind='api' AND status='fail') + SUM(kind='api' AND v1>1000)) bad
        FROM web_events WHERE sid<>'' AND created_at >= ?
        GROUP BY sid" . $having . " ORDER BY last_t DESC LIMIT 60", array($from));
    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'sid' => (string)$r['sid'],
            'first' => to_local((string)$r['first_t']),
            'last' => to_local((string)$r['last_t']),
            'dur' => max(0, (int)strtotime((string)$r['last_t'] . ' UTC') - (int)strtotime((string)$r['first_t'] . ' UTC')),
            'total' => (int)$r['total'], 'pv' => (int)$r['pv'], 'js' => (int)$r['js'],
            'api' => (int)$r['api'], 'apifail' => (int)$r['apifail'], 'apislow' => (int)$r['apislow'],
            'bad' => (int)$r['bad'], 'load' => (int)$r['load_ms'],
            'browser' => (string)$r['browser'], 'os' => (string)$r['os'],
            'screen' => (string)$r['screen'], 'net' => (string)$r['net'], 'uid' => (int)$r['uid'],
        );
    }
    ok(array('range' => param_str('range', '7d'), 'only' => $only ? 'bad' : 'all', 'items' => $items));
}

/* ---------- 会话追踪：单会话时间线 ---------- */
if ($action === 'session') {
    list($from, ) = mon_window(param_str('range', '7d'));
    $sid = substr((string)preg_replace('/[^A-Za-z0-9_\-]/', '', param_str('sid', '')), 0, 40);
    if ($sid === '') { fail(400, '参数错误'); }
    $rows = db_all("SELECT id, kind, level, name, page, msg, v1, v2, status, browser, os, screen, net, created_at
        FROM web_events WHERE sid = ? AND created_at >= ? ORDER BY id ASC LIMIT 300", array($sid, $from));
    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'level' => (string)$r['level'],
            'name' => mon_clean((string)$r['name']), 'msg' => (string)$r['msg'], 'page' => (string)$r['page'],
            'v1' => (int)$r['v1'], 'v2' => (int)$r['v2'], 'status' => (string)$r['status'],
            'time' => to_local((string)$r['created_at']),
        );
    }
    ok(array('sid' => $sid, 'items' => $items));
}

/* ---------- 服务端请求指标（P2） ---------- */
if ($action === 'srv') {
    list($from, ) = mon_window(param_str('range', '7d'));
    $t  = max(100, (int)setting_get('monitor.apdex_t', '1200'));      // Apdex 基线（ms）
    $th = max(1, (int)setting_get('monitor.srv_slow_ms', '200'));     // 慢查询阈值（ms）

    $n     = (int)db_val('SELECT COUNT(*) FROM web_srv WHERE created_at >= ?', array($from));
    $avg   = (int)round((float)db_val('SELECT AVG(dur_ms) FROM web_srv WHERE created_at >= ?', array($from)));
    $mx    = (int)db_val('SELECT MAX(dur_ms) FROM web_srv WHERE created_at >= ?', array($from));
    $slow  = (int)db_val('SELECT COUNT(*) FROM web_srv WHERE dur_ms > ? AND created_at >= ?', array($t, $from));
    $code5 = (int)db_val('SELECT COUNT(*) FROM web_srv WHERE code >= 500 AND created_at >= ?', array($from));
    $errN  = (int)db_val('SELECT COUNT(*) FROM web_srv WHERE err <> \'\' AND created_at >= ?', array($from));
    $dbAvg = (int)round((float)db_val('SELECT AVG(db_ms) FROM web_srv WHERE created_at >= ?', array($from)));
    $dbN   = (int)round((float)db_val('SELECT AVG(db_n) FROM web_srv WHERE created_at >= ?', array($from)));
    $slowQ = (int)db_val('SELECT COALESCE(SUM(slow_n),0) FROM web_srv WHERE created_at >= ?', array($from));

    /* P95（按耗时升序取第 95 百分位） */
    $p95 = 0;
    if ($n > 0) {
        $off = (int)floor($n * 0.95);
        if ($off >= $n) { $off = $n - 1; }
        $p95 = (int)db_val('SELECT dur_ms FROM web_srv WHERE created_at >= ? ORDER BY dur_ms ASC LIMIT 1 OFFSET ' . $off, array($from));
    }

    /* Apdex：满意(≤T) 与 容忍(≤4T) 各占权重 */
    $sat = (int)db_val('SELECT COUNT(*) FROM web_srv WHERE dur_ms <= ? AND created_at >= ?', array($t, $from));
    $tol = (int)db_val('SELECT COUNT(*) FROM web_srv WHERE dur_ms > ? AND dur_ms <= ? AND created_at >= ?', array($t, $t * 4, $from));
    $apdex = $n > 0 ? round(($sat + $tol / 2) / $n, 3) : 1.0;

    /* 按路由聚合（URL 跟踪） */
    $routes = db_all('SELECT route, COUNT(*) n, ROUND(AVG(dur_ms)) avg_ms, MAX(dur_ms) max_ms,
        SUM(dur_ms > ?) slow, SUM(code >= 500) code5, COALESCE(SUM(slow_n),0) slow_q,
        COALESCE(SUM(err <> \'\'),0) errs
        FROM web_srv WHERE created_at >= ? GROUP BY route ORDER BY n DESC LIMIT 30', array($t, $from));

    /* 慢查询 TOP（按语句指纹聚合） */
    $slowTop = db_all('SELECT slow_sql, SUM(slow_n) n, COUNT(*) reqs, MAX(dur_ms) max_ms
        FROM web_srv WHERE slow_n > 0 AND slow_sql <> \'\' AND created_at >= ?
        GROUP BY slow_sql ORDER BY n DESC LIMIT 10', array($from));

    /* 异常 TOP */
    $errs = db_all('SELECT route, err, COUNT(*) n, MAX(created_at) last
        FROM web_srv WHERE err <> \'\' AND created_at >= ?
        GROUP BY route, err ORDER BY n DESC LIMIT 10', array($from));

    /* 趋势：按天 */
    $trend = db_all('SELECT DATE(created_at) d, COUNT(*) n, ROUND(AVG(dur_ms)) avg_ms,
        SUM(dur_ms > ?) slow, SUM(code >= 500) code5
        FROM web_srv WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d ASC', array($t, $from));

    /* 告警：单请求过慢 / 5xx 由采集端记录；这里补「重点接口成功率」与「慢请求占比」 */
    $track = mon_srv_track_urls();
    if ($track) {
        $in = implode(',', array_fill(0, count($track), '?'));
        $tw = db_all('SELECT route, COUNT(*) n, SUM(code >= 400) bad
            FROM web_srv WHERE created_at >= ? AND route IN (' . $in . ') GROUP BY route',
            array_merge(array($from), $track));
        foreach ($tw as $r) {
            $tn = (int)$r['n'];
            if ($tn < 10) { continue; }
            $sr = round(($tn - (int)$r['bad']) / $tn * 100, 1);
            if ($sr < 95) {
                mon_alert('track_succ_' . substr(md5((string)$r['route']), 0, 6), 'warn',
                    $r['route'] . ' 成功率 ' . $sr . '%（低于 95%）', (int)$sr, 95);
            }
        }
    }
    $slowRatio = $n > 0 ? round($slow / $n * 100, 1) : 0.0;
    $thSlow = (int)setting_get('monitor.alert_slow_ratio', '20');
    if ($n >= 20 && $slowRatio > $thSlow) {
        mon_alert('srv_slow_ratio', 'warn', '服务端慢请求占比 ' . $slowRatio . '% 超过阈值 ' . $thSlow . '%', (int)$slowRatio, $thSlow);
    }

    ok(array(
        'range' => param_str('range', '7d'), 'apdex_t' => $t, 'slow_ms' => $th, 'track_urls' => $track,
        'kpi' => array(
            'n' => $n, 'avg' => $avg, 'p95' => $p95, 'max' => $mx,
            'slow' => $slow, 'slow_ratio' => $slowRatio,
            'code5' => $code5, 'errs' => $errN, 'db_avg' => $dbAvg, 'db_n' => $dbN,
            'slow_q' => $slowQ, 'apdex' => $apdex,
        ),
        'trend' => $trend, 'routes' => $routes, 'slow_top' => $slowTop, 'errors' => $errs,
    ));
}

/* ---------- 服务端请求明细 ---------- */
if ($action === 'srvlist') {
    list($from, ) = mon_window(param_str('range', '7d'));
    $route = mb_substr(mon_clean(param_str('route', '')), 0, 191, 'UTF-8');
    $size = 30;
    $page = max(1, param_int('page', 1));
    $off  = ($page - 1) * $size;
    $w = ''; $args = array($from);
    if ($route !== '') { $w = ' AND route = ?'; $args[] = $route; }

    $rows = db_all('SELECT id, route, method, code, dur_ms, db_ms, db_n, slow_n, slow_sql, mem_kb, err, created_at
        FROM web_srv WHERE created_at >= ?' . $w . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $off, $args);
    $total = (int)db_val('SELECT COUNT(*) FROM web_srv WHERE created_at >= ?' . $w, $args);
    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'id' => (int)$r['id'], 'route' => (string)$r['route'], 'method' => (string)$r['method'],
            'code' => (int)$r['code'], 'dur' => (int)$r['dur_ms'], 'db' => (int)$r['db_ms'],
            'db_n' => (int)$r['db_n'], 'slow_n' => (int)$r['slow_n'], 'slow_sql' => (string)$r['slow_sql'],
            'mem' => (int)$r['mem_kb'], 'err' => (string)$r['err'],
            'time' => to_local((string)$r['created_at']),
        );
    }
    ok(array('route' => $route, 'items' => $items, 'total' => $total, 'page' => $page,
             'has_more' => ($off + count($rows)) < $total));
}

/* ---------- 告警列表 ---------- */
if ($action === 'alerts') {
    $rows = db_all("SELECT id, rule, level, message, value, threshold, acked, created_at FROM web_alerts ORDER BY id DESC LIMIT 50");
    $unacked = (int)db_val("SELECT COUNT(*) FROM web_alerts WHERE acked = 0");
    ok(array('unacked' => $unacked, 'items' => array_map(function ($r) {
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
        setting_set('monitor.srv_sample', (string)max(1, min(100, param_int('srv_sample', 30))));
        setting_set('monitor.srv_slow_ms', (string)max(1, min(60000, param_int('srv_slow_ms', 200))));
        setting_set('monitor.srv_slow_alert_ms', (string)max(100, min(60000, param_int('srv_slow_alert_ms', 3000))));
        setting_set('monitor.apdex_t', (string)max(100, min(10000, param_int('apdex_t', 1200))));
        setting_set('monitor.track_urls', mb_substr((string)preg_replace('/[^A-Za-z0-9_,.\/\-]/', '', (string)param('track_urls', '')), 0, 500, 'UTF-8'));
        ok(null, '已保存');
    }
    ok(array(
        'enabled' => setting_get('monitor.enabled', '1'),
        'sample' => (int)setting_get('monitor.sample', '100'),
        'keep_days' => (int)setting_get('monitor.keep_days', '7'),
        'alert_error_rate' => (int)setting_get('monitor.alert_error_rate', '5'),
        'alert_slow_ratio' => (int)setting_get('monitor.alert_slow_ratio', '20'),
        'srv_sample' => (int)setting_get('monitor.srv_sample', '30'),
        'srv_slow_ms' => (int)setting_get('monitor.srv_slow_ms', '200'),
        'srv_slow_alert_ms' => (int)setting_get('monitor.srv_slow_alert_ms', '3000'),
        'apdex_t' => (int)setting_get('monitor.apdex_t', '1200'),
        'track_urls' => (string)setting_get('monitor.track_urls', ''),
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
    ok(array('deleted' => mon_cleanup()), '已清理');
}

fail(400, '未知操作');
