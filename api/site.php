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
        /* 分类计数与公告都走站点聚合缓存（60 秒）：
           此前两件事分属不同请求、各查一次库，同一屏就重复算了整表聚合。 */
        ok(array(
            'site'        => (string)cfg('site.name', 'Kimi游戏榜'),
            'version'     => APP_VERSION,
            'announce'    => site_announce(),
            'categories'  => site_category_counts(),
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
