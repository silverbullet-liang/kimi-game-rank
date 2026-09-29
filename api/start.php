<?php
/**
 * API：启动载荷（首屏一次往返取回）
 * ------------------------------------------------------------
 * 首屏此前要串行走好几个请求：csrf → 令牌 → 公告 → 分类计数 → 榜单。
 * 每次请求都要重新引导一遍 PHP（加载全部核心库 + 连库），在共享主机上这部分
 * 开销往往比查询本身还大。这里把「进站必需」的东西合并成一次：
 *
 *   csrf · 登录态（无有效令牌时顺带下发游客令牌）· 站内公告 · 分类计数
 *   ·（可选）首屏榜单第一页 —— 调用方给出榜别与分类，内容直接带回
 *
 * 与旧流程等价：令牌仍由既有 guest_enter() 下发，身份仍由 identity_payload() 整形，
 * 只是把两次往返合成一次，并未放宽任何鉴权。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (param_str('action', 'app') !== 'app') { fail(400, '未知操作'); }

$ident = current_identity();
if ($ident !== null) {
    $data = identity_payload($ident);
} else {
    /* 没有有效令牌：下发游客令牌（等价于旧的「取 csrf → 换游客令牌」两步） */
    $g = guest_enter();
    $data = array(
        'role'     => 'guest',
        'uid'      => 0,
        'username' => '游客',
        'token'    => (string)$g['token'],
        'avatar'   => identicon_data_uri('guest', 80),
    );
}

$data['csrf']       = csrf_token();
$data['announce']   = site_announce();
$data['categories'] = site_category_counts();
$data['version']    = APP_VERSION;

/* 首屏内容：带上榜别与分类就直接返回第一页，省掉一次往返。
   不传 first=1 就是纯启动载荷（站内跳转、回访都不需要重复取）。 */
if (param_int('first', 0) === 1) {
    $cat   = param_str('category', 'all');
    $board = param_str('board', 'total');
    $size  = max(1, min(50, param_int('size', 12)));
    $uid   = isset($data['uid']) ? (int)$data['uid'] : 0;

    $page1 = works_list_page($cat, $board, '', 1, $size, $uid, true);
    $page1['cat']   = $cat;        // 回带参数，前端据此判断能否直接采用
    $page1['board'] = $board;
    $data['first']  = $page1;
}

ok($data);
