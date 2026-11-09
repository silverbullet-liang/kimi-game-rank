<?php
/**
 * API：用户主题（theme/1）云同步
 * actions: save | get | del
 * 说明：仅登录用户；一份即全部（保存即覆盖），保留 7 天，到期自动失效。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'get');
$ident  = require_user();

switch ($action) {

    case 'save': {
        csrf_verify();
        if (!rate_limit('theme_save_' . (int)$ident['uid'], 12, 3600)) {
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

        $now = time();
        $created = gmdate('Y-m-d H:i:s', $now);
        $expires = gmdate('Y-m-d H:i:s', $now + theme_json_ttl_days() * 86400);
        db_exec('DELETE FROM user_themes WHERE uid = ?', array((int)$ident['uid']));
        db_insert('INSERT INTO user_themes (uid, theme_id, theme_name, bytes, body, created_at, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)',
            array((int)$ident['uid'], $meta['id'], $meta['name'], (int)$meta['bytes'], $raw, $created, $expires));

        ok(array('id' => $meta['id'], 'name' => $meta['name'], 'expires_at' => $expires));
        break;
    }

    case 'get': {
        if (!table_exists('user_themes')) { ok(array('json' => '', 'name' => '')); }
        $row = db_one('SELECT theme_id, theme_name, body, expires_at FROM user_themes WHERE uid = ? ORDER BY id DESC LIMIT 1',
            array((int)$ident['uid']));
        if ($row === null) { ok(array('json' => '', 'name' => '')); }
        $exp = strtotime((string)$row['expires_at'] . ' UTC');
        if ($exp !== false && $exp <= time()) {
            /* 过期即删：不等到 GC，读取时顺手清掉 */
            db_exec('DELETE FROM user_themes WHERE uid = ?', array((int)$ident['uid']));
            ok(array('json' => '', 'name' => '', 'expired' => 1));
        }
        ok(array(
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
            db_exec('DELETE FROM user_themes WHERE uid = ?', array((int)$ident['uid']));
        }
        ok(array('deleted' => 1));
        break;
    }
}

fail(400, '未知操作');
