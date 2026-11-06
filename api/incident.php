<?php
declare(strict_types=1);
/**
 * API：攻击事件档案（公开只读）
 * actions: list | get
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'list');

switch ($action) {

    case 'list': {
        $rows = db_all('SELECT id, title, started_at, ended_at, summary, culprit, severity
                        FROM incidents WHERE is_public = 1
                        ORDER BY COALESCE(started_at, created_at) DESC LIMIT 100');
        $out = array();
        foreach ((array)$rows as $r) {
            $out[] = array(
                'id'       => (int)$r['id'],
                'title'    => (string)$r['title'],
                'started'  => empty($r['started_at']) ? '' : to_local((string)$r['started_at']),
                'ended'    => empty($r['ended_at']) ? '' : to_local((string)$r['ended_at']),
                'summary'  => (string)$r['summary'],
                'culprit'  => (string)$r['culprit'],
                'severity' => (string)$r['severity'],
                'comments' => (int)db_val(
                    "SELECT COUNT(*) FROM comments WHERE target_type = 'incident' AND work_id = ? AND is_deleted = 0",
                    array((int)$r['id'])),
            );
        }
        ok(array('items' => $out, 'total' => count($out)));
        break;
    }

    case 'get': {
        $id = param_int('id', 0);
        if ($id <= 0) { fail(400, '参数错误'); }
        $r = db_one('SELECT * FROM incidents WHERE id = ? AND is_public = 1 LIMIT 1', array($id));
        if ($r === null) { fail(404, '档案不存在'); }
        ok(array(
            'id'       => (int)$r['id'],
            'title'    => (string)$r['title'],
            'started'  => empty($r['started_at']) ? '' : to_local((string)$r['started_at']),
            'ended'    => empty($r['ended_at']) ? '' : to_local((string)$r['ended_at']),
            'summary'  => (string)$r['summary'],
            'doc'      => (string)$r['doc'],
            'culprit'  => (string)$r['culprit'],
            'severity' => (string)$r['severity'],
        ));
        break;
    }

    default:
        fail(400, '未知操作');
}
