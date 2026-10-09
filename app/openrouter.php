<?php
/**
 * OpenRouter 客户端
 * ------------------------------------------------------------
 * - 模型列表：公开接口，过滤「纯文本 + 完全免费」，缓存入 or_models，每自然日首访自动刷新
 * - 额度：全站每日以 /key 的 free_model_daily_requests 为准，本地计数兜底
 * - 限额：每人每日 N 次（ai_daily_quota 原子自增）
 * - 对话：/chat/completions，非流式与流式两种
 *
 * 说明：列表接口无需鉴权；对话与额度查询需要 key（config/api_keys.php）。
 */
declare(strict_types=1);

function or_config(): array
{
    static $c = null;
    if ($c === null) {
        $all = require APP_ROOT . '/config/api_keys.php';
        $c = isset($all['openrouter']) && is_array($all['openrouter']) ? $all['openrouter'] : array();
    }
    return $c;
}

function or_base(): string { return rtrim((string)(or_config()['base'] ?? 'https://openrouter.ai/api/v1'), '/'); }
function or_key(): string  { return (string)(or_config()['key'] ?? ''); }

function or_headers(bool $auth = true): array
{
    $cfg = or_config();
    $h = array('Content-Type: application/json', 'Accept: application/json');
    if ($auth && or_key() !== '') { $h[] = 'Authorization: Bearer ' . or_key(); }
    if (!empty($cfg['title'])) { $h[] = 'X-Title: ' . preg_replace('/[^\x20-\x7e]/', '', (string)$cfg['title']); }
    $siteUrl = (string)cfg('site.url', '');
    if ($siteUrl !== '') { $h[] = 'HTTP-Referer: ' . $siteUrl; }
    return $h;
}

/** 统一 HTTP：返回 array(ok, code, body, err) */
function or_http(string $method, string $path, $payload = null, int $timeout = 30, bool $auth = true): array
{
    $url = or_base() . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => or_headers($auth),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
    );
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array(
        'ok'   => ($body !== false && $code >= 200 && $code < 300),
        'code' => $code,
        'body' => (string)$body,
        'err'  => (string)$err,
    );
}

/* ============================================================
 * 模型列表：拉取 / 过滤 / 缓存
 * ============================================================ */

/** 判定是否为「纯文本模型」 */
function or_is_text(array $m): bool
{
    $out = $m['architecture']['output_modalities'] ?? null;
    if (is_array($out)) { return count($out) === 1 && (string)$out[0] === 'text'; }
    $mod = (string)($m['architecture']['modality'] ?? '');
    return $mod === '' ? false : (strpos($mod, '->text') !== false);
}

/** 判定是否为「完全免费模型」 */
function or_is_free(array $m): bool
{
    $p = $m['pricing'] ?? array();
    if (!is_array($p)) { return false; }
    return (string)($p['prompt'] ?? '') === '0' && (string)($p['completion'] ?? '') === '0';
}

/**
 * 拉取并缓存免费文本模型。$force=false 时若今日已刷新则跳过。
 * 返回 array(ok, count, msg)
 */
function or_models_refresh(bool $force = false): array
{
    $today = gmdate('Y-m-d');
    if (!$force) {
        $st = or_state();
        if ((string)($st['refresh_date'] ?? '') === $today) {
            return array('ok' => true, 'count' => or_models_count(), 'msg' => '今日已刷新');
        }
    }

    $r = or_http('GET', 'models', null, 12, false);
    if (!$r['ok']) {
        return array('ok' => false, 'count' => or_models_count(), 'msg' => '列表接口不可用（HTTP ' . $r['code'] . '）');
    }
    $j = json_decode($r['body'], true);
    $list = (is_array($j) && isset($j['data']) && is_array($j['data'])) ? $j['data'] : array();
    if (empty($list)) {
        return array('ok' => false, 'count' => or_models_count(), 'msg' => '列表为空');
    }

    $now = gmdate('Y-m-d H:i:s');
    $kept = 0;
    foreach ($list as $m) {
        if (!is_array($m) || empty($m['id'])) { continue; }
        if (!or_is_text($m) || !or_is_free($m)) { continue; }
        $params = isset($m['supported_parameters']) && is_array($m['supported_parameters'])
            ? json_encode($m['supported_parameters'], JSON_UNESCAPED_UNICODE) : '';
        db_exec(
            'INSERT INTO `or_models` (`model_id`, `name`, `context_length`, `params`, `is_active`, `fetched_at`)
             VALUES (?,?,?,?,1,?)
             ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `context_length`=VALUES(`context_length`),
                `params`=VALUES(`params`), `is_active`=1, `fetched_at`=VALUES(`fetched_at`)',
            array(
                (string)$m['id'],
                (string)($m['name'] ?? $m['id']),
                (int)($m['context_length'] ?? 0),
                $params,
                $now,
            )
        );
        $kept++;
    }

    /* 本轮未出现者判为下架 */
    db_exec('UPDATE `or_models` SET `is_active` = 0 WHERE `fetched_at` < ?', array($now));
    or_state_set(array('refresh_date' => $today, 'updated_at' => $now));
    app_log('openrouter models refreshed: kept=' . $kept);
    return array('ok' => true, 'count' => $kept, 'msg' => '已刷新 ' . $kept . ' 个免费文本模型');
}

function or_models_count(): int
{
    try { return (int)db_val('SELECT COUNT(*) FROM `or_models` WHERE `is_active` = 1'); }
    catch (Throwable $e) { return 0; }
}

/** 当前可用免费文本模型（表不存在时返回空数组，绝不抛错） */
function or_models(): array
{
    try {
        return db_all('SELECT `model_id`, `name`, `context_length` FROM `or_models`
                       WHERE `is_active` = 1 ORDER BY `name` ASC');
    } catch (Throwable $e) { return array(); }
}

/** 每自然日首访触发一次（幂等；失败静默，不阻断页面） */
function or_ensure_daily() {
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        if (!table_exists('or_models')) { return; }
        or_models_refresh(false);
    } catch (Throwable $e) { /* 静默 */ }
}

/* ============================================================
 * 状态行（or_state 单行）
 * ============================================================ */
function or_state(): array
{
    try {
        $row = db_one('SELECT * FROM `or_state` WHERE `id` = 1');
        if ($row !== null) { return $row; }
    } catch (Throwable $e) { return array(); }
    return array();
}

function or_state_set(array $fields) {
    if (empty($fields)) { return; }
    $set = array(); $vals = array();
    foreach ($fields as $k => $v) { $set[] = '`' . $k . '` = ?'; $vals[] = $v; }
    try {
        db_exec('INSERT INTO `or_state` (`id`) VALUES (1) ON DUPLICATE KEY UPDATE `id` = `id`');
        $vals[] = 1;
        db_exec('UPDATE `or_state` SET ' . implode(', ', $set) . ' WHERE `id` = ?', $vals);
    } catch (Throwable $e) { /* 静默 */ }
}

/* ============================================================
 * 全站配额：上游为准 + 本地兜底
 * ============================================================ */

/** 查询上游额度并写入状态（缓存 60 秒） */
function or_api_state(bool $force = false) {
    $st = or_state();
    $checked = (string)($st['api_checked_at'] ?? '');
    if (!$force && $checked !== '' && (time() - strtotime($checked . ' UTC')) < 60) { return; }
    if (or_key() === '') { return; }

    $r = or_http('GET', 'key', null, 15, true);
    if (!$r['ok']) { return; }
    $j = json_decode($r['body'], true);
    $d = (is_array($j) && isset($j['data']) && is_array($j['data'])) ? $j['data'] : (is_array($j) ? $j : array());
    $fr = isset($d['free_model_daily_requests']) && is_array($d['free_model_daily_requests'])
        ? $d['free_model_daily_requests'] : array();
    $used  = isset($fr['used'])  ? (int)$fr['used']  : (int)($st['api_used'] ?? 0);
    $limit = isset($fr['limit']) ? (int)$fr['limit'] : (int)($st['api_limit'] ?? 0);
    or_state_set(array(
        'api_used'       => $used,
        'api_limit'      => $limit,
        'api_checked_at' => gmdate('Y-m-d H:i:s'),
    ));
}

/** 全站当日配额快照 */
function or_site_quota(): array
{
    $st = or_state();
    $day = gmdate('Y-m-d');
    $local = ((string)($st['site_day'] ?? '') === $day) ? (int)($st['site_used'] ?? 0) : 0;

    $cap = (int)($st['api_limit'] ?? 0);
    if ($cap <= 0) { $cap = (int)(or_config()['site_quota'] ?? 50); }

    $apiUsed = (int)($st['api_used'] ?? 0);
    $used = max($local, $apiUsed);

    return array(
        'day'       => $day,
        'limit'     => $cap,
        'used'      => $used,
        'remaining' => max(0, $cap - $used),
    );
}

/** 全站计数 +1（跨天自动归零）；返回是否成功占用 */
function or_site_consume(): bool
{
    $q = or_site_quota();
    if ($q['remaining'] <= 0) { return false; }
    $day = gmdate('Y-m-d');
    $st = or_state();
    $local = ((string)($st['site_day'] ?? '') === $day) ? (int)($st['site_used'] ?? 0) : 0;
    or_state_set(array('site_day' => $day, 'site_used' => $local + 1, 'updated_at' => gmdate('Y-m-d H:i:s')));
    return true;
}

/* ============================================================
 * 每人每日配额（ai_daily_quota）
 * ============================================================ */
function ai_daily_limit(): int
{
    return max(1, (int)(or_config()['per_user_daily'] ?? 2));
}

function ai_daily_used(int $uid): int
{
    try {
        return (int)db_val('SELECT `used` FROM `ai_daily_quota` WHERE `user_id` = ? AND `day` = ?',
            array($uid, gmdate('Y-m-d')));
    } catch (Throwable $e) { return 0; }
}

/** 占用一次；成功返回 true，超额返回 false（原子写入） */
function ai_daily_consume(int $uid): bool
{
    $limit = ai_daily_limit();
    try {
        db_exec('INSERT INTO `ai_daily_quota` (`user_id`, `day`, `used`) VALUES (?,?,1)
                 ON DUPLICATE KEY UPDATE `used` = `used` + 1', array($uid, gmdate('Y-m-d')));
        $used = (int)db_val('SELECT `used` FROM `ai_daily_quota` WHERE `user_id` = ? AND `day` = ?',
            array($uid, gmdate('Y-m-d')));
        if ($used > $limit) {
            /* 超额则回退，保证计数与限额一致 */
            db_exec('UPDATE `ai_daily_quota` SET `used` = ? WHERE `user_id` = ? AND `day` = ?',
                array($limit, $uid, gmdate('Y-m-d')));
            return false;
        }
        return true;
    } catch (Throwable $e) { return true; }
}

/**
 * 核实 fallback=1 的「自证」。
 * ------------------------------------------------------------
 * 客户端声称「上一轮流式已经计入额度，这次只是降级重放」。
 * 这句话不能盲信：流式那次若在闸门处就被拒（额度用尽 429），压根没有计入，
 * 若此时仍放行，超额用户就会靠这条兜底通道继续发送，免费额度形同虚设。
 * 只认服务端自己能证实的：最近 5 分钟内确实为这条一模一样的消息占过额度
 *（占用成功后紧接着就会写入该条用户消息，所以查得到即证明已计入）。
 */
function ai_replay_confirmed(int $uid, string $text): bool
{
    if ($uid <= 0 || $text === '') { return false; }
    try {
        $at = db_val('SELECT created_at FROM ai_messages
                      WHERE user_id = ? AND role = "user" AND content = ?
                      ORDER BY id DESC LIMIT 1', array($uid, $text));
    } catch (Throwable $e) { return false; }
    if ($at === null) { return false; }
    $ts = strtotime((string)$at . ' UTC');
    return $ts !== false && (time() - $ts) <= 300;
}

/* ============================================================
 * 对话
 * ============================================================ */

function or_payload(array $messages, string $model): array
{
    $cfg = or_config();
    $p = array('model' => $model, 'messages' => $messages);
    $re = (string)($cfg['reasoning'] ?? '');
    if ($re !== '') { $p['reasoning'] = array('effort' => $re); }
    return $p;
}

/** 非流式对话；成功返回 array(text, usage, model)，失败抛 RuntimeException */
function or_chat(array $messages, string $model): array
{
    $timeout = (int)(or_config()['timeout'] ?? 90);
    $r = or_http('POST', 'chat/completions', or_payload($messages, $model), $timeout, true);
    if (!$r['ok']) {
        $msg = 'OpenRouter HTTP ' . $r['code'];
        $j = json_decode($r['body'], true);
        if (is_array($j) && isset($j['error']['message'])) { $msg .= '：' . $j['error']['message']; }
        throw new RuntimeException($msg);
    }
    $j = json_decode($r['body'], true);
    $text = (string)($j['choices'][0]['message']['content'] ?? '');
    return array('text' => $text, 'usage' => (array)($j['usage'] ?? array()), 'model' => (string)($j['model'] ?? $model));
}

/** 流式对话；每段回调 $onDelta(string $piece, array $usage)，返回累计文本 */
function or_chat_stream(array $messages, string $model, callable $onDelta, $onReason = null): string
{
    $timeout = (int)(or_config()['timeout'] ?? 90);
    $payload = or_payload($messages, $model);
    $payload['stream'] = true;
    $payload['stream_options'] = array('include_usage' => true);

    $ch = curl_init(or_base() . '/chat/completions');
    $usage = array(); $full = ''; $buffer = '';
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(or_headers(true), array('Accept: text/event-stream')),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$full, &$usage, $onDelta, $onReason) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (strpos($line, 'data:') !== 0) { continue; }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') { continue; }
                $j = json_decode($data, true);
                if (!is_array($j)) { continue; }
                if (!empty($j['usage'])) { $usage = $j['usage']; }
                $d = isset($j['choices'][0]['delta']) ? $j['choices'][0]['delta'] : array();
                /* 推理模型的思考增量：OpenRouter 用 reasoning，部分上游用 reasoning_content */
                if ($onReason !== null) {
                    $rt = '';
                    if (isset($d['reasoning'])) { $rt = (string)$d['reasoning']; }
                    elseif (isset($d['reasoning_content'])) { $rt = (string)$d['reasoning_content']; }
                    if ($rt !== '') { $onReason($rt); }
                }
                if (isset($j['choices'][0]['delta']['content'])) {
                    $piece = (string)$j['choices'][0]['delta']['content'];
                    if ($piece !== '') { $full .= $piece; $onDelta($piece, array()); }
                }
            }
            return strlen($chunk);
        },
    ));
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 && $full === '') {
        throw new RuntimeException('OpenRouter HTTP ' . $code . ($err !== '' ? ' / ' . $err : ''));
    }
    if ($full !== '') { $onDelta('', $usage); }
    return $full;
}

/* ============================================================
 * 调度：选模型 + 失败回退智谱（多 provider 保活）
 * ============================================================ */
/**
 * 解析本次要用的模型。
 * 返回 ''  → 使用站点默认模型（GLM，走 token 额度）。这是默认行为。
 * 返回 id  → 用户显式选择了某个免费模型，且它在当前可用列表中（走次数额度）。
 * 指定模型不在列表中时一律回退默认，避免用户选到已下架的模型而调用失败。
 */
function ai_pick_model(string $want = ''): string
{
    if ($want === '') { return ''; }
    foreach (or_models() as $m) {
        if ((string)$m['model_id'] === $want) { return $want; }
    }
    return '';
}

/**
 * 非流式：OpenRouter 优先，失败回退智谱。
 * $provider 回传**实际生效**的通道（gateway=模型网关 / glm=本站模型），
 * 供用量分账使用——「选了免费模型但实际回退了」也算 glm，账要记在真身上。
 */
function ai_respond(array $messages, string $model, string &$provider = '', bool $think = false, string $glmModel = ''): array
{
    if ($model !== '') {
        try {
            $r = or_chat($messages, $model);
            $provider = 'gateway';
            return $r;
        } catch (Throwable $e) { app_log('openrouter chat failed: ' . $e->getMessage()); }
    }
    $provider = 'glm';
    return zhipu_chat($messages, $glmModel, $think);
}

/** 流式：OpenRouter 优先；已产出内容后失败则不回退（避免正文重复）。$provider 语义同上 */
function ai_respond_stream(array $messages, string $model, callable $onDelta, string &$provider = '', bool $think = false, $onReason = null, string $glmModel = ''): string
{
    if ($model !== '') {
        $got = false;
        try {
            $out = or_chat_stream($messages, $model, function ($d, $u) use ($onDelta, &$got) {
                if ($d !== '') { $got = true; }
                $onDelta($d, $u);
            }, $onReason);
            $provider = 'gateway';
            return $out;
        } catch (Throwable $e) {
            app_log('openrouter stream failed: ' . $e->getMessage());
            if ($got) { throw $e; }
        }
    }
    $provider = 'glm';
    return zhipu_chat_stream($messages, $onDelta, $think, $onReason, $glmModel);
}

/* ============================================================
 * 问答缓存已移除（v3.16.5）
 * ------------------------------------------------------------
 * 早先的规则是「会话无上下文 + 同一个提问 → 直接沿用存过的答案」。
 * 这会在用户清空对话后重问时把旧答案一字不差地回放出来，看起来就是 AI 复读，
 * 与「像真人一样聊天」的目标冲突。现在一律真实调用模型，不再回放缓存。
 * 表 ai_answer_cache 保留在库里，但已不再读写。
 * ============================================================ */
