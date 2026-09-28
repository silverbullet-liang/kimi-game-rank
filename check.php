<?php
/**
 * 环境诊断（用完即删）
 * 访问 /check.php 逐项输出，定位 500 来源。
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json; charset=utf-8');
$result = array();

/* 1. PHP 版本 */
$result['php_version'] = PHP_VERSION;

/* 2. 关键扩展 */
$result['extensions'] = array(
    'pdo'        => extension_loaded('pdo'),
    'pdo_mysql'  => extension_loaded('pdo_mysql'),
    'mbstring'   => extension_loaded('mbstring'),
    'curl'       => extension_loaded('curl'),
    'openssl'    => extension_loaded('openssl'),
    'json'       => extension_loaded('json'),
    'session'    => extension_loaded('session'),
);

/* 3. 配置文件 */
try {
    $c = require __DIR__ . '/config/config.php';
    $result['config_ok'] = is_array($c);
    $result['db_host'] = isset($c['db']['host']) ? $c['db']['host'] : '(missing)';
    $result['db_name'] = isset($c['db']['name']) ? $c['db']['name'] : '(missing)';
    $result['secrets_filled'] = !empty($c['secrets']['rc4_key']);
} catch (Throwable $e) {
    $result['config_ok'] = false;
    $result['config_error'] = $e->getMessage();
}

/* 4. storage 可写 */
$result['storage_writable'] = is_writable(__DIR__ . '/storage');
@file_put_contents(__DIR__ . '/storage/logs/check_probe.txt', 'ok');
$result['log_write_ok'] = file_exists(__DIR__ . '/storage/logs/check_probe.txt');

/* 5. 主库连接 + 表结构 */
try {
    $c = require __DIR__ . '/config/config.php';
    $db = $c['db'];
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . (int)$db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10));
    $result['db_main_connect'] = true;
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $result['db_main_tables'] = $tables;
} catch (Throwable $e) {
    $result['db_main_connect'] = false;
    $result['db_main_error'] = $e->getMessage();
}

/* 6. 管理员库连接 */
try {
    $c = require __DIR__ . '/config/config.php';
    $db = $c['db_admin'];
    $pdo2 = new PDO('mysql:host=' . $db['host'] . ';port=' . (int)$db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10));
    $result['db_admin_connect'] = true;
    $result['db_admin_tables'] = $pdo2->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $r = $pdo2->query('SELECT username FROM admin_credentials LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $result['admin_row_exists'] = $r ? true : false;
} catch (Throwable $e) {
    $result['db_admin_connect'] = false;
    $result['db_admin_error'] = $e->getMessage();
}

/* 6.5 期望表 vs 实际表（缺表 = 很多功能直接 500 的根源） */
try {
    $expect = json_decode((string)file_get_contents(__DIR__ . '/app/schema.json'), true);
    $mainTables = (isset($result['db_main_tables']) && is_array($result['db_main_tables'])) ? $result['db_main_tables'] : array();
    $admTables  = (isset($result['db_admin_tables']) && is_array($result['db_admin_tables'])) ? $result['db_admin_tables'] : array();
    $missMain = array(); $missAdm = array();
    foreach ((isset($expect['main']) ? $expect['main'] : array()) as $t => $ddl) {
        if (!in_array($t, $mainTables, true)) { $missMain[] = $t; }
    }
    foreach ((isset($expect['admin']) ? $expect['admin'] : array()) as $t => $ddl) {
        if (!in_array($t, $admTables, true)) { $missAdm[] = $t; }
    }
    $result['missing_main_tables'] = $missMain;
    $result['missing_admin_tables'] = $missAdm;
    if ($missMain || $missAdm) {
        $result['hint'] = '发现缺失表：刷新任意页面会自动执行 v6 自愈迁移补建；若刷新两次后仍缺失，请把本页结果发给开发者。';
    }
} catch (Throwable $e) { $result['schema_check_error'] = $e->getMessage(); }

/* 6.6 世界对话核心 SQL 实测（直接给出成败与错误信息） */
try {
    if (!empty($pdo)) {
        $rows = $pdo->query('SELECT m.id, m.user_id, m.content, m.msg_type, m.media_url, m.is_recalled, m.created_at, u.username, u.role
                             FROM messages m JOIN users u ON u.id = m.user_id
                             ORDER BY m.id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
        $result['world_chat_sql'] = 'ok（返回 ' . count($rows) . ' 行）';
    } else {
        $result['world_chat_sql'] = 'skipped（主库未连接）';
    }
} catch (Throwable $e) {
    $result['world_chat_sql'] = 'FAILED: ' . $e->getMessage();
}

/* 7. 引导文件语法自检（逐个 require） */
$appFiles = array('app/db.php','app/helpers.php','app/crypto.php','app/scoring.php','app/auth.php','app/feed_client.php','app/zhipu.php','app/works_tool.php','app/uid.php','app/ip_lookup.php','app/migrate.php','app/autoinstall.php','app/bootstrap.php');
$requireResults = array();
foreach ($appFiles as $f) {
    $before = get_declared_functions();
    try {
        ob_start();
        require_once __DIR__ . '/' . $f;
        ob_end_clean();
        $requireResults[$f] = 'ok';
    } catch (Throwable $e) {
        ob_end_clean();
        $requireResults[$f] = 'ERROR: ' . $e->getMessage() . ' @ line ' . $e->getLine();
    }
}
$result['require_files'] = $requireResults;

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
