<?php
/**
 * 排障工具访问口令（自包含，不依赖应用框架）
 * ------------------------------------------------------------
 * check.php / selfcheck.php / mimecheck.php / diag.php 是一次性排障工具，
 * 不属于站点功能，却会输出运行环境指纹、文件清单、数据库连接信息与报错原文。
 * 「用完即删」靠人记住并不可靠，所以口令校验写进文件本身：
 *   口令取自 config/api_keys.php 的 debug_key；未配置即完全不可访问（fail-closed）。
 * 访问方式：/check.php?k=<debug_key>
 */
$__debug_key = '';
$__key_file = __DIR__ . '/config/api_keys.php';
if (is_file($__key_file)) {
    $__keys = require $__key_file;
    if (is_array($__keys) && isset($__keys['debug_key'])) { $__debug_key = (string)$__keys['debug_key']; }
}
$__given = isset($_GET['k']) ? (string)$_GET['k'] : '';
if ($__debug_key === '' || $__given === '' || !hash_equals($__debug_key, $__given)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not Found');
}
unset($__keys, $__key_file, $__given);
