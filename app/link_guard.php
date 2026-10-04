<?php
/**
 * 链接守卫：从内容里找出域名，判断该不该拦。
 * ------------------------------------------------------------
 * 两道判据，都不把用户内容发给任何第三方：
 *
 *   1) 规律域名（纯代码判据，无数据文件）—— 主体是纯数字，且满足「短串重复 / 回文 /
 *      连续序列」的 .com / .cc 域名。这类域名没什么正经用途：
 *      111111.com、121212.com、125125.com、125521.cc 都是同一批人在批量注册。
 *      长度门槛设在 5 位，是为了不误伤 163.com / 360.com / 51.com 这类真实老站。
 *
 *   2) 公开规则集（在线拉取，**本地不保存**）—— AdGuard 公开发布的广告/恶意域名
 *      过滤列表。只在真正需要判定的那一次请求里联网拉取、解析进内存，用完即弃：
 *      不写盘、不留缓存、不在本地保存任何副本。拉取失败或超时一律「放行」，
 *      绝不牵连正常流程。
 *
 * 图片里出现这类域名基本只有导流一种用途，所以图片侧命中即拒；
 * 文本侧默认同样拦截，可用 link_guard.block_text 关掉。
 */
declare(strict_types=1);

/** 从文本里提取域名（去重，按出现顺序，最多 $limit 个） */
function link_guard_extract_hosts(string $text, int $limit = 8): array
{
    $text = (string)$text;
    if ($text === '') { return array(); }
    /* TLD 至少两个字母，因此 1.2.3.4 这类 IP 不会被当成域名；
       前面排除 @ 与单词字符，避免把邮箱域名或长串里的片段抠出来。 */
    $re = '#(?<![\w.@-])((?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,12})(?![\w.-])#i';
    if (!preg_match_all($re, $text, $m)) { return array(); }
    $out = array();
    foreach ($m[1] as $h) {
        $h = strtolower(rtrim((string)$h, '.'));
        if ($h === '' || strlen($h) > 191) { continue; }
        if (!isset($out[$h])) { $out[$h] = 1; }
        if (count($out) >= $limit) { break; }
    }
    return array_keys($out);
}

/**
 * 规律域名判据。只针对 .com / .cc，且主体全为数字。
 * 三条规律：短串重复（111111 / 121212 / 125125）、回文（125521）、连续序列（123456）。
 */
function link_guard_is_pattern_host(string $host): bool
{
    $host = strtolower(trim($host));
    if (!preg_match('#\.(com|cc)$#', $host)) { return false; }

    $name = substr($host, 0, (int)strrpos($host, '.'));
    if ($name === '' || !ctype_digit($name)) { return false; }

    $len = strlen($name);
    if ($len < 5 || $len > 15) { return false; }      // 163 / 360 / 51 这类短域名不在射程内

    /* 单字符或短串整体重复：111111、121212、125125 */
    if (preg_match('/^(\d{1,4})\1+$/', $name)) { return true; }

    /* 回文：125521 */
    if ($name === strrev($name)) { return true; }

    /* 连续升序或降序：123456、654321 */
    $seq = '01234567890123456789';
    if (strpos($seq, $name) !== false || strpos(strrev($seq), $name) !== false) { return true; }

    return false;
}

/**
 * 在线规则集：本次请求内只拉取一次，解析进内存后即用即弃。
 * 拉取失败返回空集（此后不再重试），因此最坏也只是少一道判据，不会拖慢或报错。
 */
function link_guard_ruleset(): array
{
    static $set = null;
    if ($set !== null) { return $set; }
    $set = array();
    if (!function_exists('curl_init')) { return $set; }

    $f = link_guard_fetch();
    if (empty($f['ok'])) { return $set; }

    foreach (link_guard_parse_rules((string)$f['text']) as $h) { $set[$h] = 1; }
    if ($set) { app_log('link_guard 在线规则集：' . count($set) . ' 条（不落盘）'); }
    return $set;
}

/** 在规则集里查域名与其各级父域，返回命中项；未命中返回空串。 */
function link_guard_host_in_set(string $host, array $set): string
{
    $host = strtolower(trim($host));
    if ($host === '' || !$set) { return ''; }
    $parts = explode('.', $host);
    for ($i = 0; $i < count($parts) - 1; $i++) {
        $cand = implode('.', array_slice($parts, $i));
        if (isset($set[$cand])) { return $cand; }
    }
    return '';
}

/**
 * 查规则：先查域名本身，再逐级查父域（子域常被单独列出）。
 * 返回命中的那条规则域名；未命中返回空串。同请求内结果缓存。
 */
function link_guard_rule_hit(string $host): string
{
    static $memo = array();

    $host = strtolower(trim($host));
    if ($host === '') { return ''; }
    if (array_key_exists($host, $memo)) { return $memo[$host]; }

    return $memo[$host] = link_guard_host_in_set($host, link_guard_ruleset());
}

/**
 * 综合判定：返回命中的 array(array(host, why))，未命中返回空数组。
 * why 为 pattern（规律域名）或 rule（规则集）。
 */
function link_guard_check(string $text): array
{
    $hits = array();
    foreach (link_guard_extract_hosts($text) as $h) {
        if (link_guard_is_pattern_host($h)) { $hits[] = array('host' => $h, 'why' => 'pattern'); continue; }
        $r = link_guard_rule_hit($h);
        if ($r !== '') { $hits[] = array('host' => $h, 'why' => 'rule'); }
    }
    return $hits;
}

/**
 * 解析 Adblock 语法的规则文本，取出其中的纯域名。
 * 只认 `||domain^` 这一种形式：例外规则（@@）、正则、含通配或路径的规则一律跳过 ——
 * 拿不准的规则宁可不要，误拦正常站点比漏放广告更伤。
 */
function link_guard_parse_rules(string $text): array
{
    $out = array();
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '!' || $line[0] === '[') { continue; }
        if (strpos($line, '@@') === 0) { continue; }
        if (strpos($line, '||') !== 0) { continue; }

        $body = substr($line, 2);
        /* ^ 是域名结束分隔符；它若出现在中间（如 ||a.b.c^d），说明这是一条复合规则，
           不是单纯的域名规则，直接跳过 —— 否则会把 a.b.cd 这种莫名其妙的东西当域名收下。 */
        if (strpos($body, '^') !== false && substr($body, -1) !== '^') { continue; }
        $d = str_replace('^', '', $body);
        if ($d === '' || strpos($d, '*') !== false || strpos($d, '/') !== false || strpos($d, '|') !== false) { continue; }
        $d = strtolower(rtrim($d, '.'));
        if (strlen($d) > 191) { continue; }
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,12}$/', $d)) { continue; }

        $out[$d] = 1;
    }
    return array_keys($out);
}

/** 依次尝试各镜像源拉取规则文本；返回 array(ok, text, url)。超时偏短，避免拖住发言。 */
function link_guard_fetch(): array
{
    $urls = (array)cfg('adblock.sources', array(
        'https://adguardteam.github.io/AdGuardSDNSFilter/Filters/filter.txt',
        'https://raw.gitmirror.com/AdguardTeam/AdGuardSDNSFilter/master/Filters/filter.txt',
        'https://ghproxy.net/https://raw.githubusercontent.com/AdguardTeam/AdGuardSDNSFilter/master/Filters/filter.txt',
    ));
    foreach ($urls as $u) {
        $u = trim((string)$u);
        if ($u === '' || !function_exists('curl_init')) { continue; }
        $ch = curl_init($u);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $code === 200 && strlen($body) > 100000) {
            return array('ok' => true, 'text' => $body, 'url' => $u);
        }
        app_log('link_guard fetch failed: ' . $u . ' http=' . $code);
    }
    return array('ok' => false, 'text' => '', 'url' => '');
}
