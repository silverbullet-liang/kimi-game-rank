<?php
declare(strict_types=1);
/**
 * 运行期哨兵：多维度异常检测与分级处置
 * ------------------------------------------------------------
 * 11·21 那轮试探的形状是：撞 /api/auth、首次撞出 429、摸 /api/admin。
 * 只看「全站每分钟请求数」既拦不住慢速绕行，又容易把正常高峰误判成攻击，
 * 所以这里按维度各自计数，命中只处置「命中的那一小撮」：
 *
 *   单来源请求速率       ≥600/分钟         → 该来源拒绝 10 分钟
 *   单 IP 登录失败       ≥30/10 分钟       → 该 IP 登录拒绝 30 分钟
 *   单设备试过的账号数   ≥8 个/10 分钟     → 该设备登录拒绝 1 小时（撞库最典型）
 *   非管理员撞管理接口   ≥20 次/10 分钟    → 该来源拒绝 1 小时
 *   全站登录失败率       ≥300 次且 ≥80%    → 记一个「登录承压」信号，供熔断判定
 *
 * 全部落在 storage/cache 的小文件上：不查库、不占主机配额，单次 I/O 很小。
 * 阈值刻意留得很宽（正常访客一天也不该撞到），宁可少拦，不可误伤。
 */

function sen_dir(): string
{
    $d = APP_ROOT . '/storage/cache';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

function sen_tag(string $s): string
{
    /* 保留下划线：kind 名字里带 _（auth_ip、admin_probe），
       若一并删掉，写侧文件名与读侧/熔断侧拼出来的名字就对不上了。 */
    $s = preg_replace('/[^a-z0-9_]/i', '', $s);
    return $s === '' ? 'x' : substr($s, 0, 32);
}

/** 当前来源的短哈希（不保存明文 IP） */
function sen_ip_hash(): string
{
    return substr(hash('sha256', (string)cfg('secrets.cron_key', 'kimgr') . '|senip|' . client_ip()), 0, 32);
}

/** 当前设备指纹（与封停/设备管理同一套） */
function sen_dev(): string
{
    return function_exists('discipline_dev_of_request') ? discipline_dev_of_request() : '';
}

/** 计数：当前窗口桶自增，返回自增后的值 */
function sen_bump(string $dim, string $key, int $window = 600): int
{
    $b = (int)floor(time() / $window);
    $f = sen_dir() . '/sen_' . sen_tag($dim) . '_' . sen_tag($key) . '_' . $b . '.txt';
    $n = is_file($f) ? (int)@file_get_contents($f) + 1 : 1;
    @file_put_contents($f, (string)$n, LOCK_EX);
    return $n;
}

/** 窗口内累计（默认当前与上一桶） */
function sen_sum(string $dim, string $key, int $window = 600, int $buckets = 2): int
{
    $b = (int)floor(time() / $window);
    $base = sen_dir() . '/sen_' . sen_tag($dim) . '_' . sen_tag($key) . '_';
    $n = 0;
    for ($i = 0; $i < $buckets; $i++) {
        $f = $base . ($b - $i) . '.txt';
        if (is_file($f)) { $n += (int)@file_get_contents($f); }
    }
    return $n;
}

/** 给某个来源记一个限时处置（顺手写一行日志，事后可查是谁在什么时候被拦） */
function sen_pen(string $kind, string $key, int $ttl)
{
    @file_put_contents(sen_dir() . '/pen_' . sen_tag($kind) . '_' . sen_tag($key) . '.txt',
        (string)(time() + $ttl), LOCK_EX);
    if (function_exists('app_log')) {
        app_log('sentinel: ' . $kind . ' ' . sen_tag($key) . ' -> ' . $ttl . 's');
    }
}

/** 撤掉某个处置（与 sen_pen 用同一套文件名规则） */
function sen_clear(string $kind, string $key)
{
    @unlink(sen_dir() . '/pen_' . sen_tag($kind) . '_' . sen_tag($key) . '.txt');
}

/** 处置剩余秒数（0 表示没有处置或已过期） */
function sen_pen_left(string $kind, string $key): int
{
    $f = sen_dir() . '/pen_' . sen_tag($kind) . '_' . sen_tag($key) . '.txt';
    if (!is_file($f)) { return 0; }
    $exp = (int)@file_get_contents($f);
    if ($exp <= time()) { @unlink($f); return 0; }
    return $exp - time();
}

/** 这台设备在 10 分钟内试过多少个不同账号（撞库判据） */
function sen_dev_accounts(string $dev, string $norm): int
{
    if ($dev === '') { return 0; }
    $f = sen_dir() . '/sen_acct_' . sen_tag(substr($dev, 0, 32)) . '.json';
    $now = time();
    $d = array('at' => $now, 'set' => array());
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && isset($j['at']) && isset($j['set']) && $now - (int)$j['at'] < 600) {
            $d = array('at' => (int)$j['at'], 'set' => array_values((array)$j['set']));
        }
    }
    if ($norm !== '' && !in_array($norm, $d['set'], true)) {
        $d['set'][] = substr($norm, 0, 64);
        if (count($d['set']) > 50) { $d['set'] = array_slice($d['set'], -50); }
        @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return count($d['set']);
}

/** 登录尝试记一笔（无论成败，用于算全站失败率） */
function sen_auth_try()
{
    sen_bump('aut', 'all', 600);
}

/** 登录失败打点，命中阈值即处置；返回本次是否触发了新的处置 */
function sen_auth_fail(string $norm, string $dev = ''): array
{
    $iph = sen_ip_hash();
    $out = array('ip_pen' => 0, 'dev_pen' => 0);

    $n = sen_bump('af', $iph, 600);
    sen_bump('aft', 'all', 600);

    if ($n >= 30) { sen_pen('auth_ip', $iph, 1800); }
    $out['ip_pen'] = sen_pen_left('auth_ip', $iph);

    if ($dev !== '') {
        if (sen_dev_accounts($dev, $norm) >= 8) {
            sen_pen('auth_dev', substr($dev, 0, 32), 3600);
        }
        $out['dev_pen'] = sen_pen_left('auth_dev', substr($dev, 0, 32));
    }

    /* 全站失败率：撞库进行中的整体信号，只做记号，交给熔断与面板参考 */
    $fail = sen_sum('aft', 'all', 600, 6);
    $try  = sen_sum('aut', 'all', 600, 6);
    if ($fail >= 300 && $try > 0 && ($fail / max(1, $try)) >= 0.8) {
        sen_pen('auth_all', 'all', 900);
    }
    return $out;
}

/** 登录成功：把该来源/该设备的失败账清掉，避免「试对一次还被锁」 */
function sen_auth_ok(string $dev = '')
{
    sen_clear('auth_ip', sen_ip_hash());
    if ($dev !== '') { sen_clear('auth_dev', substr($dev, 0, 32)); }
}

/** 登录是否已被处置（供 user_login 入口判定） */
function sen_login_blocked(): array
{
    $iph = sen_ip_hash();
    $dev = sen_dev();
    $ipL = sen_pen_left('auth_ip', $iph);
    $devL = $dev !== '' ? sen_pen_left('auth_dev', substr($dev, 0, 32)) : 0;
    return array('block' => ($ipL > 0 || $devL > 0), 'ip_left' => $ipL, 'dev_left' => $devL);
}

/** 拒绝当前请求（接口回 JSON，页面回一张极简说明） */
function sen_deny(string $msg)
{
    if (function_exists('is_api_request') && is_api_request()) {
        if (function_exists('fail')) { fail(429, $msg); }
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('code' => 429, 'msg' => $msg), JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(429);
    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>请稍后再试</title><body style="font:15px/1.75 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;'
        . 'max-width:520px;margin:18vh auto;padding:0 22px;color:#333">'
        . '<p style="font-size:17px;font-weight:600;margin:0 0 8px">请稍后再试</p>'
        . '<p style="margin:0 0 12px;color:#666">' . $m . '</p>'
        . '<p style="margin:0"><a href="/" style="color:#007AFF;text-decoration:none">返回首页</a></p></body>';
    exit;
}

/** 低频清理：一小时前的计数文件与已过期的处置文件 */
function sen_gc()
{
    $dir = sen_dir();
    $cut = time() - 3600;
    $names = @scandir($dir);
    if (!is_array($names)) { return; }
    foreach ($names as $f) {
        if (!is_string($f) || $f === '' || $f === '.' || $f === '..') { continue; }
        if (strpos($f, 'sen_') !== 0 && strpos($f, 'pen_') !== 0) { continue; }
        $p = $dir . '/' . $f;
        if (@filemtime($p) < $cut) { @unlink($p); }
    }
}

/**
 * 请求级判定：返回拒绝理由，空串表示放行。
 * 与 sen_guard 分开是为了能在 CLI 里单测——sen_guard 在 CLI 下直接放行。
 */
function sen_verdict(): string
{
    $iph = sen_ip_hash();

    /* 单来源请求速率：正常访客一页十几到几十个请求，600 已经是脚本级 */
    $n = sen_bump('rq', $iph, 60);
    if ($n >= 600) { sen_pen('rq', $iph, 600); }
    if (sen_pen_left('rq', $iph) > 0) { return '请求过于频繁，请稍后再试'; }

    /* 管理接口探测：非管理员反复撞同一个入口（11·21 那轮摸过 199 次） */
    $f = basename(isset($_SERVER['SCRIPT_FILENAME']) ? (string)$_SERVER['SCRIPT_FILENAME'] : '');
    if ($f === 'admin.php') {
        $id = function_exists('current_identity') ? current_identity() : null;
        $ok = $id !== null && in_array((string)(isset($id['role']) ? $id['role'] : ''), array('admin', 'subadmin'), true);
        if (!$ok) {
            if (sen_bump('adm', $iph, 600) >= 20) { sen_pen('admin_probe', $iph, 3600); }
            if (sen_pen_left('admin_probe', $iph) > 0) { return '请求被拒绝'; }
        }
    }
    return '';
}

/** 请求级闸门：单来源速率、管理接口探测 */
function sen_guard()
{
    if (PHP_SAPI === 'cli') { return; }
    $msg = sen_verdict();
    if ($msg !== '') { sen_deny($msg); }
    if (random_int(1, 80) === 1) { sen_gc(); }
}
