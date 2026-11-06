<?php
declare(strict_types=1);
/**
 * 访问记录与操作审计
 * ------------------------------------------------------------
 *  · access_log_put()：关键操作落库，供后台追溯。
 *  · access_note()：受限入口的访问记录。
 *
 *  两者都必须「永不抛错」——记录本身不能成为新的故障点。
 */

/** 表是否可用（不存在时静默降级） */
function access_ready(string $tbl): bool
{
    static $cache = array();
    if (isset($cache[$tbl])) { return $cache[$tbl]; }
    $ok = false;
    try {
        $ok = function_exists('table_exists') ? (bool)table_exists($tbl) : false;
    } catch (Throwable $e) {
        $ok = false;
    }
    $cache[$tbl] = $ok;
    return $ok;
}

/** 当前操作者（uid + role） */
function access_actor(): array
{
    if (isset($GLOBALS['__ACCESS_ACTOR'])) { return $GLOBALS['__ACCESS_ACTOR']; }
    $uid = 0;
    $role = '';
    try {
        if (function_exists('current_identity')) {
            $id = current_identity();
            if (is_array($id)) {
                $role = isset($id['role']) ? (string)$id['role'] : '';
                $uid = function_exists('actor_uid') ? (int)actor_uid($id) : (int)($id['uid'] ?? 0);
            }
        }
    } catch (Throwable $e) {
    }
    $GLOBALS['__ACCESS_ACTOR'] = array($uid, $role);
    return $GLOBALS['__ACCESS_ACTOR'];
}

/** 请求指纹（不保存明文 IP） */
function access_fp(): array
{
    $ip = '';
    try { $ip = function_exists('client_ip') ? (string)client_ip() : ''; } catch (Throwable $e) {}
    $salt = 'kimgr';
    try { $salt = (string)cfg('secrets.cron_key', 'kimgr'); } catch (Throwable $e) {}
    $hash = $ip === '' ? '' : hash('sha256', $salt . '|' . $ip);
    $masked = $ip;
    if (strlen($ip) > 6) { $masked = substr($ip, 0, 3) . str_repeat('*', 6) . substr($ip, -2); }
    $ua = isset($_SERVER['HTTP_USER_AGENT'])
        ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 160, 'UTF-8') : '';
    return array($hash, $masked, $ua);
}

/**
 * 关键操作审计。
 * @param string $action 动作代号
 * @param string $target 目标
 * @param string $detail 说明（禁止放密钥、密码、令牌）
 * @param string $result ok|fail|deny
 */
function access_log_put(string $action, string $target = '', string $detail = '', string $result = 'ok')
{
    try {
        if (!access_ready('audit_log')) { return; }
        list($uid, $role) = access_actor();
        list($ih, , $ua) = access_fp();
        db_insert(
            'INSERT INTO audit_log (created_at, actor_uid, actor_role, action, target, detail, ip_hash, ua, result)
             VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?)',
            array($uid, mb_substr($role, 0, 16, 'UTF-8'), mb_substr($action, 0, 48, 'UTF-8'),
                  mb_substr($target, 0, 96, 'UTF-8'), mb_substr($detail, 0, 480, 'UTF-8'),
                  $ih, $ua, mb_substr($result, 0, 16, 'UTF-8'))
        );
    } catch (Throwable $e) {
        if (function_exists('app_log')) { app_log('access_log_put failed: ' . $e->getMessage()); }
    }
    if (function_exists('app_log')) {
        app_log('audit ' . $action . ' ' . $result . ' target=' . $target . ' ' . $detail);
    }
}

/**
 * 受限入口的访问记录。
 * @param string $surface 入口代号
 * @param string $note    备注（简短分类）
 */
function access_note(string $surface, string $note = '')
{
    try {
        if (!access_ready('access_log')) { return; }
        list($ih, $masked, $ua) = access_fp();
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
        $path   = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $ref    = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
        $body   = '';
        try {
            $raw = file_get_contents('php://input');
            if (is_string($raw)) { $body = mb_substr($raw, 0, 512, 'UTF-8'); }
        } catch (Throwable $e) {}
        $auth   = isset($_SERVER['HTTP_AUTHORIZATION']) ? strlen((string)$_SERVER['HTTP_AUTHORIZATION']) : 0;
        $cookie = isset($_SERVER['HTTP_COOKIE']) ? 1 : 0;
        db_insert(
            'INSERT INTO access_log (created_at, surface, note, method, path, referer, body, ua,
                                    auth_len, has_cookie, ip_hash, ip_masked)
             VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array(mb_substr($surface, 0, 32, 'UTF-8'), mb_substr($note, 0, 32, 'UTF-8'),
                  mb_substr($method, 0, 8, 'UTF-8'), mb_substr($path, 0, 512, 'UTF-8'),
                  mb_substr($ref, 0, 255, 'UTF-8'), $body, $ua, $auth, $cookie, $ih, $masked)
        );
    } catch (Throwable $e) {
        if (function_exists('app_log')) { app_log('access_note failed: ' . $e->getMessage()); }
    }
    if (function_exists('app_log')) {
        app_log('access ' . $surface . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' .
                substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 200) . ' ' . $note);
    }
}
