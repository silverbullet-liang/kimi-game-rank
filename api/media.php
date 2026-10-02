<?php
/**
 * API：图片上传与读取
 * ------------------------------------------------------------
 * POST（登录用户）：multipart/form-data，字段 file → 返回 {id, url}
 * GET  ?id=YYYYMMDD/hash.ext → 输出图片
 * 安全：真实图片校验 + 大小/类型白名单 + 严格 id 格式（防路径穿越）
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

define('MEDIA_MAX_BYTES', 2 * 1024 * 1024);   // 2MB
define('MEDIA_EXTS', array('jpg', 'jpeg', 'png', 'gif', 'webp'));

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

/* ============================================================
 * 上传
 * ============================================================ */
if ($method === 'POST') {
    require_member();        // 登录用户或管理员
    csrf_verify();

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) { fail(400, '未接收到文件'); }
    $f = $_FILES['file'];
    if (isset($f['error']) && (int)$f['error'] !== UPLOAD_ERR_OK) { fail(400, '上传失败（错误码 ' . (int)$f['error'] . '）'); }
    if ((int)$f['size'] <= 0) { fail(400, '文件为空'); }
    if ((int)$f['size'] > MEDIA_MAX_BYTES) { fail(413, '图片不能超过 2MB'); }

    $tmp = (string)$f['tmp_name'];
    if (!is_uploaded_file($tmp)) { fail(400, '非法上传'); }

    $info = @getimagesize($tmp);
    if ($info === false || empty($info['mime'])) { fail(415, '不是有效的图片'); }
    $mimeToExt = array(
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
    );
    $mime = strtolower((string)$info['mime']);
    if (!isset($mimeToExt[$mime])) { fail(415, '仅支持 JPG / PNG / GIF / WebP'); }
    $ext = $mimeToExt[$mime];

    /* 原始文件名仅作展示：先净化（去路径与控制字符、限制扩展名），绝不原样落盘或回显 */
    $origin = safe_filename((string)(isset($f['name']) ? $f['name'] : ''), MEDIA_EXTS);

    cooldown_guard('upload');

    $day = gmdate('Ymd');
    $dir = APP_ROOT . '/storage/uploads/' . $day;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { fail(500, '存储目录不可写'); }

    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) { fail(500, '保存失败'); }

    /* 图片审核：先感知（OVHcloud 视觉 OCR + 违规标签）、再判断（Jev），
       任一环不可用自动切智谱视觉作备选。审核不通过就删掉文件再拒绝，
       不留半成品在磁盘上。 */
    try {
        $verdict = image_audit_check($dir . '/' . $name);
    } catch (Throwable $e) {
        app_log('image_audit error: ' . $e->getMessage());
        $verdict = array('ok' => true, 'via' => 'error', 'reason' => '');
    }
    if (empty($verdict['ok'])) {
        @unlink($dir . '/' . $name);
        fail(422, (string)$verdict['reason'] !== '' ? (string)$verdict['reason'] : '图片未通过审核，请更换后重试');
    }

    $idv = $day . '/' . $name;
    ok(array(
        'id'   => $idv,
        'name' => $origin,
        'url'  => 'api/media.php?id=' . rawurlencode($idv),
    ), '上传成功');
}

/* ============================================================
 * 读取
 * ============================================================ */
$idv = isset($_GET['id']) ? (string)$_GET['id'] : '';
if (!preg_match('#^(\d{8})/([a-f0-9]{16})\.(jpg|jpeg|png|gif|webp)$#', $idv, $m)) {
    http_response_code(400); exit('bad id');
}
$path = APP_ROOT . '/storage/uploads/' . $m[1] . '/' . $m[2] . '.' . $m[3];
if (!is_file($path)) { http_response_code(404); exit('not found'); }

$types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');
header('Content-Type: ' . $types[$m[3]]);
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
exit;
