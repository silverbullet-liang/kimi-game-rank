<?php
/**
 * 图片审核
 * ------------------------------------------------------------
 * 链路按「先感知、后判断」拆开，判断环节不可用时自动切备选：
 *
 *   1) 感知：OVHcloud AI Endpoints 的 Qwen2.5-VL-72B —— 一次调用同时完成 OCR
 *      与违规标签提取（色情 / 暴力 / 违禁品 / 广告 / 政治敏感）。匿名层无需 Key，
 *      代价是 2 请求/分钟/IP。
 *   2) 判断：把 OCR 出来的文字与标签交给 Jev（classifier.dev）做合规判定。
 *   3) 备选：任一环节不可用（限流、超时、返回异常）时，改用智谱视觉模型直接判断 ——
 *      链路可以降级，但不能因为第三方服务抽风就把上传全堵死。
 *
 * 返回统一为 array(ok => bool, via => 通道名, reason => 说明)。
 * ok=true 表示放行；strict 模式下全部通道不可用才拒绝，默认放行并记日志。
 */
declare(strict_types=1);

define('IMG_AUDIT_OVH_URL', 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions');
define('IMG_AUDIT_OVH_MODEL', 'Qwen2.5-VL-72B-Instruct');
/* 违规标签的判定阈值：任一达到即拒绝 */
define('IMG_AUDIT_LABEL_AT', 0.80);
define('IMG_AUDIT_AD_AT', 0.90);        // 广告阈值放宽些 —— OCR 文本里广告词本就常见

function image_audit_enabled(): bool
{
    return (int)cfg('image_audit.enabled', 1) === 1;
}

/**
 * 统一的 curl POST JSON。返回 array(code, body) ——
 * 必须拿到状态码才能区分「被限流」与「服务故障」，前者应立即冷却、
 * 后面几次请求直接跳过，而不是每次都白等一遍超时。
 */
function image_audit_post(string $url, array $payload, int $timeout = 45): array
{
    $ch = net_curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (int)curl_errno($ch);
    curl_close($ch);
    return array('code' => $code, 'body' => is_string($resp) ? $resp : '', 'err' => $err);
}

/** 从模型回复里抠出 JSON（模型有时会包一层说明文字） */
function image_audit_json(string $text): array
{
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $j = json_decode($m[0], true);
        if (is_array($j)) { return $j; }
    }
    return array();
}

/**
 * 感知：OVHcloud Qwen2.5-VL。返回 array(ok, text, labels)。
 * ok=false 表示这一环不可用，调用方应切备选。
 */
function image_audit_vision_ovh(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) { return array('ok' => false, 'reason' => 'read_failed'); }

    $mime = 'image/jpeg';
    $info = @getimagesize($path);
    if (is_array($info) && !empty($info['mime'])) { $mime = (string)$info['mime']; }

    $prompt = '请完成两件事：' . "\n"
            . '1. 提取图片中的所有文字，原样输出。' . "\n"
            . '2. 判断图片是否包含以下违规内容，每项给出 0-1 的置信度：色情、暴力、违禁品、垃圾广告、政治敏感。' . "\n"
            . '只输出 JSON，不要任何解释：'
            . '{"text":"...","labels":{"porn":0.0,"violence":0.0,"illegal":0.0,"ad":0.0,"politics":0.0}}';

    $body = array(
        'model'      => (string)cfg('image_audit.model', IMG_AUDIT_OVH_MODEL),
        'max_tokens' => 500,
        'messages'   => array(array('role' => 'user', 'content' => array(
            array('type' => 'text', 'text' => $prompt),
            array('type' => 'image_url', 'image_url' => array('url' => 'data:' . $mime . ';base64,' . base64_encode($raw))),
        ))),
    );
    if (net_channel_cooling('ovh')) { return array('ok' => false, 'reason' => 'ovh_cooling'); }

    $r = image_audit_post((string)cfg('image_audit.ovh_url', IMG_AUDIT_OVH_URL), $body, 45);
    if ($r['code'] === 429) {
        /* 匿名层按 IP 计额，共享主机上常被同主机的其他站点连坐。
           冷却期内不再重试，直接降级到备选通道。 */
        net_channel_cool('ovh', 120);
        return array('ok' => false, 'reason' => 'ovh_rate_limited');
    }
    if ($r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') {
        if ($r['err'] !== 0 || $r['code'] === 0) { net_channel_cool('ovh', 60); }
        return array('ok' => false, 'reason' => 'ovh_unavailable:' . $r['code']);
    }
    $resp = $r['body'];

    $j = json_decode($resp, true);
    $content = (is_array($j) && isset($j['choices'][0]['message']['content'])) ? (string)$j['choices'][0]['message']['content'] : '';
    if ($content === '') { return array('ok' => false, 'reason' => 'ovh_empty'); }

    $parsed = image_audit_json($content);
    return array(
        'ok'     => true,
        'text'   => isset($parsed['text']) ? (string)$parsed['text'] : $content,
        'labels' => (isset($parsed['labels']) && is_array($parsed['labels'])) ? $parsed['labels'] : array(),
    );
}

/**
 * 判断：把感知结果交给 Jev。返回 array(ok, reason)。
 * ok=false 表示这一环不可用（与「判定为违规」区分开：违规用 reject=true）。
 */
function image_audit_judge(string $text, array $labels): array
{
    /* 零、本地初筛：图上文字里若出现可疑域名，直接拒。
       图片里挂网址基本只有导流一种用途，而这一步不花任何外部调用 ——
       本地能判定的事就不必再占用外部服务的额度。 */
    if (function_exists('link_guard_check')) {
        $hits = link_guard_check($text);
        if ($hits) {
            app_log('image_audit: blocked host ' . $hits[0]['host'] . ' via ' . $hits[0]['why']);
            return array('ok' => true, 'reject' => true, 'reason' => '图片含可疑网址');
        }
    }

    /* 一、标签直判：感知阶段已经给出的明确信号 */
    foreach (array('porn' => '色情', 'violence' => '暴力', 'illegal' => '违禁品', 'politics' => '政治敏感') as $k => $cn) {
        if (isset($labels[$k]) && (float)$labels[$k] >= IMG_AUDIT_LABEL_AT) {
            return array('ok' => true, 'reject' => true, 'reason' => '图片含' . $cn . '内容');
        }
    }
    if (isset($labels['ad']) && (float)$labels['ad'] >= IMG_AUDIT_AD_AT) {
        return array('ok' => true, 'reject' => true, 'reason' => '图片含广告引流内容');
    }

    /* 二、文字交给 Jev 判一次（图上的文字同样可能骂人或引流） */
    $t = trim($text);
    if ($t === '') { return array('ok' => true, 'reject' => false, 'reason' => ''); }
    if (!function_exists('jev_classify')) { return array('ok' => false, 'reason' => 'jev_missing'); }

    $j = jev_classify(mb_substr($t, 0, 2000, 'UTF-8'));
    if ($j['ok'] === null) { return array('ok' => false, 'reason' => (string)$j['reason']); }
    if (empty($j['ok'])) {
        /* 图上文字判为人身攻击：记档位便于回溯与调阈值，不对外输出 */
        app_log('image_audit: text jev level=' . (isset($j['level']) ? $j['level'] : '?') . ' blocked');
        return array('ok' => true, 'reject' => true, 'reason' => '图片文字含人身攻击');
    }
    return array('ok' => true, 'reject' => false, 'reason' => '');
}

/**
 * 备选：智谱视觉模型直接看图判断。
 * 走站点自有通道，用量记在站点名下。返回 array(ok, reject, reason)。
 */
function image_audit_vision_glm(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) { return array('ok' => false, 'reason' => 'read_failed'); }

    $mime = 'image/jpeg';
    $info = @getimagesize($path);
    if (is_array($info) && !empty($info['mime'])) { $mime = (string)$info['mime']; }

    $prompt = '你是社区内容审核员。判断这张图片是否包含色情、暴力、违禁品、'
            . '垃圾广告或政治敏感内容。' . "\n"
            . '以下都是正常内容，遇到一律放行：表情包、聊天截图、游戏画面、动漫插画、'
            . '二次元人物、生活照片、作品截图、带文字的图片、加水印或 logo 的图片、'
            . '含英文数字的图片、模糊或低清图片、纯色或纯文字图片、真人自拍。' . "\n"
            . '**拿不准就放行**——宁可漏过一张可疑图片，也不要误伤正常用户。' . "\n"
            . '只有内容明确属于上述违规类别时才回「拦截」。' . "\n"
            . '只回答一个词：拦截 或 放行。不要解释、不要复述图片内容。';
    $msgs = array(array('role' => 'user', 'content' => array(
        array('type' => 'text', 'text' => $prompt),
        array('type' => 'image_url', 'image_url' => array(
            'url' => 'data:' . $mime . ';base64,' . base64_encode($raw),
        )),
    )));
    try {
        $r = zhipu_chat($msgs, (string)cfg('image_audit.glm_model', 'glm-4v-flash'));
    } catch (Throwable $e) {
        app_log('image_audit glm failed: ' . $e->getMessage());
        return array('ok' => false, 'reason' => 'glm_unavailable');
    }
    if (!empty($r['usage'])) { ai_usage_record(0, $r['usage'], 'glm'); }   // 站点承担额度
    /* 用统一结论解析器：它会认中英文，并剔除「不需要拦截」「不会拦截」这类否定表述 ——
       若只做 strpos($out, '拦截')，模型一句「没有违规，不会拦截」就会被判成拦截。 */
    $v = function_exists('moderation_verdict_parse') ? moderation_verdict_parse((string)$r['text']) : null;
    if ($v === false) { return array('ok' => true, 'reject' => true, 'reason' => '图片未通过审核'); }
    if ($v === true)  { return array('ok' => true, 'reject' => false, 'reason' => ''); }
    /* 解析不出结论：放行（不因为模型多嘴就把用户的上传堵掉），并记日志 */
    app_log('image_audit glm unexpected output len=' . strlen((string)$r['text']));
    return array('ok' => true, 'reject' => false, 'reason' => '');
}

/**
 * 主入口：审核一张图片。返回 array(ok, via, reason)。
 * ok=true 放行；ok=false 拒绝（reason 为给用户看的原因）。
 */
function image_audit_check(string $path): array
{
    if (!image_audit_enabled()) { return array('ok' => true, 'via' => 'disabled', 'reason' => ''); }
    if (!is_file($path)) { return array('ok' => false, 'via' => 'none', 'reason' => '图片读取失败'); }

    $strict = (int)cfg('image_audit.strict', 0) === 1;

    /* 一、主链路：OVHcloud 感知 → Jev 判断 */
    $v = image_audit_vision_ovh($path);
    if (!empty($v['ok'])) {
        $j = image_audit_judge((string)$v['text'], (array)$v['labels']);
        if (!empty($j['ok'])) {
            if (!empty($j['reject'])) {
                app_log('image_audit reject via ovh+jev: ' . $j['reason']);
                return array('ok' => false, 'via' => 'ovh+jev', 'reason' => (string)$j['reason']);
            }
            return array('ok' => true, 'via' => 'ovh+jev', 'reason' => '');
        }
        app_log('image_audit: jev unavailable (' . $j['reason'] . '), fall back to glm vision');
    } else {
        app_log('image_audit: ovh unavailable (' . $v['reason'] . '), fall back to glm vision');
    }

    /* 二、备选：智谱视觉直接判断 */
    $g = image_audit_vision_glm($path);
    if (!empty($g['ok'])) {
        if (!empty($g['reject'])) {
            app_log('image_audit reject via glm: ' . $g['reason']);
            return array('ok' => false, 'via' => 'glm', 'reason' => (string)$g['reason']);
        }
        return array('ok' => true, 'via' => 'glm', 'reason' => '');
    }

    /* 三、全部通道不可用：默认放行并记日志；严格模式才拒绝 */
    app_log('image_audit: all channels unavailable (' . $g['reason'] . ') strict=' . ($strict ? 1 : 0));
    if ($strict) { return array('ok' => false, 'via' => 'none', 'reason' => '图片审核服务暂不可用，请稍后再试'); }
    return array('ok' => true, 'via' => 'degraded', 'reason' => '');
}
