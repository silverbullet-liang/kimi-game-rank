<?php
/**
 * Jev 文本合规判断（classifier.dev）
 * ------------------------------------------------------------
 * Jev 是 TypeSafe 的非自回归分类模型：一次前向给出所有标签的校准概率，
 * 单条 150–220ms，比大模型快一个数量级，且按 IP 免费、无需 Key。
 *
 * 标签用英文语义标签（实测中文标签准确率明显偏低）：
 *   harassment 人身攻击 / 威胁   → 高概率拦截
 *   sarcasm    讽刺阴阳怪气      → 放行（属于正常表达）
 *   complaint  合理抱怨与差评    → 放行
 *   normal     中性或正面        → 放行
 *
 * 注意：广告引流不交给它判断 —— 实测「加我微信…」会被判成 normal，
 * 那类内容由词库与句式正则负责，这里只管「有没有骂人」。
 *
 * 返回 ok=null 表示服务不可用，调用方应回落到智谱。
 */
declare(strict_types=1);

define('JEV_DEFAULT_URL', 'https://classifier.dev');
define('JEV_REJECT_AT', 0.70);      // harassment 达到该概率即拦截
define('JEV_HARD_AT', 0.85);        // 即使主标签不是 harassment，达到该概率也拦

function jev_labels(): array
{
    return array('harassment', 'sarcasm', 'complaint', 'normal');
}

function jev_endpoint(): string
{
    return rtrim((string)cfg('moderation.jev_url', JEV_DEFAULT_URL), '/') . '/classify';
}

/**
 * 判断一段文本。返回：
 *   array('ok' => true|false|null, 'label' => ..., 'harassment' => float, 'reason' => ...)
 * ok=null 表示不可用（网络失败、限流、返回异常），调用方自行决定回落。
 */
function jev_classify(string $text): array
{
    $text = trim($text);
    if ($text === '') { return array('ok' => null, 'reason' => 'empty'); }

    $payload = json_encode(array(
        'input'  => mb_substr($text, 0, 8000, 'UTF-8'),
        'labels' => jev_labels(),
    ), JSON_UNESCAPED_UNICODE);

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
    curl_close($ch);
    if (!is_string($resp) || $code !== 200) {
        return array('ok' => null, 'reason' => 'jev_unavailable:' . $code);
    }

    $j = json_decode($resp, true);
    $r = (is_array($j) && isset($j['results'][0]) && is_array($j['results'][0])) ? $j['results'][0] : null;
    if ($r === null || !isset($r['label'])) {
        return array('ok' => null, 'reason' => 'jev_bad_response');
    }

    $scores = (isset($r['scores']) && is_array($r['scores'])) ? $r['scores'] : array();
    $h = isset($scores['harassment']) ? (float)$scores['harassment'] : 0.0;
    $label = strtolower((string)$r['label']);

    $reject = ($label === 'harassment' && $h >= JEV_REJECT_AT) || $h >= JEV_HARD_AT;
    return array('ok' => !$reject, 'label' => $label, 'harassment' => $h,
                 'scores' => $scores, 'reason' => 'jev');
}
