<?php
/**
 * API：评论（支持任意层级楼中楼）
 * actions: list | create | delete | vote
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'list');

/** 评论目标类型：默认作品；通报用 discipline。其余一律回落到 work，避免越权写入未知域 */
function comment_target_type(): string
{
    $t = param_str('target_type', 'work');
    return in_array($t, array('discipline', 'discipline_list'), true) ? $t : 'work';
}

/* 被通报封禁的访问者：通报类评论区看不到，也不能发表任何评论。
   前端隐藏只是体验，这里才是真正的门。 */
if (function_exists('discipline_visitor_blocked') && discipline_visitor_blocked()) {
    $t = param_str('target_type', 'work');
    if ($action === 'list') {
        if ($t === 'discipline' || $t === 'discipline_list') { ok(array('comments' => array(), 'total' => 0)); }
    } elseif ($action === 'create') {
        fail(403, '账号已被封禁，暂时无法发言');
    }
}

switch ($action) {

    /* 评论列表（含楼中楼树） */
    case 'list': {
        require_token();
        $wid   = param_int('work_id', 0);
        $ttype = comment_target_type();
        if ($ttype === 'discipline_list') { $wid = 0; }          // 列表评论区固定挂在 work_id=0
        if ($ttype !== 'discipline_list' && $wid <= 0) { fail(400, '参数错误'); }
        $ident = current_identity();
        $uid = $ident['role'] === 'admin' ? admin_uid() : (int)(isset($ident['uid']) ? $ident['uid'] : 0);

        $rflag = col_ok('comments', 'review_flag') ? 'c.review_flag' : "'' AS review_flag";
        $rows = db_all(
            'SELECT c.id, c.user_id, c.parent_id, c.content, c.is_deleted, c.is_blocked, c.created_at, '
            . $rflag . ', u.username, u.role
             FROM comments c JOIN users u ON u.id = c.user_id
             WHERE c.work_id = ? AND c.target_type = ? ORDER BY c.id ASC LIMIT 800',
            array($wid, $ttype)
        );

        $votes = array();
        if ($uid > 0) {
            foreach (db_all('SELECT comment_id FROM comment_votes WHERE user_id = ?', array($uid)) as $v) {
                $votes[(int)$v['comment_id']] = true;
            }
        }
        $counts = array();
        foreach (db_all('SELECT comment_id, COUNT(*) n FROM comment_votes WHERE comment_id IN (SELECT id FROM comments WHERE work_id = ? AND target_type = ?) GROUP BY comment_id', array($wid, $ttype)) as $c) {
            $counts[(int)$c['comment_id']] = (int)$c['n'];
        }

        $raw = array(); $nameOf = array();
        foreach ($rows as $r) { $raw[(int)$r['id']] = $r; $nameOf[(int)$r['id']] = (string)$r['username']; }

        /* 作者累计被通报次数（一次聚合，不做 N+1 查询） */
        $reports = array();
        if ($raw) {
            $ids = array_values(array_unique(array_map(function ($r) { return (int)$r['user_id']; }, $raw)));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            foreach (db_all("SELECT user_id, COUNT(*) n FROM discipline_reports
                             WHERE ban_days >= 7 AND user_id IN ($ph) GROUP BY user_id", $ids) as $x) {
                $reports[(int)$x['user_id']] = (int)$x['n'];
            }
        }

        /* 删除 = 入回收站：被删评论**及其全部后代**都不再返回，前端完全不显示。
           注意这与「屏蔽」不同：屏蔽只是折叠，内容仍可见。 */
        $hidden = comment_hidden_set($rows);

        /* 顶层：parent 为 0 或父评论已不存在（被隐藏的后代已在上一步一并排除） */
        $roots = array();
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (isset($hidden[$id])) { continue; }
            $pid = (int)$r['parent_id'];
            if ($pid === 0 || !isset($raw[$pid])) { $roots[] = $id; }
        }

        /* 楼中楼：全部后代不分层级，按时间平铺为同级 */
        $flatten = function ($rootId) use ($raw, $nameOf) {
            $out = array(); $queue = array($rootId);
            $guard = 0;
            while (!empty($queue) && $guard++ < 1000) {
                $cur = array_shift($queue);
                foreach ($raw as $id => $r) {
                    if ((int)$r['parent_id'] === $cur && $id !== $rootId) { $out[$id] = true; $queue[] = $id; }
                }
            }
            return array_keys($out);
        };

        $make = function ($r, $replyTo) use ($votes, $counts, $uid, $reports) {
            return array(
                'id'       => (int)$r['id'],
                'uid'      => (int)$r['user_id'],
                'username' => (string)$r['username'],
                'role'     => (string)$r['role'],
                'reports'  => isset($reports[(int)$r['user_id']]) ? $reports[(int)$r['user_id']] : 0,
                'avatar'   => identicon_data_uri((string)$r['username'], 40),
                'content'  => (string)$r['content'],
                'blocked'  => (int)$r['is_blocked'] === 1,
                /* AI 重审判为 middle 时的标注 */
                'flag'     => (string)($r['review_flag'] ?? ''),
                'mine'     => $uid > 0 && (int)$r['user_id'] === $uid,
                'time'     => to_local((string)$r['created_at'], 'm-d H:i'),
                'likes'    => isset($counts[(int)$r['id']]) ? $counts[(int)$r['id']] : 0,
                'liked'    => isset($votes[(int)$r['id']]),
                'reply_to' => $replyTo,
            );
        };

        $out = array();
        foreach ($roots as $rid) {
            $node = $make($raw[$rid], '');
            $node['replies'] = array();
            foreach ($flatten($rid) as $cid) {
                if (isset($hidden[$cid])) { continue; }
                $node['replies'][] = $make($raw[$cid], isset($nameOf[(int)$raw[$cid]['parent_id']]) ? $nameOf[(int)$raw[$cid]['parent_id']] : '');
            }
            $out[] = $node;
        }

        $alive = count($raw) - count($hidden);
        ok(array('comments' => $out, 'total' => $alive));
        break;
    }

    /* 发表评论 / 回复 */
    case 'create': {
        $id = require_member();
        csrf_verify();
        if (!rate_limit('cmt_' . actor_uid($id), 20, 60)) { fail(429, '发言过于频繁'); }

        $wid   = param_int('work_id', 0);
        $ttype = comment_target_type();
        $content = trim(strip_invisible(nfc_normalize(param_str('content'))));
        $parent = param_int('parent_id', 0);
        $len = mb_strlen($content, 'UTF-8');
        if ($len < 1) { fail(400, '评论内容不能为空'); }
        if ($len > 300) { fail(400, '评论最长 300 字'); }

        /* 目标存在性：作品要未隐藏；通报要真实存在；通报列表固定 work_id=0 */
        if ($ttype === 'discipline_list') {
            $wid = 0;
        } elseif ($ttype === 'discipline') {
            if (!table_exists('discipline_reports')
                || db_one('SELECT id FROM discipline_reports WHERE id = ?', array($wid)) === null) {
                fail(404, '通报不存在');
            }
        } else {
            $w = db_one('SELECT id FROM works WHERE id = ? AND is_hidden = 0', array($wid));
            if ($w === null) { fail(404, '作品不存在'); }
        }

        $root = 0;
        if ($parent > 0) {
            $p = db_one('SELECT id, root_id, work_id, target_type FROM comments WHERE id = ?', array($parent));
            if ($p === null || (int)$p['work_id'] !== $wid || (string)$p['target_type'] !== $ttype) {
                fail(400, '回复目标无效');
            }
            $root = (int)$p['root_id'] > 0 ? (int)$p['root_id'] : (int)$p['id'];
        }

        cooldown_guard('comment');   // 非管理员：走全局滑动窗口频次闸门（非固定间隔）

        /* 防重复：同一用户发过完全相同的内容就不再允许（不限时间，规整口径同用户名） */
        if (dup_guard('comments', (string)actor_uid($id), $content)) {
            fail(409, '你已经发过完全相同的内容，请勿重复发送');
        }

        /* 内容过三关：词库+句式 → 译后英文脏词 → 模型判定。
           放在扣冷却与查重之后，避免为重复内容白跑外部接口。 */
        $verdict = moderate_text($content, 'comment', (int)actor_uid($id));
        if (empty($verdict['ok'])) { moderate_reject($verdict); }
        $flag = (string)(isset($verdict['flag']) ? $verdict['flag'] : '');

        $ccols = array('work_id', 'target_type', 'user_id', 'parent_id', 'root_id', 'content');
        $cvals = array($wid, $ttype, actor_uid($id), $parent, $root, $content);
        if (col_ok('comments', 'review_flag')) { $ccols[] = 'review_flag'; $cvals[] = $flag; }
        $cid = db_insert_norm('comments', $ccols, $cvals, $content);
        stats_bump('comment_count');
        ok(array('id' => $cid));
        break;
    }

    /* 删除评论 → 入回收站（软删除）。
       被删评论及其后代前端完全不显示；内容仍在库中，
       管理员可在控制面板的「评论回收站」查看、恢复或彻底清空。
       注意：这与「屏蔽」（折叠）是两件事。 */
    case 'delete': {
        $id = require_member();
        csrf_verify();
        $cid = param_int('id', 0);
        $c = db_one('SELECT id, user_id FROM comments WHERE id = ?', array($cid));
        if ($c === null) { fail(404, '评论不存在'); }

        $isOwner = (int)$c['user_id'] === actor_uid($id);
        $isAdmin = $id['role'] === 'admin' || $id['role'] === 'subadmin';
        if (!$isOwner && !$isAdmin) { fail(403, '只能删除自己的评论'); }

        db_exec('UPDATE comments SET is_deleted = 1, deleted_by = ?, is_blocked = 0 WHERE id = ?',
            array($isOwner ? 1 : 2, $cid));
        ok(null, '已移入回收站');
        break;
    }

    /* 屏蔽评论 → 折叠展示（内容仍在，读者可点开）。
       与删除的区别：屏蔽不会被回收站清空，也不隐藏作者与时间。 */
    case 'block': {
        $id = require_member();
        csrf_verify();
        if ($id['role'] !== 'admin' && $id['role'] !== 'subadmin') { fail(403, '无权屏蔽评论'); }
        $cid = param_int('id', 0);
        if ($cid <= 0) { fail(400, '参数错误'); }
        $on = param_int('on', 1) === 1 ? 1 : 0;
        $c = db_one('SELECT id, user_id FROM comments WHERE id = ?', array($cid));
        if ($c === null) { fail(404, '评论不存在'); }
        db_exec('UPDATE comments SET is_blocked = ? WHERE id = ?', array($on, $cid));
        ok(array('blocked' => $on === 1), $on ? '已屏蔽（折叠显示）' : '已取消屏蔽');
        break;
    }

    /* 评论点赞（单用户唯一） */
    case 'vote': {
        $id = require_member();
        csrf_verify();
        $cid = param_int('id', 0);
        if ($cid <= 0) { fail(400, '参数错误'); }
        $uid = actor_uid($id);
        $has = db_val('SELECT id FROM comment_votes WHERE comment_id = ? AND user_id = ?', array($cid, $uid));
        if ($has) {
            db_exec('DELETE FROM comment_votes WHERE comment_id = ? AND user_id = ?', array($cid, $uid));
            $liked = false;
        } else {
            cooldown_guard('vote');   // 点赞受限；取消点赞不受限，避免误点后无法收回
            db_exec('INSERT IGNORE INTO comment_votes (comment_id, user_id, created_at) VALUES (?,?,UTC_TIMESTAMP())', array($cid, $uid));
            $liked = true;
            stats_bump('votes');
        }
        $n = (int)db_val('SELECT COUNT(*) FROM comment_votes WHERE comment_id = ?', array($cid));
        ok(array('liked' => $liked, 'count' => $n));
        break;
    }

    default:
        fail(400, '未知操作');
}
