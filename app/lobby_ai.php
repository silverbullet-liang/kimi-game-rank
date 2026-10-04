<?php
/**
 * 世界对话 · @官方AI
 * ------------------------------------------------------------
 * 用户在消息里 @ 官方 AI 时：
 *   1) 以「发起人」的身份过闸门（频率 + 个人 token 额度）；
 *   2) 带上最近几条世界对话作为上下文，调 glm-4-flash（非流式）；
 *   3) 把回复作为「官方 AI」的消息落库（user_id=0、msg_type='ai'）；
 *   4) 用量记在发起人名下（不是站点）。
 *
 * 任何一步失败都不影响消息本身是否发出 —— 返回 note 交给前端提示。
 * 官方 AI 不是 users 表里的行：靠 msg_type='ai' 区分，无需改表结构。
 */
declare(strict_types=1);

if (!defined('LOBBY_AI_NAME')) { define('LOBBY_AI_NAME', '官方AI'); }

/** 文本里是否 @ 了官方 AI */
function lobby_ai_mentioned(string $text): bool
{
    return strpos($text, '@' . LOBBY_AI_NAME) !== false;
}

/** 清掉模型可能带出的工具标签、控制字符，并限长 */
function lobby_ai_clean(string $s): string
{
    $s = (string)preg_replace('/<Works\s*[\-_ ]?\s*check\s*>[\s\S]*?(?:<\/Works\s*[\-_ ]?\s*check\s*>|$)/i', '', $s);
    $s = (string)preg_replace('#</?(?:web_open|webopen|search|get|rank|comments|weather|time)\b[^>]*/?>#i', '', $s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    $s = trim($s);
    if (mb_strlen($s, 'UTF-8') > 500) { $s = mb_substr($s, 0, 500, 'UTF-8'); }
    return $s;
}

/** 官方 AI 的系统提示：纯聊天，不给工具协议 */
function lobby_ai_system(): string
{
    $site = (string)cfg('site.name', 'Kimi游戏榜');
    return '你是「' . $site . '」世界对话里的官方 AI（显示名：' . LOBBY_AI_NAME . '）。'
        . '有人在消息里 @ 你时，用简体中文、纯文本、简洁友好地回应当前话题，通常不超过 150 字。'
        . '不要使用 Markdown 标记，不要输出任何工具调用标签或 JSON。'
        . '遇到辱骂、攻击或违规请求，礼貌拒绝即可。';
}

/** 组装带最近对话上下文的消息数组 */
function lobby_ai_messages(string $ask, string $asker): array
{
    $rows = array();
    try {
        $rows = db_all(
            "SELECT m.content, " . (col_ok('messages', 'msg_type') ? 'm.msg_type' : "'text' AS msg_type") . ", "
            . (col_ok('messages', 'is_recalled') ? 'm.is_recalled' : '0 AS is_recalled') . ", "
            . "COALESCE(u.username, '用户') AS username
              FROM messages m LEFT JOIN users u ON u.id = m.user_id
              ORDER BY m.id DESC LIMIT 8"
        );
    } catch (Throwable $e) { $rows = array(); }

    $ctx = array();
    foreach (array_reverse((array)$rows) as $r) {
        if ((int)$r['is_recalled'] === 1 || (string)$r['msg_type'] === 'image') { continue; }
        $t = trim((string)$r['content']);
        if ($t === '') { continue; }
        if (mb_strlen($t, 'UTF-8') > 120) { $t = mb_substr($t, 0, 120, 'UTF-8') . '…'; }
        $ctx[] = (string)$r['username'] . '：' . $t;
    }

    $body = $ask;
    if ($ctx) { $body = "【最近的世界对话】\n" . implode("\n", $ctx) . "\n\n【{$asker} 对你说】\n" . $ask; }
    return array(
        array('role' => 'system', 'content' => lobby_ai_system()),
        array('role' => 'user',   'content' => $body),
    );
}

/**
 * 生成一条官方 AI 回复并落库。
 * 返回 array(ok:bool, item?:array, note?:string)。
 */
function lobby_ai_reply(int $uid, string $ask, string $asker): array
{
    if (!rate_limit('lobby_ai_' . $uid, (int)cfg('ai_limits.per_minute', 6), 60)) {
        return array('ok' => false, 'note' => '@AI 太频繁了，稍后再试');
    }
    $q = ai_quota_check($uid);
    if (empty($q['ok'])) { return array('ok' => false, 'note' => (string)$q['reason']); }

    if (!col_ok('messages', 'msg_type')) { return array('ok' => false, 'note' => '当前数据库尚未支持 @AI'); }

    try {
        $r = zhipu_chat(lobby_ai_messages($ask, $asker), 'glm-4-flash');
    } catch (Throwable $e) {
        app_log('lobby_ai failed: ' . $e->getMessage());
        return array('ok' => false, 'note' => 'AI 暂时不可用，请稍后再试');
    }

    $reply = lobby_ai_clean(isset($r['text']) ? (string)$r['text'] : '');
    if ($reply === '') { return array('ok' => false, 'note' => 'AI 没有给出回复'); }

    $cols = array('user_id', 'content', 'msg_type');
    $vals = array(0, $reply, 'ai');
    if (col_ok('messages', 'media_url'))   { $cols[] = 'media_url';   $vals[] = ''; }
    if (col_ok('messages', 'is_recalled')) { $cols[] = 'is_recalled'; $vals[] = 0; }
    $ph = array_fill(0, count($cols), '?');
    try {
        $mid = db_insert(
            'INSERT INTO messages (`' . implode('`, `', $cols) . '`, created_at) VALUES (' . implode(', ', $ph) . ', UTC_TIMESTAMP())',
            $vals
        );
    } catch (Throwable $e) {
        app_log('lobby_ai insert failed: ' . $e->getMessage());
        return array('ok' => false, 'note' => 'AI 回复保存失败');
    }

    /* 用量记在发起人名下（用户要求：扣发送人的额度） */
    if (!empty($r['usage'])) { ai_usage_record($uid, (array)$r['usage'], 'glm'); }

    return array('ok' => true, 'item' => array(
        'id'       => (int)$mid,
        'uid'      => 0,
        'username' => LOBBY_AI_NAME,
        'role'     => 'ai',
        'avatar'   => identicon_data_uri(LOBBY_AI_NAME, 40),
        'content'  => $reply,
        'msg_type' => 'ai',
        'media'    => '',
        'recalled' => false,
        'flag'     => '',
        'mine'     => false,
        'time'     => to_local(now_utc(), 'm-d H:i'),
    ));
}
