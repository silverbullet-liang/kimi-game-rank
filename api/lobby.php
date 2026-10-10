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
    /* 官方 AI 消息：user_id=0、msg_type='ai'，身份不再来自 users 表 */
    $isAi = ((string)($r['msg_type'] ?? '') === 'ai');
    $name = $isAi ? LOBBY_AI_NAME : (string)($r['username'] ?? '已注销用户');
    $meta = chat_meta_unpack(isset($r['meta']) ? $r['meta'] : '');
    return array(
        'id'       => (int)$r['id'],
        'oid'      => (string)($r['oid'] ?? ''),
        'uid'      => (int)$r['user_id'],
        'username' => $name,
        'role'     => $isAi ? 'ai' : (string)($r['role'] ?? 'user'),
        'avatar'   => identicon_data_uri($isAi ? LOBBY_AI_NAME : (string)($r['username'] ?? '用户'), 40),
        'content'  => $recalled ? '' : (string)($r['content'] ?? ''),
        'msg_type' => (string)($r['msg_type'] ?? 'text'),
        'media'    => (string)($r['media_url'] ?? ''),
        'recalled' => $recalled,
        /* AI 重审判为 middle 时的标注（撤回后不再提示） */
        'flag'     => $recalled ? '' : (string)($r['review_flag'] ?? ''),
        'mine'     => $myUid > 0 && (int)$r['user_id'] === $myUid,
        'cards'    => $recalled ? array() : $meta['cards'],
        'time'     => to_local((string)$r['created_at'], 'm-d H:i'),
    );
}

/** 消息表的可用列 → SELECT 片段（缺失列以常量兜底） */
function chat_msg_cols(): string
{
    $type  = col_ok('messages', 'msg_type')    ? 'm.msg_type'     : "'text' AS msg_type";
    $media = col_ok('messages', 'media_url')   ? 'm.media_url'    : "'' AS media_url";
    $rec   = col_ok('messages', 'is_recalled') ? 'm.is_recalled'  : '0 AS is_recalled';
    $flag  = col_ok('messages', 'review_flag') ? 'm.review_flag'  : "'' AS review_flag";
    $oid   = col_ok('messages', 'oid')         ? 'm.oid'          : "'' AS oid";
    $meta  = col_ok('messages', 'meta')        ? 'm.meta'         : "'' AS meta";
    return 'm.id, ' . $oid . ', ' . $meta . ', m.user_id, m.content, ' . $type . ', ' . $media . ', ' . $rec . ', ' . $flag . ', m.created_at, '
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
            /* 外链图片先过链接初筛（与文本同一套判据）。命中按「审核未通过」回报，
               前端会给出一条明确提示，而不是发出一条打不开的空消息。 */
            if (preg_match('#^https?://#i', $media) && function_exists('link_guard_check')) {
                $lh = link_guard_check($media);
                if ($lh) {
                    app_log('lobby image link blocked: ' . $lh[0]['host'] . ' via ' . $lh[0]['why']);
                    fail(422, '图片链接含可疑网址，已拦截');
                }
            }
        } else {
            $content = trim(strip_invisible(nfc_normalize(param_str('content'))));
            $len = mb_strlen($content, 'UTF-8');
            if ($len < 1) { fail(400, '消息不能为空'); }
            if ($len > 500) { fail(400, '消息最长 500 字'); }
        }

        cooldown_guard('chat');   // 非管理员：发言冷却

        /* 刷屏检测：短时间连发、或同一句话重复刷屏 */
        if ($type !== 'image' && function_exists('moderation_flood') && moderation_flood((int)$uid, $content)) {
            fail(429, '发送过于频繁或内容重复，请勿刷屏');
        }

        /* 内容过三关：只针对文本消息（图片消息没有文本可审）。
           传入 uid：AI 重审通过的凭证与用户绑定，凭它放行「重审说可以、发送又被拦」的那条。 */
        $flag = '';
        if ($type !== 'image' && $content !== '') {
            $verdict = moderate_text($content, 'message', (int)$uid);
            if (empty($verdict['ok'])) {
                /* 拆字骂人：把参与拼接的那几条消息一并撤回（进「回收站」） */
                if (isset($verdict['via']) && $verdict['via'] === 'context'
                    && function_exists('moderation_recall_context')) {
                    moderation_recall_context((int)$uid);
                }
                moderate_reject($verdict);
            }
            $flag = (string)(isset($verdict['flag']) ? $verdict['flag'] : '');
        }

        /* 写入列按存在性拼装：旧库未补齐这些列时仍可正常发言 */
        $cols = array('user_id', 'content');
        $vals = array($uid, $content);
        if (col_ok('messages', 'msg_type'))  { $cols[] = 'msg_type';  $vals[] = $type; }
        if (col_ok('messages', 'media_url')) { $cols[] = 'media_url'; $vals[] = $media; }
        if (col_ok('messages', 'review_flag')) { $cols[] = 'review_flag'; $vals[] = $flag; }
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
        $out = array(
            'id'       => $mid,
            'oid'      => $mOid,
            'uid'      => $uid,
            'username' => $name,
            'role'     => $u !== null ? (string)$u['role'] : 'user',
            'avatar'   => identicon_data_uri($name, 40),
            'content'  => $content,
            'msg_type' => $type,
            'media'    => $media,
            'recalled' => false,
            'flag'     => $flag,
            'mine'     => true,
            'time'     => to_local(now_utc(), 'm-d H:i'),
        );

        /* 消息里 @ 了官方 AI：以「发送人」身份生成一条 AI 回复（非流式）。
           失败只回注 ai_note，不影响这条消息本身是否成功发出。 */
        if ($type !== 'image' && $content !== '' && lobby_ai_mentioned($content)) {
            $ai = lobby_ai_reply((int)$uid, $content, $name);
            if (!empty($ai['ok'])) { $out['ai'] = $ai['item']; }
            else { $out['ai_note'] = (string)($ai['note'] ?? 'AI 暂时不可用'); }
        }

        ok($out);
        break;
    }

    /* ---------- 删除单条消息（本人或管理员） ----------
       官方 AI 的消息 user_id=0，只有管理员能删。删除与撤回一样是软删除：
       消息行保留（内容留档），对话里不再显示。 */
    case 'del': {
        $id = require_member();
        csrf_verify();
        $mid = param_int('id', 0);
        if ($mid <= 0) { fail(400, '参数错误'); }
        $hasMedia = col_ok('messages', 'media_url');
        $cols = 'id, user_id' . ($hasMedia ? ', media_url' : '') . (col_ok('messages', 'is_recalled') ? ', is_recalled' : '');
        $m = db_one('SELECT ' . $cols . ' FROM messages WHERE id = ?', array($mid));
        if ($m === null) { fail(404, '消息不存在'); }

        $role = (string)(isset($id['role']) ? $id['role'] : '');
        $isAdmin = ($role === 'admin' || $role === 'subadmin');
        if (!$isAdmin && (int)$m['user_id'] !== actor_uid($id)) { fail(403, '只能删除自己的消息'); }
        if (isset($m['is_recalled']) && (int)$m['is_recalled'] === 1) { ok(null, '该消息已删除'); break; }

        if ($hasMedia && (string)$m['media_url'] !== '' && function_exists('chat_media_drop')) {
            chat_media_drop((string)$m['media_url']);
        }
        $sets = array("`content` = ''");
        if (col_ok('messages', 'is_recalled')) { $sets[] = "`is_recalled` = 1"; }
        if ($hasMedia) { $sets[] = "`media_url` = ''"; }
        if ($sets) { db_exec('UPDATE `messages` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', array($mid)); }
        app_log('chat: message #' . $mid . ' deleted by uid=' . actor_uid($id));
        ok(null, '已删除');
        break;
    }

    /* ---------- 撤回（仅本人） ---------- */
    case 'recall': {
        $id = require_member();
        csrf_verify();
        $mid = param_int('id', 0);
        $hasMedia = col_ok('messages', 'media_url');
        $m = db_one('SELECT id, user_id' . ($hasMedia ? ', media_url' : '') . ' FROM messages WHERE id = ?', array($mid));
        if ($m === null) { fail(404, '消息不存在'); }
        if ((int)$m['user_id'] !== actor_uid($id)) { fail(403, '只能撤回自己的消息'); }

        /* 撤回即删图：图片对本条消息已无意义，留着纯占空间 */
        if ($hasMedia && function_exists('chat_media_drop')) {
            chat_media_drop((string)$m['media_url']);
        }

        $sets = array("`content` = ''");
        if ($hasMedia) { $sets[] = "`media_url` = ''"; }
        if (col_ok('messages', 'is_recalled')) { $sets[] = '`is_recalled` = 1'; }
        db_exec('UPDATE `messages` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', array($mid));
        ok(null, '已撤回');
        break;
    }

    /* ---------- 取消 / 恢复「可能有恶意」标注（管理员） ----------
       标注不影响内容是否可见，只是给读者一个提示；误标时一键取消，恢复同理。 */
    case 'flag': {
        $id = require_member();
        csrf_verify();
        if ($id['role'] !== 'admin' && $id['role'] !== 'subadmin') { fail(403, '无权修改标注'); }
        if (!col_ok('messages', 'review_flag')) { fail(500, '当前数据库尚未支持标注'); }
        $mid = param_int('id', 0);
        if ($mid <= 0) { fail(400, '参数错误'); }
        if (db_one('SELECT id FROM messages WHERE id = ?', array($mid)) === null) { fail(404, '消息不存在'); }
        $on = param_int('on', 0) === 1 ? 'middle' : '';
        db_exec('UPDATE `messages` SET `review_flag` = ? WHERE `id` = ?', array($on, $mid));
        ok(array('flag' => $on), $on ? '已恢复标注' : '已取消标注');
        break;
    }

    default:
        fail(400, '未知操作');
}
