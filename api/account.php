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
/* 只拦游客。管理员与副管理员同样可以绑邮箱、开双重验证 ——
   他们在 users 表里各有一行，actor_uid() 会把 admin 的 uid=0 映射到那一行。 */
if ((string)$id['role'] === 'guest') { fail(403, '请登录后再操作'); }
$uid = actor_uid($id);
if ($uid <= 0) { fail(403, '该账号暂不支持邮箱功能'); }

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

    /* 解绑邮箱：不需要验证码（按用户要求）。解绑会顺手关掉登录双重验证 ——
       没有第二因子时开关必须失效，否则下一步登录就会把自己锁在门外。 */
    case 'email_unbind': {
        csrf_verify();
        $cur = user_email_of($uid);
        if ($cur === '') { ok(array('email' => '', 'twofa' => false, 'twofa_off' => false), '当前未绑定邮箱'); break; }
        db_exec('UPDATE users SET email = NULL, email_norm = NULL, email_at = NULL WHERE id = ?', array($uid));
        $u = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', array($uid));
        $st = user_settings($u);
        $off = false;
        if (!empty($st['twofa'])) {
            $st['twofa'] = 0;
            db_exec('UPDATE users SET settings = ? WHERE id = ?',
                array(json_encode($st, JSON_UNESCAPED_UNICODE), $uid));
            $off = true;
        }
        /* 该账号的未用验证码与票据一并作废 */
        db_exec('DELETE FROM email_codes WHERE user_id = ?', array($uid));
        app_log('email unbound uid=' . $uid . ($off ? ' (twofa off)' : ''));
        ok(array('email' => '', 'twofa' => false, 'twofa_off' => $off),
           $off ? '邮箱已解绑，登录双重验证已关闭' : '邮箱已解绑');
        break;
    }

    /* 双重验证：状态 + 开关（开关需要密码确认；开启前必须先绑邮箱） */
    case 'twofa_state': {
        $u = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', array($uid));
        $s = user_settings($u);
        ok(array('enabled' => !empty($s['twofa']), 'email' => user_email_of($uid),
                 'ready' => email_ready() && user_email_of($uid) !== ''));
        break;
    }

    case 'twofa_set': {
        csrf_verify();
        if (!rate_limit('twofa_' . $uid, 10, 3600)) { fail(429, '操作过于频繁，请稍后再试'); }
        $on = (int)param_str('on', '0') === 1;
        $r = user_twofa_set($uid, $on, param_str('password', ''));
        ok($r, $on ? '已开启登录双重验证' : '已关闭登录双重验证');
        break;
    }

    default:
        fail(400, '未知操作');
}
