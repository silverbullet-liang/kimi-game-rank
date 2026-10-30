<?php
declare(strict_types=1);
/**
 * 邮件发送：多通道失败重试
 * ------------------------------------------------------------
 * 设计前提：站点不要求管理员绑定自己的邮箱。默认通道全部是「免注册」的匿名服务：
 *
 *   quietmail   免注册匿名服务（quiet-mail）。首次发信自动开户，发件地址是它分配的
 *               <agent>@quiet-mail.com，与站长本人无关。发信走 Bearer 认证的 REST 接口。
 *   smtp        管理员自配 SMTP（任意服务商，含企业邮与自建 Postfix）。
 *   resend      API Key 通道（免费额度足以支撑验证码）
 *   brevo       同上
 *   mailjet     同上
 *   direct      直接投递到收件域 MX：不需要任何账号，真正零配置；送达率低、
 *               且许多主机封 25 端口，因此排在最后，仅当其余全部不可用时兜底。
 *
 * 顺序由 config 的 mail.channels 决定，缺省为 quietmail → smtp → resend → brevo → mailjet → direct。
 * 任一通道成功即返回；失败给该通道上 5 分钟冷却（net_channel_cooling），
 * 冷却期内直接跳过，不再白等一次超时。
 */

/** 通道配置读取 */
function mail_cfg(string $k, $d = null)
{
    return cfg('mail.' . $k, $d);
}

/** 通道顺序（只保留真正可用的：需要 Key 的通道没配 Key 就跳过） */
function mail_channels(): array
{
    $all = array('quietmail', 'smtp', 'resend', 'brevo', 'mailjet', 'direct');
    $cfg = mail_cfg('channels', array());
    $list = (is_array($cfg) && $cfg) ? $cfg : $all;
    $out = array();
    foreach ($list as $c) {
        $c = strtolower(trim((string)$c));
        if ($c === '' || in_array($c, $out, true)) { continue; }
        if (!in_array($c, $all, true)) { continue; }
        if ($c === 'smtp' && mail_cfg('smtp.host', '') === '') { continue; }
        if (in_array($c, array('resend', 'brevo', 'mailjet'), true) && mail_cfg($c . '.key', '') === '') { continue; }
        $out[] = $c;
    }
    return $out;
}

/** 邮箱格式与归一（RFC 简化版：够用且不接受换行，杜绝头注入） */
function mail_valid(string $email): bool
{
    $email = trim($email);
    if ($email === '' || strlen($email) > 190) { return false; }
    if (preg_match('/[\r\n\t\0]/', $email)) { return false; }
    if (!preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email)) { return false; }
    /* 域名部分不能有点连续或首尾点 */
    $dom = substr($email, strrpos($email, '@') + 1);
    return (strpos($dom, '..') === false && $dom[0] !== '.' && substr($dom, -1) !== '.');
}

function mail_norm(string $email): string
{
    return strtolower(trim($email));
}

/** 展示用：ab***@example.com */
function mail_mask(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false) { return $email; }
    $u = substr($email, 0, $at);
    $d = substr($email, $at);
    if (mb_strlen($u, 'UTF-8') <= 2) { return mb_substr($u, 0, 1, 'UTF-8') . '***' . $d; }
    return mb_substr($u, 0, 2, 'UTF-8') . '***' . $d;
}

/** 纯文本头字段净化 */
function mail_hdr(string $s): string
{
    return trim(preg_replace('/[\r\n\t\0]+/', ' ', $s));
}

/** 统一邮件模板：一处定义，验证码/通知共用；内联样式，不依赖外部资源 */
function mail_html(string $site, string $title, string $lead, string $code, string $tail): string
{
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $codeBox = '';
    if ($code !== '') {
        $codeBox = '<div style="margin:22px 0;padding:18px 0;border:1px solid #E6E3F0;border-radius:14px;'
                 . 'background:#F8F7FC;text-align:center">'
                 . '<div style="font:600 34px/1.2 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;'
                 . 'letter-spacing:8px;color:#1B1A22">' . $e($code) . '</div></div>';
    }
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e($title) . '</title></head>'
        . '<body style="margin:0;padding:28px 16px;background:#F4F2FA;'
        . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,Segoe UI,PingFang SC,Microsoft YaHei,sans-serif;color:#1B1A22">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:520px;margin:0 auto;'
        . 'background:#FFFFFF;border-radius:18px;padding:28px 26px">'
        . '<tr><td>'
        . '<div style="font:600 13px/1.4 ui-monospace,Menlo,Consolas,monospace;color:#7C3AED;'
        . 'letter-spacing:.08em;text-transform:uppercase">' . $e($site) . '</div>'
        . '<h1 style="margin:14px 0 10px;font:600 21px/1.35 inherit;color:#1B1A22">' . $e($title) . '</h1>'
        . '<p style="margin:0;color:#5C5A68">' . $e($lead) . '</p>'
        . $codeBox
        . '<p style="margin:0;color:#5C5A68">' . $e($tail) . '</p>'
        . '<hr style="margin:26px 0 14px;border:0;border-top:1px solid #EDEBF3">'
        . '<p style="margin:0;font-size:12px;color:#8B8896">' . $e($site) . '</p>'
        . '</td></tr></table></body></html>';
}

/* ============================================================
 *  通道一：quiet-mail（免注册匿名）
 *  首次调用自动开户：POST {base}/agents {"id","name"} → {agent:{id,email}, apiKey}
 *  发信：POST {base}/agents/{id}/send  Authorization: Bearer <apiKey>
 *  凭据存 storage/mail_quiet.json（0640），丢了就重新开户，不需要人工介入。
 * ============================================================ */

function mail_quiet_base(): string
{
    $b = (string)mail_cfg('quiet_base', 'https://api.quiet-mail.com');
    return rtrim($b, '/');
}

function mail_quiet_store_path(): string
{
    return APP_ROOT . '/storage/mail_quiet.json';
}

function mail_quiet_load(): array
{
    $f = mail_quiet_store_path();
    if (!is_file($f)) { return array(); }
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['id']) || empty($j['key'])) { return array(); }
    return $j;
}

function mail_quiet_save(array $a)
{
    $f = mail_quiet_store_path();
    $dir = dirname($f);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($f, json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($f, 0640);
}

/** 极简 HTTP：够用即可，返回 [code, body] */
function mail_http(string $url, string $method, array $headers, string $body, int $timeout = 12): array
{
    /* 测试注入点：tools/verify_email.php 借此替换传输层，整个自检不联网 */
    if (isset($GLOBALS['MAIL_HTTP_STUB'])) {
        return (array)call_user_func($GLOBALS['MAIL_HTTP_STUB'], $url, $method, $headers, $body, $timeout);
    }
    if (!function_exists('curl_init')) { return array(0, ''); }
    $ch = curl_init($url);
    $h = array('Content-Type: application/json');
    foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'kimi-game-rank/mail',
    ));
    if ($body !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (int)curl_errno($ch);
    curl_close($ch);
    return array($err !== 0 ? 0 : $code, is_string($resp) ? $resp : '');
}

/** 开户：失败返回空数组；409 说明 id 被占，换个 id 立刻重试 */
function mail_quiet_create(): array
{
    $site = (string)cfg('site.name', 'kimi游戏榜');
    for ($i = 0; $i < 3; $i++) {
        $id = 'kimgr-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $payload = json_encode(array('id' => $id, 'name' => mb_substr($site, 0, 32, 'UTF-8')), JSON_UNESCAPED_UNICODE);
        list($code, $body) = mail_http(mail_quiet_base() . '/agents', 'POST', array(), $payload, 15);
        if ($code === 409) { continue; }
        if ($code < 200 || $code >= 300) { return array(); }
        $j = json_decode($body, true);
        if (!is_array($j) || empty($j['apiKey'])) { return array(); }
        $a = array(
            'id'    => isset($j['agent']['id']) ? (string)$j['agent']['id'] : $id,
            'key'   => (string)$j['apiKey'],
            'email' => isset($j['agent']['email']) ? (string)$j['agent']['email'] : ($id . '@quiet-mail.com'),
            'at'    => now_utc(),
        );
        mail_quiet_save($a);
        app_log('mail: quiet-mail agent created ' . $a['email']);
        return $a;
    }
    return array();
}

/** 取凭据：配置优先（管理员可手填），否则本地凭据，再否则自动开户 */
function mail_quiet_agent(): array
{
    $kid = (string)mail_cfg('quiet_agent', '');
    $key = (string)mail_cfg('quiet_key', '');
    if ($kid !== '' && $key !== '') {
        return array('id' => $kid, 'key' => $key, 'email' => $kid . '@quiet-mail.com', 'at' => '');
    }
    $a = mail_quiet_load();
    if ($a) { return $a; }
    return mail_quiet_create();
}

function mail_via_quietmail(string $to, string $subject, string $text, string $html): array
{
    $a = mail_quiet_agent();
    if (!$a) { return array(false, 'agent_create_failed'); }
    $payload = json_encode(array(
        'to'      => $to,
        'subject' => mail_hdr($subject),
        'text'    => $text,
        'html'    => $html !== '' ? $html : null,
    ), JSON_UNESCAPED_UNICODE);
    list($code, $body) = mail_http(mail_quiet_base() . '/agents/' . rawurlencode($a['id']) . '/send', 'POST',
        array('Authorization' => 'Bearer ' . $a['key']), $payload, 20);
    if ($code >= 200 && $code < 300) { return array(true, 'quietmail'); }
    /* 401/403：凭据失效（被封或 key 丢失）——丢掉本地凭据，下轮自动重新开户 */
    if ($code === 401 || $code === 403) {
        if (is_file(mail_quiet_store_path())) { @unlink(mail_quiet_store_path()); }
        return array(false, 'quietmail_cred:' . $code);
    }
    return array(false, 'quietmail:' . $code);
}

/* ============================================================
 *  通道二：SMTP（管理员自配）
 *  支持 ssl(465) 与 starttls(587)；只实现 AUTH LOGIN，够覆盖主流服务商。
 * ============================================================ */

function mail_smtp_talk($fp, string $cmd, array $expect): string
{
    if ($cmd !== '') { fwrite($fp, $cmd . "\r\n"); }
    $lines = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $lines .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') { break; }
    }
    if ($lines === '' || !in_array((int)substr($lines, 0, 3), $expect, true)) {
        throw new RuntimeException('smtp: ' . trim(substr($lines, 0, 200)));
    }
    return $lines;
}

function mail_via_smtp(string $to, string $subject, string $text, string $html): array
{
    $host = (string)mail_cfg('smtp.host', '');
    $port = (int)mail_cfg('smtp.port', 465);
    $user = (string)mail_cfg('smtp.user', '');
    $pass = (string)mail_cfg('smtp.pass', '');
    $from = (string)mail_cfg('smtp.from', $user);
    $name = (string)mail_cfg('smtp.from_name', (string)cfg('site.name', ''));
    $secure = strtolower((string)mail_cfg('smtp.secure', $port === 465 ? 'ssl' : 'tls'));
    if ($host === '' || $from === '') { return array(false, 'smtp_not_configured'); }

    $ctx = stream_context_create(array('ssl' => array('SNI_enabled' => true, 'peer_name' => $host)));
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return array(false, 'smtp_connect:' . $errno); }
    stream_set_timeout($fp, 12);
    try {
        mail_smtp_talk($fp, '', array(220));
        mail_smtp_talk($fp, 'EHLO ' . (parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost'), array(250));
        if ($secure === 'tls') {
            mail_smtp_talk($fp, 'STARTTLS', array(220));
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('smtp: starttls failed');
            }
            mail_smtp_talk($fp, 'EHLO localhost', array(250));
        }
        if ($user !== '') {
            mail_smtp_talk($fp, 'AUTH LOGIN', array(334));
            mail_smtp_talk($fp, base64_encode($user), array(334));
            mail_smtp_talk($fp, base64_encode($pass), array(235));
        }
        mail_smtp_talk($fp, 'MAIL FROM:<' . $from . '>', array(250));
        mail_smtp_talk($fp, 'RCPT TO:<' . $to . '>', array(250, 251));
        mail_smtp_talk($fp, 'DATA', array(354));
        $msg = mail_build($from, $name, $to, $subject, $text, $html);
        fwrite($fp, $msg . "\r\n.\r\n");
        mail_smtp_talk($fp, '', array(250));
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return array(true, 'smtp');
    } catch (Throwable $e) {
        @fclose($fp);
        return array(false, 'smtp:' . mb_substr($e->getMessage(), 0, 80, 'UTF-8'));
    }
}

/** 组信（RFC 5322 + 简单 multipart） */
function mail_build(string $from, string $fromName, string $to, string $subject, string $text, string $html): string
{
    $b = bin2hex(random_bytes(8));
    $h = 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . ">\r\n"
       . 'To: <' . $to . ">\r\n"
       . 'Subject: =?UTF-8?B?' . base64_encode(mail_hdr($subject)) . "?=\r\n"
       . 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
       . 'Message-ID: <' . $b . '@' . (substr($from, strpos($from, '@') + 1)) . ">\r\n"
       . "MIME-Version: 1.0\r\n";
    if ($html === '') {
        return $h . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($text), 76, "\r\n");
    }
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text), 76, "\r\n")
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html), 76, "\r\n")
          . "--$b--";
    return $h . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $body;
}

/* ============================================================
 *  通道三：API Key 类（Resend / Brevo / Mailjet）
 * ============================================================ */

function mail_via_resend(string $to, string $subject, string $text, string $html): array
{
    $key = (string)mail_cfg('resend.key', '');
    $from = (string)mail_cfg('resend.from', 'onboarding@resend.dev');
    $payload = json_encode(array('from' => $from, 'to' => array($to),
        'subject' => mail_hdr($subject), 'text' => $text, 'html' => $html), JSON_UNESCAPED_UNICODE);
    list($code) = mail_http('https://api.resend.com/emails', 'POST',
        array('Authorization' => 'Bearer ' . $key), $payload, 15);
    return array($code >= 200 && $code < 300, 'resend:' . $code);
}

function mail_via_brevo(string $to, string $subject, string $text, string $html): array
{
    $key  = (string)mail_cfg('brevo.key', '');
    $from = (string)mail_cfg('brevo.from', '');
    $name = (string)mail_cfg('brevo.from_name', (string)cfg('site.name', ''));
    $payload = json_encode(array(
        'sender'      => array('email' => $from, 'name' => $name),
        'to'          => array(array('email' => $to)),
        'subject'     => mail_hdr($subject),
        'textContent' => $text,
        'htmlContent' => $html,
    ), JSON_UNESCAPED_UNICODE);
    list($code) = mail_http('https://api.brevo.com/v3/smtp/email', 'POST',
        array('api-key' => $key, 'accept' => 'application/json'), $payload, 15);
    return array($code >= 200 && $code < 300, 'brevo:' . $code);
}

function mail_via_mailjet(string $to, string $subject, string $text, string $html): array
{
    $key  = (string)mail_cfg('mailjet.key', '');
    $sec  = (string)mail_cfg('mailjet.secret', '');
    $from = (string)mail_cfg('mailjet.from', '');
    $name = (string)mail_cfg('mailjet.from_name', (string)cfg('site.name', ''));
    $payload = json_encode(array('Messages' => array(array(
        'From'     => array('Email' => $from, 'Name' => $name),
        'To'       => array(array('Email' => $to)),
        'Subject'  => mail_hdr($subject),
        'TextPart' => $text,
        'HTMLPart' => $html,
    ))), JSON_UNESCAPED_UNICODE);
    list($code) = mail_http('https://api.mailjet.com/v3.1/send', 'POST',
        array('Authorization' => 'Basic ' . base64_encode($key . ':' . $sec)), $payload, 15);
    return array($code >= 200 && $code < 300, 'mailjet:' . $code);
}

/* ============================================================
 *  通道四：direct —— 直接投递到收件域 MX（零配置兜底）
 *  无发件域名信誉、无 SPF/DKIM，容易被判垃圾；仅在其余通道都不可用时使用。
 * ============================================================ */

function mail_via_direct(string $to, string $subject, string $text, string $html): array
{
    $dom = substr($to, strpos($to, '@') + 1);
    $mx = array();
    if (!function_exists('dns_get_mx') || !@dns_get_mx($dom, $mx) || !$mx) { return array(false, 'direct_no_mx'); }
    usort($mx, function ($a, $b) { return 0; });          // 保留 DNS 给出的优先级顺序
    $from = (string)mail_cfg('direct.from', 'noreply@' . (string)cfg('site_host', 'localhost'));
    $fromName = (string)cfg('site.name', '');
    foreach (array_slice($mx, 0, 2) as $host) {
        $fp = @stream_socket_client('tcp://' . $host . ':25', $errno, $errstr, 8);
        if (!$fp) { continue; }
        stream_set_timeout($fp, 8);
        try {
            mail_smtp_talk($fp, '', array(220));
            mail_smtp_talk($fp, 'HELO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), array(250));
            mail_smtp_talk($fp, 'MAIL FROM:<' . $from . '>', array(250));
            mail_smtp_talk($fp, 'RCPT TO:<' . $to . '>', array(250, 251));
            mail_smtp_talk($fp, 'DATA', array(354));
            fwrite($fp, mail_build($from, $fromName, $to, $subject, $text, $html) . "\r\n.\r\n");
            mail_smtp_talk($fp, '', array(250));
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            return array(true, 'direct:' . $host);
        } catch (Throwable $e) {
            @fclose($fp);
        }
    }
    return array(false, 'direct_failed');
}

/* ============================================================
 *  对外接口
 * ============================================================ */

/**
 * 发一封邮件：按通道顺序尝试，成功即返回。
 * @return array ok|via|reason|tried（各通道尝试结果）
 */
function mail_send(string $to, string $subject, string $text, string $html = ''): array
{
    $to = trim($to);
    if (!mail_valid($to)) { return array('ok' => false, 'via' => '', 'reason' => 'bad_address', 'tried' => array()); }
    $chans = mail_channels();
    if (!$chans) { return array('ok' => false, 'via' => '', 'reason' => 'no_channel', 'tried' => array()); }

    $tried = array();
    foreach ($chans as $c) {
        if (net_channel_cooling('mail_' . $c)) { $tried[$c] = 'cooling'; continue; }
        $r = array(false, 'unknown');
        if ($c === 'quietmail') { $r = mail_via_quietmail($to, $subject, $text, $html); }
        elseif ($c === 'smtp')    { $r = mail_via_smtp($to, $subject, $text, $html); }
        elseif ($c === 'resend')  { $r = mail_via_resend($to, $subject, $text, $html); }
        elseif ($c === 'brevo')   { $r = mail_via_brevo($to, $subject, $text, $html); }
        elseif ($c === 'mailjet') { $r = mail_via_mailjet($to, $subject, $text, $html); }
        elseif ($c === 'direct')  { $r = mail_via_direct($to, $subject, $text, $html); }
        $tried[$c] = $r[1];
        if ($r[0]) {
            app_log('mail sent via ' . $c . ' to ' . mail_mask($to));
            return array('ok' => true, 'via' => $c, 'reason' => 'ok', 'tried' => $tried);
        }
        net_channel_cool('mail_' . $c, 300);
    }
    app_log('mail failed: ' . json_encode($tried, JSON_UNESCAPED_UNICODE));
    return array('ok' => false, 'via' => '', 'reason' => 'all_channels_failed', 'tried' => $tried);
}

/** 通道体检（面板用）：只做连通性探测，不发信 */
function mail_probe(): array
{
    $out = array();
    foreach (mail_channels() as $c) {
        $ok = false; $note = '';
        if ($c === 'quietmail') {
            $a = mail_quiet_agent();
            $ok = !empty($a['key']);
            $note = $ok ? ('agent ' . $a['email']) : 'agent_create_failed';
        } elseif ($c === 'smtp') {
            $host = (string)mail_cfg('smtp.host', '');
            $port = (int)mail_cfg('smtp.port', 465);
            $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 6);
            $ok = (bool)$fp;
            $note = $ok ? 'connected' : ('connect_failed:' . $en);
            if ($fp) { @fclose($fp); }
        } else {
            $ok = true;
            $note = 'configured';
        }
        $out[] = array('channel' => $c, 'ok' => $ok, 'note' => $note);
    }
    return $out;
}
