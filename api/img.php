<?php
/**
 * API：远程图片代理（仅在必要时使用）
 * ------------------------------------------------------------
 * GET ?u=<urlencoded>
 *
 * 定位：这是「回退通道」，不是默认通道。站点默认让浏览器直连原图
 *       （见 app/helpers.php 的 img_src()），只有两类情况才会走到这里：
 *       1) 该域名确知有 Referer 防盗链，后端已直接给出本代理地址；
 *       2) 前端直连失败，全局 error 捕获自动回退到本代理重试。
 *
 * 服务端带 Referer 抓取 → 落盘缓存 → 输出；缓存命中时直接读本地文件，
 * 不再发起任何外部请求，因此同一张图的重复访问不产生额外流量。
 *
 * 安全：域名白名单 + 类型白名单 + 大小上限 + 禁止 IP 直连。
 * 说明：图片在 <img> 中加载，无法携带认证头，故本接口不校验令牌，
 *       以域名白名单、类型白名单与容量上限控制滥用风险。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$url = isset($_GET['u']) ? (string)$_GET['u'] : '';
if ($url === '' || mb_strlen($url, 'UTF-8') > 600) { http_response_code(400); exit('bad url'); }
if (!preg_match('#^https?://#i', $url)) { http_response_code(400); exit('bad scheme'); }

/* ---------- 域名白名单（允许代理的范围，与前端回退策略对应） ---------- */
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

/* ---------- 本地缓存 ---------- */
$cacheDir = APP_ROOT . '/storage/cache/img';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
$key = hash('sha256', $url);
$metaFile = $cacheDir . '/' . $key . '.json';
$binFile  = $cacheDir . '/' . $key . '.bin';

/** 输出缓存：一年强缓存 + ETag，命中浏览器缓存后连 304 都不需要往返。 */
$serve = function (string $type, string $body, string $etag) {
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    $imm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($imm !== '' && trim($imm, '"') === trim($etag, '"')) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
};

if (is_file($metaFile) && is_file($binFile)) {
    $meta = json_decode((string)file_get_contents($metaFile), true);
    if (is_array($meta) && isset($meta['type'])) {
        $body = (string)file_get_contents($binFile);
        if ($body !== '') { $serve((string)$meta['type'], $body, '"' . md5($key . $body) . '"'); }
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

/* 抓取失败不再返回空白（破图），而是把浏览器送回原地址 —— 让它在原站的
   正常上下文里自行尝试，比让用户看到一块空白更体面。 */
if (!is_string($body) || $code !== 200 || $body === '') {
    header('Location: ' . $url, true, 302);
    exit;
}
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

/* 容量治理：缓存目录无上限增长会拖慢目录扫描并挤占空间。
   超过阈值时按修改时间删掉最旧的一批（保留最近使用的部分）。 */
$maxFiles = 800;
$stale = @glob($cacheDir . '/*.bin');
if (is_array($stale) && count($stale) > $maxFiles) {
    $items = array();
    foreach ($stale as $f) { $items[$f] = (int)@filemtime($f); }
    asort($items);
    $drop = count($items) - (int)($maxFiles * 0.7);
    $i = 0;
    foreach ($items as $f => $t) {
        if ($i++ >= $drop) { break; }
        @unlink($f);
        @unlink(substr($f, 0, -4) . '.json');
    }
}

$serve($type, $body, '"' . md5($key . $body) . '"');
