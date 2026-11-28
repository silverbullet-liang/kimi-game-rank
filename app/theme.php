<?php
/**
 * 内置主题服务端渲染（themes/*.theme.json）
 * ------------------------------------------------------------
 * 节日与内置主题的数据全部在 JSON 里：配色 token、图标 path、装饰层、背景层。
 * 这里在首屏把「变量 + 背景层 + 装饰层」直接写进 HTML：
 *   · 零额外请求（不再有 skins/*.css）；
 *   · 无样式闪动（head 内联，解析期即生效）；
 *   · 明暗两套 token 同时下发，切换 data-theme 即时生效。
 * 只读本仓库自带文件；用户投稿主题仍由前端 theme-json.js 严格校验。
 */

define('THEME_DIR', dirname(__DIR__) . '/themes');

/** 设计风格皮肤：仍是 CSS 文件；glass 为 app.css 默认样式，无文件 */
function theme_style_keys()
{
    return array('glass', 'md3', 'pixel', 'sketch', 'brutal');
}

/** 主题文件名白名单（与前端 theme-json.js 的正则一致） */
function theme_key_ok($key)
{
    return is_string($key) && preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $key) === 1;
}

/** 读取内置主题；风格皮肤与不存在的键返回 null */
function theme_load($key)
{
    if (!theme_key_ok($key) || $key === 'default' || in_array($key, theme_style_keys(), true)) {
        return null;   /* default 就是 app.css 默认样式，无需注入 */
    }
    $path = THEME_DIR . '/' . $key . '.theme.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** 单个 CSS 值：只接受颜色、渐变、长度与阴影；拒绝 url()/表达式/结构字符 */
function theme_val_ok($v)
{
    if (!is_string($v)) {
        return false;
    }
    $v = trim($v);
    if ($v === '' || strlen($v) > 160) {
        return false;
    }
    return preg_match('/url\s*\(|expression|@import|javascript:|!important|var\s*\(|[{}<>;]/i', $v) !== 1;
}

/** 可覆写的设计 token 白名单（与 app.css 的变量同名） */
function theme_token_keys()
{
    return array(
        '--bg-1', '--bg-2', '--surface', '--surface-solid', '--border', '--border-soft',
        '--text', '--text-2', '--text-3', '--accent', '--accent-2', '--accent-ink',
        '--shadow', '--shadow-sm', '--radius-lg', '--radius-md', '--radius-sm',
        '--msg-bg', '--msg-fg', '--msg-mine-bg', '--msg-mine-fg',
        '--tab-active-bg', '--tab-active-fg', '--toast-bg', '--toast-fg',
        '--toggle-on', '--slider-fill',
    );
}

/** 把一组 token 变成声明串 */
function theme_decls(array $set)
{
    $allow = theme_token_keys();
    $out = array();
    foreach ($set as $k => $v) {
        if (!in_array($k, $allow, true) || !theme_val_ok($v)) {
            continue;
        }
        $out[] = $k . ':' . trim($v);
    }
    return implode(';', $out);
}

/** 装饰层 HTML：只保留 div/span/i 且 class 以 fd- 开头，非法即整体丢弃 */
function theme_deco_html(array $t)
{
    if (!isset($t['deco']['html']) || !is_string($t['deco']['html'])) {
        return '';
    }
    $html = $t['deco']['html'];
    if ($html === '' || strlen($html) > 1200) {
        return '';
    }
    if (preg_match('/style\s*=|on[a-z]+\s*=|javascript:/i', $html)) {
        return '';
    }
    /* 标签只允许 div/span/i；class 只允许 fd- 前缀（可多个） */
    if (!preg_match_all('/<\s*\/?\s*([a-z]+)/i', $html, $m)) {
        return '';
    }
    foreach ($m[1] as $tag) {
        if (!in_array(strtolower($tag), array('div', 'span', 'i'), true)) {
            return '';
        }
    }
    if (!preg_match_all('/class\s*=\s*"([^"]*)"/i', $html, $c)) {
        return '';
    }
    foreach ($c[1] as $cls) {
        foreach (preg_split('/\s+/', trim($cls)) as $one) {
            if ($one !== '' && strpos($one, 'fd-') !== 0) {
                return '';
            }
        }
    }
    return $html;
}

/** 装饰层 CSS：逐条过滤危险声明（data URL、import、content、选择器逃逸） */
function theme_deco_css(array $t)
{
    if (!isset($t['deco']['css']) || !is_string($t['deco']['css'])) {
        return '';
    }
    $css = $t['deco']['css'];
    if ($css === '' || strlen($css) > 4000) {
        return '';
    }
    if (preg_match('/url\s*\(|@import|expression|javascript:|content\s*:|<\/?[a-z]/i', $css)) {
        return '';
    }
    return $css;
}

/** 背景层：每层一个 fixed 全屏 div，用 background-position/size 定位 */
function theme_bg_html(array $t)
{
    if (!isset($t['background']) || !is_array($t['background'])) {
        return '';
    }
    $out = array();
    $baseDone = false;
    foreach (array_slice($t['background'], 0, 12) as $l) {
        if (!is_array($l)) {
            continue;
        }
        $type = isset($l['type']) ? $l['type'] : 'css';
        if ($type === 'css') {
            $img = isset($l['css']) ? $l['css'] : '';
            if (!theme_val_ok($img)) {
                continue;
            }
            $decl = 'background-image:' . $img;
        } else {
            $src = isset($l['src']) ? $l['src'] : '';
            if (!is_string($src) || !preg_match('#^data:image/(svg\+xml|png|jpeg|webp)[;,]|^https://#i', $src)) {
                continue;
            }
            if (stripos($src, 'javascript:') !== false) {
                continue;
            }
            $decl = 'background-image:url("' . $src . '")';
        }
        foreach (array('position' => 'background-position', 'size' => 'background-size', 'repeat' => 'background-repeat') as $k => $prop) {
            if (isset($l[$k]) && is_string($l[$k]) && $l[$k] !== '' && strlen($l[$k]) < 60) {
                $decl .= ';' . $prop . ':' . $l[$k];
            }
        }
        if (isset($l['opacity'])) {
            $op = (float) $l['opacity'];
            $decl .= ';opacity:' . max(0, min(1, $op));
        }
        if (isset($l['blend']) && is_string($l['blend']) && preg_match('/^[a-z-]{3,20}$/', $l['blend'])) {
            $decl .= ';mix-blend-mode:' . $l['blend'];
        }
        /* 第一层纯渐变当作底色：暗色模式换用 token 的深色底，避免浅底配浅字 */
        $attr = (!$baseDone && $type === 'css') ? ' data-base="1"' : '';
        if ($attr !== '') { $baseDone = true; }
        $out[] = '<div' . $attr . ' style="' . htmlspecialchars($decl, ENT_QUOTES) . '"></div>';
    }
    return $out ? '<div class="tj-bg" aria-hidden="true">' . implode('', $out) . '</div>' : '';
}


/** 图标覆盖表：只保留白名单位置与合法 path，供前端启动时应用 */
function theme_icons_map(array $t)
{
    if (!isset($t['icons']) || !is_array($t['icons'])) {
        return array();
    }
    $slots = array(
        'nav.total', 'nav.vote', 'nav.gods', 'nav.cold', 'nav.chat', 'nav.submit', 'nav.mine',
        'nav.help', 'nav.feedback', 'nav.admin', 'nav.monitor', 'nav.today',
        'action.back', 'action.close', 'action.more', 'action.vote', 'action.star',
        'action.comment', 'action.reply', 'action.copy', 'action.share', 'action.external',
        'action.edit', 'action.delete', 'action.report', 'action.send', 'action.stop',
        'action.retry', 'action.download', 'action.expand',
        'tab.rank', 'tab.chat', 'tab.mine', 'ui.menu', 'ui.theme',
        'field.search', 'field.password', 'field.emoji', 'field.image', 'field.video',
        'field.upload', 'field.mention', 'field.model', 'field.think', 'field.filter',
        'field.sort', 'field.refresh',
        'media.play', 'media.pause', 'media.fullscreen', 'media.mute',
        'state.loading', 'state.empty', 'state.error', 'state.success', 'state.warn', 'state.offline',
        'user.login', 'user.logout', 'user.avatar', 'user.admin', 'user.verified',
        'theme.light', 'theme.dark', 'theme.auto', 'theme.palette', 'theme.lang',
        'doc.community', 'doc.terms', 'doc.privacy', 'doc.features', 'doc.criteria',
        'doc.ranking', 'doc.changelog', 'doc.ai',
    );
    $out = array();
    foreach ($t['icons'] as $slot => $path) {
        if (!in_array($slot, $slots, true) || !is_string($path) || $path === '' || strlen($path) > 900) {
            continue;
        }
        if (preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9eE,.\- ]+$/', $path) !== 1) {
            continue;
        }
        $out[$slot] = $path;
    }
    return $out;
}

/**
 * 一个内置主题的完整首屏样式：明暗两套 token + 装饰 + 背景层承载规则。
 * 返回 array('css' => string, 'deco' => string, 'bg' => string)
 */
function theme_first_paint(array $t)
{
    $tok = (isset($t['tokens']) && is_array($t['tokens'])) ? $t['tokens'] : array();
    $light = (isset($tok['light']) && is_array($tok['light'])) ? $tok['light'] : array();
    $dark = (isset($tok['dark']) && is_array($tok['dark'])) ? $tok['dark'] : array();
    if (!$light && !$dark) {           /* 兼容旧扁平写法 */
        $light = $tok;
    }
    $css = array();
    $l = theme_decls($light);
    $d = theme_decls($dark);
    if ($l !== '') {
        $css[] = ':root{' . $l . '}';
    }
    if ($d !== '') {
        $css[] = 'html[data-theme="dark"]{' . $d . '}';
    }
    /* 背景层由主题接管：body 让出底色，改用 .tj-bg 的第一层 */
    $css[] = '.tj-bg{position:fixed;inset:0;z-index:-1;pointer-events:none;overflow:hidden}';
    $css[] = '.tj-bg>div{position:fixed;inset:0}';
    /* 暗色：抽掉浅色底图层，改用 token 的深色渐变；SVG 点缀层保留 */
    $css[] = 'html[data-theme="dark"] .tj-bg>div[data-base]{display:none}';
    $css[] = 'html[data-theme="dark"][data-tjbg] body{background:linear-gradient(160deg,var(--bg-1),var(--bg-2) 70%) !important}';
    $css[] = 'html[data-festival="1"] .festival-deco{display:block;position:fixed;inset:0;z-index:3;pointer-events:none;overflow:hidden}';
    $dc = theme_deco_css($t);
    if ($dc !== '') {
        $css[] = $dc;
    }
    return array(
        'css'  => implode("\n", $css),
        'deco' => theme_deco_html($t),
        'bg'   => theme_bg_html($t),
    );
}
