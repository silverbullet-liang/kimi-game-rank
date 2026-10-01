<?php
/**
 * 部署自检 / 故障诊断
 * ------------------------------------------------------------
 * 完全独立于应用框架：不 require bootstrap、不依赖任何业务代码，
 * 因此「整站 500」时它仍然能运行，用来看清到底坏在哪一步。
 *
 * 用法：https://你的域名/diag.php?k=kimi-diag-2026
 * ⚠️ 诊断完请立刻删除本文件。
 */

$KEY = 'kimi-diag-2026';
if (!isset($_GET['k']) || !hash_equals($KEY, (string)$_GET['k'])) {
    http_response_code(404);
    exit('Not Found');
}
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(30);

$root = __DIR__;
$line = str_repeat('=', 56) . "\n";
echo $line, "站点自检\n", $line;

/* ---------- 1. 运行环境 ---------- */
echo "\n[1] 运行环境\n";
echo "PHP 版本    : " . PHP_VERSION . "\n";
echo "SAPI        : " . php_sapi_name() . "\n";
foreach (array('pdo_mysql', 'curl', 'mbstring', 'json', 'openssl') as $ext) {
    echo sprintf("扩展 %-10s: %s\n", $ext, extension_loaded($ext) ? '有' : '【缺失】');
}
echo "storage 目录 : storage/ " . (is_dir($root . '/storage') ? '存在' : '【不存在】') . "\n";
$w = $root . '/storage';
echo "storage 可写: " . (is_writable($w) ? '是' : '【否 —— 迁移锁与缓存都会失败】') . "\n";

/* ---------- 2. 关键文件 ---------- */
echo "\n[2] 关键文件\n";
foreach (array('app/bootstrap.php', 'app/migrate.php', 'app/helpers.php',
               'app/schema.json', 'config/config.php', 'index.php') as $f) {
    $p = $root . '/' . $f;
    echo sprintf("%-24s: %s\n", $f, is_file($p) ? ('在（' . filesize($p) . ' 字节）') : '【缺失】');
}

/* 目录扫描：文件「存在」不等于「能用」。
   上线时最阴的一类故障是某个 .php 被误传成了同名目录（或权限不对）——
   此时服务器会直接返回 403，后端 PHP 根本不会执行，前端只表现为「请求失败」，
   网络面板里也看不出所以然。这里不做清单比对（清单会过期），直接扫目录，
   凡不是「正常可读的普通文件」的条目一律列出。 */
echo "\n  目录扫描（凡不是正常可读文件的条目）：\n";
$badEntries = array();
$allowDirs  = array();   // 交付包不含源码目录，以下位置不应再有子目录
foreach (array('api', 'app') as $dir) {
    $dh = @opendir($root . '/' . $dir);
    if ($dh === false) { echo sprintf("  %-24s: 【目录不存在】\n", $dir); continue; }
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $root . '/' . $dir . '/' . $e;
        if (is_dir($p)) {
            if (!in_array($dir . '/' . $e, $allowDirs, true)) { $badEntries[] = $dir . '/' . $e . '（被传成了目录）'; }
        }
        elseif (!is_file($p))     { $badEntries[] = $dir . '/' . $e . '（不是普通文件）'; }
        elseif (!is_readable($p)) { $badEntries[] = $dir . '/' . $e . '（不可读）'; }
    }
    closedir($dh);
}
echo $badEntries
    ? ('  【异常】' . implode("\n            ", $badEntries)
       . "\n    修复：用 FTP 把该条目整个删除，再从压缩包重新上传对应文件，权限 644\n")
    : "  全部正常\n";

/* ---------- 3. 迁移状态 ---------- */
echo "\n[3] 迁移状态\n";
$locks = glob($root . '/storage/migrated_*.lock');
echo "迁移锁文件  : " . ($locks ? implode(', ', array_map('basename', $locks)) : '无') . "\n";
echo "体检时间戳  : storage/schema_checked.txt " .
     (is_file($root . '/storage/schema_checked.txt') ? '存在' : '无') . "\n";

/* ---------- 4. 数据库连接与结构 ---------- */
echo "\n[4] 数据库\n";
$cfgFile = $root . '/config/config.php';
if (!is_file($cfgFile)) { exit("\n【config/config.php 缺失，无法继续】\n"); }
$cfg = @include $cfgFile;
$db = isset($cfg['db']) ? $cfg['db'] : array();
if (empty($db['name'])) { exit("\n【config 里没有数据库配置】\n"); }

try {
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int)$db['port'] . ';dbname=' . $db['name']
         . ';charset=' . (isset($db['charset']) ? $db['charset'] : 'utf8mb4');
    $pdo = new PDO($dsn, $db['user'], $db['pass'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    echo "连接        : 成功\n";
    echo "服务端版本  : " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
} catch (Throwable $e) {
    exit("连接        : 【失败】" . $e->getMessage() . "\n");
}

/* schema_version（表结构是 settings(k, v)，不是 key / value） */
$ver = '未知';
try {
    $st = $pdo->query("SELECT v FROM settings WHERE k = 'schema_version' LIMIT 1");
    $row = $st ? $st->fetch() : null;
    if ($row) { $ver = is_array($row) ? array_values($row)[0] : $row; }
} catch (Throwable $e) {
    $ver = '读取失败：' . $e->getMessage();
}
/* 期望值直接从代码里取，避免写死之后代码升了版本它却没说 */
$expectVer = '?';
if (preg_match("/SCHEMA_VERSION',\s*(\d+)/", (string)@file_get_contents($root . '/app/migrate.php'), $m)) {
    $expectVer = $m[1];
}
echo "schema_version: " . $ver . "（代码期望 " . $expectVer . "）\n";
if ($expectVer !== '?' && (string)$ver !== (string)$expectVer) {
    echo "              ^ 不一致：刷新首页会自动补跑迁移；若长期不变，检查 storage 是否可写\n";
}

/* 表与关键列 */
$expect = array(
    'users'    => array('role', 'uid8'),
    'messages' => array('msg_type', 'media_url', 'is_recalled'),
    'comments' => array('content_norm', 'is_deleted', 'deleted_by'),
    'feedback' => array('content_norm', 'is_public', 'is_deleted', 'admin_reply', 'replied_at'),
    'works'    => array('vote_count', 'peak_score'),
);
$tables = array();
try {
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) { $tables[$r[0]] = true; }
} catch (Throwable $e) { echo "读取表清单失败：" . $e->getMessage() . "\n"; }

echo "\n[5] 关键列体检（缺列会让对应功能直接 500）\n";
$missing = 0;
foreach ($expect as $t => $cols) {
    if (!isset($tables[$t])) { echo sprintf("  %-9s: 【表不存在】\n", $t); $missing++; continue; }
    $have = array();
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . $t . '`')->fetchAll(PDO::FETCH_NUM) as $r) { $have[$r[0]] = true; }
    } catch (Throwable $e) { echo sprintf("  %-9s: 读取失败 %s\n", $t, $e->getMessage()); continue; }
    $bad = array();
    foreach ($cols as $c) { if (!isset($have[$c])) { $bad[] = $c; } }
    if ($bad) { $missing += count($bad); echo sprintf("  %-9s: 【缺少 %s】\n", $t, implode(', ', $bad)); }
    else      { echo sprintf("  %-9s: 正常（%s）\n", $t, implode(', ', $cols)); }
}
echo $missing ? "\n>>> 共缺少 {$missing} 个关键列：这就是 500 的原因。\n"
              : "\n>>> 关键列齐全，它不应该是 500 的原因。\n";

/* ---------- 6. 最近日志 ---------- */
echo "\n[6] 最近日志（尾部 60 行）\n";
$logDir = $root . '/storage/logs';
$logs = is_dir($logDir) ? glob($logDir . '/*.log') : array();
if (!$logs) {
    echo "无日志文件\n";
} else {
    usort($logs, function ($a, $b) { return filemtime($b) - filemtime($a); });
    $f = $logs[0];
    echo "文件: " . basename($f) . "（更新于 " . date('Y-m-d H:i:s', filemtime($f)) . "）\n";
    $lines = @file($f, FILE_IGNORE_NEW_LINES);
    if ($lines) { echo implode("\n", array_slice($lines, -60)) . "\n"; }
}

echo "\n", $line, "自检结束\n", $line;
