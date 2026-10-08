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
            'alive'     => (int)$row['banned'] === 1 && discipline_ban_alive($row),
            'ip_banned' => (int)$row['ip_banned'] === 1,
            'ban_days'  => (float)$row['ban_days'],
            'ban_until' => $row['ban_until'] === null ? '' : to_local((string)$row['ban_until']),
            'user_count' => discipline_user_count((int)$row['user_id']),
            'views'     => (int)$row['views'],
            'created'   => to_local((string)$row['created_at']),
        ));
        break;
    }

    /* 位置校验（公开、免登录）：被 IP 拦下的人可提交自己的定位来证明「我不是当事人」。
       本接口在封禁白名单内，被封停时也能调用。 */
    case 'geo': {
        $lat = (float)param('lat', 0);
        $lng = (float)param('lng', 0);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            fail(400, '定位数据无效');
        }
        $hash = discipline_ip_hash(discipline_client_ip());
        $v = discipline_geo_verdict($hash, array($lat, $lng));
        ok(array('blocked' => (bool)$v['blocked'], 'anchored' => (bool)$v['anchored'],
                 'distance' => (float)$v['distance'], 'tol' => discipline_geo_tol_km()));
        break;
    }

    /* 最新一条通报（公开）：供全站弹窗使用 */
    case 'latest': {
        ok(array('item' => discipline_latest()));
        break;
    }

    default:
        fail(400, '未知操作');
}
