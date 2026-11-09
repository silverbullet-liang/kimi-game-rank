<?php
/**
 * 主题 JSON（theme/1）：服务端准入校验
 * ------------------------------------------------------------
 * 前端已经校验过一次，这里是第二道闸：只做「能不能收」，不做改写。
 * 原则：宁可拒收，不去「修好」——两端各自改写会让同一份文件在不同地方表现不一致。
 */
declare(strict_types=1);

/** 单份主题上限：1 MB */
function theme_json_max_bytes(): int { return 1048576; }

/** 云端保留天数：7 天 */
function theme_json_ttl_days(): int { return 7; }

/**
 * @return array [ok(bool), errors(string[]), meta(array|null)]
 *         meta = ['id'=>..., 'name'=>..., 'bytes'=>..., 'force'=>...]
 */
function theme_json_check(string $raw): array
{
    $max = theme_json_max_bytes();
    if ($raw === '') { return array(false, array('内容为空'), null); }
    if (strlen($raw) > $max) { return array(false, array('超过 1 MB'), null); }

    /* 整份原文的危险模式：不做逐字段修补，直接拒绝 */
    $danger = array(
        '/<\s*script/i'      => '含脚本标签',
        '/javascript\s*:/i'  => '含脚本协议',
        '/\son[a-z]+\s*=/i'  => '含内联事件',
        '/foreignObject/i'   => '含 foreignObject',
        '/@import/i'         => '含 @import',
        '/expression\s*\(/i' => '含 CSS 表达式',
        '/-moz-binding|behavior\s*:/i' => '含旧式脚本绑定',
    );
    foreach ($danger as $re => $why) {
        if (preg_match($re, $raw)) { return array(false, array($why), null); }
    }

    $d = json_decode($raw, true);
    if (!is_array($d)) { return array(false, array('JSON 解析失败'), null); }

    if (!isset($d['schema']) || $d['schema'] !== 'theme/1') {
        return array(false, array('schema 必须是 theme/1'), null);
    }
    $id = isset($d['id']) ? (string)$d['id'] : '';
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $id)) {
        return array(false, array('id 非法'), null);
    }

    $name = theme_json_name($d['name'] ?? null);
    if ($name === '') { return array(false, array('name 缺失或非法'), null); }

    $colour = $d['colour'] ?? null;
    if (is_string($colour)) { $colour = array('accent' => $colour); }
    if (!is_array($colour) || !isset($colour['accent']) || !preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$colour['accent'])) {
        return array(false, array('colour.accent 必须是 #RRGGBB'), null);
    }

    if (isset($d['force']) && !in_array($d['force'], array('auto', 'light', 'dark'), true)) {
        return array(false, array('force 只能是 auto / light / dark'), null);
    }

    /* 外链：只允许 https 与图片 data URL；逐个字段查，防止藏一个 http 的图床 */
    $urls = array();
    if (isset($d['background']) && is_array($d['background'])) {
        if (count($d['background']) > 12) { return array(false, array('background 最多 12 层'), null); }
        foreach ($d['background'] as $layer) {
            if (is_array($layer) && isset($layer['src'])) { $urls[] = (string)$layer['src']; }
        }
    }
    if (isset($d['icons']) && is_array($d['icons'])) {
        foreach ($d['icons'] as $v) {
            if (is_array($v) && isset($v['src'])) { $urls[] = (string)$v['src']; }
        }
    }
    if (isset($d['font']['body']['url'])) { $urls[] = (string)$d['font']['body']['url']; }
    foreach ($urls as $u) {
        if (strpos($u, 'data:') === 0) {
            if (!preg_match('#^data:image/(svg\+xml|png|jpeg|webp|gif)[;,]#i', $u)) {
                return array(false, array('data URL 只接受图片类型'), null);
            }
            continue;
        }
        if (strpos($u, 'https://') !== 0) {
            return array(false, array('外链必须使用 https'), null);
        }
    }

    return array(true, array(), array(
        'id'    => $id,
        'name'  => $name,
        'bytes' => strlen($raw),
        'force' => (string)($d['force'] ?? 'auto'),
    ));
}

/** 取展示名：当前语言缺失就回退中文，再回退第一个非空 */
function theme_json_name($v): string
{
    if (is_string($v)) { return mb_substr(trim($v), 0, 32, 'UTF-8'); }
    if (!is_array($v)) { return ''; }
    foreach (array('zh', 'en', 'ja') as $k) {
        if (isset($v[$k]) && is_string($v[$k]) && trim($v[$k]) !== '') {
            return mb_substr(trim($v[$k]), 0, 32, 'UTF-8');
        }
    }
    return '';
}
