<?php
/**
 * 人机验证：只用 Cloudflare Turnstile，没有备用通道。
 * ------------------------------------------------------------
 * 为什么不留备通道：备通道只能是更弱的验证（纯计算题，脚本几十毫秒可解）。
 * 只要存在「主通道不通就退到弱通道」的路径，攻击者就能靠让主通道加载失败
 * 把整道门关掉 —— 2026-11-14 那 180 个空账号就是这么进来的。
 * 所以这里只有一条路：Turnstile 复核通过才放行，复核失败一律拒绝（fail-closed），
 * 不降级、不切换、不猜测。
 *
 * 配置（config/api_keys.php）：
 *   'turnstile' => array('sitekey' => '…', 'secret' => '…')
 * 密钥缺失时人机验证直接不可用（而非自动放行），面板里能看到原因。
 */
declare(strict_types=1);

define('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
define('TURNSTILE_SCRIPT', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit');

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
    return array(
        'on'      => captcha_enabled(),
        'sitekey' => captcha_sitekey(),
        'script'  => TURNSTILE_SCRIPT,
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

/**
 * 校验 token：只认 Turnstile。$channel 参数保留只为兼容旧调用签名，不参与任何决策。
 * 返回 array(ok, via, codes)。
 */
function captcha_verify(string $token, string $channel = '', string $ip = ''): array
{
    $token = trim($token);
    if ($token === '') { return array('ok' => false, 'via' => '', 'codes' => 'empty-token'); }
    if (!captcha_turnstile_ready()) { return array('ok' => false, 'via' => '', 'codes' => 'not-configured'); }

    $r = captcha_verify_turnstile($token, $ip);
    if (!empty($r['ok'])) { return array('ok' => true, 'via' => 'turnstile', 'codes' => ''); }
    app_log('captcha turnstile rejected: ' . $r['codes']);
    return array('ok' => false, 'via' => 'turnstile', 'codes' => (string)$r['codes']);
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

    $r = captcha_verify($token, '', client_ip());
    if (empty($r['ok'])) {
        app_log('captcha rejected via=' . $r['via'] . ' reason=' . $r['codes']);
        fail(400, '人机验证未通过，请重新验证后再试');
    }
}
