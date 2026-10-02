<?php
/**
 * API：违纪通报
 * ------------------------------------------------------------
 * actions:
 *   list —— 通报列表（公开可读，进入违纪界面与通报页都用它）
 *   get  —— 单条通报详情（含多条理由）
 *
 * 说明：这里只读。创建 / 撤销通报与理由维护在 api/admin.php 里，仅管理员可用。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'list');

switch ($action) {

    case 'list': {
        $page = max(1, param_int('page', 1));
        $r = discipline_list($page, 20);
        ok($r);
        break;
    }

    case 'get': {
        $id = param_int('id', 0);
        $row = discipline_get($id);
        if ($row === null) { fail(404, '该通报不存在或已被撤销'); }
        discipline_view_count($id);
        $row = discipline_get($id);           // 取回浏览数 +1 后的值
        ok(array(
            'id'        => (int)$row['id'],
            'user_id'   => (int)$row['user_id'],
            'username'  => (string)$row['username'],
            'avatar'    => identicon_data_uri((string)$row['username'], 64),
            'reasons'   => discipline_reasons_of($row),
            'note'      => (string)($row['note'] ?? ''),
            'banned'    => (int)$row['banned'] === 1,
            'ip_banned' => (int)$row['ip_banned'] === 1,
            'ban_days'  => (int)$row['ban_days'],
            'ban_until' => $row['ban_until'] === null ? '' : to_local((string)$row['ban_until']),
            'user_count' => discipline_user_count((int)$row['user_id']),
            'views'     => (int)$row['views'],
            'created'   => to_local((string)$row['created_at']),
        ));
        break;
    }

    default:
        fail(400, '未知操作');
}
