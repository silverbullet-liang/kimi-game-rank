<?php
/**
 * API：站点信息
 * actions: bootstrap | announce | admins
 * 说明：令牌获取类接口（auth.php 的 csrf/guest/login/register）与本站点
 *       元信息接口，是「拿到令牌前」必要的引导入口；其余所有接口一律强制令牌。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'bootstrap');

switch ($action) {

    case 'bootstrap':
    case 'announce': {
        require_token();
        $cat = array();
        foreach (db_all('SELECT category, COUNT(*) n FROM works WHERE is_hidden = 0 GROUP BY category') as $r) {
            $cat[(string)$r['category']] = (int)$r['n'];
        }
        $ann = trim((string)db_val("SELECT content FROM announcements WHERE content <> '' ORDER BY id ASC LIMIT 1"));
        if ($ann === '') { $ann = trim((string)setting_get('announcement', '')); }
        ok(array(
            'site'        => (string)cfg('site.name', 'Kimi游戏榜'),
            'version'     => APP_VERSION,
            'announce'    => $ann,
            'categories'  => array(
                'game'       => isset($cat['game']) ? $cat['game'] : 0,
                'tool'       => isset($cat['tool']) ? $cat['tool'] : 0,
                'literature' => isset($cat['literature']) ? $cat['literature'] : 0,
                'fanart'     => isset($cat['fanart']) ? $cat['fanart'] : 0,
            ),
            'csrf'        => csrf_token(),
        ));
        break;
    }

    /* 公开管理人员名单：「关于」页实时读取。
       刻意独立于控制面板的接口（admin.php / dashboard.php）——
       公开页面只碰这一条只读链路，且只导出用户名与角色标签。 */
    case 'admins': {
        require_token();
        ok(array('items' => admin_public_list()));
        break;
    }

    default:
        fail(400, '未知操作');
}
