<?php
declare(strict_types=1);
/**
 * API：账号设置（邮箱绑定 / 换绑）
 * actions: email_state | email_send | email_bind
 *
 * 绑定流程两步：
 *   1) email_send  {email, lang}      → 校验格式与占用，发 6 位验证码（两分钟）
 *   2) email_bind  {email, code}      → 校验通过即写入，旧邮箱自动被覆盖
 * 一个邮箱只能属于一个账号：先查一次给友好提示，数据库唯一索引兜住并发。
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'email_state');
$id = require_token();
if ($id['role'] !== 'user' && $id['role'] !== 'subadmin') { fail(403, '请登录后再操作'); }
$uid = (int)$id['uid'];

switch ($action) {

    case 'email_state': {
        $e = user_email_of($uid);
        $at = '';
        if ($e !== '' && email_ready()) {
            $r = db_one('SELECT email_at FROM users WHERE id = ? LIMIT 1', array($uid));
            $at = ($r !== null && !empty($r['email_at'])) ? (string)$r['email_at'] : '';
        }
        ok(array('ready' => email_ready(), 'email' => $e,
                 'masked' => $e !== '' ? mail_mask($e) : '', 'at' => $at));
        break;
    }

    case 'email_send': {
        csrf_verify();
        if (!rate_limit('mail_bind_' . $uid, 8, 3600)) { fail(429, '操作过于频繁，请稍后再试'); }
        $r = email_code_send(param_str('email'), 'bind', $uid, param_str('lang', 'zh'));
        ok(array('ttl' => isset($r['ttl']) ? (int)$r['ttl'] : email_code_ttl()), '验证码已发送');
        break;
    }

    case 'email_bind': {
        csrf_verify();
        $email = param_str('email');
        $chk = email_code_check($email, 'bind', param_str('code'));
        if (empty($chk['ok'])) {
            $map = array(
                'expired'        => '验证码已过期，请重新获取',
                'too_many_tries' => '错误次数过多，请重新获取验证码',
                'wrong_code'     => '验证码不正确',
                'no_code'        => '验证码不正确',
                'bad_code'       => '请输入 6 位验证码',
                'not_ready'      => '邮箱功能暂未开放',
            );
            fail(401, isset($map[$chk['reason']]) ? $map[$chk['reason']] : '验证码不正确');
        }
        $res = email_bind_apply($uid, $email);
        email_code_consume($email, 'bind');
        ok($res, '邮箱已绑定');
        break;
    }

    default:
        fail(400, '未知操作');
}
