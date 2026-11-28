<?php
/**
 * 站点入口：输出 SPA 骨架，页面切换与数据全部走 /api/*.php
 */
declare(strict_types=1);
try {
    require_once __DIR__ . '/app/bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    $msg  = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
    exit('<!DOCTYPE html><meta charset="utf-8"><body style="font-family:sans-serif;background:#f6f7f9;padding:28px">'
        . '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:24px">'
        . '<h2 style="margin:0 0 10px;color:#c62a2f;font-size:18px">启动失败 · 诊断信息</h2>'
        . '<p style="margin:0 0 8px"><b>' . $msg . '</b></p>'
        . '<p style="margin:0;color:#666;font-size:13px">' . $file . ' : 第 ' . $e->getLine() . ' 行</p>'
        . '</div></body>');
}

/* 旧地址归一：/?p=detail&arg=12 与 ?chat 这类查询式地址一律 301 到普通路径，
   让同一内容只有一个地址（对收录与分享都更干净）。 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        $__legacyUrl = seo_legacy_redirect_url();
        if ($__legacyUrl !== '') {
            header('Location: ' . $__legacyUrl, true, 301);
            exit;
        }
    } catch (Throwable $e) {
        app_log('legacy redirect skipped: ' . $e->getMessage());   // 绝不因归一失败而挡住站点
    }
}

/* 违纪封禁：不再跳转 —— 被封停者仍可打开站点、甚至登录（便于看清处理结果），
   但前端会整体锁在封禁通知界面，后端所有 API 也一律拒绝（违纪界面所需接口除外）。
   管理员与副管理员永不因此受限，防止同一网络下误伤值班的人。 */
$__bannedId = 0;
try {
    $__ident = current_identity();
    $__role  = is_array($__ident) ? (string)($__ident['role'] ?? '') : '';
    if ($__role !== 'admin' && $__role !== 'subadmin') {
        $__uid = is_array($__ident) ? (int)($__ident['uid'] ?? 0) : 0;
        $__hit = discipline_hit($__uid, discipline_ip_hash(discipline_client_ip()));
        if (is_array($__hit)) { $__bannedId = max(1, (int)$__hit['id']); }
    }
} catch (Throwable $e) {
    app_log('discipline guard failed: ' . $e->getMessage());   // 绝不因拦截本身出错而挡住站点
}

/* 设计风格：由 cookie 决定加载哪一份皮肤文件（切换时刷新页面即换皮肤）。
   登录用户的偏好在「我的 → 外观设置」中同步到账号，跨设备时由前端写回 cookie。 */
$__SKINS = array('glass', 'md3', 'pixel', 'sketch', 'brutal');
$SKIN = isset($_COOKIE['kimgr_skin']) ? (string)$_COOKIE['kimgr_skin'] : 'glass';
if (!in_array($SKIN, $__SKINS, true)) { $SKIN = 'glass'; }

/* 公告：服务端直接读库渲染（不依赖前端请求与动画，必然可见）
   三级兜底：announcements 表 → settings 键 → 空（隐藏） */
$announceText = '';
try {
    $announceText = trim((string)db_val("SELECT content FROM announcements WHERE content <> '' ORDER BY id ASC LIMIT 1"));
} catch (Throwable $e) {
    $announceText = '';
}
if ($announceText === '') {
    try { $announceText = trim((string)setting_get('announcement', '')); } catch (Throwable $e) { $announceText = ''; }
}

/* 人机验证的前端配置：通道地址与开关（公共实例无密钥，可安全下发） */
$__captcha = array('on' => false, 'channels' => array());
try { $__captcha = captcha_client_config(); } catch (Throwable $e) { }

/* 限时节日：规则在 app/festival.php，这里下发结果（前端用它做角标与文案） */
$__festival = array('now' => '', 'list' => array());
try { $__festival = festival_client(); } catch (Throwable $e) { }

/* 首屏主题：节日优先、其次用户所选设计风格。
   节日与内置主题的数据全在 themes/*.theme.json —— 配色 token、背景层、装饰层、图标 path。
   这里在服务端把当前主题内联进 head，不再加载 skins/*.css，也不产生额外请求。 */
$__now      = (string)($__festival['now'] ?? '');
$__optout   = isset($_COOKIE['kimgr_skin_optout']) && $_COOKIE['kimgr_skin_optout'] === '1';
$__theme    = ($__now !== '' && !$__optout) ? theme_load($__now) : null;
$__festOn   = $__theme !== null;
$__themeKey = $__festOn ? $__now : $SKIN;
$__paint    = $__theme ? theme_first_paint($__theme) : null;
$__darkFest = $__festOn && !empty($__festival['list'][$__now]['dark']);
/* 风格皮肤只剩 4 份 CSS（md3/pixel/sketch/brutal）；glass 就是 app.css 默认样式 */
$__skinCss  = (!$__festOn && $SKIN !== 'glass') ? $SKIN : '';

/* ---------- SEO：按路由生成 meta / 结构化数据 / 服务端渲染内容 ----------
   纯前端 SPA 的 HTML 默认是空骨架，不执行 JS 的搜索引擎抓不到内容。这里在服务端
   按当前地址补齐可被收录的 meta 与真实内容；任何异常都回退到站点默认值，绝不影响站点。 */
$__seo = array(
    'title'       => cfg('site.name', 'Kimi游戏榜') . seo_t(' · 六维综合排行榜', ' · six-dimension leaderboard', ' · 六次元ランキング'),
    'description' => seo_t(
        '收录 Kimi 社区公开作品的六维评分榜单，覆盖游戏、工具、文学与二创。',
        'A six-dimension leaderboard of public works in the Kimi community — games, tools, writing and fan art.',
        'Kimiコミュニティの公開作品を六次元で評価するランキング。ゲーム・ツール・文学・二次創作を収録。'
    ),
    'canonical'   => rtrim((string)cfg('site.url', ''), '/') . '/',
    'robots'      => 'index,follow', 'type' => 'website', 'image' => '', 'jsonld' => array(),
);
/* 站点根路径前缀：路径式路由下静态资源与接口必须用绝对地址，否则 /detail/12 会解析成 /detail/assets/… */
$__P = seo_base_path();

/* 前端产物完整性（SRI）：实时算入口脚本的 sha256，浏览器按 base64 校验。
   文件被改写（主机注入、扩展替换、上传不全）时浏览器直接拒绝执行，而不是带着后门跑。
   可在 config 里用 security.sri = false 关闭。 */
$__appPath = __DIR__ . '/assets/js/app.js';
$__appSha  = is_file($__appPath) ? (string)hash_file('sha256', $__appPath) : '';
$__appVer  = $__appSha !== '' ? (APP_VERSION . '.' . substr($__appSha, 0, 8)) : APP_VERSION;
$__sri     = ($__appSha !== '' && cfg('security.sri', true)) ? 'sha256-' . base64_encode(hex2bin($__appSha)) : '';

$__seoBody = '';
try {
    $__seoRoute = seo_request_route();
    $__seo     = seo_meta_for($__seoRoute);
    $__seoBody = seo_body_html($__seoRoute);
} catch (Throwable $e) { app_log('seo failed: ' . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= $__darkFest ? 'dark' : 'light' ?>" data-accent="blue-purple" data-skin="<?= htmlspecialchars($__themeKey, ENT_QUOTES) ?>"<?= $__festOn ? ' data-festival="1"' : '' ?><?= (!empty($__theme['background']) ? ' data-tjbg="1"' : '') ?>>
<head>
<meta charset="utf-8">
<link rel="preconnect" href="https://gcore.jsdelivr.net" crossorigin>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#7C3AED">
<title><?= e($__seo['title']) ?></title>
<meta name="description" content="<?= e($__seo['description']) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="canonical" href="<?= e($__seo['canonical']) ?>">
<meta name="robots" content="<?= e($__seo['robots']) ?>">
<meta property="og:type" content="<?= e($__seo['type']) ?>">
<meta property="og:title" content="<?= e($__seo['title']) ?>">
<meta property="og:description" content="<?= e($__seo['description']) ?>">
<meta property="og:url" content="<?= e($__seo['canonical']) ?>">
<meta property="og:site_name" content="<?= e(cfg('site.name', 'Kimi游戏榜')) ?>">
<meta name="twitter:card" content="summary">
<?php if (!empty($__seo['image'])): ?><meta property="og:image" content="<?= e($__seo['image']) ?>"><?php endif; ?>
<?php foreach ($__seo['jsonld'] as $__ld): ?><script type="application/ld+json"><?= json_encode($__ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endforeach; ?>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%236D3BF5'/%3E%3Cpath d='M7 7h10a4 4 0 0 1 4 4v2a3 3 0 0 1-3 3h-.9a2 2 0 0 1-1.6-.8l-.5-.7a1.5 1.5 0 0 0-2.4 0l-.5.7A2 2 0 0 1 10.4 16H10a3 3 0 0 1-3-3v-2a4 4 0 0 1 4-4zm.5 3.5h-1.5v1.5H4.5v1.5h1.5v1.5h1.5v-1.5H9v-1.5H7.5V10.5zm8 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm2.2 2.6a1 1 0 1 0 0 2 1 1 0 0 0 0-2z' fill='%23fff'/%3E%3C/svg%3E">
<script>window.__BASE = <?= json_encode(seo_base_path()) ?>;</script>
<?php /* 路由段映射：内部标识 ↔ 对外 URL 段（单一来源在 app/seo.php），前端不再硬编码路径名 */ ?>
<script>window.__ROUTES = <?= json_encode(array('seg' => seo_route_segments(), 'name' => array_flip(seo_route_segments()), 'docSlug' => seo_doc_slugs(), 'docName' => array_flip(seo_doc_slugs())), JSON_UNESCAPED_UNICODE) ?>;</script>
<script>window.__CAPTCHA = <?= json_encode($__captcha, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script>window.__FESTIVAL = <?= json_encode($__festival, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php if ($__theme): /* 主题图标覆盖：前端以它为基线，自定义主题在其上叠加 */ ?>
<script>window.__THEME_ICONS = <?= json_encode(theme_icons_map($__theme), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<script>window.__BANNED = <?= (int)$__bannedId ?>;</script>
<?php $__monUid = (isset($__ident) && is_array($__ident)) ? (int)($__ident['uid'] ?? 0) : 0; ?>
<script>window.__MON_CFG__ = { endpoint: '<?= e($__P) ?>/api/monitor', uid: <?= $__monUid ?>, version: '<?= APP_VERSION ?>' };</script>
<script src="<?= e($__P) ?>/assets/js/monitor.js?v=<?= APP_VERSION ?>"></script>
<script>
/* 首屏前应用本机偏好（深浅色 / 设计风格 / 主题色），避免样式闪烁 */
(function () {
  try {
    var p = JSON.parse(localStorage.getItem('kimgr_prefs') || '{}') || {};
    var r = document.documentElement;
    var t = p.theme || 'light';
    if (t === 'system') { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
    r.dataset.theme = (t === 'dark') ? 'dark' : 'light';
    if (p.accent) { r.dataset.accent = p.accent; }
    if (p.accent === 'custom' && /^#[0-9a-fA-F]{6}$/.test(p.accent_custom || '')) {
      r.style.setProperty('--accent', p.accent_custom);
    }
  } catch (e) {}
})();
</script>
<?php
/* 首屏关键 CSS 直接内联：app.css 无任何 url() 外链依赖，可安全内联，
   省掉一次跨洋往返与渲染阻塞；皮肤文件同理。动态 HTML 本身不缓存，内联不损失缓存收益。 */
?>
<style><?php readfile(__DIR__ . '/assets/css/app.css'); ?></style>
<?php
/* 主题：节日与内置主题全部来自 themes/*.theme.json，服务端把明暗两套 token、
   装饰层与背景承载规则内联在这里 —— 解析期即生效，没有样式闪动，也没有额外请求。
   只有 4 份设计风格皮肤（md3/pixel/sketch/brutal）仍是独立 CSS。 */
?>
<?php if ($__paint): ?><style id="theme-json"><?= $__paint['css'] ?></style><?php endif; ?>
<?php if ($__skinCss !== ''): ?><link rel="stylesheet" href="<?= htmlspecialchars($__P, ENT_QUOTES) ?>/assets/css/skins/<?= htmlspecialchars($__skinCss, ENT_QUOTES) ?>.css?v=<?= htmlspecialchars(APP_VERSION, ENT_QUOTES) ?>"><?php endif; ?>
<?php
/* 字体加载策略（均不占首屏关键路径）：
   1. 全部字体（正文 MiSans、手绘 ZCOOL KuaiLe、像素 FusionPixel）都由浏览器直连公共 CDN，
      本站不存放、也不转发任何字体文件 —— 字体流量完全不占用主机资源与请求数。
   2. 皮肤专属字体只在选到对应皮肤时才注入，其余皮肤零字体请求。
   3. 每组按顺序回退备用源，全组失败则退回系统字体；首屏先用系统字体渲染
      （@font-face 为 swap 语义），空闲后再注入，避免字体抢占首屏连接。 */
?>
<script>
(function () {
  var skin = <?= json_encode($SKIN) ?>;
  /* 公共 CDN 的跨域头为 Access-Control-Allow-Origin: *，浏览器可直接取用 */
  var FONTS = {
    main: [
      'https://gcore.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css',
      'https://cdn.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css'
    ],
    /* 以下两组只在选到对应皮肤时才发起请求 */
    sketch: [
      'https://gcore.jsdelivr.net/npm/@fontsource/zcool-kuaile@5.3.0/400.css',
      'https://cdn.jsdelivr.net/npm/@fontsource/zcool-kuaile@5.3.0/400.css'
    ],
    pixel: [
      'https://gcore.jsdelivr.net/npm/@fontsource/fusion-pixel-12px-proportional-sc@5.3.0/400.css',
      'https://cdn.jsdelivr.net/npm/@fontsource/fusion-pixel-12px-proportional-sc@5.3.0/400.css'
    ]
  };
  /* 逐个尝试：当前源失败则换下一个，全组失败就安静地退回系统字体 */
  function inject(list, i) {
    if (i >= list.length) { return; }
    var l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = list[i];
    l.onerror = function () { inject(list, i + 1); };
    document.head.appendChild(l);
  }
  function run() {
    /* 像素皮肤全站文字由 FusionPixel 承载，无需 MiSans，省下一次样式请求 */
    if (skin !== 'pixel') { inject(FONTS.main, 0); }
    if (FONTS[skin]) { inject(FONTS[skin], 0); }
  }
  if ('requestIdleCallback' in window) { requestIdleCallback(run, { timeout: 2000 }); }
  else { setTimeout(run, 400); }
})();
</script>
<noscript><link rel="stylesheet" href="https://gcore.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css"></noscript>
<script>
/* 全局诊断：脚本或资源加载失败时，直接在页面顶部显示原因（便于截图反馈） */
(function () {
  function showErr(msg) {
    var d = document.getElementById('jsErr');
    if (!d) {
      d = document.createElement('div');
      d.id = 'jsErr';
      d.style.cssText = 'position:fixed;left:8px;right:8px;top:8px;z-index:9999;background:#c62a2f;color:#fff;' +
        'padding:10px 13px;border-radius:12px;font:12px/1.6 ui-monospace,Menlo,monospace;white-space:pre-wrap;word-break:break-all';
      (document.body || document.documentElement).appendChild(d);
    }
    d.textContent = '页面脚本错误（截图发给开发者）：' + msg;
  }
  window.addEventListener('error', function (e) {
    var t = e.target || {};
    if (t.tagName === 'SCRIPT' || t.tagName === 'LINK') {
      showErr('资源加载失败 → ' + (t.src || t.href));
      return;
    }
    if (e.message) { showErr(e.message + ' @ ' + (e.filename || '') + ':' + (e.lineno || '')); }
  }, true);
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason || {};
    showErr('Promise 未捕获：' + (r.message || r));
  });
})();
</script>
</head>
<body>

<?php
/* 节日装饰层：内容来自当前主题 JSON 的 deco 字段（服务端已做标签与 class 白名单校验）。
   容器只在节日期间输出；pointer-events:none，绝不拦截交互。 */ ?>
<?php if ($__paint): ?>
<?= $__paint['bg'] ?>
<div class="festival-deco" aria-hidden="true"><?= $__paint['deco'] ?></div>
<?php endif; ?>

<!-- 全局加载条（API 请求自动显隐） -->
<div id="globalBar" aria-hidden="true"></div>

<!-- 应用根 -->
<div id="app">

  <!-- 公告横幅 -->
  <div class="announce" id="announceBar"<?= $announceText === '' ? ' hidden' : '' ?>>
    <span class="announce-tag">
      <span class="ic-slot" data-ic="nav.today"><svg viewBox="0 0 24 24" class="ic"><path d="M3 10v4h3l5 4V6L6 10H3zm13.5 2a4.5 4.5 0 0 0-2.5-4v8a4.5 4.5 0 0 0 2.5-4z"/></svg></span>
      <?= e(seo_t('公告', 'Notice', 'お知らせ')) ?>
    </span>
    <div class="announce-track"><span id="announceText"><?= e($announceText) ?></span></div>
  </div>

  <!-- 顶栏：品牌 + 控制组 -->
  <header class="topbar glass" id="topbar">
    <button class="icon-btn" id="btnBack" aria-label="<?= e(seo_t('返回', 'Back', '戻る')) ?>" hidden>
      <span class="ic-slot" data-ic="action.back"><svg viewBox="0 0 24 24" class="ic"><path d="M15.4 5L8 12l7.4 7L17 17.6 11.2 12 17 6.4z"/></svg></span>
    </button>
    <div class="brand" id="brandHome" role="button" tabindex="0">
      <span class="logo">
        <span class="ic-slot" data-ic="nav.total"><svg viewBox="0 0 24 24" class="ic"><path d="M7 6h10a5 5 0 0 1 5 5v3a4 4 0 0 1-4 4h-1.2a3 3 0 0 1-2.4-1.2l-.6-.8a2 2 0 0 0-3.2 0l-.6.8A3 3 0 0 1 7.6 18H7a4 4 0 0 1-4-4v-3a5 5 0 0 1 5-5zm1.5 4.5h-2v2h-1.5v-2h-2v-1.5h2v-2h1.5v2h2v1.5zM16 10.2a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2zm2.8 0a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z"/></svg></span>
      </span>
      <span class="brand-name"><?= e(seo_t('kimi游戏榜', 'Kimi Game Rank', 'Kimiゲームランク')) ?></span>
    </div>
    <div class="ctrl">
      <button class="icon-btn" id="btnSearch" aria-label="<?= e(seo_t('搜索', 'Search', '検索')) ?>">
        <span class="ic-slot" data-ic="field.search"><svg viewBox="0 0 24 24" class="ic"><path d="M10 4a6 6 0 1 0 3.7 10.7l4.3 4.3 1.4-1.4-4.3-4.3A6 6 0 0 0 10 4zm0 2a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/></svg></span>
      </button>
      <button class="icon-btn" id="btnTheme" aria-label="<?= e(seo_t('切换深浅色', 'Toggle theme', 'テーマ切替')) ?>">
        <span class="ic-slot" id="themeIcon" data-ic="theme.auto"><svg viewBox="0 0 24 24" class="ic"><path d="M12 3q-.5 0-.5.5t.5.5q3.7 0 6.3 2.7T21 13q0 2.4-1.2 4.4.5-1.3.5-2.7 0-3-2.1-5.1T13 7.5q-1.8 0-3.4.9t-2.5 2.4q-.9 1.6-.9 3.4 0 1.2.4 2.2-2.1-2-2.1-4.9 0-3.7 2.6-6.3T12 3z"/></svg></span>
      </button>
      <button class="icon-btn" id="btnMenu" aria-label="<?= e(seo_t('菜单', 'Menu', 'メニュー')) ?>">
        <span class="ic-slot" data-ic="ui.menu"><svg viewBox="0 0 24 24" class="ic"><path d="M4 7h16v2H4V7zm0 4h16v2H4v-2zm0 4h16v2H4v-2z"/></svg></span>
      </button>
    </div>
  </header>

  <!-- 搜索面板 -->
  <div class="search-panel glass" id="searchPanel" hidden>
    <input type="text" id="searchInput" placeholder="<?= e(seo_t('搜索作品标题或作者…', 'Search by title or author…', '作品名や作者で検索…')) ?>" autocomplete="off">
    <button class="btn-ghost" id="searchGo"><?= e(seo_t('搜索', 'Search', '検索')) ?></button>
    <button class="icon-btn" id="searchClose" aria-label="<?= e(seo_t('关闭', 'Close', '閉じる')) ?>">
      <span class="ic-slot" data-ic="action.close"><svg viewBox="0 0 24 24" class="ic"><path d="M6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4 6.4 5z"/></svg></span>
    </button>
  </div>

  <!-- 页面容器：服务端先渲染可被搜索引擎读取的真实内容（含真实链接），
       JS 就绪后由 SPA 原子替换为交互界面；无 JS 时也能正常浏览。 -->
  <main id="view" class="view">
<?php if ($__seoBody !== ''): ?>
<?= $__seoBody ?>
<?php else: ?>
    <div class="skeleton" style="height:72px"></div>
    <div class="skeleton"></div>
    <div class="skeleton"></div>
    <div class="skeleton"></div>
<?php endif; ?>
  </main>

  <!-- 底部三栏 -->
  <nav class="tabbar glass" id="tabbar">
    <button class="tab" data-tab="rank" data-route="#/rank">
      <span class="ic-slot" data-ic="tab.rank"><svg viewBox="0 0 24 24" class="ic"><path d="M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 5.2 12H4a3 3 0 0 1 0-6h2V3zm0 5H4a1 1 0 0 0 0 2h2V8zm14 0h-2v2h2a1 1 0 0 0 0-2z"/></svg></span>
      <span data-i18n="榜单">榜单</span>
    </button>
    <button class="tab" data-tab="chat" data-route="#/chat">
      <span class="ic-slot" data-ic="tab.chat"><svg viewBox="0 0 24 24" class="ic"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 4V6a2 2 0 0 1 2-2z"/></svg></span>
      <span data-i18n="对话">对话</span>
    </button>
    <button class="tab" data-tab="mine" data-route="#/mine">
      <span class="ic-slot" data-ic="tab.mine"><svg viewBox="0 0 24 24" class="ic"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z"/></svg></span>
      <span data-i18n="我的">我的</span>
    </button>
  </nav>
</div>

<!-- 汉堡抽屉 -->
<div class="drawer-scrim" id="drawerScrim" hidden></div>
<aside class="drawer glass" id="drawer" hidden>
  <div class="drawer-head">
    <span><?= e(seo_t('导航菜单', 'Menu', 'メニュー')) ?></span>
    <button class="icon-btn" id="drawerClose" aria-label="<?= e(seo_t('关闭', 'Close', '閉じる')) ?>">
      <span class="ic-slot" data-ic="action.close"><svg viewBox="0 0 24 24" class="ic"><path d="M6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4 6.4 5z"/></svg></span>
    </button>
  </div>
  <div class="drawer-list" id="drawerList"></div>
</aside>

<!-- 对话框 -->
<div class="dialog-scrim" id="dialogScrim" hidden>
  <div class="dialog glass" id="dialog" role="dialog" aria-modal="true"></div>
</div>

<!-- 回到顶部 -->
<button class="totop" id="toTop" aria-label="<?= e(seo_t('回到顶部', 'Back to top', 'トップへ戻る')) ?>" hidden>
  <span class="ic-slot" data-ic="action.expand"><svg viewBox="0 0 24 24" class="ic"><path d="M12 6l6 6-1.4 1.4L13 9.8V19h-2V9.8L7.4 13.4 6 12z"/></svg></span>
</button>

<!-- 轻提示 -->
<div class="toast-wrap" id="toastWrap" aria-live="polite"></div>

<?php $__lang = isset($_COOKIE['kimgr_lang']) ? strtolower((string)$_COOKIE['kimgr_lang']) : '';
      if (!in_array($__lang, array('zh', 'en', 'ja'), true)) { $__lang = ''; } ?>
<script>window.__LANG = <?= json_encode($__lang) ?>;</script>
<script>window.__SITE__ = { name: <?= json_encode((string)cfg('site.name', 'Kimi游戏榜')) ?>, ver: <?= json_encode(APP_VERSION) ?>, timezone: <?= json_encode((string)cfg('site.timezone', 'Asia/Shanghai')) ?> };</script>
<!-- 二维码编码器（MIT，qrcode-generator 1.4.4）：本地引入，不依赖外部 CDN -->
<script defer src="<?= e($__P) ?>/assets/js/vendor/qrcode.js?v=<?= e($__appVer) ?>"></script>
<link rel="preload" as="script" href="<?= e($__P) ?>/assets/js/app.js?v=<?= e($__appVer) ?>">
<script type="module" src="<?= e($__P) ?>/assets/js/app.js?v=<?= e($__appVer) ?>"<?= $__sri !== '' ? ' integrity="' . e($__sri) . '"' : '' ?> onerror="(function(){if(window.__lgRetry)return;window.__lgRetry=1;var s=document.createElement('script');s.type='module';s.src='<?= e($__P) ?>/assets/js/app.js?v=<?= e($__appVer) ?>&r='+Date.now();document.body.appendChild(s);})()"></script>
</body>
</html>
