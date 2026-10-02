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

/* 限时节日皮肤：规则在 app/festival.php，这里下发结果（首屏脚本据此决定皮肤） */
$__festival = array('now' => '', 'list' => array());
try { $__festival = festival_client(); } catch (Throwable $e) { }
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light" data-accent="blue-purple" data-skin="<?= $SKIN ?>">
<head>
<meta charset="utf-8">
<link rel="preconnect" href="https://gcore.jsdelivr.net" crossorigin>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#7C3AED">
<title><?= e(cfg('site.name', 'Kimi游戏榜')) ?> · 六维综合排行榜</title>
<meta name="description" content="<?= e(cfg('site.name', 'Kimi游戏榜')) ?>：收录 Kimi 社区公开作品的六维评分榜单，覆盖游戏、工具、文学与二创，支持搜索、评论与 AI 问答。">
<meta name="format-detection" content="telephone=no">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e(cfg('site.name', 'Kimi游戏榜')) ?> · 六维综合排行榜">
<meta property="og:description" content="Kimi 社区作品六维评分榜单：创意 / 体验 / 深度 / 成本 / 态度 / 热度。">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%236D3BF5'/%3E%3Cpath d='M7 7h10a4 4 0 0 1 4 4v2a3 3 0 0 1-3 3h-.9a2 2 0 0 1-1.6-.8l-.5-.7a1.5 1.5 0 0 0-2.4 0l-.5.7A2 2 0 0 1 10.4 16H10a3 3 0 0 1-3-3v-2a4 4 0 0 1 4-4zm.5 3.5h-1.5v1.5H4.5v1.5h1.5v1.5h1.5v-1.5H9v-1.5H7.5V10.5zm8 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm2.2 2.6a1 1 0 1 0 0 2 1 1 0 0 0 0-2z' fill='%23fff'/%3E%3C/svg%3E">
<script>window.__CAPTCHA = <?= json_encode($__captcha, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script>window.__FESTIVAL = <?= json_encode($__festival, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script>window.__BANNED = <?= (int)$__bannedId ?>;</script>
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
/* 皮肤：国庆专版由前端按北京时间判断（9/30–10/8）自动启用，窗口结束自动回退到用户所选皮肤。
   这里输出一段同步脚本而非直接内联皮肤文件 —— 它在 head 解析期同步决定并写入 <link>，
   不会出现样式闪动；窗口外不产生任何额外请求。 */
?>
<script>
(function () {
  var V = <?= json_encode(APP_VERSION) ?>;
  var skin = <?= json_encode($SKIN) ?>;          /* 服务端已按白名单校验过 */
  /* 当前节日由服务端（北京时间）判定，前端只负责套用 —— 规则只有 app/festival.php 一处 */
  var FEST = <?= json_encode($__festival, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var now = FEST.now || '';
  var meta = now ? FEST.list[now] : null;
  /* 用户在窗口内主动切走过皮肤 = 不参与节日专版，尊重其选择 */
  var optout = /(?:^|;\s*)kimgr_skin_optout=1/.test(document.cookie);
  var on = now !== '' && !optout;
  var r = document.documentElement;

  r.dataset.skin = on ? now : skin;
  if (on) { r.setAttribute('data-festival', '1'); }
  /* 强制暗色的节日（万圣夜）：窗口内不论用户偏好一律暗色 */
  if (on && meta && meta.dark) { r.dataset.theme = 'dark'; }

  var file = on ? now : skin;                    /* 液态玻璃 = app.css 默认样式，无需额外文件 */
  if (file !== 'glass') {
    document.write('<link rel="stylesheet" href="assets/css/skins/' + file + '.css?v=' + V + '">');
  }
})();
</script>
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

<!-- 节日装饰层：各节日的装饰都放在这里，由皮肤 CSS 决定显示哪一组
     （默认 display:none；pointer-events:none，绝不拦截任何交互） -->
<div class="festival-deco" aria-hidden="true">
  <!-- 国庆：灯笼 + 缓落星点 -->
  <span class="fd-lantern fd-lantern--l"><i></i></span>
  <span class="fd-lantern fd-lantern--r"><i></i></span>
  <span class="fd-star fd-star--1"></span>
  <span class="fd-star fd-star--2"></span>
  <span class="fd-star fd-star--3"></span>
  <span class="fd-star fd-star--4"></span>
  <span class="fd-star fd-star--5"></span>
  <span class="fd-star fd-star--6"></span>
  <!-- 辛亥纪念：铁血星芒 + 顶部光晕 -->
  <span class="fd-xh-glow"></span>
  <span class="fd-xh-star fd-xh-star--1"></span>
  <span class="fd-xh-star fd-xh-star--2"></span>
  <span class="fd-xh-star fd-xh-star--3"></span>
  <span class="fd-xh-star fd-xh-star--4"></span>
  <span class="fd-xh-star fd-xh-star--5"></span>
  <span class="fd-xh-star fd-xh-star--6"></span>
  <!-- 抗美援朝纪念：红星 + 远山剪影 + 微光 -->
  <span class="fd-km-star"></span>
  <span class="fd-km-hill fd-km-hill--1"></span>
  <span class="fd-km-hill fd-km-hill--2"></span>
  <span class="fd-km-spark fd-km-spark--1"></span>
  <span class="fd-km-spark fd-km-spark--2"></span>
  <span class="fd-km-spark fd-km-spark--3"></span>
  <!-- 万圣夜：月亮 + 蝙蝠 + 南瓜灯 + 地面雾 -->
  <span class="fd-hw-moon"></span>
  <span class="fd-hw-bat fd-hw-bat--1"></span>
  <span class="fd-hw-bat fd-hw-bat--2"></span>
  <span class="fd-hw-bat fd-hw-bat--3"></span>
  <span class="fd-hw-pumpkin"><i></i></span>
  <span class="fd-hw-fog fd-hw-fog--1"></span>
  <span class="fd-hw-fog fd-hw-fog--2"></span>
</div>

<!-- 全局加载条（API 请求自动显隐） -->
<div id="globalBar" aria-hidden="true"></div>

<!-- 应用根 -->
<div id="app">

  <!-- 公告横幅 -->
  <div class="announce" id="announceBar"<?= $announceText === '' ? ' hidden' : '' ?>>
    <span class="announce-tag">
      <svg viewBox="0 0 24 24" class="ic"><path d="M3 10v4h3l5 4V6L6 10H3zm13.5 2a4.5 4.5 0 0 0-2.5-4v8a4.5 4.5 0 0 0 2.5-4z"/></svg>
      公告
    </span>
    <div class="announce-track"><span id="announceText"><?= e($announceText) ?></span></div>
  </div>

  <!-- 顶栏：品牌 + 控制组 -->
  <header class="topbar glass" id="topbar">
    <button class="icon-btn" id="btnBack" aria-label="返回" hidden>
      <svg viewBox="0 0 24 24" class="ic"><path d="M15.4 5L8 12l7.4 7L17 17.6 11.2 12 17 6.4z"/></svg>
    </button>
    <div class="brand" id="brandHome" role="button" tabindex="0">
      <span class="logo">
        <svg viewBox="0 0 24 24" class="ic"><path d="M7 6h10a5 5 0 0 1 5 5v3a4 4 0 0 1-4 4h-1.2a3 3 0 0 1-2.4-1.2l-.6-.8a2 2 0 0 0-3.2 0l-.6.8A3 3 0 0 1 7.6 18H7a4 4 0 0 1-4-4v-3a5 5 0 0 1 5-5zm1.5 4.5h-2v2h-1.5v-2h-2v-1.5h2v-2h1.5v2h2v1.5zM16 10.2a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2zm2.8 0a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z"/></svg>
      </span>
      <span class="brand-name">kimi游戏榜</span>
    </div>
    <div class="ctrl">
      <button class="icon-btn" id="btnSearch" aria-label="搜索">
        <svg viewBox="0 0 24 24" class="ic"><path d="M10 4a6 6 0 1 0 3.7 10.7l4.3 4.3 1.4-1.4-4.3-4.3A6 6 0 0 0 10 4zm0 2a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/></svg>
      </button>
      <button class="icon-btn" id="btnTheme" aria-label="切换深浅色">
        <svg viewBox="0 0 24 24" class="ic" id="themeIcon"><path d="M12 3q-.5 0-.5.5t.5.5q3.7 0 6.3 2.7T21 13q0 2.4-1.2 4.4.5-1.3.5-2.7 0-3-2.1-5.1T13 7.5q-1.8 0-3.4.9t-2.5 2.4q-.9 1.6-.9 3.4 0 1.2.4 2.2-2.1-2-2.1-4.9 0-3.7 2.6-6.3T12 3z"/></svg>
      </button>
      <button class="icon-btn" id="btnMenu" aria-label="菜单">
        <svg viewBox="0 0 24 24" class="ic"><path d="M4 7h16v2H4V7zm0 4h16v2H4v-2zm0 4h16v2H4v-2z"/></svg>
      </button>
    </div>
  </header>

  <!-- 搜索面板 -->
  <div class="search-panel glass" id="searchPanel" hidden>
    <input type="text" id="searchInput" placeholder="搜索作品标题或作者…" autocomplete="off">
    <button class="btn-ghost" id="searchGo">搜索</button>
    <button class="icon-btn" id="searchClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" class="ic"><path d="M6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4 6.4 5z"/></svg>
    </button>
  </div>

  <!-- 页面容器（首屏骨架，JS 就绪后原子替换） -->
  <main id="view" class="view">
    <div class="skeleton" style="height:72px"></div>
    <div class="skeleton"></div>
    <div class="skeleton"></div>
    <div class="skeleton"></div>
  </main>

  <!-- 底部三栏 -->
  <nav class="tabbar glass" id="tabbar">
    <button class="tab" data-tab="rank" data-route="#/rank">
      <svg viewBox="0 0 24 24" class="ic"><path d="M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 5.2 12H4a3 3 0 0 1 0-6h2V3zm0 5H4a1 1 0 0 0 0 2h2V8zm14 0h-2v2h2a1 1 0 0 0 0-2z"/></svg>
      <span>榜单</span>
    </button>
    <button class="tab" data-tab="chat" data-route="#/chat">
      <svg viewBox="0 0 24 24" class="ic"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 4V6a2 2 0 0 1 2-2z"/></svg>
      <span>对话</span>
    </button>
    <button class="tab" data-tab="mine" data-route="#/mine">
      <svg viewBox="0 0 24 24" class="ic"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z"/></svg>
      <span>我的</span>
    </button>
  </nav>
</div>

<!-- 汉堡抽屉 -->
<div class="drawer-scrim" id="drawerScrim" hidden></div>
<aside class="drawer glass" id="drawer" hidden>
  <div class="drawer-head">
    <span>导航菜单</span>
    <button class="icon-btn" id="drawerClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" class="ic"><path d="M6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4 6.4 5z"/></svg>
    </button>
  </div>
  <div class="drawer-list" id="drawerList"></div>
</aside>

<!-- 对话框 -->
<div class="dialog-scrim" id="dialogScrim" hidden>
  <div class="dialog glass" id="dialog" role="dialog" aria-modal="true"></div>
</div>

<!-- 回到顶部 -->
<button class="totop" id="toTop" aria-label="回到顶部" hidden>
  <svg viewBox="0 0 24 24" class="ic"><path d="M12 6l6 6-1.4 1.4L13 9.8V19h-2V9.8L7.4 13.4 6 12z"/></svg>
</button>

<!-- 轻提示 -->
<div class="toast-wrap" id="toastWrap" aria-live="polite"></div>

<script>window.__SITE__ = { name: <?= json_encode((string)cfg('site.name', 'Kimi游戏榜')) ?>, ver: <?= json_encode(APP_VERSION) ?>, timezone: <?= json_encode((string)cfg('site.timezone', 'Asia/Shanghai')) ?> };</script>
<link rel="preload" as="script" href="assets/js/app.js?v=<?= APP_VERSION ?>">
<script type="module" src="assets/js/app.js?v=<?= APP_VERSION ?>" onerror="(function(){if(window.__lgRetry)return;window.__lgRetry=1;var s=document.createElement('script');s.type='module';s.src='assets/js/app.js?v=<?= APP_VERSION ?>&r='+Date.now();document.body.appendChild(s);})()"></script>
</body>
</html>
