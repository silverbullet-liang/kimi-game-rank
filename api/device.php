<?php
/**
 * API：登录设备
 * ------------------------------------------------------------
 * actions:
 *   list   —— 设备列表（含风险标记），只读、不校验 CSRF，管理员也能看
 *   logout —— 单台登出（远程下线，令牌立即进黑名单）
 *   switch —— 远程切换账号：密码在这一侧校验，把那台设备换成另一个账号
 *   claim  —— 被切号的设备用旧令牌领取新身份（旧令牌已被拉黑，故不走身份校验）
 *   forget —— 本机下线后切号
 *   hello  —— 前端痕迹上报（canvas 指纹），用于风险判据「有无前端痕迹」
 *
 * 一个账号最多 3 台同时在线，第 4 台登录时自动挤掉最久未活动的那台。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'list');

/* 领号必须在身份校验之前：旧令牌已经进黑名单，它自己就是领取凭证 */
if ($action === 'claim') {
    $out = dev_claim_switch(bearer_token());
    if (!$out) { fail(401, '没有待领取的账号切换'); }
    ok($out);
}

$id = current_identity();
if ($id === null) { fail(401, '请先登录'); }
$uid = dev_owner_uid($id);
if ($uid <= 0) { fail(401, '账号不可用'); }

switch ($action) {

    case 'list': {
        ok(array(
            'devices' => dev_list($uid),
            'limit' => DEV_MAX_ACTIVE,
        ));
        break;
    }

    case 'logout': {
        csrf_verify();
        $rid = (int)param('id', 0);
        if ($rid <= 0) { fail(400, '参数错误'); }
        if (!dev_logout($uid, $rid)) { fail(404, '设备不存在或已下线'); }
        ok(array('ok' => true), '该设备已下线');
        break;
    }

    case 'switch': {
        csrf_verify();
        $rid = (int)param('id', 0);
        if ($rid <= 0) { fail(400, '参数错误'); }
        $out = dev_switch_account($uid, $rid, param_str('username', ''), (string)param('password', ''));
        ok($out, '已切换到 ' . $out['to'] . '，该设备下次请求即生效');
        break;
    }

    case 'forget': {
        csrf_verify();
        $cur = dev_hash_of_request();
        if ($cur !== '') {
            $row = db_one('SELECT id FROM login_devices WHERE user_id = ? AND dev_hash = ? AND active = 1 LIMIT 1',
                array($uid, $cur));
            if ($row !== null) { dev_logout($uid, (int)$row['id']); }
        }
        ok(array('ok' => true), '已下线，可以切换账号');
        break;
    }

    case 'hello': {
        csrf_verify();
        $fp = preg_replace('/[^a-f0-9]/i', '', param_str('fp', ''));
        if ($fp !== '') { dev_fp_report($uid, (string)$fp); }
        ok(array('ok' => true));
        break;
    }

    default:
        fail(400, '未知操作');
}
