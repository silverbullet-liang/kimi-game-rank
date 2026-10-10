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
    $s = (string)preg_replace('#</?(?:' . works_tool_names_re() . ')\b[^>]*/?>#i', '', $s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    $s = trim($s);
    if (mb_strlen($s, 'UTF-8') > 500) { $s = mb_substr($s, 0, 500, 'UTF-8'); }
    return $s;
}

/**
 * 官方 AI 的系统提示：纯聊天，不给工具协议。
 * 刻意往「社区里的真人玩家」上调 —— 早先写成「简洁友好 / 礼貌拒绝」，
 * 结果它回什么都像客服，还爱复读，故补上性格与防复读、禁用语两条硬约束。
 */
function lobby_ai_system(): string
{
    $site = (string)cfg('site.name', 'Kimi游戏榜');
    return '你是「' . $site . '」世界对话里的官方 AI（显示名：' . LOBBY_AI_NAME . '）。'
        . '你是这个社区的老玩家，说话像真人：直白、带点幽默，可以吐槽、可以有情绪，就是别像客服。'
        . '用简体中文、纯文本，一般不超过 150 字；不要 Markdown，不要输出任何工具调用标签或 JSON。'
        . '【防复读】回之前先看上面的对话：如果你的意思跟已经说过的重复，就换个说法，'
        . '或者直接吐槽一句（例如「这题刚说过啊，换个话题呗」），绝不照抄自己。'
        . '【禁用语】不要出现「如有疑问请咨询客服」「请关注公告」「感谢您的反馈」这类机械结尾，'
        . '也别写成公告或通报的格式。'
        . '不知道就说不知道（可以说「这题超纲了，我去问问站长」），别硬编。'
        . '遇到辱骂、攻击或违规要求，简短怼回去或拒绝即可，不用长篇说教。'
        . lobby_ai_tools_prompt();
}

/** 工具结果 → 卡片结构（世界对话前端按此渲染，与 AI 对话同一套） */
function lobby_ai_cards(array $tools): array
{
    $out = array();
    foreach ($tools as $t) {
        $a = isset($t['action']) ? (string)$t['action'] : '';
        if ($a === '') { continue; }
        $out[] = array('action' => $a, 'ok' => !empty($t['ok']),
                       'summary' => isset($t['summary']) ? (string)$t['summary'] : '');
    }
    return $out;
}

/** 世界对话的工具协议（精简版：公共频道只开放公共信息类工具） */
function lobby_ai_tools_prompt(): string
{
    return "\n【可用工具】需要站内数据或实时信息时，输出一行 JSON —— 工具名与参数写在同一个 JSON 里、"
        . '最多 2 个，形如 <Works check>{"action":"search","query":"关键词"}</Works check>：'
        . 'search 站内检索(query)｜get 作品详情(id)｜rank 排名区间(from,to)｜comments 评论(id)｜'
        . 'stats 站点概览｜categories 分类统计｜announce 最新公告｜calc 精确计算(expr)｜random 随机推荐(category,n)｜'
        . 'lunar 农历与节日(date 或 lunar)｜weather 天气(city)｜time 当前时间｜web_open 读网页(url)｜docs 查站内文档(query 或 name)。'
        . "\n拿到结果后直接用中文回一句话（不超过 150 字），不要再输出任何标签，也不必向用户说明你用了什么工具；"
        . '先查后答，绝不凭印象编数据。';
}



/**
 * 组装消息数组：system 放最前（权重最高），其后是最近 8 条历史（AI 的当 assistant、
 * 其他人的当 user），最后才是这次的提问 —— 真·多轮，比把历史压成一段文本更容易
 * 让模型「看到自己说过什么」，从而不重复。
 */
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

    $hist = array();
    foreach (array_reverse((array)$rows) as $r) {
        if ((int)$r['is_recalled'] === 1 || (string)$r['msg_type'] === 'image') { continue; }
        $t = trim((string)$r['content']);
        if ($t === '') { continue; }
        if (mb_strlen($t, 'UTF-8') > 120) { $t = mb_substr($t, 0, 120, 'UTF-8') . '…'; }
        $hist[] = array('ai' => ((string)$r['msg_type'] === 'ai'), 'name' => (string)$r['username'], 'text' => $t);
    }

    /* 当前这条提问此刻已经落库，别把它当成历史再喂一遍 */
    $askT = trim($ask);
    if ($hist) {
        $last = $hist[count($hist) - 1];
        $askCut = mb_strlen($askT, 'UTF-8') > 120 ? mb_substr($askT, 0, 120, 'UTF-8') . '…' : $askT;
        if (!$last['ai'] && ($last['text'] === $askT || $last['text'] === $askCut)) { array_pop($hist); }
    }

    $msgs = array(array('role' => 'system', 'content' => lobby_ai_system()));
    foreach ($hist as $h) {
        $msgs[] = $h['ai']
            ? array('role' => 'assistant', 'content' => $h['text'])
            : array('role' => 'user',      'content' => $h['name'] . '：' . $h['text']);
    }
    $msgs[] = array('role' => 'user', 'content' => $asker . '：' . $askT);
    return $msgs;
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
        $r = works_tool_run(lobby_ai_messages($ask, $asker), 'glm-4-flash', array(
            'max_rounds' => (int)cfg('ai_limits.lobby_tool_rounds', 1),
            'max_tools'  => (int)cfg('ai_limits.lobby_tool_max', 2),
            'allow'      => works_tool_public_names(),
        ));
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
    if (col_ok('messages', 'meta'))        { $cols[] = 'meta';        $vals[] = chat_meta_pack((array)$r['tools'], array()); }
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
        'tools'    => works_tool_labels(isset($r['tools']) ? (array)$r['tools'] : array()),
        'cards'    => lobby_ai_cards(isset($r['tools']) ? (array)$r['tools'] : array()),
        'msg_type' => 'ai',
        'media'    => '',
        'recalled' => false,
        'flag'     => '',
        'mine'     => false,
        'time'     => to_local(now_utc(), 'm-d H:i'),
    ));
}
