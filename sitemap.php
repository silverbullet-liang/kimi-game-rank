<?php
/**
 * sitemap.xml（动态）：列出首页、各文档页、通报页与全部未下架作品。
 * 镜像站输出空 sitemap（不索引）。由 .htaccess 将 /sitemap.xml 内部重写到本文件。
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
try { echo seo_sitemap_xml(); }
catch (Throwable $e) {
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"></urlset>\n";
}
