<?php
/**
 * 智谱 AI 客户端
 * ------------------------------------------------------------
 * - Key 池 round-robin 轮询，失败自动切换 + 短冷却
 * - 流式输出（SSE 逐段回调）
 * - 用量记录与限额（每日/每月）
 * - System Prompt 注入站内全部规则与榜单数据
 */
declare(strict_types=1);

function zhipu_config(): array
{
    static $c = null;
    if ($c === null) { $c = require APP_ROOT . '/config/api_keys.php'; }
    return $c;
}

/* ============================================================
 * Key 轮询
 * ============================================================ */
function zhipu_key_state_file(): string
{
    return APP_ROOT . '/storage/cache/zhipu_keys.json';
}

function zhipu_key_state(): array
{
    $f = zhipu_key_state_file();
    if (!file_exists($f)) { return array('cursor' => 0, 'cooldown' => array()); }
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : array('cursor' => 0, 'cooldown' => array());
}

function zhipu_key_state_save(array $s)
{
    @file_put_contents(zhipu_key_state_file(), json_encode($s), LOCK_EX);
}

/** 取下一个可用 key（round-robin，跳过冷却中的） */
function zhipu_pick_key(): string
{
    $cfg = zhipu_config();
    $keys = isset($cfg['keys']) && is_array($cfg['keys']) ? array_values($cfg['keys']) : array();
    if (empty($keys)) { throw new RuntimeException('未配置智谱 API Key'); }

    $st = zhipu_key_state();
    $cooldown = isset($st['cooldown']) && is_array($st['cooldown']) ? $st['cooldown'] : array();
    $cool = isset($cfg['cooldown']) ? (int)$cfg['cooldown'] : 60;
    $now = time();
    $n = count($keys);
    $cursor = isset($st['cursor']) ? (int)$st['cursor'] : 0;

    for ($i = 0; $i < $n; $i++) {
        $idx = ($cursor + $i) % $n;
        $k = (string)$keys[$idx];
        $h = substr(hash('sha256', $k), 0, 12);
        $until = isset($cooldown[$h]) ? (int)$cooldown[$h] : 0;
        if ($until <= $now) {
            $st['cursor'] = ($idx + 1) % $n;
            zhipu_key_state_save($st);
            return $k;
        }
    }
    // 全部冷却：取游标位 key 兜底
    $st['cursor'] = ($cursor + 1) % $n;
    zhipu_key_state_save($st);
    return (string)$keys[$cursor % $n];
}

/** 标记某 key 失败进入冷却 */
function zhipu_key_fail(string $key)
{
    $cfg = zhipu_config();
    $st = zhipu_key_state();
    if (!isset($st['cooldown']) || !is_array($st['cooldown'])) { $st['cooldown'] = array(); }
    $h = substr(hash('sha256', $key), 0, 12);
    $st['cooldown'][$h] = time() + (isset($cfg['cooldown']) ? (int)$cfg['cooldown'] : 60);
    zhipu_key_state_save($st);
}

/* ============================================================
 * 请求
 * ============================================================ */
function zhipu_payload(array $messages, string $model = ''): array
{
    $cfg = zhipu_config();
    $payload = array(
        'model'    => $model !== '' ? $model : (string)$cfg['model'],
        'messages' => $messages,
    );
    /* 说明：glm-4-flash 系列的 web_search 工具实测不生效，
       联网检索改由 <Works check> 协议中的 web_open 工具完成。 */
    return $payload;
}

/**
 * 非流式调用（多 key 轮询，失败自动切换）。
 * $model 非空时覆盖配置模型（如搜索总结固定用 glm-4-flash 保速度）。
 */
function zhipu_chat(array $messages, string $model = ''): array
{
    $cfg = zhipu_config();
    $keys = isset($cfg['keys']) ? array_values($cfg['keys']) : array();
    $tries = max(1, count($keys));
    $lastErr = 'AI 服务不可用';

    for ($i = 0; $i < $tries; $i++) {
        $key = zhipu_pick_key();
        $payload = zhipu_payload($messages, $model);
        $ch = net_curl_init((string)$cfg['endpoint']);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $key, 'Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 6,
        ));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { $lastErr = '网络错误：' . $err; zhipu_key_fail($key); continue; }
        if ($code === 200) {
            $j = json_decode((string)$resp, true);
            if (is_array($j) && isset($j['choices'][0]['message']['content'])) {
                return array(
                    'text' => (string)$j['choices'][0]['message']['content'],
                    'usage' => isset($j['usage']) ? $j['usage'] : array(),
                );
            }
            $lastErr = 'AI 返回格式异常';
            zhipu_key_fail($key);
            continue;
        }
        $lastErr = 'AI 接口 HTTP ' . $code;
        zhipu_key_fail($key);
    }
    throw new RuntimeException($lastErr);
}

/**
 * 流式调用。$onDelta(string $text, array $usage) 每段回调；返回累计文本。
 */
function zhipu_chat_stream(array $messages, callable $onDelta): string
{
    $cfg = zhipu_config();
    $keys = isset($cfg['keys']) ? array_values($cfg['keys']) : array();
    $tries = max(1, count($keys));
    $lastErr = 'AI 服务不可用';

    for ($i = 0; $i < $tries; $i++) {
        $key = zhipu_pick_key();
        $payload = zhipu_payload($messages);
        $payload['stream'] = true;
        $payload['stream_options'] = array('include_usage' => true);
        $usage = array();
        $buffer = '';
        $full = '';

        $ch = net_curl_init((string)$cfg['endpoint']);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: text/event-stream'),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$full, &$usage, $onDelta) {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if (strpos($line, 'data:') !== 0) { continue; }
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]' || $data === '') { continue; }
                    $j = json_decode($data, true);
                    if (!is_array($j)) { continue; }
                    if (!empty($j['usage'])) { $usage = $j['usage']; }
                    if (isset($j['choices'][0]['delta']['content'])) {
                        $piece = (string)$j['choices'][0]['delta']['content'];
                        if ($piece !== '') { $full .= $piece; $onDelta($piece, array()); }
                    }
                }
                return strlen($chunk);
            },
        ));
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $full !== '') {
            $onDelta('', $usage);   // 收尾回调携带 usage
            return $full;
        }
        $lastErr = ($httpCode === 200) ? 'AI 未返回内容' : ('AI 接口 HTTP ' . $httpCode . ($cerr !== '' ? ' / ' . $cerr : ''));
        zhipu_key_fail($key);
    }
    throw new RuntimeException($lastErr);
}

/* ============================================================
 * 用量与限额
 * ============================================================ */
/**
 * AI 用量记账（按通道分账）。
 * $provider：'glm' = 本站模型（智谱）；'gateway' = 模型网关（免费模型）；'' = 早期未区分。
 * 记账属于旁路：列没就位或写库失败都只记日志，绝不牵连已经生成好的回答。
 */
function ai_usage_record(int $uid, array $usage, string $provider)
{
    $p = isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : 0;
    $c = isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0;
    $t = isset($usage['total_tokens']) ? (int)$usage['total_tokens'] : ($p + $c);
    if ($t <= 0) { return; }
    if (!in_array($provider, array('glm', 'gateway'), true)) { $provider = ''; }

    try {
        if (col_ok('ai_usage', 'provider')) {
            db_exec('INSERT INTO ai_usage (user_id, provider, prompt_tokens, completion_tokens, total_tokens, created_at)
                     VALUES (?,?,?,?,?,UTC_TIMESTAMP())', array($uid, $provider, $p, $c, $t));
        } else {
            /* 新列尚未就位（迁移没跑成）：先照旧记账，不让分账拖垮主流程 */
            db_exec('INSERT INTO ai_usage (user_id, prompt_tokens, completion_tokens, total_tokens, created_at)
                     VALUES (?,?,?,?,UTC_TIMESTAMP())', array($uid, $p, $c, $t));
        }
    } catch (Throwable $e) {
        app_log('ai_usage_record failed: ' . $e->getMessage());
        return;
    }
    stats_bump('ai_calls');
    stats_bump('ai_tokens', $t);
}

/** 兼容旧名：智谱通道记账 */
function zhipu_record_usage(int $uid, array $usage) { ai_usage_record($uid, $usage, 'glm'); }

function ai_usage_today(int $uid): int
{
    // 按站点本地日界线统计（库内 UTC），日/月额度均随自然周期自动切换
    return (int)db_val('SELECT COALESCE(SUM(total_tokens),0) FROM ai_usage WHERE user_id = ? AND created_at >= ?',
        array($uid, local_period_start('day')));
}

function ai_usage_month(int $uid): int
{
    return (int)db_val('SELECT COALESCE(SUM(total_tokens),0) FROM ai_usage WHERE user_id = ? AND created_at >= ?',
        array($uid, local_period_start('month')));
}

/** 返回 array(ok, reason, today, month, limits) */
function ai_quota_check(int $uid): array
{
    $limits = ai_quota_limits($uid);   // 基础额度 + 签到加成（app/checkin.php）
    $day = ai_usage_today($uid);
    $mon = ai_usage_month($uid);
    $ok = true; $reason = '';
    if ($day >= (int)$limits['daily_tokens']) { $ok = false; $reason = '今日 token 额度已用完'; }
    elseif ($mon >= (int)$limits['monthly_tokens']) { $ok = false; $reason = '本月 token 额度已用完'; }
    return array('ok' => $ok, 'reason' => $reason, 'today' => $day, 'month' => $mon, 'limits' => $limits,
        'streak' => checkin_streak($uid));
}

/* ============================================================
 * System Prompt：站内规则 + 工具调用协议（不再注入榜单数据）
 * ============================================================ */
function build_system_prompt(): string
{
    $site = (string)cfg('site.name', 'Kimi游戏榜');
    $p  = "你是「" . $site . "」的官方 AI 助手，服务本站用户。请用简洁、友好的中文回答。\n\n";

    $p .= "【站内规则】\n";
    $p .= "本站为 Kimi 社区作品榜单，采用六维综合评分：创意/体验/深度/成本/态度/热度，单维 0-200，总分 0-1200。\n";
    $p .= "入榜规则：收录 Kimi 社区公开作品，分游戏榜/工具榜/文学榜/二创榜；由管理员按作品 ID 收录，重复收录会更新并重算六维。\n";
    $p .= "游客可浏览榜单与世界对话；登录后可评论、点赞、使用 AI 对话。\n\n";

    $p .= "【工具调用协议】（唯一的数据获取方式，必须严格遵守）\n";
    $p .= "需要站内数据或联网内容时，在回复中输出如下格式的标签（可一次多个，最多 3 个）：\n";
    $p .= '<Works check>{"action":"search","query":"关键词"}</Works check>' . "\n";
    $p .= "可用工具：\n";
    $p .= '1. search 站内检索：{"action":"search","query":"标题/作者/关键词","limit":6}' . "\n";
    $p .= '2. get 单作品完整信息（六维、总分、作者、社区ID、链接）：{"action":"get","id":"作品ID或社区ID"}' . "\n";
    $p .= '3. rank 总榜排名区间：{"action":"rank","from":1,"to":10}' . "\n";
    $p .= '4. comments 某作品评论区：{"action":"comments","id":"作品ID","limit":15}' . "\n";
    $p .= '5. web_open 读取任意网页正文：{"action":"web_open","url":"https://..."}' . "\n";
    $p .= '6. weather 查询任意城市实时天气：{"action":"weather","city":"北京"}' . "\n";
    $p .= '7. time 获取当前日期与时间：{"action":"time"}，指定时区：{"action":"time","timezone":"Asia/Tokyo"}' . "\n";
    $p .= "【硬性要求】\n";
    $p .= "- 用户问天气（任何城市）：必须调用 weather 工具，禁止回答“我无法获取天气”，也不要用 web_open 去抓天气网页。\n";
    $p .= "- 用户问现在几点、今天几号、今天星期几、当前时间：必须调用 time 工具，禁止回答“我无法提供实时时间”。\n";
    $p .= "- 用户问站内榜单/作品：调用 search / get / rank / comments。\n";
    $p .= "【格式铁律】（违反会导致调用失败、参数丢失，用户会看到一串乱码 JSON）\n";
    $p .= "1. 工具名 action 与它需要的参数必须写在同一个 JSON 对象里，一次写完。\n";
    $p .= "2. 严禁先单独写一行工具名、再另起一行写参数。这样参数会丢，调用必定失败。\n";
    $p .= "3. 严禁把工具名写在 JSON 外面，例如写成 weather 换行 {\"city\":\"北京\"}。\n";
    $p .= "4. 参数必须齐全：weather 必须带 city，search 必须带 query，get/comments 必须带 id，web_open 必须带 url。\n";
    $p .= "以下写法全部是错误的（系统无法识别，你将拿不到任何数据）：\n";
    $p .= '<web_open url="https://x"></web_open>' . "\n";
    $p .= '<search query="x"></search>' . "\n";
    $p .= '[web_open: https://x]' . "\n";
    $p .= "weather" . "\n" . '{"city":"北京"}' . "\n";
    $p .= '{"city":"北京"}' . "\n";
    $p .= '{"action":"weather"}' . "（缺 city，同样会失败）\n";
    $p .= "正确写法示例：<Works check>{\"action\":\"web_open\",\"url\":\"https://www.weather.com.cn/weather/101010100/\"}</Works check>\n";
    $p .= "正确写法示例：<Works check>{\"action\":\"weather\",\"city\":\"北京\"}</Works check>\n";
    $p .= "【多工具】同一条回复里可以一次调用多个工具（最多 3 个），系统会把它们全部执行，结果一起回传给你。\n";
    $p .= "【失败重试】工具执行失败时，<tool_result> 里会写明 error 与修正建议。请据此修正参数后重新调用同一个工具，最多重试两次；\n";
    $p .= "两次仍失败就如实向用户说明失败原因，绝不能编造或假装拿到了数据。\n";
    $p .= "系统执行后会把结果放在 <tool_result> 中回传给你；收到结果后必须给出最终中文回答，且不要再输出任何标签。\n";
    $p .= "不需要数据时直接回答，不要输出标签；不要编造或假装工具结果。\n";

    $ann = setting_get('announcement', '');
    if ($ann !== '') { $p .= "\n【最新公告】" . $ann . "\n"; }
    return $p;
}

/** 组装对话消息（含历史） */
function zhipu_messages(array $history, string $userText): array
{
    $msgs = array(array('role' => 'system', 'content' => build_system_prompt()));
    foreach ($history as $h) {
        if (!isset($h['role'], $h['content'])) { continue; }
        $msgs[] = array('role' => (string)$h['role'], 'content' => (string)$h['content']);
    }
    $msgs[] = array('role' => 'user', 'content' => $userText);
    return $msgs;
}
