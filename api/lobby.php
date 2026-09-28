<?php
/**
 * API：世界对话（重构版）
 * ------------------------------------------------------------
 * 文件名曾为 chat.php：免费主机（InfinityFree 等）会拦截「路径中含 chat」的请求，
 * 直接返回 403，PHP 根本不会执行——表现为世界对话永远打不开。
 * 故改名为 lobby.php；功能与协议完全不变，前端路由仍叫 chat（#/chat 是片段，不发给服务器）。
 * actions: list | send | recall
 * ------------------------------------------------------------
 * 设计要点：
 *  - 列表字段按「列是否真的存在」动态拼装，旧库未迁移时不至于整条查询报错
 *  - 查询异常不抛 500，返回 degraded 标记交给前端展示可重试提示
 *  - 列表与发送共用同一套序列化，保证前端拿到的字段完全一致
 *  - 发送仅登录成员；撤回仅限本人
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

/** 单条消息 → 输出结构（列表与发送共用） */
function chat_out(array $r, int $myUid): array
{
    $recalled = isset($r['is_recalled']) ? ((int)$r['is_recalled'] === 1) : false;
    return array(
        'id'       => (int)$r['id'],
        'uid'      => (int)$r['user_id'],
        'username' => (string)($r['username'] ?? '已注销用户'),
        'role'     => (string)($r['role'] ?? 'user'),
        'avatar'   => identicon_data_uri((string)($r['username'] ?? '用户'), 40),
        'content'  => $recalled ? '' : (string)($r['content'] ?? ''),
        'msg_type' => (string)($r['msg_type'] ?? 'text'),
        'media'    => (string)($r['media_url'] ?? ''),
        'recalled' => $recalled,
        'mine'     => $myUid > 0 && (int)$r['user_id'] === $myUid,
        'time'     => to_local((string)$r['created_at'], 'm-d H:i'),
    );
}

/** 消息表的可用列 → SELECT 片段（缺失列以常量兜底） */
function chat_msg_cols(): string
{
    $type  = col_ok('messages', 'msg_type')    ? 'm.msg_type'     : "'text' AS msg_type";
    $media = col_ok('messages', 'media_url')   ? 'm.media_url'    : "'' AS media_url";
    $rec   = col_ok('messages', 'is_recalled') ? 'm.is_recalled'  : '0 AS is_recalled';
    return 'm.id, m.user_id, m.content, ' . $type . ', ' . $media . ', ' . $rec . ', m.created_at, '
         . "COALESCE(u.username, '已注销用户') AS username, COALESCE(u.role, 'user') AS role";
}

$action = param_str('action', 'list');

switch ($action) {

    /* ---------- 列表（支持 since_id 增量） ---------- */
    case 'list': {
        $id = require_token();
        $since = max(0, param_int('since_id', 0));
        $limit = min(60, max(10, param_int('limit', 30)));
        $me = current_identity();
        $myUid = ($me !== null && $me['role'] === 'admin') ? admin_uid() : (int)($me['uid'] ?? 0);

        try {
            $cols = chat_msg_cols();
            if ($since > 0) {
                $rows = db_all(
                    'SELECT ' . $cols . ' FROM messages m LEFT JOIN users u ON u.id = m.user_id
                     WHERE m.id > ? ORDER BY m.id ASC LIMIT ' . $limit,
                    array($since)
                );
            } else {
                $rows = db_all(
                    'SELECT * FROM (SELECT ' . $cols . ' FROM messages m LEFT JOIN users u ON u.id = m.user_id
                        ORDER BY m.id DESC LIMIT ' . $limit . ') t ORDER BY t.id ASC'
                );
            }
        } catch (Throwable $e) {
            app_log('chat list failed: ' . $e->getMessage());
            ok(array('items' => array(), 'degraded' => true,
                'hint' => '对话数据暂不可用，请稍后重试（若持续出现，请告知管理员）'));
        }

        $items = array();
        foreach ($rows as $r) { $items[] = chat_out($r, $myUid); }
        ok(array('items' => $items));
        break;
    }

    /* ---------- 发送（仅登录成员） ---------- */
    case 'send': {
        $id = require_member();
        csrf_verify();
        $uid = actor_uid($id);
        if (!rate_limit('msg_' . $uid, 15, 60)) { fail(429, '发言过于频繁，请稍后再试'); }

        $type = (param_str('type', 'text') === 'image') ? 'image' : 'text';
        $content = '';
        $media = '';

        if ($type === 'image') {
            $media = trim(nfc_normalize(param_str('media')));
            if ($media === '' || mb_strlen($media, 'UTF-8') > 255) { fail(400, '图片地址无效'); }
            if (!preg_match('#^(https?://|api/media\.php\?id=)#i', $media)) { fail(400, '不支持的图片地址'); }
        } else {
            $content = trim(strip_invisible(nfc_normalize(param_str('content'))));
            $len = mb_strlen($content, 'UTF-8');
            if ($len < 1) { fail(400, '消息不能为空'); }
            if ($len > 500) { fail(400, '消息最长 500 字'); }
        }

        cooldown_guard('chat');   // 非管理员：发言冷却

        /* 内容过三关：只针对文本消息（图片消息没有文本可审） */
        if ($type !== 'image' && $content !== '') {
            $verdict = moderate_text($content, 'message');
            if (empty($verdict['ok'])) { moderate_reject($verdict); }
        }

        /* 写入列按存在性拼装：旧库未补齐这些列时仍可正常发言 */
        $cols = array('user_id', 'content');
        $vals = array($uid, $content);
        if (col_ok('messages', 'msg_type'))  { $cols[] = 'msg_type';  $vals[] = $type; }
        if (col_ok('messages', 'media_url')) { $cols[] = 'media_url'; $vals[] = $media; }
        $ph = array_fill(0, count($cols), '?');
        $mid = db_insert(
            'INSERT INTO messages (`' . implode('`, `', $cols) . '`, created_at) VALUES (' . implode(', ', $ph) . ', UTC_TIMESTAMP())',
            $vals
        );
        stats_bump('message_count');

        $u = current_user_row();
        $name = $u !== null ? (string)$u['username'] : '用户';
        ok(array(
            'id'       => $mid,
            'uid'      => $uid,
            'username' => $name,
            'role'     => $u !== null ? (string)$u['role'] : 'user',
            'avatar'   => identicon_data_uri($name, 40),
            'content'  => $content,
            'msg_type' => $type,
            'media'    => $media,
            'recalled' => false,
            'mine'     => true,
            'time'     => to_local(now_utc(), 'm-d H:i'),
        ));
        break;
    }

    /* ---------- 撤回（仅本人） ---------- */
    case 'recall': {
        $id = require_member();
        csrf_verify();
        $mid = param_int('id', 0);
        $m = db_one('SELECT id, user_id FROM messages WHERE id = ?', array($mid));
        if ($m === null) { fail(404, '消息不存在'); }
        if ((int)$m['user_id'] !== actor_uid($id)) { fail(403, '只能撤回自己的消息'); }
        $sets = array("`content` = ''");
        if (col_ok('messages', 'media_url'))   { $sets[] = "`media_url` = ''"; }
        if (col_ok('messages', 'is_recalled')) { $sets[] = '`is_recalled` = 1'; }
        db_exec('UPDATE `messages` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', array($mid));
        ok(null, '已撤回');
        break;
    }

    default:
        fail(400, '未知操作');
}
