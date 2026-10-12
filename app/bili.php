<?php
/**
 * B站视频：解析（米人 API）。
 * ------------------------------------------------------------
 * · 米人 api.mir6.com/api/bzjiexi：给 BV 号/链接，返回视频信息与 mp4 直链。
 *   直链带防盗链（要求请求带 Referer —— 浏览器从本站发起即自带本站 Referer，
 *   实测可直接播放）且有时效（约 1 天），因此**永不入库**：只在播放前实时解析
 *   一次交给前端直连播放，本站不代理视频流量。
 * · 入库只存 BV 号（messages.media_url），展示信息（标题/封面/时长/作者）存 meta。
 * · 内容审核：只审**标题与简介**（连同用户附言一起过文本审核），不对视频抽帧。
 */
declare(strict_types=1);

define('BILI_MIREN_EP', 'https://api.mir6.com/api/bzjiexi');
define('BILI_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('BILI_REFERER', 'https://www.bilibili.com/');

/** 从任意输入（完整链接 / BV 号 / b23.tv 短链）提取 BV 号；失败返回空串 */
function bili_bv_of(string $input): string
{
    $s = trim($input);
    if ($s === '') { return ''; }
    if (preg_match('/[Bb][Vv]([0-9A-Za-z]{10})/', $s, $m)) { return 'BV' . $m[1]; }
    if (preg_match('#^https?://#i', $s) && (stripos($s, 'b23.tv') !== false || stripos($s, 'bilibili.com') !== false)) {
        $final = bili_follow($s);
        if ($final !== '' && preg_match('/[Bb][Vv]([0-9A-Za-z]{10})/', $final, $m2)) { return 'BV' . $m2[1]; }
    }
    return '';
}

/** 跟随重定向取最终地址（b23.tv 短链 → 标准页） */
function bili_follow(string $url): string
{
    if (!function_exists('curl_init')) { return ''; }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_NOBODY         => true,          // HEAD
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => BILI_UA,
    ));
    curl_exec($ch);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return $final;
}

/** 简单 GET（可选 Referer），返回 array(code, body) */
function bili_get(string $url, bool $withReferer = true, int $timeout = 15): array
{
    if (!function_exists('curl_init')) { return array('code' => 0, 'body' => ''); }
    $h = array('User-Agent: ' . BILI_UA, 'Accept: application/json, text/plain, */*');
    if ($withReferer) { $h[] = 'Referer: ' . BILI_REFERER; }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => is_string($body) ? $body : '');
}

/** 米人解析：返回归一信息（含 video_url 直链，仅供即时播放）；失败返回 null */
function bili_miren(string $bv)
{
    if (!preg_match('/^BV[0-9A-Za-z]{10}$/', $bv)) { return null; }
    $link = 'https://www.bilibili.com/video/' . $bv . '/';
    $r = bili_get(BILI_MIREN_EP . '?url=' . rawurlencode($link) . '&type=json', true, 20);
    if ($r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') { return null; }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || (int)($j['code'] ?? 0) !== 200) { return null; }
    $d0 = (isset($j['data'][0]) && is_array($j['data'][0])) ? $j['data'][0] : array();
    return array(
        'bv'         => $bv,
        'title'      => mb_substr(trim((string)($j['title'] ?? $d0['title'] ?? '')), 0, 120, 'UTF-8'),
        'desc'       => mb_substr(trim((string)($j['desc'] ?? '')), 0, 1000, 'UTF-8'),
        'cover'      => preg_replace('#^http:#i', 'https:', trim((string)($j['imgurl'] ?? ''))),
        'duration'   => (int)($d0['duration'] ?? 0),
        'duration_f' => (string)($d0['durationFormat'] ?? ''),
        'author'     => mb_substr(trim((string)($j['user']['name'] ?? '')), 0, 60, 'UTF-8'),
        'video_url'  => (string)($d0['video_url'] ?? ''),
    );
}
