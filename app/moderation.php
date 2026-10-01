<?php
/**
 * 内容过三关（评论 / 消息发送前，服务端强制）
 * ============================================================
 * 一验：本地词库 + 常用句式正则 —— 零网络开销，先挡掉绝大多数。
 * 二验：译成英文后交给英文脏词接口 —— 覆盖词库收不到的新说法。
 * 过三关：glm-4-flash 判定，只认 True（放行）/ False（拦截）。
 *
 * 三条原则：
 *   ① 词库与白名单都从数据文件读取，本文件不含任何词条字面量；
 *   ② 结论按「内容指纹」缓存，同样的内容只验一次；
 *   ③ 外部环节故障默认不阻断发言（可在配置里改成严格模式），
 *      但每一次降级都会记日志，方便事后回溯。
 *
 * 额度：第三关走本站自有模型（glm-4-flash），用量记在站点名下，
 *       任何情况下都不消耗发言者的个人额度。
 */

/** 词库与白名单的磁盘路径 */
function moderation_word_file()  { return APP_ROOT . '/app/data/moderation_words.txt'; }
function moderation_allow_file() { return APP_ROOT . '/app/data/moderation_allow.txt'; }

/**
 * 载入词库：返回 array(index => array(首字 => array(词条...)), all => array(词条...))。
 * 首字索引把逐条比对从「整库扫」降为「只看同首字的候选」，长文本下差距明显。
 */
function moderation_words(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $idx = array(); $all = array();
    $f = moderation_word_file();
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        if ($raw !== false) {
            foreach (explode("\n", $raw) as $line) {
                $w = trim($line);
                if ($w === '' || $w[0] === '#') { continue; }
                $w = mb_strtolower($w, 'UTF-8');
                if (mb_strlen($w, 'UTF-8') < 2) { continue; }   // 单字误伤面过大，整库不收录
                if (isset($all[$w])) { continue; }
                $all[$w] = true;
                $idx[mb_substr($w, 0, 1, 'UTF-8')][] = $w;
            }
        }
    }
    return $cache = array('index' => $idx, 'all' => array_keys($all));
}

/** 白名单：命中这些词的位置先被屏蔽，避免「正常词里夹着敏感片段」被误伤 */
function moderation_allow(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = array();
    $f = moderation_allow_file();
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        if ($raw !== false) {
            foreach (explode("\n", $raw) as $line) {
                $w = trim($line);
                if ($w !== '' && $w[0] !== '#') { $out[] = mb_strtolower($w, 'UTF-8'); }
            }
        }
    }
    return $cache = $out;
}

/**
 * 归一化：让「插空格 / 夹符号 / 全角 / 大小写」这类绕行写法回到同一形态。
 * 返回 array(plain, squeezed)：squeezed 还把连续重复字压成 2 个，用于挡刷屏式变体。
 */
function moderation_norm(string $s): array
{
    /* 全角字母数字与全角空格 → 半角（mb_convert_kana 的 'as'） */
    if (function_exists('mb_convert_kana')) {
        $t = @mb_convert_kana($s, 'as', 'UTF-8');
        if (is_string($t) && $t !== '') { $s = $t; }
    }
    $s = mb_strtolower($s, 'UTF-8');
    /* 去掉不可见与零宽字符 */
    $s = (string)preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]/u', '', $s);
    /* 去掉夹在中间的轻度分隔符：这是最常见的「打码绕行」手法 */
    $s = (string)preg_replace('/[\s\x{00B7}\x{2022}\x{30FB}\.\-\_\*\~\^\+\=\|\/\\\\,\x{3002}\x{FF0C}\x{FF01}\x{FF1F}\x{FF1B}\x{FF1A}]+/u', '', $s);
    if ($s === '') { return array('plain' => '', 'squeezed' => ''); }
    $sq = (string)preg_replace('/(.)\1{2,}/u', '$1$1', $s);      // 连续 3 个以上压成 2 个
    return array('plain' => $s, 'squeezed' => $sq);
}

/** 把白名单命中的位置替换成等长占位符：位置不变，但不再参与匹配 */
function moderation_shield(string $text): string
{
    foreach (moderation_allow() as $w) {
        $n = mb_strlen($w, 'UTF-8');
        if ($n < 2) { continue; }
        $pos = 0;
        while (($pos = mb_strpos($text, $w, $pos, 'UTF-8')) !== false) {
            $text = mb_substr($text, 0, $pos, 'UTF-8')
                  . str_repeat('·', $n)
                  . mb_substr($text, $pos + $n, null, 'UTF-8');
            $pos += $n;
        }
    }
    return $text;
}

/** 在给定文本里查词库；命中即返回该词（仅用于内部判断，不对外输出） */
function moderation_scan(string $text): string
{
    if ($text === '') { return ''; }
    $cat = moderation_words();
    if (!$cat['all']) { return ''; }
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $c1 = mb_substr($text, $i, 1, 'UTF-8');
        if (!isset($cat['index'][$c1])) { continue; }
        foreach ($cat['index'][$c1] as $w) {
            $n = mb_strlen($w, 'UTF-8');
            if ($i + $n > $len) { continue; }
            if (mb_substr($text, $i, $n, 'UTF-8') === $w) { return $w; }
        }
    }
    return '';
}

/**
 * 表情名清单：取自站内表情库（assets/emoji/index.json，结构为 packs[].items[]）。
 * 表情在消息里以 [名称] 形式内嵌，名称（含 keywords 里的别名）本身可能含被误判的片段，
 * 因此凡是能对上表情库的 [名称]，都整段屏蔽、不参与审核。
 */
function moderation_emoji_names(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $names = array();
    $f = APP_ROOT . '/assets/emoji/index.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && !empty($j['packs']) && is_array($j['packs'])) {
            foreach ($j['packs'] as $pack) {
                if (empty($pack['items']) || !is_array($pack['items'])) { continue; }
                foreach ($pack['items'] as $it) {
                    $c = isset($it['code']) ? trim((string)$it['code']) : '';
                    if ($c !== '') { $names[$c] = true; }
                    /* 关键词也收进来：用户常按别名发 [2333]，而不是正式名 [笑哭] */
                    if (!empty($it['keywords']) && is_array($it['keywords'])) {
                        foreach ($it['keywords'] as $kw) {
                            $kw = trim((string)$kw);
                            if ($kw !== '' && mb_strlen($kw, 'UTF-8') <= 12) { $names[$kw] = true; }
                        }
                    }
                }
            }
        }
    }
    return $cache = $names;
}

/**
 * AI 模型名及其版本 / 参数规模（Gemma 4 31B、Qwen3.8 27B、GLM-4、DeepSeek-V3…）。
 * 这类「字母＋数字」串本身毫无恶意，却最容易同时踩中词库与审核模型的误判，
 * 因此在送审前整段屏蔽：只影响这一段，其余文字照常判定。
 */
function moderation_model_name_re(): string
{
    $fam = 'gemma|qwen|llama|deepseek|chatglm|glm|phi|mistral|mixtral|baichuan|internlm|minicpm|'
         . 'grok|claude|gpt|kimi|moonshot|doubao|ernie|hunyuan|gemini|opus|sonnet|haiku|'
         . 'seed|abab|command|falcon|olmo|smollm|nanbeige|step|spark|nova|minimax|yi';
    /* 词首边界 + 家族名 + 任意段「版本 / 规模」（可重复，容忍空格与点号，
       并允许 V3 / K2.5 / 3-32B 这类带字母前缀的写法） */
    return '/(?<![\w.])(?:' . $fam . ')(?:[\s._-]*[a-zA-Z]{0,2}\d+(?:\.\d+)?[bBmMkWw]?)*/iu';
}

/** 把整段替换成等长占位符（长度不变，便于与原文逐字对齐排查） */
function moderation_mask_span(string $text): string
{
    $text = (string)preg_replace_callback(moderation_model_name_re(), function ($m) {
        return str_repeat('·', mb_strlen($m[0], 'UTF-8'));
    }, $text);

    $names = moderation_emoji_names();
    if ($names) {
        $text = (string)preg_replace_callback('/\[([^\[\]\s]{1,12})\]/u', function ($m) use ($names) {
            return isset($names[$m[1]]) ? str_repeat('·', mb_strlen($m[0], 'UTF-8')) : $m[0];
        }, $text);
    }
    return $text;
}

/**
 * 常用句式正则：只针对「形态」而非「词义」，因此不受词库覆盖面限制。
 * 返回命中的规则名（空串 = 无命中）。
 */
function moderation_patterns(string $text): string
{
    if ($text === '') { return ''; }

    /* 刷屏：同一字符连发 12 次以上 */
    if (preg_match('/(.)\1{11,}/u', $text)) { return 'flood'; }

    /* 联系方式引流：加/留 + 平台词，或平台词后紧跟 5 位以上数字 */
    if (preg_match('/(加|留|私|扣)\s*(我|你)?\s*(微|威信|v信|vx|wx|qq|扣扣|企鹅|电报|tg|telegram)/iu', $text)) { return 'contact'; }
    if (preg_match('/(微|威信|v信|vx|wx|qq|扣扣|telegram|tg)\s*[:：]?\s*\d{5,}/iu', $text)) { return 'contact'; }

    /* 站外手机号（11 位，1 开头） */
    if (preg_match('/(?<!\d)1[3-9]\d{9}(?!\d)/u', $text)) { return 'phone'; }

    /* 纯数字长串（常见于报号引流） */
    if (preg_match('/(?<!\d)\d{8,}(?!\d)/u', $text)) { return 'number_spam'; }

    return '';
}

/** 第一验：词库 + 句式。返回 array(ok, reason) */
function moderation_check1(string $text): array
{
    $n = moderation_norm($text);
    $hit = moderation_scan(moderation_shield($n['plain']));
    if ($hit === '' && $n['squeezed'] !== $n['plain']) {
        $hit = moderation_scan(moderation_shield($n['squeezed']));
    }
    if ($hit !== '') { return array('ok' => false, 'reason' => 'wordlist'); }

    $p = moderation_patterns($n['plain']);
    if ($p !== '') { return array('ok' => false, 'reason' => 'pattern:' . $p); }

    return array('ok' => true, 'reason' => '');
}

/** 外部接口取文本（短超时；失败返回 ''，由调用方决定降级） */
function moderation_http(string $url, int $timeout = 4, int $maxBytes = 20000): string
{
    if (!function_exists('curl_init')) { return ''; }
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'KimiGameRank/' . (defined('APP_VERSION') ? APP_VERSION : '1.0'),
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, $maxBytes) {
            $body .= $chunk;
            return (strlen($body) > $maxBytes) ? 0 : strlen($chunk);
        },
    ));
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200) ? $body : '';
}

/** 文本是否含中日韩字符（决定要不要送翻译） */
function moderation_has_cjk(string $s): bool
{
    return (bool)preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $s);
}

/**
 * 第二验：翻译成英文 → 英文脏词接口。
 * 任一环节不可用时返回 unknown（默认放行、记日志，绝不因为外部服务抽风就封住发言）。
 */
function moderation_check2(string $text): array
{
    $en = $text;
    if (moderation_has_cjk($text)) {
        $q   = mb_substr($text, 0, 300, 'UTF-8');
        $raw = moderation_http('https://api.mymemory.translated.net/get?q='
             . rawurlencode($q) . '&langpair=' . rawurlencode('zh-CN|en'), 5);
        if ($raw === '') { return array('ok' => true, 'unknown' => true, 'reason' => 'translate_unavailable'); }
        $j = json_decode($raw, true);
        $t = is_array($j) && isset($j['responseData']['translatedText']) ? (string)$j['responseData']['translatedText'] : '';
        if ($t === '') { return array('ok' => true, 'unknown' => true, 'reason' => 'translate_empty'); }
        $en = $t;
    }
    if (trim($en) === '') { return array('ok' => true, 'reason' => ''); }

    $raw = moderation_http('https://www.purgomalum.com/service/containsprofanity?text=' . rawurlencode(mb_substr($en, 0, 500, 'UTF-8')), 4);
    if ($raw === '') { return array('ok' => true, 'unknown' => true, 'reason' => 'profanity_unavailable'); }
    $verdict = strtolower(trim($raw));
    if ($verdict === 'true')  { return array('ok' => false, 'reason' => 'english_profanity'); }
    if ($verdict === 'false') { return array('ok' => true, 'reason' => ''); }
    return array('ok' => true, 'unknown' => true, 'reason' => 'profanity_unexpected');
}

/**
 * 解析审核模型的一句话结论，返回 true（放行）/ false（拦截）/ null（无法判断）。
 *
 * 模型既可能回英文 True / False，也可能直接回中文「放行 / 拦截」——两种都要认：
 * 只认英文时，一句「拦截」会被当成解析失败而默认放行，等于没审。
 * 判断顺序从可靠到宽松，并避开「不需要拦截」这类会被误读的表述。
 */
function moderation_verdict_parse(string $out)
{
    $s = strtolower(trim($out));
    if ($s === '') { return null; }

    /* 一、整个回复就是一个结论词（最常见） */
    $bare = (string)preg_replace('/[^\p{Han}a-z]/u', '', $s);
    if ($bare === 'true'  || $bare === '放行' || $bare === '合规') { return true; }
    if ($bare === 'false' || $bare === '拦截' || $bare === '违规') { return false; }

    /* 二、回复以结论词开头 */
    if (preg_match('/^(?:true|放行|合规|通过)/u', $s))                                  { return true; }
    if (preg_match('/^(?:false|拦截|不合规|不通过)/u', $s))                             { return false; }

    /* 三、明确否定优先；裸的「违规」不算，免得「不违规」被判成违规。
       「不需要拦截」这类表述先剔除，否则会被读成否定结论。 */
    $t = (string)preg_replace('/(?:不|无需|无须|没有|不必|未)(?:需要|会|应|必|再)?\s*拦截/u', '', $s);
    if (strpos($t, '拦截') !== false || strpos($t, '不合规') !== false
        || strpos($t, '不通过') !== false || strpos($t, 'false') !== false)             { return false; }
    if (strpos($s, '放行') !== false || strpos($s, '合规') !== false
        || strpos($s, 'true') !== false)                                                { return true; }

    return null;
}

/**
 * 第三关：glm-4-flash 判定，模型只回 True（合规，放行）或 False（拦截）。
 * 走站点自有通道，用量记在站点名下（uid=0），不动任何人的个人额度。
 */
function moderation_check3(string $text): array
{
    $sys = "你是社区内容审核员。判断用户提交的内容是否需要拦截。\n"
         . "【需要拦截】只有这四类：\n"
         . "1) 辱骂、人身攻击、歧视；2) 色情、暴力威胁、违法犯罪；\n"
         . "3) 广告引流（联系方式、推广链接、拉人进群）；4) 同一段内容反复刷屏。\n"
         . "【一律放行】以下情形不要拦截：\n"
         . "1) 日常聊天、打招呼、寒暄、简短提问，例如「有人吗」「在吗」「你好」；\n"
         . "2) 正常讨论游戏、作品、剧情；表达对作品或评分的不满与批评；\n"
         . "3) 提及 AI 模型名称及其版本或参数规模，例如 Gemma 4 31B、Qwen3.8 27B、GLM-4、DeepSeek-V3；\n"
         . "4) 提及作品名、网站名、技术术语，或看起来像代号、编号的字符串；\n"
         . "5) 句子短、信息量少，但既没有骂人也没有针对具体的人。\n"
         . "注意：骂人、贬损他人属于第 1 类，即使句子很短也必须拦截；\n"
         . "看不出明确违规迹象时，判放行。\n"
         . "只输出一个词：放行 或 拦截。不要输出任何其它内容。";
    $user = "待审内容：\n<content>\n" . mb_substr($text, 0, 500, 'UTF-8') . "\n</content>";
    $msgs = array(
        array('role' => 'system', 'content' => $sys),
        array('role' => 'user',   'content' => $user),
    );

    try {
        $r = zhipu_chat($msgs, (string)cfg('moderation.model', 'glm-4-flash'));
    } catch (Throwable $e) {
        app_log('moderation_check3 failed: ' . $e->getMessage());
        return array('ok' => true, 'unknown' => true, 'reason' => 'ai_unavailable');
    }
    if (!empty($r['usage'])) { ai_usage_record(0, $r['usage'], 'glm'); }   // 站点承担

    $out = (string)$r['text'];
    $v   = moderation_verdict_parse($out);
    if ($v === false) { return array('ok' => false, 'reason' => 'ai_reject'); }
    if ($v === true)  { return array('ok' => true,  'reason' => ''); }
    /* 解析不出结论：默认放行（不因模型多嘴就封住发言），并记日志便于回溯 */
    app_log('moderation_check3 unexpected output len=' . strlen($out));
    return array('ok' => true, 'unknown' => true, 'reason' => 'ai_unexpected');
}

/**
 * 主入口：过三关 + 缓存。
 * $scope：'comment' | 'message'，仅用于日志与后续分域调参。
 * 返回 array(ok, stage, reason, cached)。ok=false 时调用方应拒绝写入并给出统一话术。
 */
function moderate_text(string $text, string $scope = 'comment', int $uid = 0): array
{
    $text = trim($text);
    if ($text === '') { return array('ok' => true, 'stage' => 0, 'reason' => '', 'cached' => false); }

    /* 重审凭证优先于一切判定，也优先于「拦截」结论的缓存 —— 否则会出现
       「重审说可以、发送又被拦」的死循环。 */
    if ($uid > 0) {
        $pass = moderation_pass_take($uid, $text);
        if (is_array($pass)) {
            return array('ok' => true, 'stage' => 0, 'reason' => 'rechecked',
                         'cached' => false, 'flag' => (string)(isset($pass['flag']) ? $pass['flag'] : ''));
        }
    }

    if ((int)cfg('moderation.enabled', 1) !== 1) { return array('ok' => true, 'stage' => 0, 'reason' => 'disabled', 'cached' => false); }

    /* 结论缓存：同一内容只验一次（内容改了，指纹就变，自然重验） */
    $ttl = max(3600, (int)cfg('moderation.cache_ttl', 604800));
    $key = 'mod_' . substr(dup_content_norm($text), 0, 40);
    $hit = cache_get($key, $ttl);
    if (is_array($hit) && isset($hit['ok'])) {
        return array('ok' => (bool)$hit['ok'], 'stage' => (int)($hit['stage'] ?? 0),
                     'reason' => (string)($hit['reason'] ?? ''), 'cached' => true);
    }

    $steps = array(
        array('stage' => 1, 'fn' => 'moderation_check1', 'on' => true),
        array('stage' => 2, 'fn' => 'moderation_check2', 'on' => (int)cfg('moderation.stage2', 1) === 1),
        array('stage' => 3, 'fn' => 'moderation_check3', 'on' => (int)cfg('moderation.stage3', 1) === 1),
    );

    $strict  = (int)cfg('moderation.strict', 0) === 1;
    $verdict = array('ok' => true, 'stage' => 0, 'reason' => '');

    /* 送审前屏蔽表情名与模型名（整段等长占位）：这两类内容最容易误判，
       屏蔽只影响那几段，其余文字照常判定。缓存指纹仍用原文，互不干扰。 */
    $masked = moderation_mask_span($text);

    foreach ($steps as $s) {
        if (!$s['on']) { continue; }
        $r = call_user_func($s['fn'], $masked);
        if (empty($r['ok'])) {
            $verdict = array('ok' => false, 'stage' => $s['stage'], 'reason' => (string)$r['reason']);
            break;
        }
        if (!empty($r['unknown'])) {
            /* 外部环节故障：默认放行（社区不该被第三方服务拖停），严格模式则拒绝 */
            app_log('moderation degraded scope=' . $scope . ' stage=' . $s['stage']
                  . ' reason=' . $r['reason'] . ' strict=' . ($strict ? 1 : 0));
            if ($strict) { $verdict = array('ok' => false, 'stage' => $s['stage'], 'reason' => 'unavailable'); break; }
            $verdict = array('ok' => true, 'stage' => 0, 'reason' => 'degraded');
        }
    }

    cache_set($key, array('ok' => $verdict['ok'] ? 1 : 0, 'stage' => $verdict['stage'],
                          'reason' => $verdict['reason']), $ttl);
    if (!$verdict['ok']) {
        app_log('moderation blocked scope=' . $scope . ' stage=' . $verdict['stage'] . ' reason=' . $verdict['reason']);
    }
    return array('ok' => (bool)$verdict['ok'], 'stage' => (int)$verdict['stage'],
                 'reason' => (string)$verdict['reason'], 'cached' => false, 'flag' => '');
}

/* ============================================================
 * AI 重审（用户显式点击触发，不参与自动流程）
 * ============================================================
 * 返回三档：true（合规，放行）/ middle（可能有恶意，仍放行并在消息旁标注）/ false（维持拦截）。
 * 「通过」时签发一次性凭证（绑定用户与内容指纹、15 分钟有效），发送接口凭它放行 ——
 * 否则会出现「重审说可以、发送又被拦一次」的死循环。
 */

/** 内容指纹：与结论缓存同源，保证同一段文字的判定前后一致 */
function moderation_fingerprint(string $text): string
{
    return substr(dup_content_norm($text), 0, 40);
}

function moderation_pass_key(int $uid, string $text): string
{
    return 'mrec_' . $uid . '_' . moderation_fingerprint($text);
}

/** 签发重审通过凭证（PHP 7.0 基线：不使用 void 返回类型） */
function moderation_pass_issue(int $uid, string $text, string $flag = '')
{
    cache_set(moderation_pass_key($uid, $text), array('flag' => $flag, 'at' => now_utc()), 900);
}

/** 取重审凭证；无或已过期返回 null */
function moderation_pass_take(int $uid, string $text)
{
    $v = cache_get(moderation_pass_key($uid, $text), 900);
    return is_array($v) ? $v : null;
}

/**
 * AI 重审：只由用户点「AI 重审」时触发。
 * 通道不可用时返回 middle —— 用户已经手动申诉过一次，不该被一次网络抖动判死，
 * 放行并标注，把最终判断交给读者。
 */
function moderation_recheck(string $text, int $uid = 0): array
{
    $sys = "你是社区内容审核员，正在复核一条先前被判违规、用户已提出申诉的内容。\n"
         . "用户手动申诉过，请从严把握「违规」的界线：只有明确、确凿的违规才维持拦截。\n"
         . "只输出一个词：\n"
         . "  true   —— 合规，放行。绝大多数内容都应落在这里，包括日常聊天、打招呼、\n"
         . "            寒暄、简短提问（「有人吗」「在吗」「你好」）、批评与吐槽、\n"
         . "            提及模型名或作品名、内容简短或看不出明确含义。\n"
         . "  middle —— 确实有些不妥但远不到违规，例如明显的阴阳怪气，\n"
         . "            或疑似引流却看不出明确意图。放行，并在旁边标注提醒。\n"
         . "  false  —— 明确违规。只有辱骂攻击、色情、暴力威胁、违法犯罪、\n"
         . "            明确的广告引流（联系方式、推广链接、拉人进群）才可判此档。\n"
         . "不要因为内容短、信息量少、语气随意就判 middle 或 false。\n"
         . "拿不准时判 true。只输出那个词，不要输出任何其它内容。";
    $user = "待复核内容：\n<content>\n" . mb_substr($text, 0, 500, 'UTF-8') . "\n</content>";
    $msgs = array(
        array('role' => 'system', 'content' => $sys),
        array('role' => 'user',   'content' => $user),
    );

    try {
        $r = zhipu_chat($msgs, (string)cfg('moderation.model', 'glm-4-flash'));
    } catch (Throwable $e) {
        app_log('moderation_recheck failed: ' . $e->getMessage());
        return array('verdict' => 'middle', 'flag' => 'middle', 'reason' => 'ai_unavailable');
    }
    if (!empty($r['usage'])) { ai_usage_record(0, $r['usage'], 'glm'); }

    $out  = strtolower(trim((string)$r['text']));
    $head = (string)preg_replace('/[^a-z].*$/s', '', $out);
    $verdict = '';
    if ($head === 'true' || $head === 'false' || $head === 'middle') {
        $verdict = $head;
    } else {
        /* 模型多嘴时取最先出现的档位 */
        $pos = array();
        foreach (array('middle', 'false', 'true') as $w) {
            $i = strpos($out, $w);
            if ($i !== false) { $pos[$w] = $i; }
        }
        if ($pos) { asort($pos); $verdict = (string)key($pos); }
    }
    if ($verdict === '') {
        app_log('moderation_recheck unexpected output len=' . strlen($out));
        $verdict = 'middle';
    }

    $flag = ($verdict === 'middle') ? 'middle' : '';
    if ($verdict === 'true' || $verdict === 'middle') { moderation_pass_issue($uid, $text, $flag); }
    return array('verdict' => $verdict, 'flag' => $flag, 'reason' => '');
}

/** 拒绝写入时的统一话术（对外只说结论与出路，不透露命中了什么，避免被反向试探） */
function moderate_reject(array $v)
{
    $extra = ((int)$v['stage'] === 1) ? '请检查是否含辱骂、广告或联系方式。' : '请修改后重新发送。';
    fail(422, '内容未通过审核，' . $extra . '如认为误判，可通过「反映问题」告知我们。');
}
