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
        if (!rate_limit('reg_' . ip_hash(client_ip()), 10, 3600)) { fail(429, '注册过于频繁，请稍后再试'); }
        cooldown_guard('register');
        $r = user_register(param_str('username'), param_str('password'));
        ok(array('token' => $r['token'], 'role' => 'user', 'uid' => $r['uid'], 'username' => $r['username']));
        break;

    /* 登录（管理员与普通用户统一通道） */
    case 'login':
        csrf_verify();
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

    /* 每次打开网页的后端重新验证 */
    case 'verify':
        $id = current_identity();
        if ($id === null) { fail(401, '登录态已失效'); }
        $data = array('role' => $id['role'], 'uid' => (int)$id['uid']);
        if ($id['role'] === 'user' || $id['role'] === 'subadmin') {
            /* 普通用户与副管理员都有真实用户行：同等待遇（含 CSRF，可发言） */
            $u = current_user_row();
            if ($u === null) { fail(401, '账号不可用'); }
            $st = user_settings($u);
            $data['username'] = (string)$u['username'];
            $data['avatar'] = identicon_data_uri((string)$u['username'], 80);
            $data['settings'] = $st;
            $data['csrf'] = csrf_token();
            $data['uid8'] = uid_of_user($u, subadmin_seq_of((int)$u['id']));
            if ($id['role'] === 'subadmin') { $data['is_admin'] = true; $data['subadmin'] = true; }
        } elseif ($id['role'] === 'admin') {
            $data['username'] = 'admin';
            $data['avatar'] = identicon_data_uri('admin', 80);
            $data['is_admin'] = true;
            $data['csrf'] = csrf_token();
            $au = current_user_row();
            $data['uid8'] = $au !== null ? uid_of_user($au, 0) : UID_ADMIN;
        } else {
            $data['username'] = '游客';
            $data['avatar'] = identicon_data_uri('guest', 80);
        }
        ok($data);
        break;

    default:
        fail(400, '未知操作');
}
