<?php
/**
 * 对话消息的 meta（工具卡片 + 深度思考）
 * ------------------------------------------------------------
 * AI 对话（ai_messages）与世界对话的官方 AI 回复（messages）共用同一份打包格式：
 *   {"cards":[{"action","ok","summary"}],"thinks":["..."]}
 * 落库只存这份精简结构，前端据此还原当时的工具卡片与深度思考块。
 */
declare(strict_types=1);

/** 打包；两者都空则返回空串（不写冗余） */
function chat_meta_pack(array $cards, array $thinks): string
{
    $cards = array_values(array_filter($cards, function ($c) {
        return isset($c['action']) && (string)$c['action'] !== '';
    }));
    $thinks = array_values(array_filter(array_map(function ($t) {
        return trim((string)$t);
    }, $thinks), function ($t) { return $t !== ''; }));

    if (!$cards && !$thinks) { return ''; }

    $m = array();
    if ($cards)  { $m['cards']  = $cards; }
    if ($thinks) { $m['thinks'] = array_map(function ($t) { return mb_substr($t, 0, 4000, 'UTF-8'); }, $thinks); }
    return (string)json_encode($m, JSON_UNESCAPED_UNICODE);
}

/** 解析；坏数据一律退化为空 */
function chat_meta_unpack($s): array
{
    $empty = array('cards' => array(), 'thinks' => array());
    if (!is_string($s) || $s === '') { return $empty; }
    $j = json_decode($s, true);
    if (!is_array($j)) { return $empty; }
    return array(
        'cards'  => (isset($j['cards'])  && is_array($j['cards']))  ? array_values($j['cards'])  : array(),
        'thinks' => (isset($j['thinks']) && is_array($j['thinks'])) ? array_values($j['thinks']) : array(),
    );
}
