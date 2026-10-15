<?php
/**
 * robots.txt（动态）：按当前域名给出正确的 Sitemap 地址；镜像站 Disallow All。
 * 由 .htaccess 将 /robots.txt 内部重写到本文件。
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
try { echo seo_robots_txt(); }
catch (Throwable $e) { echo "User-agent: *\nAllow: /\n"; }
