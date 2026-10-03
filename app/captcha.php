<?php
/**
 * 人机验证（Cap PoW 双实例）
 * ------------------------------------------------------------
 * 主通道：captcha.gurl.eu.org —— 官方标准 Cap API（Cloudflare Workers），
 *         前端用官方 cap-widget，一行 script 加载、事件驱动。
 * 备通道：cap-pow.wuw.li —— PHP 定制实例，前端用实例自带的 cap-pow.js。
 *
 * 主通道 3 秒内没渲染出来（脚本被墙、实例挂了、shadowRoot 为空）就切备通道，
 * 两个通道的 token 不通用，所以校验时必须带上 channel 指明来源。
 *
 * 安全要点：
 *   · token 一律由服务端 POST 到实例的 /api/validate 校验，前端说了不算；
 *   · 校验失败 fail-closed（拒绝），超时 8–10 秒；
 *   · 最终 token 一次性，校验成功即失效，重放无效。
 */
declare(strict_types=1);

/** 双通道定义 */
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

/** 开关（默认开启；面板或配置可关，关掉后登录注册不再要求验证） */
function captcha_enabled(): bool
{
    return (int)cfg('captcha.enabled', 1) === 1;
}

/** 前端渲染所需的最小配置（不含任何密钥 —— 公共实例 secret 为空） */
function captcha_client_config(): array
{
    $out = array('on' => captcha_enabled(), 'channels' => array());
    foreach (captcha_channels() as $k => $c) {
        $out['channels'][$k] = array(
            'label'  => (string)$c['label'],
            'script' => (string)$c['script'],
            'api'    => isset($c['api']) ? (string)$c['api'] : '',
            'css'    => isset($c['css']) ? (string)$c['css'] : '',
        );
    }
    return $out;
}

/** 向某个实例校验 token */
function captcha_validate_at(string $url, string $token): bool
{
    $ch = curl_init($url);
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
 * 校验 token。优先按前端声明的 channel 校验；没声明就两个实例都试一遍。
 * 两个实例的 token 不通用，所以正常只会有一次有效请求。
 */
function captcha_verify(string $token, string $channel = ''): bool
{
    $token = trim($token);
    if ($token === '') { return false; }
    $chs = captcha_channels();

    $order = array();
    if ($channel !== '' && isset($chs[$channel])) { $order[] = $channel; }
    foreach (array_keys($chs) as $k) {
        if (!in_array($k, $order, true)) { $order[] = $k; }
    }
    foreach ($order as $k) {
        if (captcha_validate_at((string)$chs[$k]['validate'], $token)) { return true; }
    }
    return false;
}

/**
 * 登录 / 注册的验证闸门。不通过直接拒绝 —— 校验失败一律 fail-closed，
 * 绝不因为「验证服务可能挂了」就放开，那等于把这道门拆了。
 */
function captcha_guard()
{
    if (!captcha_enabled()) { return; }
    $token = param_str('cap_token', '');
    if (trim($token) === '') { fail(400, '请先完成人机验证'); }
    if (!captcha_verify($token, param_str('cap_channel', ''))) {
        app_log('captcha rejected channel=' . param_str('cap_channel', '-'));
        fail(400, '人机验证未通过，请重新验证后再试');
    }
}
