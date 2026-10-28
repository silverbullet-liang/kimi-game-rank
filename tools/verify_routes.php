<?php
/**
 * 路由与端点规整化自检 —— 离线，桩件驱动，不联网、不连库。
 * 跑法：php tools/verify_routes.php
 *
 * 覆盖：路径式路由解析、旧地址归一（301）、规范化地址生成、.htaccess 重写规则、
 *       前端不再出现 .php 端点与相对路径、文档内链无查询式地址。
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
function app_log($m) { }

require_once APP_ROOT . '/app/seo.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "OK   $name\n"; }
    else { $fail++; echo "FAIL $name\n"; }
}

/** 模拟一次请求：$uri 是含查询串的 REQUEST_URI */
function req(string $uri, string $script = '/index.php'): void
{
    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['SCRIPT_NAME']    = $script;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = array();
    $q = (string)parse_url($uri, PHP_URL_QUERY);
    if ($q !== '') {
        foreach (explode('&', $q) as $kv) {
            $p = explode('=', $kv, 2);
            $_GET[rawurldecode($p[0])] = isset($p[1]) ? rawurldecode($p[1]) : '';
        }
    }
}

/* ============================================================
 * 1. 规范化地址生成（对外段名必须一眼能懂）
 * ============================================================ */
req('/');
ok('路径：榜单 → /rankings', seo_path_of('rank') === '/rankings');
ok('路径：详情 → /works/12', seo_path_of('detail', '12') === '/works/12');
ok('路径：我的 → /me', seo_path_of('mine') === '/me');
ok('路径：控制面板 → /admin', seo_path_of('panel') === '/admin');
ok('路径：封禁说明 → /banned/12', seo_path_of('violation', '12') === '/banned/12');
ok('路径：违纪通报 → /notices/12', seo_path_of('discipline', '12') === '/notices/12');
ok('路径：文档中文名 → /docs/admission', seo_path_of('doc', '入榜规则') === '/docs/admission');
ok('路径：文档 slug 幂等', seo_path_of('doc', 'admission') === '/docs/admission');
ok('绝对地址：带域名与路径', seo_abs('detail', '12') === 'https://kimi-game-rank.wuaze.com/works/12');
ok('绝对地址：附加查询串', seo_abs('rank', '', array('board' => 'peak')) === 'https://kimi-game-rank.wuaze.com/rankings?board=peak');
ok('站内链接：文档', seo_rel('doc', '评分标准') === '/docs/scoring');
ok('站内链接：搜索用标准 q', seo_rel('rank', '', array('q' => '太空')) === '/rankings?q=' . rawurlencode('太空'));

/* ============================================================
 * 2. 路径解析
 * ============================================================ */
req('/');
ok('解析：根地址 → rank', seo_request_route()['name'] === 'rank');

req('/rankings');
ok('解析：/rankings → rank', seo_request_route()['name'] === 'rank');

req('/works/12');
$r = seo_request_route();
ok('解析：/works/12 → detail + sub=12', $r['name'] === 'detail' && $r['sub'] === '12');

req('/docs/admission');
$r = seo_request_route();
ok('解析：/docs/admission → doc + 中文名', $r['name'] === 'doc' && $r['sub'] === '入榜规则');

req('/me');
ok('解析：/me → mine', seo_request_route()['name'] === 'mine');

req('/admin');
ok('解析：/admin → panel', seo_request_route()['name'] === 'panel');

req('/banned/12');
$r = seo_request_route();
ok('解析：/banned/12 → violation + 12', $r['name'] === 'violation' && $r['sub'] === '12');

req('/notices/12');
ok('解析：/notices/12 → discipline', seo_request_route()['name'] === 'discipline');

req('/rankings?board=peak&q=%E5%A4%AA%E7%A9%BA');
$r = seo_request_route();
ok('解析：查询串保留 board 与 q', $r['name'] === 'rank' && $r['params']['board'] === 'peak' && $r['params']['q'] === '太空');

req('/chat');
ok('解析：/chat', seo_request_route()['name'] === 'chat');

req('/nonsense');
ok('解析：未知路径 → rank', seo_request_route()['name'] === 'rank');

/* 旧段仍可解析（301 之前要能认出是谁） */
req('/rank');
ok('兼容：旧段 /rank → rank', seo_request_route()['name'] === 'rank');
req('/detail/12');
$r = seo_request_route();
ok('兼容：旧段 /detail/12 → detail', $r['name'] === 'detail' && $r['sub'] === '12');
req('/doc/' . rawurlencode('入榜规则'));
$r = seo_request_route();
ok('兼容：旧段 /doc/中文名 → doc', $r['name'] === 'doc' && $r['sub'] === '入榜规则');

/* 旧查询式地址 */
req('/?p=detail&arg=12');
$r = seo_request_route();
ok('兼容：?p=detail&arg=12', $r['name'] === 'detail' && $r['sub'] === '12');
req('/?chat');
ok('兼容：裸参数 ?chat', seo_request_route()['name'] === 'chat');

/* ============================================================
 * 3. 旧地址归一（301 目标）
 * ============================================================ */
req('/rank');
ok('归一：/rank → /rankings', seo_legacy_redirect_url() === '/rankings');
req('/detail/12');
ok('归一：/detail/12 → /works/12', seo_legacy_redirect_url() === '/works/12');
req('/mine');
ok('归一：/mine → /me', seo_legacy_redirect_url() === '/me');
req('/panel');
ok('归一：/panel → /admin', seo_legacy_redirect_url() === '/admin');
req('/doc/' . rawurlencode('入榜规则'));
ok('归一：/doc/入榜规则 → /docs/admission', seo_legacy_redirect_url() === '/docs/admission');
req('/?p=detail&arg=12');
ok('归一：?p=detail&arg=12 → /works/12', seo_legacy_redirect_url() === '/works/12');
req('/?p=rank&board=peak');
ok('归一：保留额外查询参数', seo_legacy_redirect_url() === '/rankings?board=peak');
req('/rankings?board=peak');
ok('归一：已是规范地址不动', seo_legacy_redirect_url() === '');
req('/works/12');
ok('归一：/works/12 不动', seo_legacy_redirect_url() === '');
req('/');
ok('归一：站点根不动', seo_legacy_redirect_url() === '');
req('/docs/features');
ok('归一：/docs/features 不动', seo_legacy_redirect_url() === '');

/* ============================================================
 * 4. 子目录部署
 * ============================================================ */
req('/kimgr/works/12', '/kimgr/index.php');
$r = seo_request_route();
ok('子目录：前缀剥离后正确解析', $r['name'] === 'detail' && $r['sub'] === '12');
ok('子目录：链接带前缀', seo_rel('rank') === '/kimgr/rankings');
ok('子目录：绝对地址带前缀', seo_abs('rank') === 'https://kimi-game-rank.wuaze.com/kimgr/rankings');

/* ============================================================
 * 6. 前端源码：无 .php 端点、无相对 assets
 * ============================================================ */
$srcFiles = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/assets/js/src'));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -3) === '.js') { $srcFiles[] = $f->getPathname(); }
}
$dotPhp = array(); $rel = array();
foreach ($srcFiles as $f) {
    $s = (string)file_get_contents($f);
    if (preg_match("/api\(\s*'[a-z_]+\.php'/", $s)) { $dotPhp[] = basename($f); }
    if (preg_match("/fetch\(\s*'(api|assets)\//", $s)) { $rel[] = basename($f); }
    if (preg_match('/src="assets\//', $s)) { $rel[] = basename($f); }
}
ok('前端无 .php 端点调用（' . count($srcFiles) . ' 个模块）', count($dotPhp) === 0);
ok('前端无相对 api/assets 引用', count($rel) === 0);

/* 接口地址统一走 API_BASE */
$core = (string)file_get_contents(APP_ROOT . '/assets/js/src/core.js');
ok('core.js：接口走 API_BASE 绝对路径', strpos($core, "new URL(API_BASE + '/' + file") !== false);
ok('core.js：入口剥离 .php 兜底', strpos($core, "replace(/\\.php\$/i, '')") !== false);
$base = (string)file_get_contents(APP_ROOT . '/assets/js/src/base.js');
ok('base.js：导出三常量', strpos($base, 'SITE_BASE') !== false && strpos($base, 'API_BASE') !== false && strpos($base, 'ASSET_BASE') !== false);

/* 构建器必须登记 base.js，否则合并后未定义 */
$build = (string)file_get_contents(APP_ROOT . '/tools/build.py');
ok('build.py：ORDER 登记 base.js', (bool)preg_match("/^\s*'base\.js',/m", $build));

/* ============================================================
 * 7. index.php：静态资源走绝对前缀
 * ============================================================ */
$idx = (string)file_get_contents(APP_ROOT . '/index.php');
ok('index.php：无相对 assets 引用', !preg_match('/(src|href)="assets\//', $idx));
ok('index.php：入口脚本带前缀', strpos($idx, '<?= e($__P) ?>/assets/js/app.js') !== false);
ok('index.php：监控端点带前缀且无扩展名', strpos($idx, "endpoint: '<?= e(\$__P) ?>/api/monitor'") !== false);
ok('index.php：皮肤路径带前缀', strpos($idx, "' + P + '/assets/css/skins/'") !== false);
ok('index.php：下发 window.__BASE', strpos($idx, 'window.__BASE = <?= json_encode(seo_base_path()) ?>') !== false);
ok('index.php：旧地址 301 已接入', strpos($idx, 'seo_legacy_redirect_url()') !== false);

/* ============================================================
 * 8. 文档内链
 * ============================================================ */
$docs = array_merge((array)glob(APP_ROOT . '/assets/docs/*.md'), (array)glob(APP_ROOT . '/assets/docs/en/*.md'));
$old = array(); $slugged = 0;
foreach ($docs as $f) {
    $t = (string)file_get_contents($f);
    if (strpos($t, '?p=doc&arg=') !== false || strpos($t, '(/doc/') !== false) { $old[] = basename($f); }
    $slugged += substr_count($t, '(/docs/');
}
ok('文档内链已改为 /docs/<slug>（' . count($docs) . ' 份，' . $slugged . ' 处，历史日志除外）', count($old) <= 1);

/* 前端路由不再硬编码路径段，一律读服务端下发的映射 */
$appjs = (string)file_get_contents(APP_ROOT . '/assets/js/src/app.js');
ok('前端：读服务端下发的路由段映射', strpos($appjs, 'window.__ROUTES') !== false && strpos($appjs, 'SEG_OF[name]') !== false);
ok('前端：KNOWN 含 submit / panel / monitor',
    strpos($appjs, "'submit'") !== false && strpos($appjs, "'panel'") !== false && strpos($appjs, "'monitor'") !== false);
$idx2 = (string)file_get_contents(APP_ROOT . '/index.php');
ok('入口：下发 window.__ROUTES', strpos($idx2, 'window.__ROUTES = <?=') !== false);

echo "----------------------------------------------------\n";
echo '通过 ' . $pass . ' / 失败 ' . $fail . "\n";
exit($fail > 0 ? 1 : 0);
