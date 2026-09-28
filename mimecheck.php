<?php
/**
 * 静态资源响应头检测（用完即删）
 * 从服务器自身请求关键资源，输出 HTTP 状态码与 Content-Type —— 一锤定音。
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

$host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
$targets = array(
    'app.js（无参数）'        => '/assets/js/app.js',
    'app.js（带版本参数）'    => '/assets/js/app.js?v=1.1.0',
    'core.js'                 => '/assets/js/core.js',
    'app.css'                 => '/assets/css/app.css',
    'vendor 液态玻璃库'       => '/assets/vendor/liquid-glass@0.3.2.js',
);

function probe(string $url): array
{
    $out = array('status' => '(未请求)', 'type' => '(未知)', 'len' => 0, 'head' => '');
    if (!function_exists('curl_init')) {
        $out['status'] = 'curl 扩展缺失';
        return $out;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOBODY         => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 KimiRankSelfCheck',
    ));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $ret  = curl_error($ch);
    curl_close($ch);
    $out['status'] = $ret !== '' ? ('curl 错误: ' . $ret) : ('HTTP ' . $code);
    $out['type']   = $type === '' ? '(无 Content-Type)' : $type;
    if (is_string($raw)) {
        $pos = strpos($raw, "\r\n\r\n");
        $body = $pos === false ? '' : substr($raw, $pos + 4);
        $out['len'] = strlen($body);
        $out['head'] = substr(preg_replace('/\s+/', ' ', trim($body)), 0, 90);
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>资源响应头检测</title>
<style>
body{margin:0;background:#f6f7f9;color:#1f2329;font:14px/1.6 -apple-system,"PingFang SC",sans-serif}
.wrap{max-width:900px;margin:22px auto;padding:0 14px}
.card{background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:18px;margin-bottom:14px}
h1{font-size:18px;margin:0 0 10px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:8px 9px;border-bottom:1px solid #eee;vertical-align:top}
th{color:#8a8f99}
code{background:#f1f2f4;padding:1px 5px;border-radius:5px;font-size:12px;word-break:break-all}
.ok{color:#1a7f37;font-weight:700}
.bad{color:#c62a2f;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
  <h1>静态资源响应头检测</h1>
  <p style="color:#8a8f99;margin:0 0 10px">从服务器自身请求，校验 HTTP 状态与 Content-Type。<b>关键判断：</b><code>app.js</code> 的 Content-Type 必须是 <code>text/javascript</code> 或 <code>application/javascript</code>。</p>
  <table>
    <tr><th>资源</th><th>状态</th><th>Content-Type</th><th>大小</th><th>内容开头</th></tr>
    <?php foreach ($targets as $name => $path):
        $r = probe($host . $path);
        $isJs = strpos($path, '.js') !== false;
        $typeOk = !$isJs || (stripos($r['type'], 'javascript') !== false);
    ?>
    <tr>
      <td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td>
      <td class="<?= strpos($r['status'], 'HTTP 200') === 0 ? 'ok' : 'bad' ?>"><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></td>
      <td class="<?= $typeOk ? 'ok' : 'bad' ?>"><?= htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= (int)$r['len'] ?> B</td>
      <td><code><?= htmlspecialchars($r['head'], ENT_QUOTES, 'UTF-8') ?></code></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<div class="card">
  <b>如何判读：</b>
  <ul style="margin:8px 0 0;padding-left:20px;color:#5c5f6b">
    <li>若 <code>app.js</code> 的 Content-Type 是 <code>text/plain</code> 或 <code>application/octet-stream</code> → MIME 问题，本次的 .htaccess 已加 <code>AddType</code> 修正，覆盖上传后应恢复。</li>
    <li>若状态不是 200（如 403 / 302）→ 主机拦截了该请求，需换资源加载方式。</li>
    <li>若 Content-Type 正常、状态 200 但网页仍报加载失败 → 把这一页截图发我，继续定位。</li>
  </ul>
</div>
</div>
</body>
</html>
