<?php
/**
 * 上传内容守卫（v3.51.0）
 * ------------------------------------------------------------
 * 图片上传的四个风险点，逐一堵住：
 *   1) 解压炸弹（像素极大、字节很小）—— 限制单边与总像素；
 *   2) 图片马（GIF89a + `<?php` 之类多语言文件）—— 扫描脚本标签；
 *   3) 元数据夹带（EXIF / XMP / 尾部附加数据）—— GD 重编码彻底剥离；
 *   4) 落盘后可执行 —— 由 storage/uploads/.htaccess 关闭引擎并拒绝直接访问。
 * 本文件只放纯函数，便于离线自检。
 */
declare(strict_types=1);

if (!defined('UPLOAD_MAX_SIDE'))   { define('UPLOAD_MAX_SIDE', 12000); }
if (!defined('UPLOAD_MAX_PIXELS')) { define('UPLOAD_MAX_PIXELS', 40000000); }   // 4000 万像素

/** 尺寸是否在安全范围（防解压炸弹：字节数小但解码后占几百 MB） */
function upload_guard_dims_ok(array $info, int $maxSide = UPLOAD_MAX_SIDE, int $maxPixels = UPLOAD_MAX_PIXELS): bool
{
    $w = isset($info[0]) ? (int)$info[0] : 0;
    $h = isset($info[1]) ? (int)$info[1] : 0;
    if ($w <= 0 || $h <= 0) { return false; }
    if ($w > $maxSide || $h > $maxSide) { return false; }
    return ($w * $h) <= $maxPixels;
}

/** 扫描文件内容里是否夹带脚本/标记（返回命中的标记，空串表示干净） */
function upload_guard_scan(string $path): string
{
    if (!is_file($path)) { return 'missing'; }
    $size = (int)@filesize($path);
    if ($size <= 0 || $size > 8 * 1024 * 1024) { return 'size'; }
    $raw = (string)@file_get_contents($path);
    if ($raw === '') { return 'unreadable'; }
    $lower = strtolower($raw);
    $marks = array('<?php', '<?=', '<script', '<svg', '<%', '#!/', 'eval(', 'base64_decode(');
    foreach ($marks as $m) {
        if (strpos($lower, $m) !== false) { return $m; }
    }
    return '';
}

/**
 * 用 GD 重编码，彻底剥离 EXIF / XMP / 尾部附加数据，并统一输出格式。
 * GIF 不重编码（会丢动画帧），由 upload_guard_scan + 目录禁执行兜底。
 * GD 不可用时返回 false，由调用方决定是否拒绝（当前策略：放行但已扫描过内容）。
 */
function upload_guard_reencode(string $path, string $ext): bool
{
    if ($ext === 'gif') { return false; }                        // 保动画，靠扫描与目录禁执行兜底
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) { return false; }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') { return false; }
    $img = @imagecreatefromstring($raw);
    if ($img === false) { return false; }

    /* 透明的 PNG / WebP 需要保留 alpha 通道 */
    if ($ext === 'png' || $ext === 'webp') {
        @imagealphablending($img, false);
        @imagesavealpha($img, true);
    }

    $tmp = $path . '.re';
    $ok  = false;
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $ok = @imagejpeg($img, $tmp, 92);
    } elseif ($ext === 'png') {
        $ok = @imagepng($img, $tmp, 6);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        $ok = @imagewebp($img, $tmp, 92);
    }
    @imagedestroy($img);

    if (!$ok || !is_file($tmp) || (int)@filesize($tmp) <= 0) {
        @unlink($tmp);
        return false;
    }
    if ((int)@filesize($tmp) > 4 * 1024 * 1024) {                 // 重编码后不应膨胀
        @unlink($tmp);
        return false;
    }
    $moved = @rename($tmp, $path);
    if (!$moved) {
        @unlink($tmp);
        return false;
    }
    return true;
}
