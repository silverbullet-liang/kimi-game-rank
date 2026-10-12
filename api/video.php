<?php
/**
 * API：世界对话 · B站视频消息
 * ------------------------------------------------------------
 * actions:
 *   preview  发送前预览（解析标题/封面/时长）
 *   send     发送：解析 → 随机抽帧过图片审核 → 过审入库（只存 BV 号）
 *   url      播放前实时解析 mp4 直链（前端直连播放，本站不代理视频流量）
 * ------------------------------------------------------------
 * 入库只写 messages.media_url = BV 号；展示信息写 meta（不含直链）。
 * 直链带 Referer 防盗链且有时效，故永不入库、每次播放实时取。
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'preview');

/* ---------- 播放前实时解析直链 ---------- */
if ($action === 'url') {
    require_token();
    $bv = bili_bv_of(param_str('bv', ''));
    if ($bv === '') { fail(400, 'BV 号无效'); }
    if (!rate_limit('bili_url_' . ip_hash(client_ip()), 120, 60)) { fail(429, '请求过于频繁，请稍后再试'); }
    $m = bili_miren($bv);
    if ($m === null || $m['video_url'] === '') { fail(502, '视频解析失败，请稍后再试'); }
    ok(array('url' => (string)$m['video_url']));
}

/* ---------- 发送前预览 ---------- */
if ($action === 'preview') {
    $id = require_member();
    $bv = bili_bv_of(param_str('input', ''));
    if ($bv === '') { fail(400, '请填写正确的 B 站视频链接或 BV 号'); }
    if (!rate_limit('bili_prev_' . actor_uid($id), 20, 60)) { fail(429, '操作过于频繁，请稍后再试'); }
    $m = bili_miren($bv);
    if ($m === null) { fail(502, '解析失败：仅支持 B 站视频（番剧 / 电影 / 电视剧无法解析）'); }
    ok(array(
        'bv' => $bv, 'title' => $m['title'], 'cover' => $m['cover'],
        'duration' => $m['duration'], 'duration_f' => $m['duration_f'], 'author' => $m['author'],
    ));
}

/* ---------- 发送 ---------- */
if ($action === 'send') {
    $id = require_member();
    csrf_verify();
    $uid = actor_uid($id);
    if (!rate_limit('msg_' . $uid, 15, 60))        { fail(429, '发言过于频繁，请稍后再试'); }
    if (!rate_limit('bili_send_' . $uid, 5, 300))  { fail(429, '发送视频过于频繁，请稍后再试'); }
    cooldown_guard('chat');

    $bv = bili_bv_of(param_str('input', ''));
    if ($bv === '') { fail(400, '请填写正确的 B 站视频链接或 BV 号'); }
    $text = trim(strip_invisible(nfc_normalize(param_str('content', ''))));
    if (mb_strlen($text, 'UTF-8') > 500) { fail(400, '附言最长 500 字'); }

    $m = bili_miren($bv);
    if ($m === null) { fail(502, '解析失败：仅支持 B 站视频（番剧 / 电影 / 电视剧无法解析）'); }

    /* 内容审核：只审标题 + 简介（连同附言一起过文本审核），不对视频抽帧 */
    $audit = trim($m['title'] . "\n" . $m['desc'] . "\n" . $text);
    $verdict = moderate_text(mb_substr($audit, 0, 2000, 'UTF-8'), 'message', (int)$uid);
    if (empty($verdict['ok'])) { moderate_reject($verdict); }
    $flag = (string)(isset($verdict['flag']) ? $verdict['flag'] : '');

    /* 入库：media_url 只存 BV 号；展示信息进 meta，不含直链 */
    $video = array(
        'title'  => (string)$m['title'], 'cover' => (string)$m['cover'],
        'dur'    => (int)$m['duration'], 'dur_f' => (string)$m['duration_f'],
        'author' => (string)$m['author'],
    );
    $meta = chat_meta_pack(array(), array(), array('v' => $video));

    $cols = array('user_id', 'content');
    $vals = array($uid, $text);
    if (col_ok('messages', 'msg_type'))    { $cols[] = 'msg_type';    $vals[] = 'video'; }
    if (col_ok('messages', 'media_url'))   { $cols[] = 'media_url';   $vals[] = $bv; }
    if (col_ok('messages', 'review_flag')) { $cols[] = 'review_flag'; $vals[] = $flag; }
    if (col_ok('messages', 'meta'))        { $cols[] = 'meta';        $vals[] = $meta; }
    $mOid = '';
    if (col_ok('messages', 'oid')) { $cols[] = 'oid'; $mOid = oid_new('messages'); $vals[] = $mOid; }
    $ph = array_fill(0, count($cols), '?');
    $mid = db_insert(
        'INSERT INTO messages (`' . implode('`, `', $cols) . '`, created_at) VALUES (' . implode(', ', $ph) . ', UTC_TIMESTAMP())',
        $vals
    );
    stats_bump('message_count');

    $u = current_user_row();
    $name = $u !== null ? (string)$u['username'] : '用户';
    ok(array(
        'id'       => $mid, 'oid' => $mOid, 'uid' => $uid, 'username' => $name,
        'role'     => $u !== null ? (string)$u['role'] : 'user',
        'avatar'   => identicon_data_uri($name, 40),
        'content'  => $text, 'msg_type' => 'video', 'media' => $bv,
        'video'    => $video,
        'recalled' => false, 'flag' => $flag, 'mine' => true,
        'time'     => to_local(now_utc(), 'm-d H:i'),
    ));
}

fail(400, '未知操作');
