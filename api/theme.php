<?php
/**
 * API：用户主题（theme/1）云同步
 * actions: save | get | del
 * 说明：仅登录用户；一份即全部（保存即覆盖），保留 7 天，到期自动失效。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'get');
/* 管理员与副管理员同样会用主题，这里不能只放行普通用户：
   之前用 require_user() 会让站长账号一导入就收到 403，表现为「总是云同步失败」。 */
$ident = require_token();
$role = (string)$ident['role'];

/* 目录与荣誉徽标是公开的：浏览、单条查看、荣誉映射连游客都能用；
   提交 / 撤回 / 云同步 / 我的进度 才要登录账号 */
$PUBLIC_ACTIONS = array('list', 'one', 'honours');
if (!in_array($action, $PUBLIC_ACTIONS, true)
    && !in_array($role, array('user', 'admin', 'subadmin'), true)) {
    fail(403, '请登录后再操作');
}

switch ($action) {

    /* ---------- 主题目录 ---------- */

    case 'list': {
        /* 只列已通过的主题；小写 q 走服务端搜索，命中名称 / 作者 / 主题 id */
        $r = ts_public_list(trim((string)param_str('q', '')), param_int('page', 1), param_int('size', 20));
        ok($r);
        break;
    }

    case 'one': {
        ok(ts_one(param_int('id', 0), $ident));
        break;
    }

    /* 全站荣誉映射：用户名 → 荣誉列表。前端只维护这一份，用户名渲染处按名字查表。
       游客也要能拿到，否则未登录浏览时看不到任何徽标。 */
    case 'honours': {
        ok(array('list' => th_map(), 'catalog' => th_catalog()));
        break;
    }

    /* 我的三阶进度：已获得什么、下一阶还差多少 */
    case 'progress': {
        ok(th_progress((int)$ident['uid']));
        break;
    }

    case 'mine': {
        $pr = th_progress((int)$ident['uid']);
        ok(array(
            'items'     => ts_mine((int)$ident['uid']),
            'quota'     => ts_quota((int)$ident['uid']),
            'used'      => ts_used((int)$ident['uid']),
            'remaining' => ts_remaining((int)$ident['uid']),
            'kinds'     => $pr['kinds'],
        ));
        break;
    }

    case 'submit': {
        csrf_verify();
        if (!rate_limit('theme_submit_' . (int)$ident['uid'], 6, 3600)) {
            fail(429, '提交过于频繁，请稍后再试');
        }
        $raw = param_str('json', '');
        if ($raw === '') {
            $p2 = req_json();
            $raw = is_array($p2) && isset($p2['json']) ? (string)$p2['json'] : '';
        }
        $created = ts_create($ident, $raw);
        th_map_flush();
        ok($created);
        break;
    }

    case 'withdraw': {
        csrf_verify();
        $w = ts_withdraw((int)$ident['uid'], param_int('id', 0));
        th_map_flush();
        ok($w);
        break;
    }


    case 'save': {
        csrf_verify();
        if (!rate_limit('theme_save_' . (int)$ident['uid'], 60, 3600)) {
            fail(429, '保存过于频繁，请稍后再试');
        }
        $raw = param_str('json', '');
        if ($raw === '') {
            $p = req_json();
            $raw = is_array($p) && isset($p['json']) ? (string)$p['json'] : '';
        }
        list($ok, $errs, $meta) = theme_json_check($raw);
        if (!$ok) { fail(400, '主题未通过校验：' . $errs[0]); }
        if (!table_exists('user_themes')) { fail(503, '主题同步暂不可用'); }

        $uid = (int)$ident['uid'];
        $label = trim((string)param_str('name', ''));
        $label = mb_substr($label === '' ? (string)$meta['name'] : $label, 0, 32, 'UTF-8');
        if ($label === '') { $label = '未命名主题'; }

        $expires = gmdate('Y-m-d H:i:s', time() + theme_json_ttl_days() * 86400);
        /* 先清掉自己已过期的，避免占着额度 */
        db_exec('DELETE FROM user_themes WHERE uid = ? AND expires_at <= UTC_TIMESTAMP()', array($uid));

        /* 更新：给了行号就更新那一行（须属于本人）；否则按主题 id 覆盖同主题，不堆重复副本 */
        $rowId = (int)param_int('row', 0);
        if ($rowId <= 0) {
            $hit = db_one('SELECT id FROM user_themes WHERE uid = ? AND theme_id = ? ORDER BY id DESC LIMIT 1',
                array($uid, (string)$meta['id']));
            if ($hit !== null) { $rowId = (int)$hit['id']; }
        }
        if ($rowId > 0) {
            $own = db_one('SELECT id FROM user_themes WHERE id = ? AND uid = ? LIMIT 1', array($rowId, $uid));
            if ($own === null) { fail(404, '主题不存在或已删除'); }
            db_exec('UPDATE user_themes SET theme_id = ?, theme_name = ?, bytes = ?, body = ?, expires_at = ?
                     WHERE id = ? AND uid = ?',
                array((string)$meta['id'], $label, (int)$meta['bytes'], $raw, $expires, $rowId, $uid));
            ok(array('row' => $rowId, 'id' => $meta['id'], 'name' => $label, 'expires_at' => $expires,
                'count' => theme_saved_count($uid)));
            break;
        }

        if (theme_saved_count($uid) >= theme_save_limit()) {
            fail(409, '最多保存 ' . theme_save_limit() . ' 份主题，请先删掉不用的');
        }
        $newId = db_insert('INSERT INTO user_themes (uid, theme_id, theme_name, bytes, body, created_at, expires_at)
                            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)',
            array($uid, (string)$meta['id'], $label, (int)$meta['bytes'], $raw, $expires));
        ok(array('row' => $newId, 'id' => $meta['id'], 'name' => $label, 'expires_at' => $expires,
            'count' => theme_saved_count($uid)));
        break;
    }

    /* 我的主题列表（只给概要，正文按需取，避免一次拉回十份大文件） */
    case 'saved': {
        if (!table_exists('user_themes')) { ok(array('items' => array(), 'limit' => 0)); }
        $uid = (int)$ident['uid'];
        db_exec('DELETE FROM user_themes WHERE uid = ? AND expires_at <= UTC_TIMESTAMP()', array($uid));
        $rows = db_all('SELECT id, theme_id, theme_name, bytes, created_at, expires_at
                        FROM user_themes WHERE uid = ? ORDER BY id DESC LIMIT 20', array($uid));
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'row'        => (int)$r['id'],
                'id'         => (string)$r['theme_id'],
                'name'       => (string)$r['theme_name'],
                'bytes'      => (int)$r['bytes'],
                'created_at' => (string)$r['created_at'],
                'expires_at' => (string)$r['expires_at'],
            );
        }
        ok(array('items' => $out, 'limit' => theme_save_limit()));
        break;
    }

    case 'get': {
        if (!table_exists('user_themes')) { ok(array('json' => '', 'name' => '')); }
        $uid = (int)$ident['uid'];
        $rowId = (int)param_int('row', 0);
        if ($rowId > 0) {
            $row = db_one('SELECT id, theme_id, theme_name, body, expires_at FROM user_themes
                           WHERE id = ? AND uid = ? LIMIT 1', array($rowId, $uid));
        } else {
            $row = db_one('SELECT id, theme_id, theme_name, body, expires_at FROM user_themes
                           WHERE uid = ? ORDER BY id DESC LIMIT 1', array($uid));
        }
        if ($row === null) { ok(array('json' => '', 'name' => '')); }
        $exp = strtotime((string)$row['expires_at'] . ' UTC');
        if ($exp !== false && $exp <= time()) {
            /* 过期即删：不等到 GC，读取时顺手清掉 */
            db_exec('DELETE FROM user_themes WHERE id = ? AND uid = ?', array((int)$row['id'], $uid));
            ok(array('json' => '', 'name' => '', 'expired' => 1));
        }
        ok(array(
            'row'        => (int)$row['id'],
            'json'       => (string)$row['body'],
            'id'         => (string)$row['theme_id'],
            'name'       => (string)$row['theme_name'],
            'expires_at' => (string)$row['expires_at'],
        ));
        break;
    }

    case 'del': {
        csrf_verify();
        if (table_exists('user_themes')) {
            $uid = (int)$ident['uid'];
            $rowId = (int)param_int('row', 0);
            if ($rowId > 0) {
                db_exec('DELETE FROM user_themes WHERE id = ? AND uid = ?', array($rowId, $uid));
            } else {
                db_exec('DELETE FROM user_themes WHERE uid = ?', array($uid));
            }
        }
        ok(array('deleted' => 1));
        break;
    }
}

fail(400, '未知操作');
