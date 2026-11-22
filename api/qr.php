<?php
/**
 * API：扫码登录
 * ------------------------------------------------------------
 * actions:
 *   create  —— 建一张两分钟的票据，返回 qr_id 与一次性轮询凭证
 *   poll    —— 桌面端轮询（pending / confirmed 带令牌 / expired）
 *   confirm —— 手机端确认（需已登录 + CSRF）
 *
 * 防刷口径见 app/qr_login.php 顶部注释：双维度限速、短 TTL、
 * 轮询次数上限、票据总量上限、领取即删。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', 'create');

switch ($action) {

    case 'create': {
        csrf_verify();
        captcha_guard();   // 生成二维码前先过人机验证（与登录同一道闸门，未配置时自动放行）
        ok(qr_create());
        break;
    }

    case 'poll': {
        /* 轮询不带 CSRF：它只凭一次性 secret 换「自己那枚票」的状态，
           跨站拿不到 secret，也就没有任何可被利用的副作用。 */
        ok(qr_poll(param_str('qr_id', ''), param_str('secret', '')));
        break;
    }

    case 'confirm': {
        $ident = current_identity();
        if ($ident === null) { fail(401, '请先登录账号'); }
        csrf_verify();
        captcha_guard();   // 确认方（手机）同样要过一次人机验证
        ok(qr_confirm($ident, param_str('qr_id', '')));
        break;
    }
}

fail(400, '未知操作');
