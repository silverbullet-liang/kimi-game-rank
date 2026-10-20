<?php
/**
 * API：作品自主提交（所有人可用，不要求登录本站）
 * ------------------------------------------------------------
 * actions:
 *   search     快捷搜索已收录作品（按名称 / 作品 ID / 作者）
 *   submit     提交作品：名称 + 作品 ID + 封面（可选）+ Kimi 登录态
 *   qr_new     生成扫码登录会话（代理 Kimi 官方接口）
 *   qr_poll    轮询扫码状态，成功后返回 accessToken
 *
 * 安全约定：
 *   · 登录态只用于本次抓取，不落库、不写日志、不回显；
 *   · 按 IP 限频（提交 5 次/小时，搜索 60 次/分钟）；
 *   · 名称过内容审核（与评论同一套），封面只接受 http(s)。
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'search');

/* ============================================================
 * 快捷搜索：已收录作品
 * ============================================================ */
if ($action === 'search') {
    $q = trim(strip_invisible(nfc_normalize(param_str('q', ''))));
    if (mb_strlen($q, 'UTF-8') < 1) { ok(array('list' => array())); }
    if (!rate_limit('sub_search_' . ip_hash(client_ip()), 60, 60)) {
        fail(429, '搜索过于频繁，请稍后再试');
    }
    $kw = '%' . $q . '%';
    $rows = db_all(
        'SELECT community_id, title, author_name, images, total_score, rating
           FROM works
          WHERE is_hidden = 0 AND (title LIKE ? OR community_id LIKE ? OR author_name LIKE ?)
          ORDER BY total_score DESC
          LIMIT 8',
        array($kw, $kw, $kw)
    );
    $list = array();
    foreach ($rows as $r) {
        $imgs = json_decode((string)($r['images'] ?? '[]'), true);
        $cover = is_array($imgs) && isset($imgs[0]) ? (string)$imgs[0] : '';
        $list[] = array(
            'community_id' => (string)$r['community_id'],
            'title'        => (string)$r['title'],
            'author'       => (string)($r['author_name'] ?? ''),
            'cover'        => $cover,
            'score'        => (int)($r['total_score'] ?? 0),
            'rating'       => (string)($r['rating'] ?? ''),
        );
    }
    ok(array('list' => $list));
}

/* ============================================================
 * 扫码登录（代理 Kimi 官方 AuthService）
 * ============================================================ */
function submit_kimi_post(string $path, array $payload): array
{
    $ch = curl_init('https://auth.kimi.com/api/' . $path);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Accept: application/json, application/connect+json',
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode(is_string($body) ? $body : '', true);
    return array('code' => $code, 'json' => is_array($j) ? $j : array());
}

if ($action === 'qr_new') {
    if (!rate_limit('sub_qr_' . ip_hash(client_ip()), 20, 3600)) {
        fail(429, '操作过于频繁，请稍后再试');
    }
    $r = submit_kimi_post('account.gateway.v1.AuthService/CreateLoginQRCode', array());
    $qr = isset($r['json']['code']) ? (string)$r['json']['code'] : '';
    if ($r['code'] !== 200 || $qr === '') {
        fail(502, '扫码服务暂时不可用，请改用粘贴登录态');
    }
    ok(array('code' => $qr, 'content' => 'https://www.kimi.com/wechat/mp/auth?id=' . rawurlencode($qr)));
}

if ($action === 'qr_poll') {
    $qr = trim(param_str('code', ''));
    if ($qr === '') { fail(400, '缺少扫码会话'); }
    $r = submit_kimi_post('account.gateway.v1.AuthService/GetLoginQRCodeStatus', array('code' => $qr));
    if ($r['code'] !== 200) { fail(502, '扫码状态查询失败'); }
    $j = $r['json'];
    $status = isset($j['status']) ? (string)$j['status'] : '';
    $token  = isset($j['accessToken']) ? (string)$j['accessToken'] : '';
    ok(array('status' => $status, 'token' => $status === 'SUCCESS' ? $token : ''));
}

/* ============================================================
 * 提交作品
 * ============================================================ */
if ($action === 'submit') {
    $memberId = require_member();     /* 游客只能搜索与浏览，不能提交 */
    $uid = actor_uid($memberId);
    csrf_verify();
    $ip = ip_hash(client_ip());
    if (!rate_limit('sub_ip_' . $ip, 5, 3600)) {
        fail(429, '提交过于频繁，请一小时后再试');
    }
    /* 一人一天一次：只统计已成功入库的（失败不占配额） */
    $todayLen = (int)db_val(
        'SELECT COUNT(*) FROM works WHERE added_by = ? AND created_at >= ?',
        array($uid, gmdate('Y-m-d') . ' 00:00:00'),
        0
    );
    if ($todayLen >= 1) {
        fail(429, '今天已经提交过一次了，明天再来');
    }

    $name   = trim(strip_invisible(nfc_normalize(param_str('name', ''))));
    $workId = trim(param_str('work_id', ''));
    $cover  = trim(param_str('cover', ''));
    $token  = trim(param_str('token', ''));

    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120) {
        fail(400, '名称需 2-120 字');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{6,80}$/', $workId)) {
        fail(400, '作品 ID 格式不正确（可在作品分享链接里找到）');
    }
    if ($cover !== '') {
        if (mb_strlen($cover) > 500 || !preg_match('#^https?://#i', $cover)) {
            fail(400, '封面链接需以 http(s) 开头');
        }
    }
    if ($token === '') {
        fail(400, '需要填写登录态，否则无法读取作品内容');
    }

    /* 重名/重复 */
    $exist = db_one('SELECT id, is_hidden FROM works WHERE community_id = ?', array($workId));
    if ($exist !== null) {
        fail(409, !empty($exist['is_hidden']) ? '该作品已被收录（当前已下架）' : '该作品已在榜，无需重复提交');
    }

    /* 名称审核 */
    $verdict = moderate_text($name, 'message', 0);
    if (empty($verdict['ok'])) {
        moderate_reject($verdict);
    }

    /* 抓取 + 入库 */
    try {
        $item = find_work_by_id($token, $workId);
    } catch (Exception $e) {
        fail(400, $e->getMessage());
    }
    if ($name !== '') { $item['title'] = $name; }
    if ($cover !== '') {
        $imgs = isset($item['images']) && is_array($item['images']) ? $item['images'] : array();
        array_unshift($imgs, $cover);
        $item['images'] = array_values(array_unique(array_slice($imgs, 0, 9)));
    }

    try {
        $res = work_upsert($item);
    } catch (Exception $e) {
        fail(500, '入库失败：' . $e->getMessage());
    }

    /* 记下提交者：既是来源标记，也是「一人一天一次」的统计依据 */
    db_exec('UPDATE works SET added_by = ? WHERE community_id = ?', array($uid, $workId));

    /* 提交者填的名称与封面优先，覆盖抓取结果 */
    if ($name !== '') {
        db_exec('UPDATE works SET title = ? WHERE community_id = ?', array($name, $workId));
    }
    if ($cover !== '') {
        $row = db_one('SELECT images FROM works WHERE community_id = ?', array($workId));
        $arr = json_decode((string)($row['images'] ?? '[]'), true);
        $arr = is_array($arr) ? $arr : array();
        array_unshift($arr, $cover);
        db_exec('UPDATE works SET images = ? WHERE community_id = ?',
                array(json_encode(array_values(array_unique(array_slice($arr, 0, 9))), JSON_UNESCAPED_UNICODE), $workId));
    }

    ok(array(
        'community_id' => $workId,
        'status'       => isset($res['status']) ? (string)$res['status'] : 'inserted',
        'score'        => isset($res['scores']['total']) ? (int)$res['scores']['total'] : 0,
    ), '提交成功，已加入榜单');
}

fail(400, '未知操作');
