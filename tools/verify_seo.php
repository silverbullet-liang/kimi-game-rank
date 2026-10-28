<?php
/**
 * SEO 层自检（v3.42.0）—— 离线，桩件驱动，不联网、不连库。
 * 跑法：php tools/verify_seo.php
 */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));

/* ---------- 桩件 ---------- */
$GLOBALS['CFG'] = array(
    'site.url' => 'https://kimi-game-rank.wuaze.com',
    'site.name' => 'kimi游戏榜',
    'site.seo_primary_host' => 'kimi-game-rank.wuaze.com',
);
function cfg($k, $d = null) { return array_key_exists($k, $GLOBALS['CFG']) ? $GLOBALS['CFG'][$k] : $d; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function works_cats() { return array('game' => '游戏类', 'tool' => '工具类', 'literature' => '文学类', 'fanart' => '二创类'); }
$GLOBALS['PAGE'] = array('items' => array(
    array('id' => 12, 'title' => '太空射击', 'author' => '张三', 'category' => 'game', 'category_name' => '游戏类', 'total' => 616, 'rating' => 'B'),
    array('id' => 13, 'title' => '像素画板', 'author' => '李四', 'category' => 'tool', 'category_name' => '工具类', 'total' => 540, 'rating' => 'C'),
), 'total' => 2);
function works_list_page($cat, $board, $q, $page, $size, $uid, $lite = true) { return $GLOBALS['PAGE']; }
function work_link($r) { return (string)(isset($r['html_url']) ? $r['html_url'] : ''); }
$GLOBALS['ROW'] = array(
    'id' => 12, 'title' => '太空射击', 'intro' => "一款太空射击小游戏。\n玩法：方向键移动，空格射击。",
    'author_name' => '张三', 'category' => 'game',
    'images' => json_encode(array('https://img.example.com/a.png')),
    'score' => json_encode(array('experience' => 150, 'creativity' => 120, 'depth' => 90, 'cost' => 80, 'attitude' => 70, 'heat' => 60)),
    'total_score' => 616, 'rating' => 'B', 'like_num' => 10, 'comment_num' => 3, 'collect_num' => 2,
    'html_url' => 'https://abc.appmiaoda.com/', 'share_link' => '', 'created_at' => '2026-10-01 12:00:00',
);
function db_one($sql, $a = array()) {
    $id = isset($a[0]) ? (int)$a[0] : 0;
    return ($id === 12) ? $GLOBALS['ROW'] : null;
}
function db_all($sql, $a = array()) {
    return array(array('id' => 12, 'updated_at' => '2026-10-01 12:00:00'),
                 array('id' => 13, 'updated_at' => '2026-10-02 08:30:00'));
}
function cache_get($k, $t = 0) { return null; }
function cache_set($k, $v, $t = 0) { return true; }

require dirname(__DIR__) . '/app/seo.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond) { global $pass, $fail; $cond ? $pass++ : $fail++; printf("%s %s\n", $cond ? 'OK  ' : 'FAIL', $name); }
function seo_reset_get($arr) { $_GET = $arr; }
function host($h) { $_SERVER['HTTP_HOST'] = $h; }

/* ---------- 路由解析 ---------- */
seo_reset_get(array('p' => 'detail', 'id' => '12'));
$r = seo_request_route();
ok('路由：?p=detail&id=12', $r['name'] === 'detail' && $r['params']['id'] === '12');
seo_reset_get(array('p' => 'doc', 'arg' => '功能说明'));
ok('路由：?p=doc&arg=功能说明', seo_request_route()['name'] === 'doc' && seo_request_route()['sub'] === '功能说明');
seo_reset_get(array('chat' => '1'));
ok('路由：裸参数 ?chat', seo_request_route()['name'] === 'chat');
seo_reset_get(array('p' => 'detail/7'));
$r = seo_request_route();
ok('路由：?p=detail/7 取 sub', $r['name'] === 'detail' && $r['sub'] === '7');
seo_reset_get(array());
ok('路由：无参数默认 rank', seo_request_route()['name'] === 'rank');
seo_reset_get(array('p' => 'evil.x'));
ok('路由：非法 p → rank', seo_request_route()['name'] === 'rank');

/* ---------- 镜像判定 ---------- */
host('kimi-game-rank.wuaze.com');
ok('主域名：非镜像', seo_is_mirror() === false);
host('kimi-game-rank.byethost33.com');
ok('镜像域名：判为镜像', seo_is_mirror() === true);

/* ---------- meta / robots ---------- */
host('kimi-game-rank.wuaze.com');
seo_reset_get(array('p' => 'rank'));
$m = seo_meta_for(seo_request_route());
ok('首页：robots=index,follow', $m['robots'] === 'index,follow');
ok('首页：canonical 带域名', strpos($m['canonical'], 'kimi-game-rank.wuaze.com') !== false);
ok('首页：JSON-LD 含 WebSite', (function ($ld) { foreach ($ld as $x) { if (($x['@type'] ?? '') === 'WebSite') { return true; } } return false; })($m['jsonld']));
ok('首页：JSON-LD 含 ItemList', (function ($ld) { foreach ($ld as $x) { if (($x['@type'] ?? '') === 'ItemList') { return true; } } return false; })($m['jsonld']));

seo_reset_get(array('p' => 'detail', 'id' => '12'));
$m = seo_meta_for(seo_request_route());
ok('详情：标题含作品名', strpos($m['title'], '太空射击') !== false);
ok('详情：type=article', $m['type'] === 'article');
ok('详情：JSON-LD 含 CreativeWork', (function ($ld) { foreach ($ld as $x) { if (($x['@type'] ?? '') === 'CreativeWork') { return true; } } return false; })($m['jsonld']));
ok('详情：canonical 为 /works/12', strpos(seo_meta_for(array('name'=>'detail','sub'=>'12','params'=>array('id'=>'12')))['canonical'], '/works/12') !== false);
ok('详情：og:image 取首图', $m['image'] === 'https://img.example.com/a.png');

seo_reset_get(array('p' => 'detail', 'id' => '999'));
$m = seo_meta_for(seo_request_route());
ok('不存在的作品：noindex', $m['robots'] === 'noindex,follow');

seo_reset_get(array('p' => 'mine'));
ok('「我的」页：noindex', seo_meta_for(seo_request_route())['robots'] === 'noindex,follow');
seo_reset_get(array('p' => 'panel'));
ok('控制面板：noindex', seo_meta_for(seo_request_route())['robots'] === 'noindex,follow');
seo_reset_get(array('p' => 'discipline'));
ok('违纪通报：可索引', seo_meta_for(seo_request_route())['robots'] === 'index,follow');

host('kimi-game-rank.byethost33.com');
seo_reset_get(array('p' => 'rank'));
ok('镜像：整站 noindex', seo_meta_for(seo_request_route())['robots'] === 'noindex,follow');

/* ---------- SSR 内容 ---------- */
host('kimi-game-rank.wuaze.com');
seo_reset_get(array('p' => 'rank'));
$b = seo_body_html(seo_request_route());
ok('SSR 首页：含真实作品链接', strpos($b, '/works/') !== false && strpos($b, '太空射击') !== false);
seo_reset_get(array('p' => 'detail', 'id' => '12'));
$b = seo_body_html(seo_request_route());
ok('SSR 详情：含简介与六维评分', strpos($b, '作品简介') !== false && strpos($b, '可玩性') !== false);
seo_reset_get(array('p' => 'mine'));
ok('SSR：非公众页不渲染', seo_body_html(seo_request_route()) === '');

/* ---------- 文档 ---------- */
ok('文档名白名单：未知 → 评分标准', seo_doc_name('随便') === '评分标准');
ok('文档名白名单：功能说明', seo_doc_name('功能说明') === '功能说明');
$txt = seo_doc_text('评分标准');
ok('文档正文读取非空', strlen($txt) > 200);
ok('文档正文已去 Markdown 井号', strpos($txt, '# ') === false);
seo_reset_get(array('p' => 'doc', 'arg' => '评分标准'));
ok('文档页 SSR 非空', seo_body_html(seo_request_route()) !== '');

/* ---------- sitemap / robots ---------- */
host('kimi-game-rank.wuaze.com');
$xml = seo_sitemap_xml();
ok('sitemap：含 urlset 命名空间', strpos($xml, 'sitemaps.org/schemas/sitemap/0.9') !== false);
ok('sitemap：含作品详情地址', strpos($xml, '/works/') !== false);
ok('sitemap：含文档页地址', strpos($xml, '/docs/') !== false);
host('kimi-game-rank.byethost33.com');
ok('sitemap：镜像为空列表', strpos(seo_sitemap_xml(), '<urlset') !== false && strpos(seo_sitemap_xml(), '<url>') === false);

host('kimi-game-rank.wuaze.com');
$rb = seo_robots_txt();
ok('robots：含 Sitemap 指令', strpos($rb, 'Sitemap: https://kimi-game-rank.wuaze.com/sitemap.xml') !== false);
ok('robots：屏蔽 /api/', strpos($rb, 'Disallow: /api/') !== false);
host('kimi-game-rank.byethost33.com');
ok('robots：镜像 Disallow All', trim(seo_robots_txt()) === "User-agent: *\nDisallow: /");

echo "\n通过 $pass / 失败 $fail\n";
exit($fail === 0 ? 0 : 1);
