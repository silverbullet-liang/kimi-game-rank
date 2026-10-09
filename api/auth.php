<?php
/**
 * API：认证
 * actions: csrf | guest | register | login | logout | verify | heartbeat
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', $_SERVER['REQUEST_METHOD'] === 'POST' ? '' : 'csrf');

switch ($action) {

    /* 获取 CSRF 令牌（页面初始化调用） */
    case 'csrf':
        ok(array('csrf' => csrf_token()));
        break;

    /* 以游客身份进入 */
    case 'guest':
        csrf_verify();
        $g = guest_enter();
        ok(array('token' => $g['token'], 'role' => 'guest', 'username' => '游客'));
        break;

    /* 注册 */
    case 'register':
        csrf_verify();
        /* 已被封停的来源 / 账号：只能登录（登录后由全屏封禁说明接管），不给注册新号绕过的口子。
           若其定位显示与封禁锚点相距很远，discipline_visitor_blocked() 会放行（同 IP 不同人）。 */
        if (function_exists('discipline_visitor_blocked') && discipline_visitor_blocked()) {
            fail(403, '当前来源已被限制，无法注册新账号');
        }
        captcha_guard();          // 人机验证：登录与注册都要过这道门
        if (!rate_limit('reg_' . ip_hash(client_ip()), 10, 3600)) { fail(429, '注册过于频繁，请稍后再试'); }
        cooldown_guard('register');
        $r = user_register(param_str('username'), param_str('password'));
        ok(array('token' => $r['token'], 'role' => 'user', 'uid' => $r['uid'], 'username' => $r['username']));
        break;

    /* 登录（管理员与普通用户统一通道） */
    case 'login':
        csrf_verify();
        captcha_guard();          // 人机验证：登录与注册都要过这道门
        $r = user_login(param_str('username'), param_str('password'));
        ok(array('token' => $r['token'], 'role' => $r['role'], 'uid' => $r['uid'], 'username' => $r['username']));
        break;

    /* 退出登录 */
    case 'logout':
        require_token();
        csrf_verify();
        user_logout();
        ok(null, '已退出');
        break;

    /* 用户自行更新用户名（仅用于修正不符合当前规范的历史用户名） */
    case 'rename': {
        $id = require_token();
        if ($id['role'] !== 'user' && $id['role'] !== 'subadmin') { fail(403, '请登录后再操作'); }
        csrf_verify();
        if (!rate_limit('ren_' . (int)$id['uid'], 5, 3600)) { fail(429, '改名过于频繁，请稍后再试'); }
        $r = user_rename_self((int)$id['uid'], param_str('username', ''));
        ok(array('username' => $r['username'], 'old' => $r['old']),
           $r['changed'] ? '用户名已更新' : '名字没有变化');
        break;
    }

    /* 每次打开网页的后端重新验证 */
    case 'verify':
        $id = current_identity();
        if ($id === null) { fail(401, '登录态已失效'); }
        ok(identity_payload($id));
        break;

    default:
        fail(400, '未知操作');
}
