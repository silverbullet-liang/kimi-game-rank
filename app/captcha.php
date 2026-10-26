<?php
/**
 * 人机验证（主：Cloudflare Turnstile；备：Cap PoW 双实例）
 * ------------------------------------------------------------
 * 主通道 Turnstile：通常无需操作即通过；服务端用 secret 去 siteverify 复核，
 *                   并核对签发域名，防 token 被挪用到别处。
 * 备通道 Cap：主通道脚本加载失败 / 长时间没渲染出来时自动切换；
 *             两个公共实例互为备份，token 不通用，按 channel 指明来源。
 *
 * 安全要点：
 *   · token 一律由服务端校验，前端说了不算；只有 success=true 才放行；
 *   · token 一次性，重放无效（Turnstile 另有 300 秒有效期）；
 *   · 校验失败一律 fail-closed（拒绝），绝不因为「验证服务可能挂了」就放开；
 *   · 没声明 channel 时按 turnstile → standard → php 依次试：拿 A 通道的 token
 *     去校验 B 通道必然失败，无害；正常只会有一次有效请求。
 *
 * 配置（config/api_keys.php）：
 *   'turnstile' => array('sitekey' => '…', 'secret' => '…')
 *   未配置 Turnstile 时主通道自动缺席，直接用备通道 Cap —— 门不会因此消失。
 */
declare(strict_types=1);

define('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
define('TURNSTILE_SCRIPT', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit');

/** Cap 备通道：两个公共实例（token 互不通用） */
function captcha_channels(): array
{
    return array(
        'standard' => array(
            'label'    => 'captcha.gurl.eu.org',
            'validate' => 'https://captcha.gurl.eu.org/api/validate',
            'script'   => 'https://captcha.gurl.eu.org/cap.min.js',
            'api'      => 'https://captcha.gurl.eu.org/api/',
        ),
        'php' => array(
            'label'    => 'cap-pow.wuw.li',
            'validate' => 'https://cap-pow.wuw.li/api/validate',
            'script'   => 'https://cap-pow.wuw.li/cap-pow.js',
            'css'      => 'https://cap-pow.wuw.li/cap-pow.css',
        ),
    );
}

/** Turnstile 配置段（缺文件/缺段一律返回空数组） */
function captcha_keys(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = array();
    try {
        $all = require APP_ROOT . '/config/api_keys.php';
        if (is_array($all) && isset($all['turnstile']) && is_array($all['turnstile'])) {
            $c = $all['turnstile'];
        }
    } catch (Throwable $e) {
        app_log('turnstile config unreadable: ' . $e->getMessage());
    }
    return $c;
}

function captcha_sitekey(): string
{
    $c = captcha_keys();
    return isset($c['sitekey']) ? trim((string)$c['sitekey']) : '';
}

function captcha_secret(): string
{
    $c = captcha_keys();
    return isset($c['secret']) ? trim((string)$c['secret']) : '';
}

/** 主通道 Turnstile 是否可用（密钥配齐才算） */
function captcha_turnstile_ready(): bool
{
    return captcha_sitekey() !== '' && captcha_secret() !== '';
}

/** 开关（默认开启；面板或配置可关，关掉后登录注册不再要求验证） */
function captcha_enabled(): bool
{
    return (int)cfg('captcha.enabled', 1) === 1;
}

/** 前端渲染所需的最小配置（不含任何密钥 —— Cap 公共实例 secret 为空） */
function captcha_client_config(): array
{
    $chs = array();
    foreach (captcha_channels() as $k => $c) {
        $chs[$k] = array(
            'label'  => (string)$c['label'],
            'script' => (string)$c['script'],
            'api'    => isset($c['api']) ? (string)$c['api'] : '',
            'css'    => isset($c['css']) ? (string)$c['css'] : '',
        );
    }
    return array(
        'on'       => captcha_enabled(),
        'sitekey'  => captcha_sitekey(),
        'script'   => TURNSTILE_SCRIPT,
        'channels' => $chs,
    );
}

/** 本次请求的域名（去端口、小写） */
function captcha_request_host(): string
{
    $h = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    return strtolower(preg_replace('/:\d+$/', '', $h));
}

/** Turnstile：siteverify 复核。返回 array(ok, host, codes) */
function captcha_verify_turnstile(string $token, string $ip = ''): array
{
    $fields = array('secret' => captcha_secret(), 'response' => $token);
    if ($ip !== '') { $fields['remoteip'] = $ip; }

    $ch = curl_init(TURNSTILE_VERIFY_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/x-www-form-urlencoded'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    curl_close($ch);

    if (!is_string($resp) || $code < 200 || $code >= 300) {
        return array('ok' => false, 'host' => '', 'codes' => 'http-' . $code . ($err !== '' ? ' ' . $err : ''));
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) { return array('ok' => false, 'host' => '', 'codes' => 'bad-json'); }

    $host  = isset($j['hostname']) ? strtolower((string)$j['hostname']) : '';
    $codes = (isset($j['error-codes']) && is_array($j['error-codes'])) ? implode(',', $j['error-codes']) : '';
    if (empty($j['success'])) { return array('ok' => false, 'host' => $host, 'codes' => $codes !== '' ? $codes : 'rejected'); }

    /* token 是给某个域名签发的，换域名使用一律拒绝（上游没回 hostname 时不强判） */
    $mine = captcha_request_host();
    if ($host !== '' && $mine !== '' && $host !== $mine) {
        return array('ok' => false, 'host' => $host, 'codes' => 'host-mismatch:' . $mine);
    }
    return array('ok' => true, 'host' => $host, 'codes' => '');
}

/** Cap 备通道：向某个实例校验 */
function captcha_verify_cap(string $token, string $key): bool
{
    $chs = captcha_channels();
    if (!isset($chs[$key])) { return false; }
    $ch = curl_init($chs[$key]['validate']);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(array('token' => $token, 'secret' => ''), JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $code < 200 || $code >= 300) { return false; }
    $j = json_decode($resp, true);
    return is_array($j) && !empty($j['success']);
}

/**
 * 校验 token：按声明的通道先校验，没声明就按主→备依次试。
 * 返回 array(ok, via, codes)，via 记录实际通过的通道，便于排障。
 */
function captcha_verify(string $token, string $channel = '', string $ip = ''): array
{
    $token = trim($token);
    if ($token === '') { return array('ok' => false, 'via' => '', 'codes' => 'empty-token'); }

    $order = array();
    if ($channel !== '') {
        if ($channel === 'turnstile' || isset(captcha_channels()[$channel])) { $order[] = $channel; }
    }
    if (captcha_turnstile_ready() && !in_array('turnstile', $order, true)) { $order[] = 'turnstile'; }
    foreach (array_keys(captcha_channels()) as $k) {
        if (!in_array($k, $order, true)) { $order[] = $k; }
    }

    $last = '';
    foreach ($order as $k) {
        if ($k === 'turnstile') {
            $r = captcha_verify_turnstile($token, $ip);
            if (!empty($r['ok'])) { return array('ok' => true, 'via' => 'turnstile', 'codes' => ''); }
            $last = 'turnstile:' . $r['codes'];
        } else {
            if (captcha_verify_cap($token, $k)) { return array('ok' => true, 'via' => $k, 'codes' => ''); }
            $last = $k . ':rejected';
        }
    }
    return array('ok' => false, 'via' => '', 'codes' => $last);
}

/**
 * 登录 / 注册的验证闸门。不通过直接拒绝 —— 校验失败一律 fail-closed。
 * 字段名沿用 cap_token / cap_channel（现 channel 也可为 turnstile）；
 * cf_token 兼容本字段更名期间的过渡版本。
 */
function captcha_guard()
{
    if (!captcha_enabled()) { return; }
    $token = param_str('cap_token', '');
    if (trim($token) === '') { $token = param_str('cf_token', ''); }
    if (trim($token) === '') { fail(400, '请先完成人机验证'); }

    $r = captcha_verify($token, param_str('cap_channel', ''), client_ip());
    if (empty($r['ok'])) {
        app_log('captcha rejected via=' . $r['via'] . ' reason=' . $r['codes']);
        fail(400, '人机验证未通过，请重新验证后再试');
    }
}
