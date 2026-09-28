<?php
/**
 * 定时任务入口（供主机 crontab / 外部计划任务调用）
 * 用法：GET /cron.php?key={cron_key}&task=sync
 * 说明：GET + key 方式，豁免 CSRF 与登录令牌（密钥本身即凭证）。
 */
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
$expect = (string)cfg('secrets.cron_key', '');
if ($expect === '' || !hash_equals($expect, $key)) {
    http_response_code(403);
    echo json_encode(array('code' => 403, 'msg' => 'cron key 校验失败'));
    exit;
}

$task = isset($_GET['task']) ? (string)$_GET['task'] : 'sync';

try {
    if ($task === 'sync') {
        $saved = setting_get('kimi_token', '');
        if ($saved === '') { throw new RuntimeException('未配置社区凭证'); }
        $token = aes_decrypt((string)$saved);
        $r = sync_from_feeds($token, 'recommend', 3, 60);
        echo json_encode(array('code' => 0, 'msg' => 'ok', 'data' => $r), JSON_UNESCAPED_UNICODE);
    } elseif ($task === 'emoji') {
        require_once ROOT . '/tools/fetch_emoji.php';
        $r = fetch_emoji_pack();
        echo json_encode(array('code' => 0, 'msg' => 'ok', 'data' => $r), JSON_UNESCAPED_UNICODE);
    } elseif ($task === 'cleanup') {
        // 清理过期黑名单与统计缓存
        db_exec('DELETE FROM token_blacklist WHERE expires_at < UTC_TIMESTAMP()');
        db_exec('DELETE FROM login_attempts WHERE created_at < ?', array(gmdate('Y-m-d H:i:s', time() - 86400)));
        // 清理冷却/限流计数文件（24 小时未活动即无意义，且避免 inode 无限堆积）
        $n = 0;
        foreach (array('cd_', 'op_') as $prefix) {
            foreach ((array)@glob(APP_ROOT . '/storage/cache/' . $prefix . '*.txt') as $f) {
                if (@is_file($f) && @filemtime($f) < time() - 86400) { @unlink($f); $n++; }
            }
        }
        echo json_encode(array('code' => 0, 'msg' => 'ok', 'data' => array('purged' => $n)));
    } else {
        echo json_encode(array('code' => 400, 'msg' => '未知任务'));
    }
} catch (Exception $e) {
    app_log('cron error: ' . $e->getMessage());
    echo json_encode(array('code' => 500, 'msg' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
}
