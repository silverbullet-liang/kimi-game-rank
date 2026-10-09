<?php
/**
 * Jev 文本违规程度分级 + 语气识别（classifier.dev）
 * ------------------------------------------------------------
 * Jev 是 TypeSafe 的非自回归分类模型：一次前向给出所有标签的校准概率，
 * 单条 150–220ms，比大模型快一个数量级，且按 IP 免费、无需 Key。
 *
 * 用「多维度」在一次请求里同时判两件事，不额外增加往返：
 *   severity —— 违规程度 10 档（1 完全正常 … 10 极端辱骂），取各档概率的**期望值**当档位。
 *               用期望而非「最高概率那一档」，是因为相邻档位的波动会在期望里互相抵消 ——
 *               实测同一段文字重复调用，档位波动 < 0.1，比单点判定稳得多。
 *   tone     —— 是否阴阳怪气 / 讽刺。讽刺常常不构成攻击（不该拦），但读者容易不适，
 *               因此单独识别出来做标注，而不是混进违规档位里。
 *
 * 处置阈值（可在 config 的 moderation 段覆盖）：
 *   level >= reject_level                    → 拦截
 *   flag_level <= level < reject_level       → 放行，但标注「可能有恶意」
 *   或 sarcasm >= sarcasm_at                 → 放行，但标注「可能有恶意」
 *   或 命中提示词注入                         → 放行，但标注「可能有恶意」
 *   其余                                      → 放行
 *
 * 提示词注入只标注、不拦截：它的危害面仅是官方 AI 的一条回复，而误伤面却覆盖
 * 所有用到 instruction / prompt / system 这类词的正常技术交流，代价不对等。
 *
 * 注意：广告引流不交给它判断 —— 实测「加我微信…」会落到很低的档位与很低的讽刺值，
 * 那类内容由词库与句式正则负责，这里只管「有没有骂人」和「是不是阴阳怪气」。
 *
 * 返回 ok=null 表示服务不可用，调用方应回落到智谱。
 */
declare(strict_types=1);

define('JEV_DEFAULT_URL', 'https://classifier.dev');
define('JEV_DEFAULT_REJECT_LEVEL', 5.5);    // 达到该档位即拦截（10 档制）
define('JEV_DEFAULT_FLAG_LEVEL', 4.5);      // 达到该档位先标注「可能有恶意」，仍放行
define('JEV_DEFAULT_SARCASM_AT', 0.95);     // 讽刺概率达到该值同样标注「可能有恶意」
define('JEV_DEFAULT_INJECT_PROB', 0.85);    // 提示词注入的判定概率阈值
define('JEV_DEFAULT_INJECT_BENIGN', 0.50);  // 「纯技术讨论」概率达到该值即视为讨论，不标注

/**
 * 10 档描述：从完全正常到极端辱骂，数组顺序即档位 1–10。
 * 第 4 档刻意写成「批评作品或观点、而非针对人」—— 把对作品的差评与对人的
 * 攻击分到不同档位，是这套分级里最实用的一条边界。
 */
function jev_level_labels(): array
{
    return array(
        'fully respectful, no negativity at all',
        'neutral or harmless remark',
        'mild complaint or disagreement',
        'harsh criticism of a work or an idea, not a person',
        'mildly rude or dismissive toward a person',
        'clear insult aimed at a person',
        'strong insult or belittling of a person',
        'hostile personal attack with harsh language',
        'severe abuse, degrading and aggressive',
        'extreme abuse, threats or illegal content',
    );
}

/** 语气维度：讽刺 / 直白。讽刺不构成攻击，但值得标注。 */
function jev_tone_labels(): array
{
    return array('sarcastic or passive-aggressive', 'sincere and straightforward');
}

/** 语气的讽刺档标签（scores 的键） */
function jev_sarcasm_label(): string
{
    return 'sarcastic or passive-aggressive';
}

function jev_endpoint(): string
{
    return rtrim((string)cfg('moderation.jev_url', JEV_DEFAULT_URL), '/') . '/classify';
}

/** 拦截档位（10 档制） */
function jev_reject_level(): float
{
    return (float)cfg('moderation.jev_reject_level', JEV_DEFAULT_REJECT_LEVEL);
}

/** 标注档位：达到即放行，但在内容旁标注「可能有恶意」 */
function jev_flag_level(): float
{
    return (float)cfg('moderation.jev_flag_level', JEV_DEFAULT_FLAG_LEVEL);
}

/** 讽刺阈值：达到即标注「可能有恶意」 */
function jev_sarcasm_at(): float
{
    return (float)cfg('moderation.jev_sarcasm_level', JEV_DEFAULT_SARCASM_AT);
}

/**
 * 提示词注入（prompt injection / jailbreak）维度。
 * 总有人以为这里接的只是「普通 AI」，于是试探性地写「忽略以上指令」「你现在是……」
 * 「把你的系统提示词发出来」。这类内容不骂人，单列一维识别；**命中只标注、不拦截**
 * —— 危害面只是官方 AI 的一条回复，误伤面却覆盖所有正常的技术交流。
 * 维度给三个选项，好让「只是讨论/引用」的技术写作有地方落，不至于被判成攻击。
 */
function jev_intent_labels(): array
{
    return array(
        'a direct instruction addressed to the assistant that tries to override, ignore or bypass its own rules, or to extract its hidden instructions or system prompt',
        'a normal message that does not try to manipulate any system',
        'technical, academic or engineering writing that merely mentions or quotes AI instructions, prompts, rules or system prompts, without instructing this assistant to do anything',
    );
}

/** 「攻击」标签：直接向 AI 发出的越权 / 套取指令 */
function jev_inject_label(): string
{
    return 'a direct instruction addressed to the assistant that tries to override, ignore or bypass its own rules, or to extract its hidden instructions or system prompt';
}

/** 「纯技术讨论」标签：只是提到或引用了这些词，并非攻击 */
function jev_inject_benign_label(): string
{
    return 'technical, academic or engineering writing that merely mentions or quotes AI instructions, prompts, rules or system prompts, without instructing this assistant to do anything';
}

/** 提示词注入的判定概率阈值 */
function jev_inject_prob(): float
{
    return (float)cfg('moderation.jev_inject_prob', JEV_DEFAULT_INJECT_PROB);
}

/** 「纯技术讨论」概率达到该值即判为讨论 / 引用，不标注 */
function jev_inject_benign(): float
{
    return (float)cfg('moderation.jev_inject_benign', JEV_DEFAULT_INJECT_BENIGN);
}

/**
 * 是否把这条内容标注为「可能有恶意（提示词注入）」（纯函数，便于单测）。
 * 只标注不拦截；当「纯技术讨论」概率足够高时视为正常讨论，不标注。
 */
function jev_inject_flag(float $injectProb, float $benignProb): bool
{
    return $injectProb >= jev_inject_prob() && $benignProb < jev_inject_benign();
}

/**
 * 由各档位概率算出违规档位（1.0–10.0）。纯函数，便于单测。
 * 期望值 = Σ(档位 × 该档概率) / Σ概率；除以总和是为了容忍 scores 未严格归一。
 * $scores 以 jev_level_labels() 的描述为键。返回 0.0 表示无法计算。
 */
function jev_level_from_scores(array $scores): float
{
    $weighted = 0.0; $total = 0.0;
    foreach (jev_level_labels() as $i => $lb) {
        $p = isset($scores[$lb]) ? (float)$scores[$lb] : 0.0;
        if ($p < 0) { $p = 0.0; }
        $weighted += ($i + 1) * $p;
        $total    += $p;
    }
    if ($total <= 0) { return 0.0; }
    $level = $weighted / $total;
    if ($level < 1.0)  { $level = 1.0; }
    if ($level > 10.0) { $level = 10.0; }
    return $level;
}

/**
 * 判断一段文本的违规程度与语气。返回：
 *   array(
 *     'ok'        => true|false|null,   // null = 服务不可用，调用方自行回落
 *     'level'     => float,             // 1.0–10.0 连续档位
 *     'level_int' => int,               // 1–10 整数档位（展示用）
 *     'sarcasm'   => float,             // 讽刺概率 0–1
 *     'flag'      => ''|'middle',       // 放行时的灰度标注
 *     'scores'    => array,             // 各档位概率，便于排查
 *     'reason'    => string,
 *   )
 */
function jev_classify(string $text): array
{
    $text = trim($text);
    if ($text === '') { return array('ok' => null, 'reason' => 'empty'); }

    $payload = json_encode(array(
        'input' => mb_substr($text, 0, 8000, 'UTF-8'),
        /* 多维度：一次前向同时给出「违规档位」与「语气」，比发两次请求省一半往返 */
        'dimensions' => array(
            'severity' => jev_level_labels(),
            'tone'     => jev_tone_labels(),
            'intent'   => jev_intent_labels(),
        ),
    ), JSON_UNESCAPED_UNICODE);

    if (net_channel_cooling('jev')) { return array('ok' => null, 'reason' => 'jev_cooling'); }
    /* 环境缺 curl 扩展：按「服务不可用」返回（上层据此降级放行），不要抛致命错误 */
    if (!function_exists('curl_init')) { return array('ok' => null, 'reason' => 'no_curl'); }

    $ch = curl_init(jev_endpoint());
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (int)curl_errno($ch);
    curl_close($ch);
    if ($code === 429) {
        net_channel_cool('jev', 60);
        return array('ok' => null, 'reason' => 'jev_rate_limited');
    }
    if (!is_string($resp) || $code !== 200) {
        if ($err !== 0 || $code === 0) { net_channel_cool('jev', 30); }
        return array('ok' => null, 'reason' => 'jev_unavailable:' . $code);
    }

    $j = json_decode($resp, true);
    $r = (is_array($j) && isset($j['results'][0]) && is_array($j['results'][0])) ? $j['results'][0] : null;
    if ($r === null) { return array('ok' => null, 'reason' => 'jev_bad_response'); }

    $dims = (isset($r['dimensions']) && is_array($r['dimensions'])) ? $r['dimensions'] : array();
    $sev  = (isset($dims['severity']['scores']) && is_array($dims['severity']['scores'])) ? $dims['severity']['scores'] : array();
    $tone = (isset($dims['tone']['scores'])     && is_array($dims['tone']['scores']))     ? $dims['tone']['scores']     : array();
    $int  = (isset($dims['intent']['scores'])   && is_array($dims['intent']['scores']))   ? $dims['intent']['scores']   : array();
    if (!$sev && isset($r['scores']) && is_array($r['scores'])) { $sev = $r['scores']; }   // 老格式兜底

    $level = jev_level_from_scores($sev);
    if ($level <= 0.0) { return array('ok' => null, 'reason' => 'jev_no_scores'); }

    $sarc = isset($tone[jev_sarcasm_label()]) ? (float)$tone[jev_sarcasm_label()] : 0.0;
    if ($sarc < 0.0) { $sarc = 0.0; }
    if ($sarc > 1.0) { $sarc = 1.0; }

    /* 提示词注入：只标注，不抬档、不拦截 */
    $inject = isset($int[jev_inject_label()]) ? (float)$int[jev_inject_label()] : 0.0;
    if ($inject < 0.0) { $inject = 0.0; }
    if ($inject > 1.0) { $inject = 1.0; }
    $benign = isset($int[jev_inject_benign_label()]) ? (float)$int[jev_inject_benign_label()] : 0.0;
    if ($benign < 0.0) { $benign = 0.0; }
    if ($benign > 1.0) { $benign = 1.0; }

    $reject = $level >= jev_reject_level();
    $mark   = !$reject && ($level >= jev_flag_level() || $sarc >= jev_sarcasm_at()
                           || jev_inject_flag($inject, $benign));

    return array(
        'ok'        => !$reject,
        'level'     => round($level, 2),
        'level_int' => (int)round($level),
        'sarcasm'   => round($sarc, 2),
        'inject'    => round($inject, 2),
        'flag'      => $mark ? 'middle' : '',
        'scores'    => $sev,
        'reason'    => 'jev',
    );
}
