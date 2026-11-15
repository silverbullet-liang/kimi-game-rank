<?php
/**
 * API：访问准入
 * actions: status | verify
 *
 * verify 是唯一一个在闸门生效期间仍被放行的接口 —— 不然验完了也进不去。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'status');

if ($action === 'status') {
    ok(array(
        'required' => gate_required(),
        'ttl'      => gate_ttl(),
        'cfg'      => captcha_client_config(),
    ));
}

if ($action !== 'verify') { fail(400, '未知操作'); }

if (!captcha_enabled()) { fail(400, '人机验证未启用，无法完成验证'); }

$ip   = client_ip();
$hash = discipline_ip_hash($ip);

/* 已有有效票：直接放行，不再消耗一次校验 */
if (!gate_required()) { ok(array('passed' => 1, 'ttl' => gate_ttl())); }

$token = param_str('cap_token', '');
$r     = captcha_verify($token, param_str('cap_channel', ''), $ip);
if (empty($r['ok'])) {
    app_log('gate verify rejected via=' . $r['via'] . ' reason=' . $r['codes']);
    fail(400, '人机验证未通过，请重新验证');
}

gate_grant($hash);
app_log('gate verify passed via=' . $r['via'] . ' hash=' . substr($hash, 0, 8));
ok(array('passed' => 1, 'ttl' => gate_ttl()));
