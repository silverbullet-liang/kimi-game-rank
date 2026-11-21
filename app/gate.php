<?php
/**
 * 访问准入闸门（不是封禁）
 * ------------------------------------------------------------
 * 命中「IP 池」的访问不直接拒绝，而是先过人机验证：
 * 通过后发一张票（cookie kimgr_gate，默认 2 小时），票绑定 IP 指纹，
 * 票在有效期内此来源像正常访客一样使用全站。
 *
 * 验证通道与站内完全一致（见 app/captcha.php）：
 *   Cloudflare Turnstile（唯一通道，没有备用、不降级）
 *
 * 池的来源，任一命中即算：
 *   ① storage/gate_pool.txt —— 每行一条：明文 IP 或 64 位指纹，支持 8+ 位指纹前缀，# 开头为注释
 *   ② settings.gate.ip_pool  —— 同格式，供运行时写入
 *   ③ banned_ips 表           —— 违纪封禁过的来源指纹（可用 gate.include_banned=0 关掉）
 *
 * 必须知道的局限：请求走到这里，主机已经记了一次 hit。
 * 这道闸门挡的是裸脚本、爬虫、批量抓接口，**不减少主机配额消耗**。
 */

define('GATE_COOKIE', 'kimgr_gate');
define('GATE_TTL_DEFAULT', 7200);

/** 票有效期（秒），300–86400 */
function gate_ttl(): int
{
    $t = (int)setting_get('gate.ttl', (string)GATE_TTL_DEFAULT);
    if ($t < 300)    { $t = 300; }
    if ($t > 86400)  { $t = 86400; }
    return $t;
}

/** 池原文行（文件 + 设置，各请求只读一次） */
function gate_pool_lines(): array
{
    if (isset($GLOBALS['__GATE_POOL'])) { return $GLOBALS['__GATE_POOL']; }

    $lines = array();
    $file  = APP_ROOT . '/storage/gate_pool.txt';
    if (is_file($file) && is_readable($file)) {
        $raw = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') { $lines = preg_split('/\r\n|\r|\n/', $raw); }
    }
    $more = (string)setting_get('gate.ip_pool', '');
    if ($more !== '') { $lines = array_merge($lines, preg_split('/\r\n|\r|\n/', $more)); }
    $GLOBALS['__GATE_POOL'] = $lines;
    return $lines;
}

/** 清池缓存（改了池之后即时生效） */
function gate_pool_reset()
{
    unset($GLOBALS['__GATE_POOL'], $GLOBALS['__GATE_INDEX'], $GLOBALS['__GATE_BANNED']);
}

/**
 * 池索引：64 位指纹走集合查找，8–63 位前缀单独成表。
 * ------------------------------------------------------------
 * 池子可能上万条（两次攻击的来源），逐行比较 + 每行算一次 sha256 会让每个请求多花几十毫秒，
 * 所以一次性建成索引，命中判定退化成一次数组查找。
 */
function gate_pool_index(): array
{
    if (isset($GLOBALS['__GATE_INDEX'])) { return $GLOBALS['__GATE_INDEX']; }
    $exact = array();
    $prefix = array();
    foreach (gate_pool_lines() as $raw) {
        $l = trim((string)$raw);
        if ($l === '' || $l[0] === '#') { continue; }
        $v = strtolower($l);
        if (preg_match('/^[0-9a-f]{64}$/', $v)) { $exact[$v] = 1; continue; }
        if (preg_match('/^[0-9a-f]{8,63}$/', $v)) { $prefix[] = $v; continue; }
        if (filter_var($l, FILTER_VALIDATE_IP)) { $exact[discipline_ip_hash($l)] = 1; }
    }
    $GLOBALS['__GATE_INDEX'] = array('exact' => $exact, 'prefix' => $prefix);
    return $GLOBALS['__GATE_INDEX'];
}

/** 指纹是否命中池 */
function gate_pool_match(string $hash): bool
{
    if ($hash === '') { return false; }
    $idx = gate_pool_index();
    if (isset($idx['exact'][$hash])) { return true; }
    foreach ($idx['prefix'] as $p) {
        if (strpos($hash, $p) === 0) { return true; }
    }
    return false;
}

/** 违纪封禁过的来源指纹（缓存 5 分钟，避免每请求查表） */
function gate_banned_hit(string $hash): bool
{
    if ($hash === '') { return false; }
    if ((string)setting_get('gate.include_banned', '1') === '0') { return false; }

    $set = cache_get('gate_banned', 300);
    if (!is_array($set)) {
        $set = array();
        try {
            $rows = db_all('SELECT ip_hash FROM banned_ips WHERE ip_hash <> ? LIMIT 5000', array(''));
            foreach ($rows as $r) {
                $h = isset($r['ip_hash']) ? (string)$r['ip_hash'] : '';
                if ($h !== '') { $set[] = $h; }
            }
        } catch (Throwable $e) {
            app_log('gate banned pool: ' . $e->getMessage());
        }
        cache_set('gate_banned', $set, 300);
    }

    /* 缓存里是完整指纹，做精确比对（不用前缀，避免撞上哈希前段） */
    if (!isset($GLOBALS['__GATE_BANNED'])) {
        $map = array();
        foreach ($set as $h) { $map[(string)$h] = 1; }
        $GLOBALS['__GATE_BANNED'] = $map;
    }
    return isset($GLOBALS['__GATE_BANNED'][$hash]);
}

/** 发票：exp . hmac(绑定指纹) */
function gate_ticket_make(string $hash, int $exp): string
{
    $key = (string)cfg('secrets.cron_key', 'kimgr');
    return $exp . '.' . substr(hash_hmac('sha256', 'gate|' . $hash . '|' . $exp, $key), 0, 32);
}

/** 验票：未过期 + 指纹一致（指纹变了票即失效） */
function gate_ticket_ok(string $val, string $hash): bool
{
    $p = explode('.', $val);
    if (count($p) !== 2) { return false; }
    $exp = (int)$p[0];
    if ($exp < time()) { return false; }
    return hash_equals(gate_ticket_make($hash, $exp), $val);
}

/** 发 cookie（api/gate.php 验证通过后调用） */
function gate_grant(string $hash)
{
    $exp = time() + gate_ttl();
    $val = gate_ticket_make($hash, $exp);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_VERSION_ID >= 70300) {
        setcookie(GATE_COOKIE, $val, array(
            'expires'  => $exp,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    } else {
        setcookie(GATE_COOKIE, $val, $exp, '/; samesite=Lax', '', $https, true);
    }
}

/** 当前来源是否需要验证（命中池且无有效票） */
function gate_required(): bool
{
    /* 命令行（定时任务、脚本）不走闸门；自检脚本用 GATE_TEST 打开以便断言 */
    if (PHP_SAPI === 'cli' && !defined('GATE_TEST')) { return false; }
    /* 验证通道被关掉时闸门不生效 —— 没有通道就没法要求别人过验证 */
    if (!captcha_enabled()) { return false; }
    $ip = client_ip();
    if ($ip === '') { return false; }
    $hash = discipline_ip_hash($ip);
    if ($hash === '') { return false; }
    if (!gate_pool_match($hash) && !gate_banned_hit($hash)) { return false; }
    $ck = isset($_COOKIE[GATE_COOKIE]) ? (string)$_COOKIE[GATE_COOKIE] : '';
    if ($ck !== '' && gate_ticket_ok($ck, $hash)) { return false; }
    return true;
}

/**
 * 统一入口：命中即拦。API 返回 403（前端据此刷新出验证页），页面直接渲染验证页。
 * api/gate.php 自身必须放行，否则永远走不到验证。
 */
function gate_guard()
{
    if (PHP_SAPI === 'cli') { return; }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if (strpos($uri, '/api/gate.php') !== false) { return; }
    if (!gate_required()) { return; }

    app_log('gate: verify required uri=' . substr($uri, 0, 120));

    if (is_api_request()) {
        header('X-Gate: 1');
        json_out(403, '当前来源需要先完成人机验证，请刷新页面');
    }
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Gate: 1');
    }
    echo gate_page_html();
    exit;
}

/** 验证页（自包含：不引站内 css/js，套用站内液态玻璃观感与主色） */
function gate_page_html(): string
{
    $cfg = captcha_client_config();
    if (empty($cfg['on'])) {
        /* 验证通道被关掉了，闸门就不该生效 —— 放行而不是把人挡在门外 */
        return '';
    }
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $json = str_replace('</', '<\/', (string)$json);

    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>确认一下再进 · 人机验证</title>'
        . '<style>'
        . '*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}'
        . 'html,body{height:100%;margin:0}'
        . 'body{display:flex;align-items:center;justify-content:center;padding:20px;'
        . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;'
        . 'color:#1c1c1e;background:#f6f5f9}'
        . 'body::before{content:"";position:fixed;inset:-20%;z-index:-1;'
        . 'background:radial-gradient(38% 32% at 22% 18%,rgba(124,77,255,.30),transparent 70%),'
        . 'radial-gradient(34% 30% at 82% 26%,rgba(88,140,255,.22),transparent 70%),'
        . 'radial-gradient(42% 36% at 60% 88%,rgba(180,120,255,.20),transparent 72%)}'
        . '.card{width:100%;max-width:420px;padding:26px 22px 22px;border-radius:22px;'
        . 'background:rgba(255,255,255,.72);border:1px solid rgba(255,255,255,.85);'
        . 'box-shadow:0 18px 50px -18px rgba(60,30,120,.32),inset 0 1px 0 rgba(255,255,255,.9);'
        . '-webkit-backdrop-filter:blur(22px) saturate(160%);backdrop-filter:blur(22px) saturate(160%)}'
        . 'h1{margin:0 0 6px;font-size:19px;letter-spacing:.2px}'
        . 'p{margin:0;color:#5b5b66;font-size:13.5px}'
        . '.why{margin:14px 0 18px;padding:10px 12px;border-radius:12px;font-size:12.5px;line-height:1.65;'
        . 'color:#4a3b6b;background:rgba(124,77,255,.09);border:1px solid rgba(124,77,255,.16)}'
        . '#slot{min-height:70px;display:flex;align-items:center;justify-content:center;flex-wrap:wrap}'
        . '#hint{margin-top:10px;font-size:12.5px;color:#7a7a86;text-align:center;min-height:18px}'
        . '.ok{color:#1a7f45 !important}'
        . '.btns{margin-top:14px;display:flex;gap:10px}'
        . 'button{flex:1;padding:11px 14px;border-radius:12px;border:1px solid rgba(124,77,255,.28);'
        . 'background:rgba(124,77,255,.10);color:#5b21b6;font:inherit;font-weight:600;cursor:pointer}'
        . 'button:active{transform:translateY(1px)}'
        . 'button[disabled]{opacity:.5;cursor:default}'
        . '.cap-ct{display:flex;align-items:center;gap:10px;cursor:pointer}'
        . '@media(prefers-color-scheme:dark){'
        . 'body{color:#eceaf3;background:#141218}'
        . '.card{background:rgba(32,29,40,.72);border-color:rgba(255,255,255,.10);'
        . 'box-shadow:0 18px 50px -18px rgba(0,0,0,.7),inset 0 1px 0 rgba(255,255,255,.06)}'
        . 'p{color:#a8a3b8}.why{color:#d6cdf5;background:rgba(124,77,255,.16);border-color:rgba(124,77,255,.28)}'
        . '#hint{color:#918ba4}button{color:#cfc2ff;background:rgba(124,77,255,.18);border-color:rgba(124,77,255,.36)}}'
        . '</style></head><body>'
        . '<div class="card">'
        . '<h1>确认一下你是人</h1>'
        . '<p>完成后自动进入，本机 2 小时内不再询问。</p>'
        . '<div class="why">这个来源刚刚在极短时间内发起了大量请求，站点正在承受流量攻击。'
        . '不是针对你 —— 验证一次就照常使用。</div>'
        . '<div id="slot"></div>'
        . '<div id="hint">正在加载验证组件…</div>'
        . '<div class="btns"><button id="retry" type="button">换一种方式重试</button></div>'
        . '</div>'
        . '<script>window.__GATE_CFG=' . $json . ';</script>'
        . '<script>' . gate_page_js() . '</script>'
        . '</body></html>';
}

/** 验证页脚本：只有 Cloudflare Turnstile 一条路，加载不出来就提示刷新 */
function gate_page_js(): string
{
    return <<<'JS'
(function () {
  var cfg = window.__GATE_CFG || {};
  var slot = document.getElementById('slot');
  var hint = document.getElementById('hint');
  var retry = document.getElementById('retry');
  var done = false, token = '', tsWidget = null;

  function say(t, ok) { hint.textContent = t; hint.className = ok ? 'ok' : ''; }

  function submit() {
    if (done || !token) { return; }
    done = true; say('验证通过，正在进入…', true);
    fetch('/api/gate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: 'action=verify&cap_token=' + encodeURIComponent(token) + '&cap_channel=turnstile'
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.code === 0) { location.reload(); return; }
      done = false; token = ''; say((j && j.msg) || '验证未通过，请重试'); restart();
    }).catch(function () { done = false; token = ''; say('网络异常，请重试'); restart(); });
  }

  function mount() {
    if (!cfg.sitekey || !cfg.script) { say('人机验证未配置，请联系管理员'); return; }
    var s = document.createElement('script');
    s.src = cfg.script;
    s.onload = function () {
      if (!window.turnstile) { say('人机验证加载失败，请刷新重试'); return; }
      var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme:dark)').matches;
      slot.innerHTML = '';
      try {
        tsWidget = window.turnstile.render(slot, {
          sitekey: cfg.sitekey,
          theme: dark ? 'dark' : 'light',
          language: (navigator.language || 'zh-CN'),
          size: 'flexible',
          callback: function (t) { token = t; submit(); },
          'expired-callback': function () { token = ''; say('验证已过期，请重新验证'); },
          'timeout-callback': function () { token = ''; say('验证超时，请重新验证'); },
          'error-callback': function () { token = ''; say('验证出错，请点下方重试'); }
        });
        say('');
      } catch (e) { say('人机验证加载失败，请刷新重试'); }
    };
    s.onerror = function () { say('人机验证加载失败，请刷新重试'); };
    document.head.appendChild(s);
  }

  function restart() {
    done = false; token = '';
    if (window.turnstile && tsWidget !== null) { try { window.turnstile.remove(tsWidget); } catch (e) {} tsWidget = null; }
    mount();
  }

  retry.addEventListener('click', restart);
  mount();
})();
JS;
}
