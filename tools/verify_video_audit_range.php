<?php
/**
 * 对照实验：视频消息审核范围
 *   A = 标题 + 简介 + 附言（旧口径，简介含官网链接）
 *   B = 标题 + 作者 + 附言（新口径）
 * 期望：A 被拦（广告引流），B 放行。
 */
declare(strict_types=1);

define('APP_ROOT', '/sandbox/workspace/kimi-game-rank');
$CFG = require APP_ROOT . '/config/config.php';

function cfg($k, $d = null) {
    global $CFG;
    $v = $CFG;
    foreach (explode('.', $k) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) { return $d; }
        $v = $v[$p];
    }
    return $v;
}
function app_log($a, $b = null) {}
function now_utc() { return gmdate('Y-m-d H:i:s'); }
function rand_hex($n = 8) { return bin2hex(random_bytes(max(1, intdiv($n, 2)))); }
function cache_get($k, $ttl = 0) { return null; }
function cache_set($k, $v, $ttl = 0) {}
function cache_del($k) {}
function dup_content_norm($s) { return md5((string)$s); }
function db_one($s, $p = array()) { return null; }
function db_all($s, $p = array()) { return array(); }
function db_exec($s, $p = array()) { return 0; }
function db_insert($s, $p = array()) { return 1; }
function db_val($s, $p = array(), $d = null) { return $d; }
function col_ok($t, $c) { return false; }
function stats_bump($k, $v = 1) {}
function fail($c, $m) { throw new RuntimeException($m); }
function ok($d) {}
function client_ip() { return '127.0.0.1'; }
function rate_limit($k, $n, $w) { return true; }
function net_channel_cooling($n) { return false; }
function net_channel_cool($n, $sec) {}

require APP_ROOT . '/app/zhipu.php';
require APP_ROOT . '/app/jev.php';
require APP_ROOT . '/app/moderation.php';

$title  = 'Kimi 作品排行榜上线：六维算法给 AI 作品打分，谁才是 SSS？';
$author = '银色子弹-Silver';
$text   = '';
$desc   = "AI 一天能生成几百个作品，但没人告诉你哪个值得玩。\n\n于是我做了一个排行榜：把 Kimi 社区里的作品收进来，用一套六维算法打分、排名、评等级。\n\n网站：https://kimi-game-rank.wuaze.com\n开源：https://github.com/silverbullet-liang/kimi-game-rank （AGPL-3.0）\n\n#Kimi #AI作品 #排行榜 #开源";

function clean_urls(string $s): string {
    return trim(preg_replace('#https?://\S+#iu', ' ', $s));
}

$cases = array(
    'A 旧口径(标题+简介+附言)' => trim($title . "\n" . $desc . "\n" . $text),
    'B 新口径(标题+作者+附言)' => clean_urls($title . "\n" . $author . "\n" . $text),
);

foreach ($cases as $name => $payload) {
    $v = moderate_text(mb_substr($payload, 0, 2000, 'UTF-8'), 'message', 0);
    printf("%s => ok=%s stage=%s reason=%s flag=%s\n",
        $name,
        !empty($v['ok']) ? 'true' : 'false',
        (string)($v['stage'] ?? ''),
        (string)($v['reason'] ?? ''),
        (string)($v['flag'] ?? '')
    );
}
