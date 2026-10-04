<?php
/**
 * API：AI 重审
 * ------------------------------------------------------------
 * POST action=run  { content }
 *
 * 被审核拦下后，由用户显式点击触发的一次复核（不参与自动流程）。
 * 返回三档结论：
 *   true   —— 合规，放行；
 *   middle —— 可能有恶意，仍放行，前端在这条消息旁标注提示；
 *   false  —— 维持拦截。
 *
 * 结论为 true / middle 时，服务端顺带签发一次性凭证（绑定用户与内容指纹、15 分钟有效），
 * 发送接口凭它放行 —— 否则会出现「重审说可以、发送又被拦一次」的死循环。
 * AI 用量记在站点名下，不消耗用户个人额度。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = param_str('action', '');

switch ($action) {
    case 'run': {
        $id  = require_member();
        /* 必须换算成落库 uid：发送接口按 actor_uid 绑定凭证，
           直接 (int) 强转身份数组会得到 1，凭证与用户对不上 → 重审通过也发不出去。 */
        $uid = (int)actor_uid($id);
        csrf_verify();
        cooldown_guard('recheck');      // 人工触发的 AI 调用，冷却防连点

        $content = trim(strip_invisible(nfc_normalize(param_str('content'))));
        if ($content === '') { fail(400, '内容不能为空'); }
        if (mb_strlen($content, 'UTF-8') > 500) { fail(400, '内容最长 500 字'); }

        $r = moderation_recheck($content, $uid);
        ok(array('verdict' => (string)$r['verdict']));
        break;
    }

    default:
        fail(400, '未知操作');
}
