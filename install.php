<?php
/**
 * 安装器 v2：无表单，一键自动初始化（读 config/config.php 的参数）
 * 绝大多数情况下无需访问本页——首次打开网站即自动完成初始化。
 */
declare(strict_types=1);

define('ROOT', __DIR__);
$lockFile = ROOT . '/storage/installed.lock';
$log = array();
$fatal = '';
$done = false;

error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
    if (!file_exists(ROOT . '/config/config.php')) {
        throw new Exception('缺少 config/config.php');
    }
    $GLOBALS['APP_CONFIG'] = require ROOT . '/config/config.php';

    date_default_timezone_set('UTC');
    if (function_exists('mb_internal_encoding')) { mb_internal_encoding('UTF-8'); }

    require_once ROOT . '/app/db.php';
    require_once ROOT . '/app/helpers.php';
    require_once ROOT . '/app/crypto.php';
    require_once ROOT . '/app/scoring.php';
    require_once ROOT . '/app/auth.php';
    require_once ROOT . '/app/feed_client.php';
    require_once ROOT . '/app/zhipu.php';
    require_once ROOT . '/app/ip_lookup.php';
    require_once ROOT . '/app/autoinstall.php';

    /* 已安装：本页不该继续对外存在。直接 404 —— 否则任何访客都能读到
       初始化日志与原始报错（含库名、主机名）。确需重装请先删除
       storage/installed.lock。 */
    if (file_exists($lockFile)) {
        http_response_code(404);
        exit('Not Found');
    }

    auto_install();
    $log[] = '数据库连接成功';
    $log[] = '主库与管理员库表结构就绪';
    $log[] = '密钥生成完成（RC4 组合密钥 / AES 100 位 / cron key）并已写回 config.php';
    $log[] = '管理员 admin 凭证已写入独立库（仅存 sha256 之前原文）';
    $done = true;
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · Kimi游戏榜</title>
<style>
:root{--bd:#e3e5e8;--tx:#1f2329;--sub:#8a8f99;--pri:#7C3AED;--bg:#f6f7f9}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);font:14px/1.6 -apple-system,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:680px;margin:48px auto;padding:0 16px}
.card{background:#fff;border:1px solid var(--bd);border-radius:12px;padding:28px}
h1{font-size:20px;margin:0 0 6px}
p.sub{color:var(--sub);margin:0 0 24px}
.step{border-left:3px solid var(--pri);background:#faf8ff;padding:8px 12px;margin:8px 0;border-radius:0 6px 6px 0;font-size:13px}
.err{border-left-color:#e5484d;background:#fff5f5;color:#c62a2f}
.ok{color:#1a7f37;font-weight:600}
a.btn{display:block;text-align:center;margin-top:20px;padding:12px;border-radius:8px;background:var(--pri);color:#fff;font-weight:600;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Kimi游戏榜 · 安装</h1>
    <p class="sub">读取 config/config.php 的数据库参数，自动完成建表、密钥生成与管理员预置。</p>
    <?php foreach ($log as $l): ?><div class="step"><?= h($l) ?></div><?php endforeach; ?>
    <?php if ($fatal !== ''): ?><div class="step err">安装失败：<?= h($fatal) ?></div><?php endif; ?>
    <?php if ($done): ?>
      <p class="ok">✅ 就绪。此页以后无需再访问。</p>
      <a class="btn" href="./">进入首页</a>
    <?php else: ?>
      <p class="hint" style="color:var(--sub);font-size:12px;margin-top:14px">修复后刷新本页即可重试。</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
