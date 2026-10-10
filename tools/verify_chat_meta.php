<?php
/**
 * 对话 meta（工具卡片 + 深度思考）打包/解析：离线回归。
 * 运行：php tools/verify_chat_meta.php    不连库、不联网。
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/chat_meta.php';

$P = 0; $F = 0;
function ck(string $n, $g, $w): void
{
    global $P, $F;
    $ok = ($g === $w);
    $ok ? $P++ : $F++;
    printf("%s %s\n", $ok ? '  ok  ' : '  FAIL', $n);
    if (!$ok) { printf("       期望 %s，实际 %s\n", var_export($w, true), var_export($g, true)); }
}

/* ---------- 打包 ---------- */
ck('都为空 → 空串', chat_meta_pack(array(), array()), '');
ck('空白思考被剔除 → 空串', chat_meta_pack(array(), array('   ', '')), '');
ck('无 action 的卡片被剔除 → 空串', chat_meta_pack(array(array('ok' => true)), array()), '');

$cards = array(
    array('action' => 'search', 'ok' => true, 'summary' => '检索「解谜」命中 3 件作品'),
    array('action' => 'calc', 'ok' => false, 'summary' => '失败：除以零'),
);
$packed = chat_meta_pack($cards, array('先看看有哪些作品', '再算一下比值'));
$j = json_decode($packed, true);
ck('可解析为 JSON', is_array($j), true);
ck('cards 数量', count($j['cards']), 2);
ck('cards[0].action', $j['cards'][0]['action'], 'search');
ck('cards[1].ok=false 保留', $j['cards'][1]['ok'], false);
ck('thinks 数量', count($j['thinks']), 2);

/* ---------- 解析 ---------- */
$u = chat_meta_unpack($packed);
ck('往返 cards 一致', $u['cards'] === $cards, true);
ck('往返 thinks 一致', $u['thinks'], array('先看看有哪些作品', '再算一下比值'));
ck('空串 → 空结构', chat_meta_unpack(''), array('cards' => array(), 'thinks' => array()));
ck('null → 空结构', chat_meta_unpack(null), array('cards' => array(), 'thinks' => array()));
ck('坏 JSON → 空结构', chat_meta_unpack('{不是 json'), array('cards' => array(), 'thinks' => array()));
ck('JSON 非对象（数组）→ 空结构', chat_meta_unpack('[1,2]'), array('cards' => array(), 'thinks' => array()));
ck('缺字段 → 空结构', chat_meta_unpack('{"cards":"x"}')['cards'], array());

/* ---------- 边界 ---------- */
$long = str_repeat('思', 5000);
$p2 = chat_meta_pack(array(), array($long));
$u2 = chat_meta_unpack($p2);
ck('超长思考被截断到 4000 字', mb_strlen($u2['thinks'][0], 'UTF-8'), 4000);
$p3 = chat_meta_pack(array(array('action' => 'x', 'ok' => true, 'summary' => '说明')), array());
ck('只有卡片也能打包', strpos($p3, '"cards"') !== false, true);
ck('只有卡片时无 thinks 键', strpos($p3, '"thinks"') === false, true);

printf("\n通过 %d，失败 %d\n", $P, $F);
exit($F === 0 ? 0 : 1);
