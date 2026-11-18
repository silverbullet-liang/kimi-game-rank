<?php
/**
 * Cloudflare 安全盾（源站保护）
 * ------------------------------------------------------------
 * 目的：让源站只接受经过 Cloudflare 的请求，直连源站一律拒绝。
 *
 * 为什么不是「校验 CF-Connecting-IP 头」就够：
 *   那个头客户端可以自己发。真正的凭证必须是「只有 CF 知道、客户端猜不到」的东西，
 *   也就是在 CF 侧用 Transform Rule / Worker 注入一个私有头（随机长串），
 *   源站只认这个头。客户端直连时没有它，一秒钟就被挡在门外。
 *
 * 配置（config/config.php 的 'cf_shield' 段）：
 *   'enabled' => true            总开关，false 时本模块什么都不做
 *   'hosts'   => array('域名')   只在这些 Host 上生效；空数组=全部生效
 *                                （主站填自有域名、镜像站留空即为关闭）
 *   'secret'  => '随机长串'      与 CF 侧注入的头值一致；空=未配置，模块自动失效
 *   'header'  => 'HTTP_X_ORIGIN_AUTH'  私有头名（$_SERVER 形式）
 *   'mode'    => 'enforce'       enforce=拦截 / observe=只记录不拦
 *   'deny'    => 'suspend'    拒绝方式，默认 suspend：302 到 https://suspended-domain.net/
 *                                （与主机商暂停账户时的表现一致）
 *                                errors404 = 302 到 errors.infinityfree.net/errors/404/
 *                                http404   = 本地直接回 404（不跳转）
 *   'allow'   => array(...)      放行的路径前缀（定时任务、互通端点等）
 *
 * 校验通过后，会把 CF-Connecting-IP 记为客户端真实 IP（供 client_ip() 采用）。
 */
declare(strict_types=1);

define('CF_SH_DEFAULT_HEADER', 'HTTP_X_ORIGIN_AUTH');
define('CF_SH_LOG', APP_ROOT . '/storage/logs/cf_shield.log');

/** 归一化配置，缺项一律给安全默认值 */
function cf_shield_settings(array $conf): array
{
    $hosts = array();
    if (isset($conf['hosts']) && is_array($conf['hosts'])) {
        foreach ($conf['hosts'] as $h) {
            $h = strtolower(trim((string)$h));
            $h = preg_replace('/:\d+$/', '', $h);
            if ($h !== '') { $hosts[] = $h; }
        }
    }
    $allow = array();
    if (isset($conf['allow']) && is_array($conf['allow'])) {
        foreach ($conf['allow'] as $a) {
            $a = trim((string)$a);
            if ($a !== '') { $allow[] = $a; }
        }
    }
    $mode = isset($conf['mode']) ? strtolower(trim((string)$conf['mode'])) : 'enforce';
    $deny = isset($conf['deny']) ? strtolower(trim((string)$conf['deny'])) : 'suspend';
    $header = isset($conf['header']) ? trim((string)$conf['header']) : '';
    $header = $header === '' ? CF_SH_DEFAULT_HEADER : strtoupper($header);

    return array(
        'enabled' => !empty($conf['enabled']),
        'hosts'   => $hosts,
        'secret'  => isset($conf['secret']) ? trim((string)$conf['secret']) : '',
        'header'  => $header,
        'mode'    => $mode === 'observe' ? 'observe' : 'enforce',
        'deny'    => in_array($deny, array('errors404', 'http404'), true) ? $deny : 'suspend',
        'allow'   => $allow,
    );
}

/** 当前请求的 Host（去端口、小写） */
function cf_shield_host(): string
{
    $h = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    return strtolower((string)preg_replace('/:\d+$/', '', $h));
}

/** 当前请求路径（去查询串、去前导斜杠） */
function cf_shield_path(): string
{
    $u = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    $p = strtok($u, '?');
    return ltrim((string)$p, '/');
}

/**
 * 判定（纯函数，不产生任何输出）。
 * 返回 array('action' => 'pass'|'deny', 'why' => '...', 'host' => ..., 'path' => ..., 'path_allow' => bool)
 *
 * 拆出来是为了能离线自检 —— 判定一旦会 exit，就只能靠真实请求测。
 */
function cf_shield_decide(array $s, string $host, string $path, string $given): array
{
    if (!$s['enabled']) {
        return array('action' => 'pass', 'why' => 'disabled');
    }
    if ($s['secret'] === '') {
        return array('action' => 'pass', 'why' => 'no-secret');
    }
    if ($s['hosts'] && !in_array($host, $s['hosts'], true)) {
        return array('action' => 'pass', 'why' => 'host-skip');
    }
    /* allow 用严格匹配：精确路径相等，或以 / 结尾的目录前缀。
       不用「以某串开头」——否则 cron.php.bak 这类备份文件会顺着 cron.php 一起被放行。 */
    foreach ($s['allow'] as $a) {
        if ($path === $a) {
            return array('action' => 'pass', 'why' => 'allow');
        }
        if (substr($a, -1) === '/' && strpos($path, $a) === 0) {
            return array('action' => 'pass', 'why' => 'allow');
        }
    }
    if ($given !== '' && hash_equals($s['secret'], $given)) {
        return array('action' => 'pass', 'why' => 'verified');
    }
    return array('action' => 'deny', 'why' => $given === '' ? 'missing' : 'mismatch');
}

/**
 * 主闸门。bootstrap 早期调用一次。
 * 通过时打两个全局标记：__CF_SHIELD_OK（已验签）、__CF_VERIFIED_IP（真实客户端 IP）。
 */
function cf_shield_guard(array $conf)
{
    $s = cf_shield_settings($conf);
    if (!$s['enabled']) { return; }

    $host = cf_shield_host();
    $path = cf_shield_path();
    $given = isset($_SERVER[$s['header']]) ? (string)$_SERVER[$s['header']] : '';
    $d = cf_shield_decide($s, $host, $path, $given);

    if ($d['action'] === 'pass') {
        if ($d['why'] === 'no-secret') {
            cf_shield_log('config', 'secret 为空，安全盾未生效');
            return;
        }
        if ($d['why'] === 'verified') {
            $GLOBALS['__CF_SHIELD_OK'] = true;
            $cfip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']) : '';
            if ($cfip !== '' && filter_var($cfip, FILTER_VALIDATE_IP) !== false) {
                $GLOBALS['__CF_VERIFIED_IP'] = $cfip;
            }
        }
        return;
    }

    cf_shield_log('deny', sprintf('host=%s path=/%s why=%s mode=%s',
        $host, $path, $d['why'], $s['mode']));

    if ($s['mode'] === 'observe') { return; }
    cf_shield_deny($s['deny']);
}

/** 拒绝响应。全部走 302，绝不用 301 —— 301 会被浏览器永久缓存，事后无法撤销 */
function cf_shield_deny(string $how)
{
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }
    if ($how === 'suspend') {
        if (!headers_sent()) { header('Location: https://suspended-domain.net', true, 302); }
        exit;
    }
    if ($how === 'http404') {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>404 Not Found</title>'
           . '<body style="margin:0;height:100vh;display:flex;align-items:center;justify-content:center;'
           . 'font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f4f3f1;color:#1a1a1a">'
           . '<div style="text-align:center"><h1 style="font-size:15px;font-weight:600;margin:0 0 6px">404</h1>'
           . '<p style="font-size:13px;color:#8a8681;margin:0">页面不存在</p></div></body>';
        exit;
    }
    if (!headers_sent()) { header('Location: https://errors.infinityfree.net/errors/404/', true, 302); }
    exit;
}

/**
 * 写一行日志。同一个来源指纹每分钟最多一条 —— 被扫时不会把日志撑爆。
 */
function cf_shield_log(string $tag, string $msg)
{
    $dir = dirname(CF_SH_LOG);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $fp = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '-';
    $key = 'cfsh_' . substr(sha1($fp), 0, 12) . '_' . floor(time() / 60);
    $mark = APP_ROOT . '/storage/cache/' . $key . '.txt';
    if (is_file($mark)) { return; }
    @file_put_contents($mark, '1');
    @file_put_contents(CF_SH_LOG,
        sprintf("[%s] %s %s ua=%s\n", gmdate('Y-m-d H:i:s'), $tag, $msg,
            substr(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '-', 0, 90)),
        FILE_APPEND | LOCK_EX);
}

/** 本请求是否已通过安全盾验签 */
function cf_shield_passed(): bool
{
    return !empty($GLOBALS['__CF_SHIELD_OK']);
}

/** 由安全盾确认的真实客户端 IP（未通过时为空） */
function cf_shield_client_ip(): string
{
    return isset($GLOBALS['__CF_VERIFIED_IP']) ? (string)$GLOBALS['__CF_VERIFIED_IP'] : '';
}

/** 给面板/诊断用的状态摘要（不含密钥） */
function cf_shield_status(array $conf): array
{
    $s = cf_shield_settings($conf);
    $host = cf_shield_host();
    return array(
        'enabled'   => $s['enabled'],
        'secret_ok' => $s['secret'] !== '',
        'mode'      => $s['mode'],
        'deny'      => $s['deny'],
        'hosts'     => $s['hosts'],
        'host_now'  => $host,
        'active'    => $s['enabled'] && $s['secret'] !== ''
                      && (!$s['hosts'] || in_array($host, $s['hosts'], true)),
        'passed'    => cf_shield_passed(),
        'client_ip' => cf_shield_client_ip(),
    );
}
