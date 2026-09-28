<?php
/**
 * 错误页（404 / 403 / 500 / 502 / 503 等）
 * ------------------------------------------------------------
 * 设计约束：
 * 1. 完全自包含——不 require bootstrap、不读数据库、不引用任何外部 CSS/JS。
 *    错误发生时（含数据库不可用）也必须能正常显示，所以样式全部内联。
 * 2. 风格自动跟随当前皮肤：读 kimgr_skin cookie，按皮肤注入对应的设计语言。
 * 3. 主题跟随系统深浅色，也支持 ?theme=dark 强制。
 *
 * 由 .htaccess 的 ErrorDocument 指向本文件；也可直接访问 error.php?code=503
 */
declare(strict_types=1);

/* ---------- 状态码与文案 ---------- */
$MAP = array(
    400 => array('请求有误', '这个请求我们没能读懂。检查一下地址是否正确，或者返回首页重新开始。'),
    403 => array('没有权限', '你没有查看这个页面的权限。如果这是误判，可以到「我的 → 反映问题」告诉我们。'),
    404 => array('页面不见了', '这个地址没有对应的内容。可能是链接过期了，或者地址打错了一个字符。'),
    405 => array('方法不允许', '这个地址不接受这种请求方式。返回首页重新操作即可。'),
    408 => array('请求超时', '等待你的请求太久了。网络恢复后可以再试一次。'),
    429 => array('操作太频繁', '短时间内请求次数过多，已被暂时限制。稍等一会儿再来。'),
    500 => array('服务出错了', '服务器遇到了一个意外问题。这通常不是你的原因，可以稍后重试。'),
    502 => array('网关无响应', '上游服务没有正常应答。通常是临时的，稍后重试即可。'),
    503 => array('暂时不可用', '站点正在维护或负载过高，请稍后再来。'),
);

$code = 0;
if (isset($_GET['code'])) {
    $code = (int)$_GET['code'];
} elseif (isset($_SERVER['REDIRECT_STATUS'])) {
    $code = (int)$_SERVER['REDIRECT_STATUS'];
}
if (!isset($MAP[$code])) { $code = 404; }

/* ---------- 皮肤与主题 ---------- */
$SKINS = array('glass', 'md3', 'pixel', 'sketch', 'brutal');
$SKIN  = isset($_COOKIE['kimgr_skin']) ? (string)$_COOKIE['kimgr_skin'] : 'glass';
if (!in_array($SKIN, $SKINS, true)) { $SKIN = 'glass'; }

$THEME = 'light';
if (isset($_GET['theme']) && ($_GET['theme'] === 'dark' || $_GET['theme'] === 'light')) {
    $THEME = (string)$_GET['theme'];
} elseif (isset($_COOKIE['kimgr_theme']) && $_COOKIE['kimgr_theme'] === 'dark') {
    $THEME = 'dark';
}

$ANIM = isset($_COOKIE['kimgr_nav']) && $_COOKIE['kimgr_nav'] === '0' ? 'off' : 'on';

/* 站点名：不读库，避免数据库不可用时连名字都显示不出 */
$SITE = 'Kimi游戏榜';

if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    /* API 客户端拿到 JSON，而不是一页 HTML。
       字段名必须与全站信封一致（code / msg / data）：这里原先发的是
       { ok, code, error }，前端按 json.msg 取值取到 undefined，只能退回
       「请求失败」四个字，真正的原因（没有权限 / 页面不见了 / 服务出错了）
       被完全吞掉，排查时无迹可寻。 */
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'code' => $code,
            'msg'  => $MAP[$code][0] . '：' . $MAP[$code][1],
            'data' => null,
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
$T = $MAP[$code];
?>
<!DOCTYPE html>
<html lang="zh-CN" data-skin="<?= $SKIN ?>" data-theme="<?= $THEME ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= $code ?> · <?= $T[0] ?> · <?= $SITE ?></title>
<style>
/* ============================================================
 * 基础层：结构、排版、通用交互（与站点一致的尺度变量）
 * ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
:root {
  --accent: #7C3AED;
  --radius-xl: 28px; --radius-lg: 20px; --radius-md: 14px; --radius-sm: 10px;
  --font: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", system-ui, sans-serif;
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
html[data-theme="light"] {
  --bg: #f4f2fa; --surface: #ffffff; --surface-2: #f6f4fb;
  --text: #1b1a22; --text-2: #5c5a68; --text-3: #8b8896;
  --border: rgba(20, 18, 40, .10); --border-strong: rgba(20, 18, 40, .18);
  --btn-fg: #ffffff;
}
html[data-theme="dark"] {
  --bg: #14131a; --surface: #1e1c26; --surface-2: #24222e;
  --text: #edeaf3; --text-2: #a8a4b6; --text-3: #7d7a8c;
  --border: rgba(255, 255, 255, .12); --border-strong: rgba(255, 255, 255, .22);
  --btn-fg: #ffffff;
}
html, body { height: 100%; }
body {
  margin: 0; background: var(--bg); color: var(--text);
  font-family: var(--font); font-size: 15px; line-height: 1.7;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 32px 20px; min-height: 100dvh;
  -webkit-font-smoothing: antialiased;
}
.wrap { width: 100%; max-width: 460px; }
.panel {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-xl); padding: 36px 28px 30px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, .04), 0 12px 32px -12px rgba(20, 18, 40, .18);
}
.code {
  font-family: var(--mono); font-size: 64px; font-weight: 700; line-height: 1;
  letter-spacing: -.02em; color: var(--accent); margin: 0 0 6px;
  font-variant-numeric: tabular-nums;
}
h1 { font-size: 21px; font-weight: 600; margin: 0 0 12px; letter-spacing: .1px; }
p { color: var(--text-2); margin: 0; font-size: 14.5px; }
.acts { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
.btn {
  appearance: none; border: 1px solid transparent; cursor: pointer;
  font: inherit; font-size: 14px; font-weight: 500; letter-spacing: .1px;
  height: 42px; padding: 0 22px; border-radius: var(--radius-lg);
  background: var(--accent); color: var(--btn-fg);
  display: inline-flex; align-items: center; gap: 8px;
  transition: transform .12s cubic-bezier(.2, 0, 0, 1), filter .16s, opacity .16s;
}
.btn:hover { filter: brightness(1.06); }
.btn:active { transform: scale(.985); }
.btn.ghost {
  background: transparent; color: var(--text);
  border-color: var(--border-strong);
}
.btn.ghost:hover { background: var(--surface-2); }
.btn svg { width: 16px; height: 16px; fill: currentColor; flex: 0 0 auto; }
.foot {
  margin-top: 22px; font-size: 12.5px; color: var(--text-3);
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.foot .dot { width: 3px; height: 3px; border-radius: 50%; background: currentColor; opacity: .6; }
::selection { background: color-mix(in srgb, var(--accent) 26%, transparent); }
@media (max-width: 380px) { .code { font-size: 52px; } .panel { padding: 28px 20px 24px; } }

/* ============================================================
 * 风格层：五套设计语言，按 cookie 注入其中一套
 * ============================================================ */

<?php if ($SKIN === 'glass'): ?>
/* —— 液态玻璃：浮层玻璃、高光边、柔和光晕 —— */
body { background: radial-gradient(120% 90% at 12% 0%, #e9e2ff 0%, #f4f2fa 42%, #eef1fa 100%); }
html[data-theme="dark"] body { background: radial-gradient(120% 90% at 12% 0%, #241d3d 0%, #14131a 46%, #171a24 100%); }
.panel {
  background: color-mix(in srgb, var(--surface) 62%, transparent);
  -webkit-backdrop-filter: blur(28px) saturate(180%);
  backdrop-filter: blur(28px) saturate(180%);
  border: 1px solid color-mix(in srgb, #fff 46%, transparent);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, .55), 0 20px 50px -18px rgba(30, 20, 70, .30);
}
html[data-theme="dark"] .panel {
  border-color: rgba(255, 255, 255, .14);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, .12), 0 20px 50px -18px rgba(0, 0, 0, .6);
}
.code { background: linear-gradient(96deg, #6D3BF5, #a855f7); -webkit-background-clip: text; background-clip: text; color: transparent; }
.btn { background: linear-gradient(96deg, #6D3BF5, #8b5cf6); border-radius: 999px; }

<?php elseif ($SKIN === 'md3'): ?>
/* —— Material Design 3：色面层级、28 圆角、双层高程 —— */
html[data-theme="light"] { --bg: #fef7ff; --surface: #fef7ff; --surface-2: #ece6f0; --text: #1d1b20; --text-2: #49454f; --text-3: #79747e; }
html[data-theme="dark"]  { --bg: #141218; --surface: #1d1b20; --surface-2: #36343b; --text: #e6e0e9; --text-2: #cac4d0; --text-3: #938f99; }
.panel {
  border: 0; background: color-mix(in srgb, var(--accent) 5%, var(--surface));
  border-radius: 28px; padding: 40px 30px 32px;
  box-shadow: 0 1px 3px 1px rgba(0, 0, 0, .10), 0 2px 6px 2px color-mix(in srgb, var(--accent) 16%, transparent);
}
.code { font-family: var(--font); font-size: 56px; font-weight: 400; letter-spacing: 0; }
h1 { font-size: 22px; font-weight: 400; line-height: 28px; }
p { letter-spacing: .5px; }
.btn { border-radius: 999px; height: 40px; box-shadow: 0 1px 3px 1px rgba(0, 0, 0, .12); }
.btn.ghost { background: color-mix(in srgb, var(--accent) 8%, transparent); border: 0; color: color-mix(in srgb, var(--accent) 74%, #101014); }
.foot { letter-spacing: .4px; }

<?php elseif ($SKIN === 'pixel'): ?>
/* —— 像素风：直角、硬边描边、台阶角 —— */
html[data-theme="light"] { --bg: #dfe4f2; --surface: #f7f9ff; --surface-2: #e8ecf8; --text: #101820; --text-2: #3a4457; --text-3: #6a7488; --accent: #2038ec; }
html[data-theme="dark"]  { --bg: #101828; --surface: #1a2334; --surface-2: #222d42; --text: #e8eefc; --text-2: #9fb0cd; --text-3: #6d7f9c; --accent: #6f86ff; }
body { background-image: repeating-linear-gradient(0deg, rgba(16, 24, 40, .045) 0 1px, transparent 1px 3px); }
.panel {
  border: 2px solid #101820; border-radius: 0; background: var(--surface);
  box-shadow: 4px 0 0 0 #101820, -4px 0 0 0 #101820, 0 4px 0 0 #101820, 0 -4px 0 0 #101820, 8px 8px 0 0 rgba(16, 24, 40, .55);
}
html[data-theme="dark"] .panel { border-color: #8fa4c8; box-shadow: 4px 0 0 0 #8fa4c8, -4px 0 0 0 #8fa4c8, 0 4px 0 0 #8fa4c8, 0 -4px 0 0 #8fa4c8, 8px 8px 0 0 rgba(0, 0, 0, .6); }
.code { font-family: var(--mono); color: #2038ec; letter-spacing: 2px; text-shadow: 3px 3px 0 rgba(16, 24, 40, .22); }
html[data-theme="dark"] .code { color: #6f86ff; }
h1, .btn { font-weight: 700; letter-spacing: .5px; }
.btn { border-radius: 0; border: 2px solid #101820; height: 44px; box-shadow: 3px 3px 0 0 #101820; }
.btn:active { transform: translate(2px, 2px); box-shadow: 1px 1px 0 0 #101820; }
.btn.ghost { background: var(--surface); color: var(--text); }

<?php elseif ($SKIN === 'sketch'): ?>
/* —— 手绘风：纸色、虚线描边、轻微歪斜 —— */
html[data-theme="light"] { --bg: #f4f1ea; --surface: #fffdf7; --surface-2: #f0ece1; --text: #2b2a26; --text-2: #5c584f; --text-3: #8a8578; --accent: #2b2a26; }
html[data-theme="dark"]  { --bg: #1c1b18; --surface: #262521; --surface-2: #302e29; --text: #f0ece1; --text-2: #bdb8a8; --text-3: #8d8779; --accent: #f0ece1; }
body { background-image: repeating-linear-gradient(0deg, rgba(60, 55, 40, .05) 0 1px, transparent 1px 26px); }
.panel {
  background: var(--surface); border: 2px dashed var(--text-2);
  border-radius: 18px 22px 16px 24px; transform: rotate(-.25deg);
  box-shadow: 3px 3px 0 rgba(60, 55, 40, .12);
}
html[data-theme="dark"] .panel { box-shadow: 3px 3px 0 rgba(0, 0, 0, .5); }
.code { color: var(--text); font-family: var(--mono); }
.code::after { content: ''; display: block; height: 6px; margin-top: 4px; border-radius: 6px;
  background: linear-gradient(90deg, rgba(250, 204, 21, .85), rgba(250, 204, 21, .25)); }
.btn { background: var(--text); color: var(--surface); border-radius: 14px 18px 12px 20px; }
.btn.ghost { background: transparent; color: var(--text); border: 2px dashed var(--text-2); }

<?php else: ?>
/* —— 新粗野：纯黑硬框、硬投影、零圆角、撞色 —— */
html[data-theme="light"] { --bg: #fdf6e3; --surface: #fffdf5; --surface-2: #fff2c9; --text: #101010; --text-2: #3a3a3a; --text-3: #6b6b6b; --accent: #ffd93d; }
html[data-theme="dark"]  { --bg: #17161a; --surface: #201f24; --surface-2: #2a282f; --text: #f5f4f7; --text-2: #c9c7cf; --text-3: #918f99; --accent: #ffd93d; }
.panel { border: 3px solid #101010; border-radius: 0; background: var(--surface); box-shadow: 8px 8px 0 0 #101010; }
html[data-theme="dark"] .panel { border-color: #f5f4f7; box-shadow: 8px 8px 0 0 #f5f4f7; }
.code { font-family: var(--mono); color: #101010; background: var(--accent); display: inline-block;
  padding: 2px 12px; border: 3px solid #101010; box-shadow: 4px 4px 0 0 #101010; }
html[data-theme="dark"] .code { color: #101010; border-color: #f5f4f7; box-shadow: 4px 4px 0 0 #f5f4f7; }
h1 { font-weight: 800; letter-spacing: -.3px; }
.btn { background: var(--accent); color: #101010; border: 3px solid #101010; border-radius: 0;
  font-weight: 800; height: 46px; box-shadow: 4px 4px 0 0 #101010; }
.btn:active { transform: translate(3px, 3px); box-shadow: 1px 1px 0 0 #101010; }
.btn.ghost { background: var(--surface); color: var(--text); border-color: #101010; }
html[data-theme="dark"] .btn { border-color: #f5f4f7; box-shadow: 4px 4px 0 0 #f5f4f7; }
html[data-theme="dark"] .btn.ghost { border-color: #f5f4f7; }
<?php endif; ?>

<?php if ($ANIM === 'on'): ?>
@keyframes errIn { from { opacity: 0; transform: translateY(10px) scale(.99); } to { opacity: 1; transform: none; } }
.panel { animation: errIn .32s cubic-bezier(.22, .85, .25, 1) both; }
@media (prefers-reduced-motion: reduce) { .panel { animation: none; } }
<?php endif; ?>
</style>
</head>
<body>
<main class="wrap">
  <div class="panel">
    <div class="code"><?= (int)$code ?></div>
    <h1><?= htmlspecialchars($T[0], ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($T[1], ENT_QUOTES, 'UTF-8') ?></p>
    <div class="acts">
      <a class="btn" href="/">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3z"/></svg>
        返回首页
      </a>
      <button class="btn ghost" type="button" onclick="if(history.length>1){history.back()}else{location.href='/'}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5l-7 7 7 7v-4h9v-6h-9z"/></svg>
        返回上一页
      </button>
    </div>
  </div>
  <div class="foot">
    <span><?= htmlspecialchars($SITE, ENT_QUOTES, 'UTF-8') ?></span>
    <span class="dot" aria-hidden="true"></span>
    <span><?= $code >= 500 ? '服务端问题' : '请求问题' ?></span>
    <span class="dot" aria-hidden="true"></span>
    <span><?= date('Y-m-d H:i') ?></span>
  </div>
</main>
</body>
</html>
