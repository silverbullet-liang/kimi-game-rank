<?php
/**
 * SEO 层（v3.42.0）
 * ------------------------------------------------------------
 * 本站是纯前端 SPA：首屏 HTML 只有骨架，不执行 JS 的搜索引擎抓不到任何内容。
 * 这里在**服务端**按路由补齐三件事，让站点可被正常收录：
 *   1) 每页专属的 title / description / canonical / robots / Open Graph；
 *   2) JSON-LD 结构化数据（WebSite / ItemList / CreativeWork / Article / BreadcrumbList）；
 *   3) 把真实内容服务端渲染进 #view（无 JS 也能读，且含真实 <a> 供爬虫跟随）。
 * 另提供 sitemap.xml 与 robots.txt 的内容生成。
 *
 * 镜像站：站点可能部署到多个域名，相同内容会分散权重。若配置了 site.seo_primary_host
 * 且当前域名不是它，则整站 noindex（robots.txt 亦 Disallow All、sitemap 为空）。
 */
declare(strict_types=1);

/* ============================================================
 * 域名与镜像判定
 * ============================================================ */
function seo_canonical_base(): string
{
    return rtrim((string)cfg('site.url', ''), '/');
}

function seo_host(): string
{
    $h = isset($_SERVER['HTTP_HOST']) ? strtolower(trim((string)$_SERVER['HTTP_HOST'])) : '';
    $p = strpos($h, ':');
    if ($p !== false) { $h = substr($h, 0, $p); }
    return $h;
}

/** 主域名：优先取 site.seo_primary_host，其次退回 site.url 的 host */
function seo_primary_host(): string
{
    $p = trim((string)cfg('site.seo_primary_host', ''));
    if ($p === '') { $p = seo_canonical_base(); }
    $host = strtolower((string)parse_url($p, PHP_URL_HOST));
    return $host !== '' ? $host : $p;
}

/** 当前域名是否为镜像（非主域名）——镜像整站不索引 */
function seo_is_mirror(): bool
{
    $primary = seo_primary_host();
    $host    = seo_host();
    if ($primary === '' || $host === '') { return false; }
    return $host !== $primary;
}

/* ============================================================
 * 路由（与前端 app.js 的路径规则一致）
 *   对外地址一律是普通路径：/rank、/detail/12、/doc/入榜规则
 *   旧地址（?p=rank&arg=12、#/rank/12）继续可用，由 seo_legacy_redirect_url() 301 归一
 * ============================================================ */

/** 已知界面名（内部标识；新增页面必须同时加到 app.js 的 KNOWN 与这里） */
function seo_route_names(): array
{
    return array('rank','detail','chat','mine','login','submit','doc','panel','monitor',
                 'feedback','about','violation','discipline','today');
}

/**
 * 内部标识 → 对外 URL 段。
 * 对外地址一律用读者一眼能懂的名词：/rankings、/works/12、/docs/admission、/me …
 * 改这里即改全站地址（页面路由、站内链接、canonical、sitemap 同时生效）。
 */
function seo_route_segments(): array
{
    return array(
        'rank'       => 'rankings',
        'detail'     => 'works',
        'chat'       => 'chat',
        'mine'       => 'me',
        'login'      => 'login',
        'submit'     => 'submit',
        'doc'        => 'docs',
        'feedback'   => 'feedback',
        'about'      => 'about',
        'violation'  => 'banned',
        'discipline' => 'notices',
        'panel'      => 'admin',
        'monitor'    => 'monitor',
        'today'      => 'today',
    );
}

/** 曾用过的 URL 段：命中即 301 到新段（旧链接、旧收录都不会失效） */
function seo_legacy_segments(): array
{
    return array('rank', 'detail', 'doc', 'mine', 'violation', 'discipline', 'panel');
}

/** 文档名 ↔ URL 段（地址用英文短名，中文名仍可访问并 301 过来） */
function seo_doc_slugs(): array
{
    return array(
        '功能说明'    => 'features',
        'AI 使用说明' => 'ai',
        '社区公约'    => 'community',
        '评分标准'    => 'scoring',
        '入榜规则'    => 'admission',
        '用户协议'    => 'terms',
        '隐私政策'    => 'privacy',
        '更新日志'    => 'changelog',
    );
}

/** 站点根路径：部署在子目录时返回 /kimgr 之类的前缀，根目录部署返回空串 */
function seo_base_path(): string
{
    $sn  = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($sn)), '/');
    return ($dir === '' || $dir === '.' || $dir === '/') ? '' : $dir;
}

/** 当前请求的路径片段（剥离站点前缀、已解码、忽略空段） */
function seo_path_segments(): array
{
    $raw  = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    $path = rawurldecode((string)parse_url($raw, PHP_URL_PATH));
    $base = seo_base_path();
    if ($base !== '' && strpos($path, $base) === 0) { $path = substr($path, strlen($base)); }
    $seg = array();
    foreach (explode('/', trim($path, '/')) as $s) {
        if ($s !== '') { $seg[] = $s; }
    }
    return $seg;
}

function seo_request_route(): array
{
    $known = seo_route_names();

    /* 主路径：/rankings 、/works/12 、/docs/admission */
    $segs  = seo_path_segments();
    $seg0  = $segs ? strtolower(trim($segs[0])) : '';
    $bySeg = array_flip(seo_route_segments());          // 段 → 内部名
    $name  = isset($bySeg[$seg0]) ? $bySeg[$seg0] : '';
    if ($name === '' && in_array($seg0, seo_legacy_segments(), true)) {
        $name = $seg0;                                  // 旧段（与内部名同名），由 301 归一新段
    }
    $arg   = count($segs) > 1 ? implode('/', array_slice($segs, 1)) : '';
    if ($name === 'doc' && $arg !== '') {
        $bySlug = array_flip(seo_doc_slugs());          // 段 → 中文文档名
        if (isset($bySlug[$arg])) { $arg = $bySlug[$arg]; }
    }

    /* 兼容旧查询式地址：?p=detail&arg=12 、?p=detail/12 、?chat */
    if (!in_array($name, $known, true)) {
        $name = '';
        $p    = isset($_GET['p']) ? trim((string)$_GET['p']) : '';
        if ($p === '') {
            foreach ($known as $k) {
                if (isset($_GET[$k])) { $p = $k; $v = trim((string)$_GET[$k]); if ($v !== '') { $arg = $v; } break; }
            }
        }
        if ($p !== '') {
            $sp   = explode('/', $p);
            $name = strtolower(trim($sp[0]));
            if ($arg === '' && count($sp) > 1) { $arg = $sp[1]; }
            if ($arg === '' && isset($_GET['arg'])) { $arg = trim((string)$_GET['arg']); }
        }
        if (!in_array($name, $known, true)) { $name = 'rank'; }
    }
    $params = array();
    foreach (array('id','cat','category','board','q') as $k) {
        if (isset($_GET[$k]) && trim((string)$_GET[$k]) !== '') { $params[$k] = trim((string)$_GET[$k]); }
    }
    return array('name' => $name, 'sub' => $arg, 'params' => $params);
}

/** 构造本站绝对地址（canonical / 结构化数据用） */
/** 规范化路径：/detail/12 、/doc/入榜规则（分段编码，中文原样进 URL） */
function seo_path_of(string $name, string $arg = ''): string
{
    $segs = seo_route_segments();
    if ($name === 'doc' && $arg !== '') {
        $slugs = seo_doc_slugs();
        if (isset($slugs[$arg])) { $arg = $slugs[$arg]; }
    }
    $path = '/' . rawurlencode(isset($segs[$name]) ? $segs[$name] : $name);
    $arg  = trim($arg, '/');
    if ($arg !== '') {
        $path .= '/' . implode('/', array_map('rawurlencode', explode('/', $arg)));
    }
    return $path;
}

function seo_abs(string $name, string $arg = '', array $extra = array()): string
{
    $url = seo_canonical_base() . seo_base_path() . seo_path_of($name, $arg);
    if (!empty($extra)) { $url .= '?' . http_build_query($extra); }
    return $url;
}

/** 站内链接（相对地址，爬虫可解析） */
function seo_rel(string $name, string $arg = '', array $extra = array()): string
{
    $url = seo_base_path() . seo_path_of($name, $arg);
    if (!empty($extra)) { $url .= '?' . http_build_query($extra); }
    return $url;
}

/** 旧地址归一：命中查询式参数时返回 301 目标，否则返回空串（由 index.php 处理） */
/** 旧地址归一：路径与规范地址不一致时返回 301 目标，否则返回空串（由 index.php 处理） */
function seo_legacy_redirect_url(): string
{
    $r     = seo_request_route();
    $canon = seo_base_path() . seo_path_of($r['name'], $r['sub']);
    $here  = seo_base_path() . '/' . implode('/', array_map('rawurlencode', seo_path_segments()));
    $root  = seo_base_path() . '/';

    $queryStyle = isset($_GET['p']) || isset($_GET['arg']);
    if (!$queryStyle) {
        foreach (seo_route_names() as $k) {
            if (isset($_GET[$k])) { $queryStyle = true; break; }
        }
    }
    /* 已经是规范地址（或站点根）就不动，查询参数原样保留 */
    if (!$queryStyle && ($here === $canon || $here === $root)) { return ''; }

    $skip  = array_merge(array('p', 'arg'), seo_route_names());
    $extra = array();
    foreach ($_GET as $k => $v) {
        if (in_array($k, $skip, true) || !is_string($v) || $v === '') { continue; }
        $extra[$k] = $v;
    }
    return $canon . (!empty($extra) ? '?' . http_build_query($extra) : '');
}

/* ============================================================
 * 每页 meta
 * ============================================================ */
function seo_meta_for(array $route): array
{
    $site  = (string)cfg('site.name', 'Kimi游戏榜');
    $base  = seo_canonical_base() . seo_base_path() . '/';
    $name  = $route['name'];
    $sub   = $route['sub'];

    $noindex = ($name === 'mine' || $name === 'login' || $name === 'panel'
                || $name === 'monitor' || $name === 'chat' || $name === 'feedback'
                || $name === 'violation');

    $title = $site . ' · 六维综合排行榜';
    $desc  = $site . '：收录 Kimi 社区公开作品的六维评分榜单，覆盖游戏、工具、文学与二创，支持搜索、评论与 AI 问答。';
    $canon = $base;
    $type  = 'website';
    $image = '';
    $jsonld = array();

    /* 站点级结构化数据：WebSite + 站内搜索 */
    $jsonld[] = array(
        '@context' => 'https://schema.org', '@type' => 'WebSite',
        'name' => $site, 'url' => $base, 'inLanguage' => 'zh-CN',
        'potentialAction' => array(
            '@type' => 'SearchAction',
            'target' => array('@type' => 'EntryPoint', 'urlTemplate' => seo_abs('rank') . '?q={search_term_string}'),
            'query-input' => 'required name=search_term_string',
        ),
    );

    if ($name === 'rank') {
        $canon = seo_abs('rank');
        $title = $site . ' · Kimi 社区作品六维排行榜';
        $desc  = 'Kimi 社区公开作品排行榜：按作品质量、作者投入、社区认可三支柱六维综合评分排序，覆盖游戏、工具、文学与二创，支持搜索、评论与 AI 问答。';
        $items = array();
        try {
            $page = works_list_page('all', 'total', '', 1, 10, 0, true);
            foreach ((array)$page['items'] as $i => $w) {
                $items[] = array('@type' => 'ListItem', 'position' => $i + 1,
                    'url' => seo_abs('detail', (string)(int)$w['id']), 'name' => (string)$w['title']);
            }
        } catch (Throwable $e) { $items = array(); }
        if (!empty($items)) {
            $jsonld[] = array('@context' => 'https://schema.org', '@type' => 'ItemList',
                'name' => $site . ' 榜单', 'itemListElement' => $items);
        }
    } elseif ($name === 'detail') {
        $id  = (int)($route['params']['id'] ?? ($sub !== '' ? $sub : 0));
        $row = ($id > 0) ? seo_work_row($id) : null;
        if ($row !== null) {
            $t     = trim((string)$row['title']);
            $intro = trim((string)$row['intro']);
            $canon = seo_abs('detail', (string)$id);
            $title = $t . ' · ' . $site;
            $desc  = $intro !== '' ? mb_substr($intro, 0, 120, 'UTF-8') : ($t . '：Kimi 社区作品，六维评分与点评。');
            $type  = 'article';
            $imgs  = json_decode((string)$row['images'], true);
            if (is_array($imgs) && !empty($imgs)) { $image = (string)$imgs[0]; }
            $work = array(
                '@context' => 'https://schema.org', '@type' => 'CreativeWork',
                'name' => $t, 'url' => $canon, 'inLanguage' => 'zh-CN',
                'genre' => (string)$row['category'],
            );
            if ($intro !== '') { $work['description'] = mb_substr($intro, 0, 300, 'UTF-8'); }
            if (trim((string)$row['author_name']) !== '') {
                $work['author'] = array('@type' => 'Person', 'name' => trim((string)$row['author_name']));
            }
            if (trim((string)$row['created_at']) !== '') { $work['datePublished'] = str_replace(' ', 'T', (string)$row['created_at']) . '+00:00'; }
            $n = (int)$row['like_num'] + (int)$row['comment_num'] + (int)$row['collect_num'];
            if ($n > 0 && (int)$row['total_score'] > 0) {
                $work['aggregateRating'] = array('@type' => 'AggregateRating',
                    'ratingValue' => (int)$row['total_score'], 'bestRating' => 1200, 'ratingCount' => $n);
            }
            $jsonld[] = $work;
            $jsonld[] = array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
                'itemListElement' => array(
                    array('@type' => 'ListItem', 'position' => 1, 'name' => '榜单', 'item' => $base),
                    array('@type' => 'ListItem', 'position' => 2, 'name' => $t, 'item' => $canon),
                ));
        } else {
            $canon = $base;
            $title = '作品不存在 · ' . $site;
            $desc  = '该作品不存在或已下架。';
            $noindex = true;
        }
    } elseif ($name === 'doc') {
        $docName = seo_doc_name($sub);
        $canon = seo_abs('doc', $docName);
        $title = $docName . ' · ' . $site;
        $txt   = seo_doc_text($docName);
        $desc  = $txt !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $txt), 0, 120, 'UTF-8') : ($site . ' 文档：' . $docName);
        $type  = 'article';
        $jsonld[] = array('@context' => 'https://schema.org', '@type' => 'Article',
            'headline' => $docName, 'description' => $desc, 'url' => $canon,
            'inLanguage' => 'zh-CN', 'publisher' => array('@type' => 'Organization', 'name' => $site));
    } elseif ($name === 'discipline') {
        $canon = seo_abs('discipline');
        $title = '违纪通报 · ' . $site;
        $desc  = '本站违纪处理记录公开页。';
    } elseif ($name === 'about') {
        $canon = seo_abs('about');
        $title = '关于 · ' . $site;
        $desc  = '关于 ' . $site . '：评分算法、收录范围与使用说明。';
    }

    $robots = seo_is_mirror() ? 'noindex,follow' : ($noindex ? 'noindex,follow' : 'index,follow');
    return array('title' => $title, 'description' => $desc, 'canonical' => $canon,
        'robots' => $robots, 'type' => $type, 'image' => $image, 'jsonld' => $jsonld);
}

/* ============================================================
 * 界面语言（与 index.php 注入的 window.__LANG 同源：cookie kimgr_lang）
 * ============================================================ */
function seo_lang(): string
{
    $l = isset($_COOKIE['kimgr_lang']) ? strtolower((string)$_COOKIE['kimgr_lang']) : '';
    if (in_array($l, array('zh', 'en', 'ja'), true)) { return $l; }
    /* 首次访问还没有 cookie：按浏览器首选语言判断，与前端 navigator.languages 的顺序一致 */
    $al = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower((string)$_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
    foreach (explode(',', $al) as $part) {
        $tag = trim(explode(';', $part)[0]);
        if (strpos($tag, 'ja') === 0) { return 'ja'; }
        if (strpos($tag, 'en') === 0) { return 'en'; }
        if (strpos($tag, 'zh') === 0) { return 'zh'; }
    }
    return 'zh';
}

/** 服务端文案三语取值；未提供对应译文时退回中文 */
function seo_t(string $zh, string $en = '', string $ja = ''): string
{
    $l = seo_lang();
    if ($l === 'en' && $en !== '') { return $en; }
    if ($l === 'ja' && $ja !== '') { return $ja; }
    return $zh;
}

/* ============================================================
 * 服务端渲染内容（放进 #view，无 JS 也能读；含真实链接）
 * ============================================================ */
function seo_body_html(array $route): string
{
    $name = $route['name'];
    if ($name === 'rank')  { return seo_body_rank(); }
    if ($name === 'detail'){ return seo_body_detail((int)($route['params']['id'] ?? ($route['sub'] !== '' ? $route['sub'] : 0))); }
    if ($name === 'doc')   { return seo_body_doc(seo_doc_name($route['sub'])); }
    if ($name === 'about') { return seo_body_about(); }
    return '';   // 其它页面不渲染（保持骨架）
}

function seo_body_rank(): string
{
    $site = e((string)cfg('site.name', 'Kimi游戏榜'));
    $h  = '<article class="seo-ssr">';
    $h .= '<h1>' . $site . ' · ' . seo_t(
        'Kimi 社区作品六维排行榜',
        'Six-dimension leaderboard of Kimi community works',
        'Kimiコミュニティ作品の六次元ランキング'
    ) . '</h1>';
    $h .= '<p>' . seo_t(
        '收录 Kimi 社区公开作品，按「作品质量 / 作者投入 / 社区认可」三支柱六维综合评分排序。',
        'Public works from the Kimi community, ordered by a six-dimension score built on three pillars: craft, effort, and community recognition.',
        'Kimiコミュニティの公開作品を、「作品の質 / 作者の取り組み / コミュニティの評価」の三本柱からなる六次元スコアで並べています。'
    ) . '</p>';
    $total = 0; $rows = array();
    try {
        $page = works_list_page('all', 'total', '', 1, 30, 0, true);
        $rows = (array)$page['items']; $total = (int)$page['total'];
    } catch (Throwable $e) { }
    if (!empty($rows)) {
        $h .= '<h2>' . seo_t('榜单', 'Ranking', 'ランキング') . '</h2><ol>';
        foreach ($rows as $w) {
            $h .= '<li><a href="' . e(seo_rel('detail', (string)(int)$w['id'])) . '">'
                . e((string)$w['title']) . '</a> — ' . e((string)$w['author']) . ' · '
                . e((string)$w['category_name']) . ' · ' . seo_t('总分', 'Score', '総合スコア') . ' ' . (int)$w['total']
                . ' · ' . seo_t('评级', 'Grade', '評価') . ' ' . e((string)$w['rating']) . '</li>';
        }
        $h .= '</ol>';
        $h .= '<p>' . seo_t('共收录 ', 'Works listed: ', '収録作品数：') . $total . seo_t(' 件作品。', '', ' 件。');
    } else {
        $h .= '<p>' . seo_t('榜单加载中。', 'Loading the ranking…', 'ランキングを読み込み中…') . '</p>';
    }
    $h .= '<a href="' . e(seo_rel('doc', '评分标准')) . '">' . seo_doc_title('评分标准') . '</a> · '
        . '<a href="' . e(seo_rel('doc', '入榜规则')) . '">' . seo_doc_title('入榜规则') . '</a> · '
        . '<a href="' . e(seo_rel('doc', '功能说明')) . '">' . seo_doc_title('功能说明') . '</a></p>';
    return $h . '</article>';
}

function seo_body_detail(int $id): string
{
    if ($id <= 0) { return '<article class="seo-ssr"><h1>' . seo_t('作品不存在', 'Work not found', '作品が見つかりません') . '</h1><p><a href="' . e(seo_rel('rank')) . '">' . seo_back() . '</a></p></article>'; }
    $r = seo_work_row($id);
    if ($r === null) {
        return '<article class="seo-ssr"><h1>' . seo_t('作品不存在或已下架', 'Work not found or taken down', '作品が見つからないか公開終了しました') . '</h1><p><a href="' . e(seo_rel('rank')) . '">' . seo_back() . '</a></p></article>';
    }
    $cats  = works_cats();
    $cat   = (string)$r['category'];
    $intro = trim((string)$r['intro']);
    $h  = '<article class="seo-ssr">';
    $h .= '<h1>' . e((string)$r['title']) . '</h1>';
    $h .= '<p>' . seo_t('作者：', 'By ', '作者：') . e((string)$r['author_name'])
        . ' · ' . seo_t('分类：', 'Category: ', 'カテゴリ：') . e(isset($cats[$cat]) ? $cats[$cat] : '游戏类')
        . ' · ' . seo_t('总分', 'Score', '総合スコア') . ' ' . (int)$r['total_score'] . '/1200'
        . ' · ' . seo_t('评级', 'Grade', '評価') . ' ' . e((string)$r['rating']) . '</p>';
    if ($intro !== '') { $h .= '<h2>' . seo_t('作品简介', 'About', '作品紹介') . '</h2><p>' . nl2br(e(mb_substr($intro, 0, 600, 'UTF-8'))) . '</p>'; }
    $sd = json_decode((string)$r['score'], true);
    if (is_array($sd) && !empty($sd)) {
        $labels = array(
            'experience' => array('可玩性', 'Playability', 'プレイ性'),
            'creativity' => array('创意', 'Creativity', '創造性'),
            'depth'      => array('深度', 'Depth', '深さ'),
            'cost'       => array('体量', 'Volume', 'ボリューム'),
            'attitude'   => array('用心', 'Effort', '丁寧さ'),
            'heat'       => array('口碑', 'Word of mouth', '評判'),
        );
        $h .= '<h2>' . seo_t('六维评分', 'Six dimensions', '六次元スコア') . '</h2><ul>';
        foreach ($labels as $k => $lab) {
            if (isset($sd[$k])) { $h .= '<li>' . seo_t($lab[0], $lab[1], $lab[2]) . '：' . (int)$sd[$k] . '/200</li>'; }
        }
        $h .= '</ul>';
    }
    $link = work_link($r);
    if ($link !== '') { $h .= '<p><a href="' . e($link) . '" rel="noopener nofollow">' . seo_t('打开作品', 'Open the work', '作品を開く') . '</a></p>'; }
    $h .= '<p><a href="' . e(seo_rel('rank')) . '">' . seo_back() . '</a></p></article>';
    return $h;
}

function seo_body_doc(string $docName): string
{
    $txt = seo_doc_text($docName, seo_lang());
    $h  = '<article class="seo-ssr"><h1>' . seo_doc_title($docName) . '</h1>';
    if ($txt !== '') {
        $paras = preg_split('/\n{2,}/u', trim($txt));
        $n = 0;
        foreach ((array)$paras as $p) {
            $p = trim((string)$p);
            if ($p === '') { continue; }
            $h .= '<p>' . nl2br(e($p)) . '</p>';
            if (++$n >= 12) { break; }     // 只取前若干段，避免整页塞满
        }
    }
    $h .= '<p><a href="' . e(seo_rel('rank')) . '">' . seo_back() . '</a></p></article>';
    return $h;
}

function seo_body_about(): string
{
    $site = e((string)cfg('site.name', 'Kimi游戏榜'));
    return '<article class="seo-ssr"><h1>' . seo_t('关于 ', 'About ', '') . $site . '</h1>'
        . '<p>' . $site . seo_t(
            ' 收录 Kimi 社区公开作品，按「作品质量 / 作者投入 / 社区认可」三支柱六维综合评分排序。',
            ' lists public works from the Kimi community, ordered by a six-dimension score built on three pillars: craft, effort, and community recognition.',
            ' は Kimiコミュニティの公開作品を、「作品の質 / 作者の取り組み / コミュニティの評価」の三本柱からなる六次元スコアで並べています。'
        ) . '</p>'
        . '<p><a href="' . e(seo_rel('doc', '评分标准')) . '">' . seo_doc_title('评分标准') . '</a> · <a href="' . e(seo_rel('doc', '功能说明')) . '">' . seo_doc_title('功能说明') . '</a> · <a href="' . e(seo_rel('rank')) . '">' . seo_t('返回榜单', 'Back to the ranking', 'ランキングへ戻る') . '</a></p></article>';
}

/* ============================================================
 * 数据小工具
 * ============================================================ */
/** 取未下架作品行（详情 SEO 用） */
function seo_work_row(int $id)
{
    if ($id <= 0) { return null; }
    try {
        $r = db_one('SELECT id, title, intro, author_name, category, images, score, total_score, rating,
                     like_num, comment_num, collect_num, html_url, share_link, created_at
                     FROM works WHERE id = ? AND is_hidden = 0', array($id));
    } catch (Throwable $e) { return null; }
    return is_array($r) ? $r : null;
}

/** 中文文档名 → 英文文件 slug */
function seo_doc_slug(string $docName): string
{
    $map = array(
        '功能说明' => 'features', 'AI 使用说明' => 'ai', '社区公约' => 'community',
        '评分标准' => 'scoring', '入榜规则' => 'admission', '用户协议' => 'terms',
        '隐私政策' => 'privacy', '更新日志' => 'changelog',
    );
    return isset($map[$docName]) ? $map[$docName] : 'scoring';
}

/** 文档标题三语 */
function seo_doc_title(string $docName): string
{
    $map = array(
        '功能说明'    => array('功能说明', 'Feature Guide', '機能説明'),
        'AI 使用说明' => array('AI 使用说明', 'AI Guide', 'AI ガイド'),
        '社区公约'    => array('社区公约', 'Community Rules', 'コミュニティ規約'),
        '评分标准'    => array('评分标准', 'Scoring Standard', '評価基準'),
        '入榜规则'    => array('入榜规则', 'Ranking Rules', 'ランク入りルール'),
        '用户协议'    => array('用户协议', 'Terms of Service', '利用規約'),
        '隐私政策'    => array('隐私政策', 'Privacy Policy', 'プライバシーポリシー'),
        '更新日志'    => array('更新日志', 'Changelog', '更新履歴'),
    );
    $x = isset($map[$docName]) ? $map[$docName] : array($docName, $docName, $docName);
    return e(seo_t($x[0], $x[1], $x[2]));
}

/** 「← 返回榜单」 */
function seo_back(): string
{
    return seo_t('← 返回榜单', '← Back to the ranking', '← ランキングへ戻る');
}

/** 文档名白名单（与前端 pages/doc.js 的 ALLOWED 一致） */
function seo_doc_names(): array
{
    return array('功能说明', 'AI 使用说明', '社区公约', '评分标准', '入榜规则', '用户协议', '隐私政策', '更新日志');
}

function seo_doc_name(string $sub): string
{
    $sub = trim(urldecode($sub));
    return in_array($sub, seo_doc_names(), true) ? $sub : '评分标准';
}

/** 读取并粗略去 Markdown，得到纯文本（供 description 与 SSR 段落） */
function seo_doc_text(string $docName, string $lang = 'zh'): string
{
    /* 英文版与日文界面共用英文文档；中文界面读原文 */
    $slug = seo_doc_slug(seo_doc_name($docName));
    $path = ($lang === 'zh')
        ? APP_ROOT . '/assets/docs/' . seo_doc_name($docName) . '.md'
        : APP_ROOT . '/assets/docs/en/' . $slug . '.md';
    if (!is_file($path)) { $path = APP_ROOT . '/assets/docs/' . seo_doc_name($docName) . '.md'; }
    if (!is_file($path)) { return ''; }
    $md = (string)@file_get_contents($path);
    if ($md === '') { return ''; }
    $md = preg_replace('/```.*?```/su', '', $md);                 // 代码块
    $md = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $md);       // 图片
    $md = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $md);    // 链接 → 文字
    $md = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $md);         // 标题符号
    $md = preg_replace('/^\s{0,3}>\s?/mu', '', $md);              // 引用
    $md = preg_replace('/^\s{0,3}[-*+]\s+/mu', '', $md);          // 列表符号
    $md = str_replace(array('**', '`', '__'), '', $md);
    return trim($md);
}

/* ============================================================
 * sitemap.xml / robots.txt
 * ============================================================ */
function seo_xml_escape(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function seo_sitemap_xml(): string
{
    if (seo_is_mirror()) {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"></urlset>\n";
    }
    $cached = cache_get('seo_sitemap', 21600);
    if (is_string($cached) && $cached !== '') { return $cached; }

    $urls = array();
    $urls[] = array('loc' => seo_abs('rank'), 'priority' => '1.0', 'changefreq' => 'daily', 'lastmod' => gmdate('c'));
    $urls[] = array('loc' => seo_abs('discipline'), 'priority' => '0.4', 'changefreq' => 'weekly', 'lastmod' => gmdate('c'));
    $urls[] = array('loc' => seo_abs('about'), 'priority' => '0.3', 'changefreq' => 'monthly', 'lastmod' => gmdate('c'));
    foreach (seo_doc_names() as $d) {
        $f = APP_ROOT . '/assets/docs/' . $d . '.md';
        $urls[] = array('loc' => seo_abs('doc', $d), 'priority' => '0.5', 'changefreq' => 'monthly',
            'lastmod' => is_file($f) ? gmdate('c', (int)@filemtime($f)) : gmdate('c'));
    }
    try {
        foreach (db_all('SELECT id, updated_at FROM works WHERE is_hidden = 0 ORDER BY id DESC LIMIT 20000') as $r) {
            $lm = trim((string)$r['updated_at']);
            $urls[] = array('loc' => seo_abs('detail', (string)(int)$r['id']),
                'priority' => '0.6', 'changefreq' => 'weekly',
                'lastmod' => $lm !== '' ? str_replace(' ', 'T', $lm) . '+00:00' : gmdate('c'));
        }
    } catch (Throwable $e) { }

    $out  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $out .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($urls as $u) {
        $out .= "  <url><loc>" . seo_xml_escape($u['loc']) . "</loc>"
              . "<lastmod>" . seo_xml_escape($u['lastmod']) . "</lastmod>"
              . "<changefreq>" . $u['changefreq'] . "</changefreq>"
              . "<priority>" . $u['priority'] . "</priority></url>\n";
    }
    $out .= "</urlset>\n";
    if (strlen($out) < 4000000) { cache_set('seo_sitemap', $out, 21600); }
    return $out;
}

function seo_robots_txt(): string
{
    if (seo_is_mirror()) { return "User-agent: *\nDisallow: /\n"; }
    $t  = "User-agent: *\n";
    $t .= "Allow: /\n";
    foreach (array('/api/', '/app/', '/config/', '/sql/', '/storage/', '/tools/', '/docs/', '/standalone/') as $d) {
        $t .= 'Disallow: ' . $d . "\n";
    }
    $t .= "\nSitemap: " . seo_canonical_base() . "/sitemap.xml\n";
    return $t;
}
