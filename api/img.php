<?php
/**
 * API：远程图片代理（解决防盗链 + 统一缓存）
 * ------------------------------------------------------------
 * GET ?u=<urlencoded>
 * 用途：B站等站点图片有 Referer 防盗链，浏览器直接引用会 403；
 *       由服务端带 Referer 抓取 → 缓存到本地 → 输出。
 * 安全：域名白名单 + 大小限制 + 禁止内网地址。
 * 说明：图片在 <img> 中加载，无法携带认证头，故本接口不校验令牌，
 *       以域名白名单与缓存策略控制滥用风险。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$url = isset($_GET['u']) ? (string)$_GET['u'] : '';
if ($url === '' || mb_strlen($url, 'UTF-8') > 600) { http_response_code(400); exit('bad url'); }
if (!preg_match('#^https?://#i', $url)) { http_response_code(400); exit('bad scheme'); }

/* ---------- 域名白名单 ---------- */
$allow = array(
    'hdslb.com', 'bilibili.com', 'b23.tv',
    'moonshot.cn', 'kimi.com', 'kimi.moonshot.cn',
    'aliyuncs.com', 'myqcloud.com', 'qpic.cn', 'gtimg.cn', 'byteimg.com', 'zhipuai.cn', 'bigmodel.cn',
);
$host = parse_url($url, PHP_URL_HOST);
if (!is_string($host) || $host === '') { http_response_code(400); exit('bad host'); }
$host = strtolower($host);
if (filter_var($host, FILTER_VALIDATE_IP) !== false) { http_response_code(403); exit('ip not allowed'); }
$okHost = false;
foreach ($allow as $d) {
    if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { $okHost = true; break; }
}
if (!$okHost) { http_response_code(403); exit('domain not allowed'); }

/* ---------- 缓存 ---------- */
$cacheDir = APP_ROOT . '/storage/cache/img';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
$key = hash('sha256', $url);
$metaFile = $cacheDir . '/' . $key . '.json';
$binFile  = $cacheDir . '/' . $key . '.bin';

if (is_file($metaFile) && is_file($binFile)) {
    $meta = json_decode((string)file_get_contents($metaFile), true);
    if (is_array($meta) && isset($meta['type'])) {
        header('Content-Type: ' . $meta['type']);
        header('Cache-Control: public, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        readfile($binFile);
        exit;
    }
}

/* ---------- 抓取（带 Referer 绕过防盗链） ---------- */
$referer = 'https://www.bilibili.com/';
if (strpos($host, 'moonshot.cn') !== false || strpos($host, 'kimi') !== false) { $referer = 'https://www.kimi.com/'; }

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122 Safari/537.36',
    CURLOPT_REFERER        => $referer,
    CURLOPT_HTTPHEADER     => array('Accept: image/avif,image/webp,image/png,image/*,*/*;q=0.8'),
));
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (!is_string($body) || $code !== 200 || $body === '') { http_response_code(502); exit('fetch failed'); }
if (strlen($body) > 5 * 1024 * 1024) { http_response_code(413); exit('too large'); }

/* 仅允许图片类型 */
$type = strtolower(trim(explode(';', $type)[0]));
$allowTypes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml');
if (!in_array($type, $allowTypes, true)) {
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($body);
        if ($info === false || empty($info['mime'])) { http_response_code(415); exit('not an image'); }
        $type = (string)$info['mime'];
    } else {
        http_response_code(415); exit('not an image');
    }
}

@file_put_contents($binFile, $body);
@file_put_contents($metaFile, json_encode(array('type' => $type, 'src' => $url, 'at' => now_utc())));

header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
echo $body;
exit;
