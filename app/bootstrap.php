<?php
/**
 * 应用引导：加载配置与全部核心库，统一初始化。
 * 所有入口（index.php / api/*.php / cron.php）都先 require 本文件。
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '3.9.1');

if (!file_exists(APP_ROOT . '/config/config.php')) {
    http_response_code(500);
    exit('未安装：请先访问 install.php');
}

/* ---------- 致命错误兜底：把裸 500 变成可读诊断页 ---------- */
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e === null) { return; }
    if (!in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) { return; }
    if (headers_sent()) { return; }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $msg  = htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8');
    $file = htmlspecialchars($e['file'], ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<body style="font-family:-apple-system,sans-serif;background:#f6f7f9;padding:28px">'
       . '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:24px">'
       . '<h2 style="margin:0 0 10px;color:#c62a2f;font-size:18px">启动失败 · 诊断信息</h2>'
       . '<p style="margin:0 0 8px"><b>' . $msg . '</b></p>'
       . '<p style="margin:0;color:#666;font-size:13px">' . $file . ' : 第 ' . $e['line'] . ' 行</p>'
       . '<p style="margin:14px 0 0;color:#999;font-size:12px">将本页截图发送给开发者即可修复；修复后此页自动消失。</p>'
       . '</div></body>';
});

/* ---------- PHP 运行环境预检（InfinityFree 等主机关闭 display_errors 时仍可读） ---------- */
if (!function_exists('mb_strlen')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<body style="font-family:sans-serif;background:#f6f7f9;padding:28px"><div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:24px"><h2 style="color:#c62a2f;font-size:18px;margin:0 0 10px">缺少 mbstring 扩展</h2><p>请到主机面板将 PHP 版本切换为 7.4+（mbstring 默认启用），或启用 mbstring 扩展后刷新。</p></div>');
}

$APP_CONFIG = require APP_ROOT . '/config/config.php';

/* ---------- 时区与错误 ---------- */
date_default_timezone_set('UTC');            // 库内全 UTC，展示层再转本地
mb_internal_encoding('UTF-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php_error.log');
error_reporting(E_ALL);

/* ---------- 加载核心库 ---------- */
require_once APP_ROOT . '/app/db.php';
require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/crypto.php';
require_once APP_ROOT . '/app/scoring.php';
require_once APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/feed_client.php';
require_once APP_ROOT . '/app/zhipu.php';
require_once APP_ROOT . '/app/openrouter.php';
require_once APP_ROOT . '/app/captcha.php';
require_once APP_ROOT . '/app/image_audit.php';
require_once APP_ROOT . '/app/jev.php';
require_once APP_ROOT . '/app/moderation.php';
require_once APP_ROOT . '/app/lunar.php';
require_once APP_ROOT . '/app/festival.php';
require_once APP_ROOT . '/app/chat_media.php';
require_once APP_ROOT . '/app/backup.php';
require_once APP_ROOT . '/app/link_smart.php';
require_once APP_ROOT . '/app/siteinfo.php';
require_once APP_ROOT . '/app/works_list.php';
require_once APP_ROOT . '/app/works_tool.php';
require_once APP_ROOT . '/app/checkin.php';
require_once APP_ROOT . '/app/uid.php';
require_once APP_ROOT . '/app/ip_lookup.php';
require_once APP_ROOT . '/app/discipline.php';
require_once APP_ROOT . '/app/migrate.php';
require_once APP_ROOT . '/app/autoinstall.php';

/* ---------- 自动初始化：首次访问零操作完成建表/密钥/管理员 ---------- */
try {
    auto_install();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit('<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:-apple-system,sans-serif;background:#f6f7f9;padding:28px">'
        . '<div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:24px">'
        . '<h2 style="margin:0 0 10px;color:#c62a2f;font-size:18px">自动初始化未完成</h2>'
        . '<p style="margin:0 0 10px;line-height:1.7">' . $msg . '</p>'
        . '<p style="margin:0;color:#999;font-size:12px">修复后刷新本页即可自动完成剩余步骤。</p>'
        . '</div></body>');
}

/* ---------- 数据库结构自动迁移（首次访问即升级） ---------- */
try {
    run_migrations();
} catch (Throwable $e) {
    app_log('migration error: ' . $e->getMessage());
}

/* ---------- 违纪封禁：API 一律拒绝 ----------
 * 被通报封停的账号或来源，前端会整体锁在封禁通知界面；这里做后端兜底：
 * 除违纪界面自身所需的白名单接口外，所有 API 直接拒绝。
 * 页面入口（index.php）不在此列 —— 否则连封禁通知都显示不出来。
 * 管理员与副管理员永不因此被拦。 */
if (is_api_request()) {
    try {
        if (function_exists('discipline_visitor_blocked') && discipline_visitor_blocked()) {
            $__api = basename((string)(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : ''));
            if (!in_array($__api, array('start.php', 'auth.php', 'discipline.php'), true)) {
                json_out(403, '账号已被封停，暂时无法使用本站功能');
            }
        }
    } catch (Throwable $e) {
        app_log('discipline api guard failed: ' . $e->getMessage());
    }
}

/* ---------- 全局异常兜底 ---------- */
set_exception_handler(function ($e) {
    app_log('EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (is_api_request()) {
        json_out(500, '服务器内部错误');
    }
    http_response_code(500);
    exit('服务器内部错误');
});

/* ---------- 存储自清理（约 2% 请求抽样；失败不阻断请求） ---------- */
try { if (function_exists('storage_gc')) { storage_gc(); } } catch (Throwable $e) {}

/* ---------- 会话（仅用于管理面板二次认证等短状态） ---------- */
if (session_status() !== PHP_SESSION_ACTIVE && php_sapi_name() !== 'cli') {
    session_name('kimgr_sess');
    // PHP 7.0 兼容：使用旧版参数签名；SameSite 通过 ini 尽力设置（7.3+ 生效，低版本忽略）
    @ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params(0, '/', '', is_https(), true);
    session_start();
}
