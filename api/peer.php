<?php
/**
 * 站点互通端点（供对端服务器调用，不涉及浏览器会话）
 * ------------------------------------------------------------
 * POST body = peer_pack 产出的密文（base64, text/plain）。
 * 认证即解密：只有「本站私钥 + 对端公钥」配对的请求才解得开，密钥不符直接拒。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 4000000) { http_response_code(400); exit('bad request'); }

try {
    if (!peer_crypto_ready()) { throw new RuntimeException('sodium 扩展不可用'); }
    $mySk = peer_my_private();
    if (!peer_key_valid($mySk)) { throw new RuntimeException('本站私钥未配置'); }

    /* 用站点列表里已登记的对端公钥逐个试解：只有登记过且公钥正确的对端才解得开。
       列表规模很小，逐个尝试的代价可忽略；解不开即视为无权限。 */
    $msg = null; $from = null;
    foreach (db_all('SELECT * FROM peers WHERE enabled = 1') as $p) {
        $pk = trim((string)$p['pubkey']);
        if (!peer_key_valid($pk)) { continue; }
        try { $msg = peer_unpack($raw, $mySk, $pk); $from = $p; break; }
        catch (Throwable $e) { /* 继续试下一个对端 */ }
    }
    if ($msg === null || $from === null) { throw new RuntimeException('无法解密：对端未登记或密钥不符'); }

    $action = isset($msg['action']) ? (string)$msg['action'] : '';
    $data   = isset($msg['data']) && is_array($msg['data']) ? $msg['data'] : array();

    $reply = array('ok' => true);
    if ($action === 'hello') {
        $reply['manifest'] = sync_manifest();
    } elseif ($action === 'fetch') {
        $t = isset($data['table']) ? (string)$data['table'] : '';
        if (!isset(sync_plan()[$t])) { throw new RuntimeException('未知数据表'); }
        $off = isset($data['offset']) ? max(0, (int)$data['offset']) : 0;
        $lim = isset($data['limit']) ? min(500, max(1, (int)$data['limit'])) : 200;
        $reply['rows'] = sync_export($t, $off, $lim);
    } elseif ($action === 'apply') {
        $t = isset($data['table']) ? (string)$data['table'] : '';
        if (!isset(sync_plan()[$t])) { throw new RuntimeException('未知数据表'); }
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : array();
        if (count($rows) > 500) { $rows = array_slice($rows, 0, 500); }
        $r = sync_apply($t, $rows);
        $reply['applied'] = (int)$r['applied'];
        $reply['skipped'] = (int)$r['skipped'];
        $reply['failed']  = isset($r['failed']) ? (int)$r['failed'] : 0;
    } else {
        throw new RuntimeException('未知动作');
    }

    echo peer_pack($reply, $mySk, trim((string)$from['pubkey']));
} catch (Throwable $e) {
    http_response_code(400);
    echo 'error';
    app_log('peer endpoint: ' . $e->getMessage());
}
