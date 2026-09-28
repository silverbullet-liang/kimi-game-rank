<?php
/**
 * API：反馈
 * actions: list | create | reply | delete
 * 可见性：公开=所有人；私密=仅管理员与反馈人
 * 权限：提交=任意登录成员；回复/删除他人=主管理员或副管理员
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'list');

switch ($action) {

    case 'list': {
        $id = require_token();
        $isAdmin = $id['role'] === 'admin' || $id['role'] === 'subadmin';
        $uid = $isAdmin ? actor_uid($id) : (int)$id['uid'];

        $SEL = 'SELECT f.*, u.username, u.role FROM feedback f JOIN users u ON u.id = f.user_id';
        if ($isAdmin) {
            $rows = db_all($SEL . ' WHERE f.is_deleted = 0 ORDER BY f.id DESC LIMIT 200');
        } elseif ($id['role'] === 'user') {
            $rows = db_all($SEL . ' WHERE f.is_deleted = 0 AND (f.is_public = 1 OR f.user_id = ?) ORDER BY f.id DESC LIMIT 200',
                            array($uid));
        } else {
            // 游客仅见公开反馈
            $rows = db_all($SEL . ' WHERE f.is_deleted = 0 AND f.is_public = 1 ORDER BY f.id DESC LIMIT 200');
        }

        $items = array_map(function ($r) use ($uid, $isAdmin) {
            return array(
                'id'        => (int)$r['id'],
                'username'  => (string)$r['username'],
                'role'      => (string)$r['role'],
                'content'   => (string)$r['content'],
                'public'    => (int)$r['is_public'] === 1,
                'reply'     => $r['admin_reply'] === null ? '' : (string)$r['admin_reply'],
                'replied_at'=> $r['replied_at'] === null ? '' : to_local((string)$r['replied_at']),
                'time'      => to_local((string)$r['created_at'], 'm-d H:i'),
                'mine'      => $uid > 0 && (int)$r['user_id'] === $uid,
                'can_deleted'=> $isAdmin || ($uid > 0 && (int)$r['user_id'] === $uid),
            );
        }, $rows);
        ok(array('items' => $items));
        break;
    }

    case 'create': {
        $id = require_member();
        csrf_verify();
        if (!rate_limit('fb_' . actor_uid($id), 5, 600)) { fail(429, '提交过于频繁'); }
        $content = trim(strip_invisible(nfc_normalize(param_str('content'))));
        $len = mb_strlen($content, 'UTF-8');
        if ($len < 2) { fail(400, '内容太短'); }
        if ($len > 1000) { fail(400, '内容最长 1000 字'); }
        cooldown_guard('feedback');

        /* 防重复：同一用户提交过完全相同的内容就不再允许（不限时间） */
        if (dup_guard('feedback', (string)actor_uid($id), $content)) {
            fail(409, '你已经提交过完全相同的内容，请勿重复提交');
        }

        $public = param_int('is_public', 1) === 1 ? 1 : 0;
        $fid = db_insert_norm(
            'feedback',
            array('user_id', 'content', 'is_public'),
            array(actor_uid($id), $content, $public),
            $content
        );
        stats_bump('feedback_count');
        ok(array('id' => $fid));
        break;
    }

    case 'reply': {
        require_any_admin();
        csrf_verify();
        $fid = param_int('id', 0);
        $reply = trim(strip_invisible(nfc_normalize(param_str('content'))));
        if (mb_strlen($reply, 'UTF-8') < 1) { fail(400, '回复不能为空'); }
        if (mb_strlen($reply, 'UTF-8') > 1000) { fail(400, '回复过长'); }
        /* 先确认存在，再看更新行数。不能拿受影响行数判存在与否：
           重复提交一模一样的回复时 MySQL 返回 0 行，会被误报成「反馈不存在」。 */
        $f = db_one('SELECT id FROM feedback WHERE id = ? AND is_deleted = 0', array($fid));
        if ($f === null) { fail(404, '反馈不存在'); }
        db_exec('UPDATE feedback SET admin_reply = ?, replied_at = UTC_TIMESTAMP() WHERE id = ?',
            array($reply, $fid));
        ok(null, '已回复');
        break;
    }

    case 'delete': {
        $id = require_token();
        csrf_verify();
        $fid = param_int('id', 0);
        $f = db_one('SELECT id, user_id FROM feedback WHERE id = ?', array($fid));
        if ($f === null) { fail(404, '反馈不存在'); }
        $isAdmin = $id['role'] === 'admin' || $id['role'] === 'subadmin';
        $okOwner = $id['role'] === 'user' && (int)$f['user_id'] === (int)$id['uid'];
        if (!$okOwner && !$isAdmin) { fail(403, '无权操作'); }
        db_exec('UPDATE feedback SET is_deleted = 1 WHERE id = ?', array($fid));
        ok(null, '已删除');
        break;
    }

    default:
        fail(400, '未知操作');
}
