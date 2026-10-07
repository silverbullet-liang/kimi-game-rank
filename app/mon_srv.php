<?php
/**
 * 服务端请求指标采集（Web APM · P2）
 * ------------------------------------------------------------
 * 采集：每个被抽样 PHP 请求的 总耗时 / DB 累计耗时与次数 /
 *       慢查询条数与最慢语句指纹 / 峰值内存 / 首个错误。
 * 落库：web_srv（迁移 v25）。
 * 约束：
 *   1) 采样未命中时，所有钩子在第一次判断就返回，几乎零开销；
 *   2) 采集自身绝不进入统计（落库前先关闭开关），也绝不影响主流程
 *      （全程 try/catch + 静默）；
 *   3) 不采集监测接口自身（api/monitor.php），避免自激。
 */
declare(strict_types=1);

/** 归一化路由：/api/works.php → api/works.php */
function mon_srv_route(): string
{
    $s = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    if ($s === '') {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $s = (string)parse_url($uri, PHP_URL_PATH);
    }
    $s = ltrim(str_replace('\\', '/', $s), '/');
    return $s === '' ? 'unknown' : mb_substr($s, 0, 191, 'UTF-8');
}

/** SQL 指纹：抹掉字面量与多余空白，便于把同类语句聚到一起 */
function mon_srv_sql_print(string $sql): string
{
    $s = (string)preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql);
    $s = (string)preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $s);
    $s = (string)preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $s);
    $s = (string)preg_replace('/\s+/', ' ', $s);
    return mb_substr(trim($s), 0, 191, 'UTF-8');
}

/** 采集配置（一次取回 monitor.* 全部键，进程内缓存） */
function mon_srv_cfg(): array
{
    static $cfg = null;
    if (is_array($cfg)) { return $cfg; }
    $cfg = function_exists('mon_defaults') ? mon_defaults() : array(
        'enabled' => '1', 'srv_sample' => '30', 'srv_slow_ms' => '200',
        'srv_slow_alert_ms' => '3000', 'keep_days' => '7', 'apdex_t' => '1200', 'track_urls' => '',
    );
    try {
        foreach (db_all("SELECT k, v FROM settings WHERE k LIKE 'monitor.%'") as $r) {
            $k = substr((string)$r['k'], 8);
            if (array_key_exists($k, $cfg)) { $cfg[$k] = (string)$r['v']; }
        }
    } catch (Throwable $e) { /* settings 尚未建好 → 用默认值 */ }
    return $cfg;
}

/** 路由是否值得采集（监测接口自身除外，避免自激） */
function mon_srv_route_ok(string $route): bool
{
    return $route !== '' && strpos($route, 'monitor.php') === false;
}

/** 是否采集本请求 */
function mon_srv_should(): bool
{
    if (PHP_SAPI === 'cli') { return false; }
    return mon_srv_route_ok(mon_srv_route());
}

/** 启动采集：bootstrap 在迁移完成后调用 */
function mon_srv_init()
{
    try {
        if (!mon_srv_should()) { return; }
        $cfg = mon_srv_cfg();
        if ($cfg['enabled'] !== '1') { return; }
        $sample = max(1, min(100, (int)$cfg['srv_sample']));
        if ($sample < 100 && random_int(1, 100) > $sample) { return; }

        $GLOBALS['__MON_SRV'] = array(
            'on'       => true,
            't0'       => microtime(true),
            'db_ms'    => 0.0,
            'db_n'     => 0,
            'slow_n'   => 0,
            'slow_ms'  => 0.0,
            'slow_sql' => '',
            'err'      => '',
            'slow_th'  => max(1, (int)$cfg['srv_slow_ms']),
        );
        register_shutdown_function('mon_srv_flush');
    } catch (Throwable $e) { /* 静默 */ }
}

/** DB 查询钩子：由 app/db.php 的查询函数调用 */
function mon_srv_db(string $sql, float $ms)
{
    if (empty($GLOBALS['__MON_SRV']['on'])) { return; }
    $GLOBALS['__MON_SRV']['db_ms'] += $ms;
    $GLOBALS['__MON_SRV']['db_n']++;
    if ($ms >= $GLOBALS['__MON_SRV']['slow_th']) {
        $GLOBALS['__MON_SRV']['slow_n']++;
        if ($ms > $GLOBALS['__MON_SRV']['slow_ms']) {
            $GLOBALS['__MON_SRV']['slow_ms']  = $ms;
            $GLOBALS['__MON_SRV']['slow_sql'] = mon_srv_sql_print($sql);
        }
    }
}

/** 记录首个错误（业务代码可主动上报；fatal 由 flush 兜底） */
function mon_srv_note_err(string $msg)
{
    if (empty($GLOBALS['__MON_SRV']['on'])) { return; }
    if ($GLOBALS['__MON_SRV']['err'] !== '') { return; }
    $GLOBALS['__MON_SRV']['err'] = mb_substr(mon_clean($msg), 0, 255, 'UTF-8');
}

/** 请求结束落库 + 阈值告警（shutdown 时执行） */
function mon_srv_flush()
{
    if (empty($GLOBALS['__MON_SRV']['on'])) { return; }
    $s = $GLOBALS['__MON_SRV'];
    $GLOBALS['__MON_SRV']['on'] = false;   // 先关开关：落库自身不计入

    $dur = (int)round((microtime(true) - (float)$s['t0']) * 1000);
    $err = (string)$s['err'];
    if ($err === '') {
        $e = error_get_last();
        if (is_array($e) && in_array((int)$e['type'],
                array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_WARNING, E_NOTICE), true)) {
            $err = mb_substr(mon_clean((string)$e['message']), 0, 255, 'UTF-8');
        }
    }
    $code = (int)http_response_code();
    if ($code <= 0) { $code = 200; }
    $mem = (int)round(memory_get_peak_usage(true) / 1024);

    try {
        if (!table_exists('web_srv')) { return; }
        db_insert('INSERT INTO web_srv (route, method, code, dur_ms, db_ms, db_n, slow_n, slow_sql, mem_kb, err, uid, sid, ip_hash, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array(
            mon_srv_route(),
            isset($_SERVER['REQUEST_METHOD']) ? mb_substr((string)$_SERVER['REQUEST_METHOD'], 0, 8, 'UTF-8') : 'GET',
            $code, $dur,
            (int)round((float)$s['db_ms']), (int)$s['db_n'],
            (int)$s['slow_n'], (string)$s['slow_sql'],
            $mem, $err, 0, '', ip_hash(client_ip()), now_utc(),
        ));
        mon_srv_alert($dur, $code);
    } catch (Throwable $ex) { /* 采集失败绝不外泄 */ }
}

/** URL 跟踪白名单（重点接口，单独关注成功率） */
function mon_srv_track_urls(): array
{
    $cfg = mon_srv_cfg();
    $out = array();
    foreach (explode(',', (string)$cfg['track_urls']) as $u) {
        $u = trim($u);
        if ($u !== '') { $out[] = $u; }
    }
    return array_slice(array_values(array_unique($out)), 0, 20);
}

/** 阈值告警：单请求过慢 / 5xx（mon_alert 自带 1 小时去重） */
function mon_srv_alert(int $dur, int $code)
{
    $cfg = mon_srv_cfg();
    $th  = max(1, (int)$cfg['srv_slow_alert_ms']);
    if ($dur >= $th) {
        mon_alert('srv_slow_req', 'warn',
            mon_srv_route() . ' 耗时 ' . $dur . ' ms，超过阈值 ' . $th . ' ms', $dur, $th);
    }
    if ($code >= 500) {
        mon_alert('srv_5xx', 'error', mon_srv_route() . ' 返回 HTTP ' . $code, $code, 500);
    }
}
