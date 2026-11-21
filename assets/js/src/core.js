import { API_BASE } from './base.js';
import { mountCaptcha, captchaConfig, captchaChannel } from './captcha.js';
import { festName } from './i18n.js';
/**
 * 核心：令牌管理、API 封装、UI 工具、启动引导
 * 信任边界：本层只负责「原样传递数据」，一切规整/校验均在后端完成。
 */

const LS_KEY = 'kimgr_token';
const LS_PREFS = 'kimgr_prefs';

export const state = {
  token: '',
  role: 'guest',
  uid: 0,
  uid8: '',          // 8 位可逆 UID
  username: t('游客'),
  avatar: '',
  settings: {},
  csrf: '',
  booted: false,
  nameFix: null,     // 存量用户名待更新：{ current, suggest } | null
  honours: {},       // 主题征集荣誉：用户名 → ['first','volume','mastery']
};

/* ============================================================
 * 图片加载降级
 * ============================================================
 * 本站不再做服务端图片代理（避免被主机判定为代理滥用与流量超支），
 * 所有图片一律浏览器直连。直连失败时不再回源本站重试，只把破损的图隐藏掉，
 * 避免页面留下裂图占位。
 */
export function installImageFallback() {
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG') { return; }
    img.style.visibility = 'hidden';
  }, true);
}
installImageFallback();

/* ============================================================
 * 本地存储
 * ============================================================ */
export function getToken() {
  try { return localStorage.getItem(LS_KEY) || ''; } catch (e) { return ''; }
}
/* 身份代号：每次令牌变更 +1。
   异步拿到的旧令牌（例如 401 后补发的游客令牌）在回填前要比对代号，
   否则会把用户刚登录成功的新令牌覆盖成游客令牌——表现为「一登录就失效」。 */
let __authEpoch = 0;
export function authEpoch() { return __authEpoch; }

export function setToken(t) {
  try { t ? localStorage.setItem(LS_KEY, t) : localStorage.removeItem(LS_KEY); } catch (e) {}
  state.token = t || '';
  __authEpoch++;
}

/* ---------- 设备标识 ----------
   localStorage 与 cookie 双写：任意一侧被清掉，另一侧还能认回来。
   仅用于封停匹配，不采集任何可识别个人的信息。 */
const DEV_KEY = 'kimgr_dev';
let __devId = '';

function readCookie(k) {
  try {
    const m = document.cookie.match(new RegExp('(?:^|; )' + k + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  } catch (e) { return ''; }
}

export function deviceId() {
  if (__devId) { return __devId; }
  let v = '';
  try { v = localStorage.getItem(DEV_KEY) || ''; } catch (e) {}
  if (!v) { v = readCookie(DEV_KEY); }
  if (!/^[A-Za-z0-9_-]{8,80}$/.test(v)) {
    v = 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }
  __devId = v;
  try { localStorage.setItem(DEV_KEY, v); } catch (e) {}
  try {
    if (readCookie(DEV_KEY) !== v) {
      document.cookie = DEV_KEY + '=' + encodeURIComponent(v) + ';path=/;max-age=63072000;SameSite=Lax';
    }
  } catch (e) {}
  return v;
}
export function getPrefs() {
  try { return JSON.parse(localStorage.getItem(LS_PREFS) || '{}') || {}; } catch (e) { return {}; }
}
export function setPrefs(p) {
  try { localStorage.setItem(LS_PREFS, JSON.stringify(p)); } catch (e) {}
}

/** 搜索页 AI 总结是否可见：游客取本机偏好、登录用户取账号设置，默认开 */
export function searchAiOn() {
  const v = state.role === 'guest'
    ? getPrefs().search_ai
    : ((state.settings || {}).search_ai);
  return Number(v) !== 0;
}

/* ============================================================
 * 限频预检（前端镜像后端的「滑动窗口计数」）
 * ------------------------------------------------------------
 * 后端才是唯一权威（前端可被绕过）。此处只为「提前告知」，避免用户填完内容
 * 才被拒。命中时抛 code=429 的错误，与后端 429 走完全相同的错误通道——
 * 文案、处理路径一致，不做二次提示。
 *
 * 口径必须与后端同构，否则就是「前端误拦」：
 * 后端 cooldown_guard() 只做「窗口内最多 N 次」的滑动计数，
 * 正常使用不受任何间隔限制（见 helpers.php）。若前端按「固定间隔」拦
 * （历史上正是如此：每类操作 60 秒一次），正常发言会被全部挡下，
 * 表现为「点发送后网络面板里根本没有请求」。
 * 数值须与后端 api/*.php 的 rate_limit 保持一致。
 * 主管理员不受限；点赞不在此列（后端区分新增/取消，取消不应被锁）。
 * ============================================================ */
const CD_LIMITS = {
  'lobby:send':           { label: t('发言'),     max: 15, window: 60   },
  'comments:create':      { label: t('发表评论'), max: 20, window: 60   },
  'ai:send_sync':         { label: t('AI 对话'),  max: 6,  window: 60   },
  'feedback:create':      { label: t('提交反馈'), max: 5,  window: 600  },
  'auth:register':        { label: t('注册'),     max: 10, window: 3600 },
};

/** 读写本机该动作的时间戳队列（只在窗口内的才留） */
function cdStore(file, action, hits) {
  const key = 'kimgr_cd_' + (state.uid || 0) + '_t(' + file + '):' + action;
  try {
    if (hits === undefined) {
      const raw = JSON.parse(localStorage.getItem(key) || '[]');
      return Array.isArray(raw) ? raw.filter(t => typeof t === 'number') : [];
    }
    localStorage.setItem(key, JSON.stringify(hits));
  } catch (e) {}
  return [];
}

function cdCheck(file, action, data) {
  if (state.role === 'admin') { return; }
  const lim = CD_LIMITS[file + ':' + action];
  if (!lim) { return; }
  if (data && Number(data.fallback) === 1) { return; }   // 同一轮对话的降级重放不重复计
  const now = Math.floor(Date.now() / 1000);
  const hits = cdStore(file, action).filter(t => t > now - lim.window);
  if (hits.length < lim.max) { return; }
  const left = Math.max(1, hits[0] + lim.window - now);   // 最早一次滑出窗口即可再试
  throw new ApiError(429, lim.label + t('过于频繁，请在 ') + left + t(' 秒后再试（每 ')
    + Math.round(lim.window / 60) + t(' 分钟最多 ') + lim.max + t(' 次）'));
}

function cdMark(file, action) {
  if (state.role === 'admin') { return; }
  const lim = CD_LIMITS[file + ':' + action];
  if (!lim) { return; }
  const now = Math.floor(Date.now() / 1000);
  cdStore(file, action, cdStore(file, action).filter(t => t > now - lim.window).concat(now));
}

/* ============================================================
 * 评论屏蔽规则（用户 / 游客均可用）
 * ------------------------------------------------------------
 * 每行一条：以 / 包裹的按正则（/词|词/i），其余按普通子串，统一忽略大小写。
 * 游客存本机 localStorage，登录用户存账号设置；纯前端过滤，不改动他人数据。
 * 单条 ≤ 100 字符、最多 50 条（后端同样清洗，此处再兜一层防卡顿）。
 * ============================================================ */
export function blockRules() {
  const raw = state.role === 'guest'
    ? String(getPrefs().block_words || '')
    : String((state.settings || {}).block_words || '');
  const out = [];
  raw.split(/\r?\n/).forEach(line => {
    const rule = line.trim();
    if (!rule || rule.length > 100) { return; }
    if (out.length >= 50) { return; }
    const m = rule.match(/^\/(.+)\/([imsu]*)$/);
    if (m) { try { out.push(new RegExp(m[1], m[2])); } catch (e) {} }
    else { out.push(rule.toLowerCase()); }
  });
  return out;
}

export function isBlocked(text) {
  const s = String(text == null ? '' : text);
  if (!s) { return false; }
  const low = s.toLowerCase();
  const rules = blockRules();
  for (let i = 0; i < rules.length; i++) {
    const r = rules[i];
    if (typeof r === 'string') { if (low.indexOf(r) >= 0) { return true; } }
    else if (r.test(s)) { return true; }
  }
  return false;
}

/* ============================================================
 * API
 * ============================================================ */
let onUnauthorized = null;
export function setUnauthorizedHandler(fn) { onUnauthorized = fn; }

/* ---------------- 请求并发闸门 ----------------
 * 共享主机对「同一时刻占用的入口进程」有硬顶。控制面板打开时一次放出七八个
 * 请求，总会有一半拿到 508 / 520 这类 HTML 错误页，用户看到的就是「各种接口
 * 返回 508，重试又好了」。
 * 排队比失败好：超出并发额度的请求等一个名额，而不是直接报错。
 */
const API_MAX_CONCURRENCY = 3;
let __apiBusy = 0;
const __apiQueue = [];
function apiSlot() {
  if (__apiBusy < API_MAX_CONCURRENCY) { __apiBusy++; return Promise.resolve(); }
  return new Promise(res => __apiQueue.push(res));
}
function apiRelease() {
  const next = __apiQueue.shift();
  if (next) { next(); }        // 名额直接转交，不减 busy
  else { __apiBusy--; }
}

/* 哪些失败值得再试一次：
 *   code=0      —— 网络层失败（超时、连接被重置）
 *   5xx         —— 网关类瞬时错误。508/520/522/524 是共享主机与 CDN 在压力下的
 *                  标准返回，隔一两秒重试多半就通，不该让用户看见。
 * 4xx（含 401/403/429）与业务错误一律不重试：重试只会放大问题。 */
function apiRetriable(e) {
  const c = Number(e && e.code);
  return c === 0 || (c >= 500 && c <= 599);
}

/* ---------------- 设备指纹上报 ----------------
 * 服务器需要「这台设备确实跑着浏览器」的证据，用来给登录设备判风险
 * （登录后一直没有前端痕迹 = 可能是脚本拿着令牌在跑）。
 * 指纹由 canvas 渲染结果 + 屏幕尺寸算 FNV-1a，不采集任何个人内容，
 * 只在本机算完上传一个 8 位十六进制串。 */
function canvasFp() {
  try {
    const c = document.createElement('canvas');
    c.width = 200; c.height = 40;
    const x = c.getContext('2d');
    if (!x) { return ''; }
    x.textBaseline = 'top';
    x.font = '14px "Arial"';
    x.fillStyle = '#f60';
    x.fillRect(0, 0, 200, 40);
    x.fillStyle = '#069';
    x.fillText('kimi-game-rank|' + (navigator.language || ''), 2, 8);
    x.fillStyle = 'rgba(102,204,0,0.7)';
    x.fillText('kimi-game-rank|' + (navigator.language || ''), 4, 20);
    const data = c.toDataURL();
    let h = 2166136261;
    for (let i = 0; i < data.length; i++) {
      h ^= data.charCodeAt(i);
      h = (h * 16777619) >>> 0;
    }
    const scr = (screen.width || 0) + 'x' + (screen.height || 0);
    let h2 = 2166136261;
    for (let i = 0; i < scr.length; i++) { h2 ^= scr.charCodeAt(i); h2 = (h2 * 16777619) >>> 0; }
    return ('00000000' + h.toString(16)).slice(-8) + ('00000000' + h2.toString(16)).slice(-4);
  } catch (e) { return ''; }
}

/* 每个浏览器会话上报一次即可（localStorage 记 6 小时） */
export function reportDevice() {
  if (!state.token) { return; }
  try {
    const k = 'kimgr_dvrep';
    const last = Number(localStorage.getItem(k) || 0);
    if (last && Date.now() - last < 6 * 3600 * 1000) { return; }
    const fp = canvasFp();
    if (!fp) { return; }
    localStorage.setItem(k, String(Date.now()));
    api('device', 'hello', { fp: fp }, { silent: true, retry: false }).catch(() => {});
  } catch (e) {}
}

export async function api(file, action, data = null, opts = {}) {
  cdCheck(file, action, data);   // 冷却期内直接拒绝，不发无谓请求
  if (opts.silent !== true) { loading(true); }
  const maxTries = Math.max(1, Number(opts.tries || 3));   // 网络层与网关类失败最多 3 次
  let lastErr = null;
  if (!opts.__noSlot) { await apiSlot(); }
  try {
    for (let i = 0; i < maxTries; i++) {
      try {
        const out = await apiOnce(file, action, data, opts);
        cdMark(file, action);
        return out;
      } catch (e) {
        lastErr = e;
        /* 面板密钥凭证失效：交给面板自己重新验证一次，成功后把这次请求重做。
           只有 panel.js 注册了回调，其它 403 照旧抛出。 */
        if (e && Number(e.code) === 403 && !opts.__reauth
            && typeof window.__panelReauth === 'function'
            && String(e.message || '').indexOf('密钥验证') >= 0) {
          let again = false;
          try { again = await window.__panelReauth(); } catch (err) { again = false; }
          if (again) {
            /* 复用外层已经拿到的并发名额（__noSlot），否则递归那一层再排队，
               几路同时失效就会互相卡住。 */
            return api(file, action, data, Object.assign({}, opts, { __reauth: true, __noSlot: true }));
          }
        }
        /* 网络层失败与网关类 5xx 重试；4xx 与业务错误立即抛出 */
        if (!apiRetriable(e) || opts.retry === false || i === maxTries - 1) { break; }
        try { console.warn(t('[api] 第 ') + (i + 1) + t(' 次失败，准备重试：') + file + '?' + action, e.message); } catch (err) {}
        /* 5xx 是瞬时拥塞，退避短一点；网络层失败稍长 */
        const backoff = (Number(e.code) >= 500 ? 500 : 1200) * (i + 1) + Math.round(Math.random() * 300);
        await new Promise(r => setTimeout(r, backoff));
      }
    }
    throw lastErr;
  } finally {
    if (!opts.__noSlot) { apiRelease(); }
    if (opts.silent !== true) { loading(false); }
  }
}

/**
 * 被远程切号时领取新身份。
 * 旧令牌已经进了黑名单，但它正是领取凭证——只在原设备的本地存储里，
 * 伪造设备号拿不到。领到即换令牌并重载；领不到返回 false，走原本的失效流程。
 */
let __claiming = null;
/* 领到新身份后置真：并发请求里剩下的 401 必须用新令牌重放，
   否则它们会把刚拿到的新令牌当成失效清掉，整页掉回游客。 */
let __switchDone = false;
function claimSwitch(useTok) {
  if (__claiming) { return __claiming; }
  const tok = useTok || state.token;
  if (!tok) { return Promise.resolve(false); }
  __claiming = (async function () {
    try {
      const resp = await fetch(API_BASE + '/device?action=claim', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + tok,
          'X-Token': tok,
          'X-Device-Id': deviceId(),
        },
        credentials: 'same-origin',
        body: '{}',
      });
      const j = await resp.json();
      if (!j || Number(j.code) !== 0 || !j.data || !j.data.token) { return false; }
      const d = j.data;
      setToken(d.token);
      __switchDone = true;
      state.role = d.role || 'user';
      state.uid = Number(d.uid || 0);
      state.username = d.username || state.username;
      try { toast(t('本机账号已由管理员切换为 ') + state.username); } catch (e) {}
      setTimeout(function () { location.reload(); }, 300);
      return true;
    } catch (e) {
      return false;
    } finally {
      setTimeout(function () { __claiming = null; }, 1500);
    }
  })();
  return __claiming;
}

/** 页面主动触发领取（本机刚被换号时不必等下一次 401） */
export function claimSwitchNow() { return claimSwitch(); }

async function apiOnce(file, action, data = null, opts = {}) {
  file = String(file || '').replace(/\.php$/i, '');   // 端点规整化：对外一律无扩展名
  const timeoutMs = opts.timeout || 20000;
  const method = opts.method || (data ? 'POST' : 'GET');
  const url = new URL(API_BASE + '/' + file, location.origin);
  if (action) url.searchParams.set('action', action);

  const headers = { 'Accept': 'application/json' };
  /* 设备标识：与登录态无关，一直带。换 IP 时它不变，
     这样封停才能落到「这台设备的这个浏览器」而不是某个地址段。 */
  const __dev = deviceId();
  if (__dev) { headers['X-Device-Id'] = __dev; }
  if (state.token) {
    headers['Authorization'] = 'Bearer ' + state.token;
    headers['X-Token'] = state.token;   // 部分主机会剥离 Authorization，双通道兜底
  }
  if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
  /* 使用者已授权的定位：随请求带上，供服务端做「同 IP 是否同一个人」的地区判定 */
  const __geo = readGeo();
  if (__geo) { headers['X-Geo-Lat'] = String(__geo.lat); headers['X-Geo-Lng'] = String(__geo.lng); }

  const init = { method, headers, credentials: 'same-origin' };
  if (method === 'POST') {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(data || {});
  } else if (data) {
    Object.keys(data).forEach(k => url.searchParams.set(k, data[k]));
  }

  let resp;
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  init.signal = ctrl.signal;
  try {
    resp = await fetch(url, init);
  } catch (e) {
    clearTimeout(timer);
    if (e && e.name === 'AbortError') { throw new ApiError(0, t('请求超时，请重试')); }
    throw new ApiError(0, t('网络连接不稳定，请重试'));   // code=0 标记网络层失败
  }
  clearTimeout(timer);
  if (resp.status === 401) {
    /* 游客令牌失效 → 静默续期一次并重放请求。
       这里刻意不触发「整页重载」：一旦密钥/令牌异常，重载会与轮询叠加成请求风暴，
       在共享主机上表现为「网络连接不稳定」。 */
    if (state.role === 'guest' && !opts._reauth) {
      const renewed = await reauthGuest();
      if (renewed) {
        return await apiOnce(file, action, data, Object.assign({}, opts, { _reauth: true }));
      }
    }
    /* 本机刚被换过号：并发的其它请求拿的还是旧令牌，直接用新令牌重放 */
    if (__switchDone && !opts._claim) {
      return await apiOnce(file, action, data, Object.assign({}, opts, { _claim: true }));
    }
    /* 已登录却 401：先看是不是被管理员在设备列表里换了号 */
    if (state.role !== 'guest' && !opts._claim && await claimSwitch()) {
      return await apiOnce(file, action, data, Object.assign({}, opts, { _claim: true }));
    }
    if (onUnauthorized) { onUnauthorized(); }
    throw new ApiError(401, t('登录态已失效'));
  }
  let json;
  try { json = await resp.json(); } catch (e) {
    /* 拿到的是网页而不是数据：多半被主机/WAF 拦下（例如文件被拒返回 403 错误页）。
       必须说清是「被拦」而不是笼统的「请求失败」，否则排查时无从下手。 */
    const __st = Number(resp.status) || 502;
    /* 5xx 且拿到 HTML：共享主机在并发压力下会吐错误页（508 循环、520 源站错误）。
       这类是瞬时的，交给 api() 去重试；文案不要写「被拦截」，那会把排查带偏。 */
    if (__st >= 500 && __st <= 599) {
      throw new ApiError(__st, t('主机繁忙（HTTP ') + __st + t('），请稍后再试'));
    }
    throw new ApiError(__st,
      t('服务返回了网页而不是数据（HTTP ') + __st + t('），可能被主机拦截，请刷新后重试'));
  }
  if (!json || json.code === undefined || json.code === null) {
    throw new ApiError(resp.status || 502,
      t('服务响应格式异常（HTTP ') + resp.status + t('），请刷新后重试'));
  }
  if (Number(json.code) !== 0) {
    /* 账号被封停：后端已拒绝本次操作，前台立刻拉起全屏封禁说明 */
    const __bm = String(json.msg || json.error || '');
    if (Number(json.code) === 403 && __bm.indexOf(t('封停')) >= 0 && typeof window.__forceBan === 'function') {
      try { window.__forceBan(); } catch (e) {}
    }
    /* 访问准入：当前来源被要求过人机验证。刷新即可看到验证页，验完原地回到原路由 */
    if (Number(json.code) === 403 && __bm.indexOf('人机验证') >= 0) {
      if (!window.__gateReloading) {
        window.__gateReloading = 1;
        setTimeout(() => { location.reload(); }, 120);
      }
    }
    throw new ApiError(Number(json.code) || resp.status,
      json.msg || json.error || (t('请求失败（HTTP ') + resp.status + '）'));
  }
  return json.data;
}

/* 安全 DOM 辅助：元素不存在时不抛错 */
export function $(id) { return document.getElementById(id); }
export function on(el, ev, fn) {
  if (el && typeof el.addEventListener === 'function') { el.addEventListener(ev, fn); }
  return el;
}

export class ApiError extends Error {
  constructor(code, msg) { super(msg); this.code = code; }
}

/* ============================================================
 * 加载反馈（顶部进度条 + 按钮加载态）
 * ============================================================ */
let _loadCount = 0;
export function loading(on) {
  _loadCount = Math.max(0, _loadCount + (on ? 1 : -1));
  const bar = document.getElementById('globalBar');
  if (bar) bar.classList.toggle('on', _loadCount > 0);
}

/** 按钮加载态：禁用 + 三点脉冲，不改变宽度 */
export function btnLoading(btn, on) {
  if (!btn) { return; }
  btn.classList.toggle('loading', !!on);
  btn.disabled = !!on;
  /* 加载态图标：只在 loading 时插入，避免每次都改 DOM */
  const holder = btn.querySelector('[data-ic="state.loading"]');
  if (on && !holder) {
    const sp = document.createElement('span');
    sp.innerHTML = IC('state.loading', 'ico-sm');
    btn.insertBefore(sp.firstChild, btn.firstChild);
  } else if (!on && holder) {
    holder.remove();
  }
}

/* ============================================================
 * 身份徽章（管理员 / 副管理员）
 * ============================================================ */
export const ROLES = {
  admin:    { label: t('管理员'),   cls: 'bd-admin' },
  subadmin: { label: t('副管理员'), cls: 'bd-sub' },
};

/** 是否具备后台身份 */
export function isAdminish(role) {
  const r = role || state.role;
  return r === 'admin' || r === 'subadmin';
}

/** 身份徽章（内联 SVG，无表情字符）；普通用户/游客返回空串 */
export function badge(role) {
  if (role === 'ai') { return '<span class="badge bd-ai" title="' + t('官方 AI') + '">AI</span>'; }
  const r = ROLES[role];
  if (!r) { return ''; }
  const shield = '<path d="M8 1.35 13.7 3.3v4.45c0 3.1-2.35 5.6-5.7 7-3.35-1.4-5.7-3.9-5.7-7V3.3z" '
    + 'fill="currentColor" opacity=".2"/>'
    + '<path d="M8 1.35 13.7 3.3v4.45c0 3.1-2.35 5.6-5.7 7-3.35-1.4-5.7-3.9-5.7-7V3.3z" '
    + 'fill="none" stroke="currentColor" stroke-width="1.05"/>';
  const mark = role === 'admin'
    ? '<path d="M8 5.1l.98 2 2.2.32-1.59 1.55.38 2.19L8 10.12l-1.97 1.04.38-2.19L4.82 7.42l2.2-.32z" fill="currentColor"/>'
    : '<path d="M6.55 11.4 4.35 9.2l.95-.95 1.25 1.25 3.4-3.4.95.95z" fill="currentColor"/>';
  return '<span class="badge ' + r.cls + '" title="' + r.label + '">'
    + '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false">'
    + shield + mark + '</svg>' + r.label + '</span>';
}

/* ============================================================
 * 主题征集荣誉（三阶）——与管理员的身份徽标同一套样式
 * ------------------------------------------------------------
 * 勇于参与：全站第一个投稿（管理员之外），授予即永久
 * 积微成著：累计若干套通过审核
 * 匠心独运：更多套通过，且至少一套被标为推荐
 * 数据来自 api/theme.php?action=honours 的全站映射（按用户名查表），
 * 因此各页渲染用户名时不必再单独取字段。
 * ============================================================ */
const HONOURS = {
  first: { label: '勇于参与', cls: 'bd-h1', hint: '全站第一个提交主题的人（管理员之外）' },
  volume: { label: '积微成著', cls: 'bd-h2', hint: '累计多套主题通过审核' },
  mastery: { label: '匠心独运', cls: 'bd-h3', hint: '更多套通过，且有作品被标为推荐' },
};

const HONOUR_ICON = {
  first: '<path d="M3.2 2.2v9.6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" fill="none"/>'
    + '<path d="M4 2.7h5.6l-1.2 2.1 1.2 2.1H4z" fill="currentColor"/>',
  volume: '<path d="M6 1.8 10.2 3.9 6 6 1.8 3.9z" fill="currentColor"/>'
    + '<path d="M2.3 6.4 6 8.3l3.7-1.9" stroke="currentColor" stroke-width="1.1" fill="none" stroke-linejoin="round"/>'
    + '<path d="M2.3 8.9 6 10.8l3.7-1.9" stroke="currentColor" stroke-width="1.1" fill="none" stroke-linejoin="round"/>',
  mastery: '<path d="M2.2 9.1 2.9 3.9l2.3 2.1L6 2.4l.8 3.6 2.3-2.1.7 5.2z" fill="currentColor"/>'
    + '<path d="M2.9 10.7h6.2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" fill="none"/>',
};

/** 更新全站荣誉映射（app.js 启动时拉一次） */
export function setHonours(map) {
  state.honours = (map && typeof map === 'object') ? map : {};
}

/** 单枚荣誉徽标；不认识的 key 返回空串 */
export function honourBadge(kind) {
  const h = HONOURS[kind];
  if (!h) { return ''; }
  return '<span class="badge ' + h.cls + '" title="' + esc(t(h.label) + '：' + t(h.hint)) + '">'
    + '<svg viewBox="0 0 12 12" width="11" height="11" aria-hidden="true" focusable="false">'
    + (HONOUR_ICON[kind] || '') + '</svg>' + esc(t(h.label)) + '</span>';
}

/** 某用户名的全部荣誉徽标（无荣誉返回空串） */
export function userHonours(name) {
  const m = state.honours || {};
  const arr = m[String(name)];
  if (!arr || !arr.length) { return ''; }
  let html = '';
  for (let i = 0; i < arr.length; i++) { html += honourBadge(arr[i]); }
  return html;
}

/** 三阶说明（活动弹窗与主题目录页共用） */
export function honoursCatalog() {
  return ['first', 'volume', 'mastery'].map(function (k) {
    const h = HONOURS[k];
    return { key: k, label: t(h.label), hint: t(h.hint), html: honourBadge(k) };
  });
}

/** 用户名 + 徽章（统一入口，role 与通报次数由后端下发） */
export function userName(name, role, reports, nowName) {
  const n = Number(reports || 0);
  const tag = n > 0
    ? '<span class="badge badge-violation" title="' + t('累计被通报 ') + n + t(' 次') + '">' + t('被通报 ') + n + t(' 次') + '</span>'
    : '';
  const alt = (nowName && String(nowName) !== String(name))
    ? '<span class="tiny muted">' + t('（现名：') + esc(nowName) + t('）') + '</span>' : '';
  return '<span class="uname">' + esc(name) + '</span>' + alt + badge(role) + userHonours(name) + tag;
}

/** 全站对象编号标签：#000000000042；无编号返回空串 */
export function oidTag(id) {
  const s = String(id == null ? '' : id).trim();
  return s ? '<span class="oid" title="' + t('对象编号 ') + esc(s) + '">#' + esc(s) + '</span>' : '';
}

/** 通报里的用户名：改过名就同时给出「原名」与「现名」 */
export function namePair(snap, now) {
  const a = String(snap == null ? '' : snap);
  const b = String(now == null ? '' : now);
  if (b && b !== a) {
    return '<b>' + esc(a) + '</b> <span class="tiny muted">现名</span> <b>' + esc(b) + '</b>';
  }
  return '<b>' + esc(a || '（未知用户）') + '</b>';
}

/* ============================================================
 * UI 工具
 * ============================================================ */

/** 统一空状态：图标 + 文案（state.empty） */
export function emptyState(text, actionHtml) {
  return '<div class="empty">' + IC('state.empty') + '<p>' + esc(text) + '</p>'
    + (actionHtml || '') + '</div>';
}

/** 离线提示条：断网时出现（state.offline） */
export function initOfflineBar() {
  if (typeof document === 'undefined' || !document.body) { return; }
  const bar = document.createElement('div');
  bar.className = 'offline-bar';
  bar.hidden = true;
  bar.innerHTML = IC('state.offline', 'ico-sm') + '<span>' + t('网络已断开，连接恢复后会自动同步') + '</span>';
  const app = document.getElementById('app');
  (app || document.body).appendChild(bar);
  const sync = () => { bar.hidden = navigator.onLine !== false; };
  window.addEventListener('online', sync);
  window.addEventListener('offline', sync);
  sync();
}
export function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function toast(msg, type = '') {
  const wrap = document.getElementById('toastWrap');
  if (!wrap) { return; }
  /* 容器是纵向 flex，新通知追加在末尾即自动向上堆叠。
     同时最多保留 3 条：超出的先移除最旧的一条，避免连点后糊满屏幕。 */
  while (wrap.children.length >= 3) { wrap.firstElementChild.remove(); }
  const el = document.createElement('div');
  el.className = 'toast' + (type === 'err' ? ' err' : '') + (type === 'ok' ? ' ok' : '') + (type === 'warn' ? ' warn' : '');
  /* 状态图标：err=错误 ok=成功 warn=警告，其余不带图标 */
  const slot = type === 'err' ? 'state.error' : type === 'ok' ? 'state.success' : type === 'warn' ? 'state.warn' : '';
  el.innerHTML = (slot ? IC(slot, 'ico-sm') : '') + '<span>' + esc(msg) + '</span>';
  wrap.appendChild(el);
  const life = type === 'err' ? 3200 : 2400;
  setTimeout(() => {
    el.style.transition = 'opacity .24s ease, transform .24s ease';
    el.style.opacity = '0';
    el.style.transform = 'translateY(6px)';
    setTimeout(() => el.remove(), 260);
  }, life);
}

/** 通用对话框，返回 Promise<boolean> */
export function dialog(title, text, confirmLabel = t('确定'), opts = {}) {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';   // 保留初始的玻璃质感（此前被抹掉）
    const danger = opts.danger ? 'btn-danger' : '';
    box.innerHTML = `<h3>${esc(title)}</h3><p>${text}</p>
      <div class="dialog-actions">
        <button class="btn-ghost" data-r="0">${IC('action.close', 'ico-sm')}<span>取消</span></button>
        <button class="btn ${danger}" data-r="1">${esc(confirmLabel)}</button>
      </div>`;
    scrim.hidden = false;
    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => close(b.dataset.r === '1')));
    scrim.onclick = e => { if (e.target === scrim) close(false); };
  });
}

/**
 * 人机验证弹窗闸门：验证通过即 resolve(token)，取消或关闭 resolve('')。
 * 用于投票、反馈这类「一次性写操作」—— 验证不通过就不提交，token 一次性可用。
 */
export function captchaGate(title, tip) {
  return new Promise(function (resolve) {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    if (!scrim || !box) { resolve(''); return; }
    box.className = 'dialog glass';
    box.innerHTML = `<h3>${esc(title)}</h3>`
      + (tip ? `<p class="tiny muted">${esc(tip)}</p>` : '')
      + `<div id="capGateHost" class="cf-box"></div>`
      + `<div class="dialog-actions"><button class="btn-ghost" data-r="0">${t('取消')}</button></div>`;
    scrim.hidden = false;

    let done = false;
    function finish(tok) {
      if (done) { return; }
      done = true;
      scrim.hidden = true;
      box.innerHTML = '';
      scrim.onclick = null;
      resolve(tok || '');
    }
    box.querySelector('[data-r="0"]').addEventListener('click', function () { finish(''); });
    scrim.onclick = function (e) { if (e.target === scrim) { finish(''); } };

    try {
      /* 通过就自动往下走：用户不必再点一次「确定」 */
      mountCaptcha(box.querySelector('#capGateHost'), function (tok) {
        if (tok) { finish(tok); }
      });
    } catch (e) {
      finish('');
    }
  });
}

/** 人机验证是否开启（关闭时调用方直接提交，不弹窗） */
export function captchaOn() {
  try { return !!captchaConfig().on; } catch (e) { return false; }
}

/** 一次写操作的验证参数：开启时返回 {cap_token, cap_channel}，关闭或取消返回 null */
export async function captchaFields(tip) {
  if (!captchaOn()) { return {}; }
  const tok = await captchaGate(t('人机验证'), tip || t('验证通过后自动提交'));
  if (!tok) { return null; }
  return { cap_token: tok, cap_channel: captchaChannel() };
}

/** 输入型对话框（用于管理员密钥等），返回 Promise<string|null> */
export function prompt_(title, text, confirmLabel = t('确定'), defaultValue = '') {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';   // 保留初始的玻璃质感（此前被抹掉）
    box.innerHTML = `<h3>${esc(title)}</h3><p>${text}</p>
      <div class="field"><input class="input" id="dlgInput" type="text" autocomplete="off" value="${esc(defaultValue)}"></div>
      <div class="dialog-actions">
        <button class="btn-ghost" data-r="0">取消</button>
        <button class="btn" data-r="1">${esc(confirmLabel)}</button>
      </div>`;
    scrim.hidden = false;
    const input = box.querySelector('#dlgInput');
    input.focus();
    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => close(b.dataset.r === '1' ? input.value : null)));
    input.addEventListener('keydown', e => { if (e.key === 'Enter') close(input.value); });
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}

/* ============================================================
 * 启动引导
 * 1) 取 CSRF → 2) 有 token 则 verify，失败则落游客 → 3) 无 token 申请游客令牌
 * ============================================================ */
export async function boot() {
  const bootTok = getToken();
  if (bootTok) { setToken(bootTok); }

  /* 启动载荷：一次请求拿回 csrf、登录态（无令牌则顺带下发游客令牌）、
     站内公告、分类计数，以及（榜单页）首屏第一页。
     此前这些要串行走四个请求，而每次请求后端都要重新引导一遍 —— 首屏慢的主因。 */
  const want = window.__firstPayload || null;
  try {
    const q = { first: want ? 1 : 0 };
    if (want) { q.category = want.category; q.board = want.board; q.size = want.size; }
    const d = await api('start', 'app', q, { silent: true, tries: 2 });
    /* 管理员替这台设备换了号：旧令牌已作废，先用它把新身份领回来。
       必须在采用响应里的游客令牌之前判断，否则旧令牌会被覆盖、再也领不回来。 */
    if (d && Number(d.sw) === 1) {
      if (await claimSwitch(bootTok)) { state.booted = true; return state; }
    }
    if (d && d.token) { setToken(d.token); }
    applyIdentity(d);
    state.announce = (d && d.announce !== undefined) ? String(d.announce || '') : '';
    state.categories = (d && d.categories) ? d.categories : {};
    state.firstPage = (d && d.first) ? d.first : null;
    state.ban  = (d && d.ban)  ? d.ban  : null;
    state.disc = (d && d.disc) ? d.disc : null;
    state.booted = true;
    return state;
  } catch (e) {
    /* 落回旧流程：任何情况下都要能进站 */
  }

  // CSRF（会话级）
  await fetchCsrf();

  if (t) {
    try {
      const d = await api('auth', 'verify');
      applyIdentity(d);
      state.booted = true;
      return state;
    } catch (e) {
      // 验证失败 → 清空强制退出，落游客模式
      setToken('');
      state.csrf = state.csrf || '';
      await ensureGuestCsrf();
    }
  }

  // 无有效登录态 → 游客
  try {
    const d = await api('auth', 'guest');
    setToken(d.token);
    state.role = 'guest';
    state.username = d.username || t('游客');
    state.avatar = '';
    state.uid = 0;
    state.uid8 = '';
  } catch (e) {
    state.role = 'guest';
  }
  state.booted = true;
  return state;
}

/** 取一张新的页面令牌（CSRF）：会话被共享主机回收后必须重新拿 */
async function fetchCsrf() {
  try {
    const r = await fetch(API_BASE + '/auth?action=csrf', { credentials: 'same-origin' });
    const j = await r.json();
    if (j && j.data && j.data.csrf) { state.csrf = j.data.csrf; return true; }
  } catch (e) { /* 下面按失败处理 */ }
  return false;
}

/** 静默重新获取游客令牌（失败返回 false，不抛错）
    —— 必须带 X-CSRF-Token：guest 接口同样走 csrf_verify()，
       裸 fetch 会被 419 拒绝，表现为「登出后一直登录态失效、刷新才好」。 */
async function reauthGuest() {
  const ep = __authEpoch;
  const ask = async () => {
    const r = await fetch(API_BASE + '/auth?action=guest', {
      credentials: 'same-origin',
      headers: state.csrf ? { 'X-CSRF-Token': state.csrf } : {}
    });
    return await r.json();
  };
  try {
    if (!state.csrf) { await fetchCsrf(); }
    let j = await ask();
    if (__authEpoch !== ep) { return false; }
    if (j && Number(j.code) === 419 && await fetchCsrf()) {
      j = await ask();                       // 会话被回收：换令牌再来一次
      if (__authEpoch !== ep) { return false; }
    }
    if (j && j.code === 0 && j.data && j.data.token) { setToken(j.data.token); return true; }
  } catch (e) { /* 交给上层处理 */ }
  return false;
}

/** 退出登录后立刻补回游客身份：留空的令牌会让后续每个请求都 401 */
export async function ensureGuestSession() {
  if (state.token) { return true; }
  return await reauthGuest();
}

async function ensureGuestCsrf() {
  try {
    const d = await api('auth', 'guest');
    setToken(d.token);
    state.role = 'guest';
  } catch (e) {}
}

function applyIdentity(d) {
  state.role = d.role || 'guest';
  state.uid = d.uid || 0;
  state.uid8 = d.uid8 || '';
  state.username = d.username || t('游客');
  state.nameFix = d.name_fix || null;
  state.avatar = d.avatar || '';
  state.settings = d.settings || {};
  if (d.csrf) state.csrf = d.csrf;
}

/* ============================================================
 * 网页通知
 * ============================================================ */
export function notify(title, body) {
  if (!('Notification' in window)) return;
  if (state.settings && Number(state.settings.notify) === 0) return;
  if (Notification.permission === 'granted') {
    try { new Notification(title, { body, icon: state.avatar || undefined }); } catch (e) {}
  }
}
export async function askNotifyPermission() {
  if (!('Notification' in window)) return false;
  if (Notification.permission === 'granted') return true;
  if (Notification.permission === 'denied') return false;
  try { const r = await Notification.requestPermission(); return r === 'granted'; } catch (e) { return false; }
}

/* ============================================================
 * 图片大图查看：点聊天里的图片全屏看细节
 * 点遮罩或关闭按钮退出，Esc 也能关。同一时刻只保留一个查看层。
 * ============================================================ */
export function imageViewer(src) {
  if (!src) { return; }
  const old = document.querySelector('.img-viewer');
  if (old) { old.remove(); }

  const scrim = document.createElement('div');
  scrim.className = 'img-viewer';
  scrim.innerHTML = '<button class="iv-close" type="button" aria-label="' + t('关闭') + '">'
    + '<svg viewBox="0 0 24 24" class="ic"><path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z"/></svg>'
    + '</button>'
    + '<img src="' + esc(src) + '" alt="' + t('图片') + '" referrerpolicy="no-referrer" draggable="false">';
  document.body.appendChild(scrim);

  let closed = false;
  const close = () => {
    if (closed) { return; }
    closed = true;
    document.removeEventListener('keydown', onKey);
    scrim.classList.remove('on');
    setTimeout(() => { try { scrim.remove(); } catch (e) {} }, 180);
  };
  const onKey = e => { if (e.key === 'Escape') { close(); } };

  /* 点图片本身不关闭，点遮罩或关闭按钮才关 —— 免得看细节时误触退出 */
  scrim.addEventListener('click', e => {
    if (e.target === scrim || (e.target.closest && e.target.closest('.iv-close'))) { close(); }
  });
  document.addEventListener('keydown', onKey);
  requestAnimationFrame(() => scrim.classList.add('on'));
  return close;
}

/** 给容器内所有聊天图片挂上「点开看大图」（事件委托，一次绑定管全部） */
export function bindImageViewer(container) {
  if (!container) { return; }
  container.addEventListener('click', e => {
    const img = e.target && e.target.closest ? e.target.closest('img.msg-img') : null;
    if (img && img.src) { imageViewer(img.src); }
  });
}

/* ============================================================
 * 违纪通报：全屏封禁说明 + 最新通报弹窗
 * ------------------------------------------------------------
 * 都用「动态创建 + 固定定位」实现，不改 index.php 骨架。
 * 封禁说明不可关闭（强制全屏），但保留「切换账号登录」入口。
 * ============================================================ */
const I_SHIELD = '<svg viewBox="0 0 24 24" class="ic"><path d="M12 2 4 5.2v5.9c0 4.7 3.3 8.8 8 10.9 4.7-2.1 8-6.2 8-10.9V5.2L12 2z"/></svg>';
const I_CROSS  = '<svg viewBox="0 0 24 24" class="ic"><path d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12 19 6.4 17.6 5 12 10.6z"/></svg>';

/** 封禁状态文案：未封停 / 封停中（至某时或永久）/ 已解封 */
export function discStatusText(it) {
  const o = it || {};
  if (!o.banned) { return t('未封停账号'); }
  const until = String(o.ban_until || '');
  if (o.alive) { return until ? (t('封停中 · 至 ') + until) : t('封停中 · 永久'); }
  return until ? (t('已解封（原定 ') + until + '）') : t('已解封');
}

/** 封禁天数文案 */
export function discDaysText(it) {
  const o = it || {};
  if (!o.banned) { return '—'; }
  const d = Number(o.ban_days || 0);
  return d > 0 ? (d + t(' 天')) : t('永久');
}

/* ============================================================
 * 定位与封禁状态缓存
 * ============================================================ */
const GEO_KEY = 'kimgr_geo';
const BAN_KEY = 'kimgr_ban';

/** 缓存使用者授权的定位（仅 lat/lng，24 小时内复用；不上传明文给展示层） */
export function saveGeo(lat, lng) {
  try { localStorage.setItem(GEO_KEY, JSON.stringify({ lat: lat, lng: lng, t: Date.now() })); } catch (e) {}
}

/** 读取已授权的定位；缺失 / 过期 / 非法均返回 null */
export function readGeo() {
  try {
    const g = JSON.parse(localStorage.getItem(GEO_KEY) || 'null');
    if (!g || typeof g.lat !== 'number' || typeof g.lng !== 'number') { return null; }
    if (Date.now() - Number(g.t || 0) > 86400000) { return null; }
    return g;
  } catch (e) { return null; }
}

/* ---------- 封禁状态的本地冗余 ----------
 * 同一份状态写入浏览器所有可用的持久位置，读时任一命中即视为封禁：
 *   localStorage  ｜ sessionStorage ｜ Cookie ｜ window.name
 *   IndexedDB     ｜ Cache Storage（异步）
 * 目的是让「随手清一下缓存」不能立刻恢复访问；解封时逐处清除。
 * 全部写入都包在 try/catch 里：任一存储不可用都不影响其余，也不影响主流程。 */
const BAN_COOKIE = 'kimgr_b';
const BAN_IDB = 'kimgr_ban_db';
const BAN_CACHE = 'kimgr-ban-v1';
const BAN_CACHE_URL = '/__kimgr_ban';
const BAN_STORE = 'kv';
const BAN_NAME_RE = /^kimgr-ban\|/;

/** 最小占位对象：Cookie 里只存到期标记，读到后先用它上锁，再由接口补全详情 */
function banStub() {
  return { alive: true, username: '', reasons: [], note: '', ban_until: '', ip_banned: false };
}

function banCookieWrite(b) {
  try {
    if (b) {
      const till = b.ban_until ? Math.floor(new Date(b.ban_until).getTime() / 1000) : 0;
      const d = new Date();
      d.setTime(d.getTime() + 30 * 864e5);
      document.cookie = BAN_COOKIE + '=' + (till > 0 ? till : 'perm')
        + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    } else {
      document.cookie = BAN_COOKIE + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
    }
  } catch (e) {}
}

function banCookieRead() {
  try {
    const m = document.cookie.match(/(?:^|;\s*)kimgr_b=([^;]*)/);
    if (!m || !m[1]) { return null; }
    const v = m[1];
    if (v !== 'perm') {
      const till = Number(v);
      if (till > 0 && till * 1000 < Date.now()) { return null; }   // 已到解封时间
    }
    return banStub();
  } catch (e) { return null; }
}

function banIdb(mode, b) {
  return new Promise(function (resolve) {
    try {
      if (!window.indexedDB) { return resolve(null); }
      const req = indexedDB.open(BAN_IDB, 1);
      req.onupgradeneeded = function () {
        try { if (!req.result.objectStoreNames.contains(BAN_STORE)) { req.result.createObjectStore(BAN_STORE); } } catch (e) {}
      };
      req.onerror = function () { resolve(null); };
      req.onsuccess = function () {
        const db = req.result;
        try {
          const st = db.transaction(BAN_STORE, mode === 'read' ? 'readonly' : 'readwrite').objectStore(BAN_STORE);
          const op = (mode === 'read') ? st.get(BAN_KEY) : (b ? st.put(b, BAN_KEY) : st.delete(BAN_KEY));
          op.onsuccess = function () { const r = (mode === 'read') ? (op.result || null) : true; try { db.close(); } catch (e) {} resolve(r); };
          op.onerror = function () { try { db.close(); } catch (e) {} resolve(null); };
        } catch (e) { try { db.close(); } catch (e2) {} resolve(null); }
      };
    } catch (e) { resolve(null); }
  });
}

function banCacheStore(mode, b) {
  return new Promise(function (resolve) {
    try {
      if (!window.caches || !window.Response) { return resolve(null); }
      caches.open(BAN_CACHE).then(function (c) {
        if (mode === 'read') {
          c.match(BAN_CACHE_URL).then(function (r) { return r ? r.text() : null; }).then(function (t) {
            try { resolve(t ? JSON.parse(t) : null); } catch (e) { resolve(null); }
          }).catch(function () { resolve(null); });
        } else {
          const p = b
            ? c.put(BAN_CACHE_URL, new Response(JSON.stringify(b), { headers: { 'Content-Type': 'application/json' } }))
            : c.delete(BAN_CACHE_URL);
          p.then(function () { resolve(true); }).catch(function () { resolve(null); });
        }
      }).catch(function () { resolve(null); });
    } catch (e) { resolve(null); }
  });
}

/** 写入全部可用位置；b 为 null 表示解封，逐处清除 */
export function saveBanCache(b) {
  const json = b ? JSON.stringify(b) : '';
  try { if (b) { localStorage.setItem(BAN_KEY, json); } else { localStorage.removeItem(BAN_KEY); } } catch (e) {}
  try { if (b) { sessionStorage.setItem(BAN_KEY, json); } else { sessionStorage.removeItem(BAN_KEY); } } catch (e) {}
  banCookieWrite(b);
  try {
    const cur = String(window.name || '');
    const kept = cur.replace(/^kimgr-ban\|[\s\S]*$/, '');
    window.name = b ? ('kimgr-ban|' + json) : kept;
  } catch (e) {}
  banIdb(b ? 'write' : 'delete', b);
  banCacheStore(b ? 'write' : 'delete', b);
}

/** 同步读取（localStorage / sessionStorage / Cookie / window.name）；任一命中即返回 */
export function readBanCache() {
  const found = [];
  try { const v = localStorage.getItem(BAN_KEY); if (v) { found.push(JSON.parse(v)); } } catch (e) {}
  try { const v = sessionStorage.getItem(BAN_KEY); if (v) { found.push(JSON.parse(v)); } } catch (e) {}
  const ck = banCookieRead();
  if (ck) { found.push(ck); }
  try {
    const m = String(window.name || '').match(/^kimgr-ban\|([\s\S]*)$/);
    if (m) { found.push(JSON.parse(m[1])); }
  } catch (e) {}
  for (let i = 0; i < found.length; i++) { if (found[i]) { return found[i]; } }
  return null;
}

/** 异步读取（IndexedDB / Cache Storage），供启动后再补一次校验 */
export function readBanCacheAsync() {
  return Promise.all([banIdb('read'), banCacheStore('read')]).then(function (r) {
    return r[0] || r[1] || null;
  }).catch(function () { return null; });
}

/** 向上取整数公里的中文描述 */
function kmText(km) {
  const n = Number(km);
  if (!isFinite(n) || n < 0) { return ''; }
  return (n < 1 ? '不到 1' : String(Math.round(n))) + t(' 公里');
}

/**
 * 「我不是当事人」位置校验：先说明为什么需要定位，用户同意后再申请浏览器定位权限。
 * 服务端按「与封禁锚点的距离」判定：明显不在同一片区域 → 放行。
 */
async function banGeoCheck(lockEl) {
  const okGo = await dialog(
    t('校验你的位置'),
    t('本站按访问来源地址限制违规者。同一个地址常被很多人共用（学校、公司、公共网络），直接封禁可能误伤到与此事无关的你。')
    + '\n\n'
    + t('授权定位后，会用你的大致位置判断你是否与当事人处在同一片区域：相距较远 → 判定为不同的人、立即恢复访问；')
    + t('无明显差别 → 维持限制。位置仅用于本次判断，不保存坐标明文。'),
    t('允许定位并校验')
  );
  if (!okGo) { return; }
  if (!navigator.geolocation) { toast(t('当前浏览器不支持定位'), 'err'); return; }

  toast('正在获取位置…');
  navigator.geolocation.getCurrentPosition(
    async function (pos) {
      const lat = pos.coords.latitude, lng = pos.coords.longitude;
      try {
        const r = await api('discipline', 'geo', { lat: lat, lng: lng }, { silent: true, tries: 1 });
        if (r && r.blocked === false) {
          saveGeo(lat, lng);
          saveBanCache(null);
          state.ban = null;
          toast('已确认你不在限制区域，正在恢复访问');
          /* 此前的接口全部被服务端拒绝，页面数据已不完整，需要重新拉取 —— 这里的整页刷新是必要的 */
          setTimeout(function () { location.reload(); }, 700);
        } else {
          toast('校验结果：你与当事人处在同一区域' + (r && r.distance >= 0 ? t('（相距 ') + kmText(r.distance) + '）' : ''));
        }
      } catch (e) { toast((e && e.message) || t('校验失败'), 'err'); }
    },
    function (err) { toast('未能获取定位：' + ((err && err.message) || t('已拒绝授权')), 'err'); },
    { timeout: 8000, maximumAge: 300000 }
  );
}

let __banEl = null;

/** 全屏封禁说明（不可关闭）。$info 为 start.php 下发的 ban 对象。 */
export function showBanLock(info) {
  if (__banEl) { return __banEl; }
  const b = info || {};
  const reasons = (b.reasons && b.reasons.length) ? b.reasons : [];
  const el = document.createElement('div');
  el.className = 'banlock';
  el.innerHTML = `
    <div class="banlock-inner dialog">
      <div class="banlock-ic">${I_SHIELD}</div>
      <h2>访问已被限制</h2>
      <p class="banlock-sub">本站已对相关账号与来源地址作出处理。如有异议，请通过站内反馈或联系管理员。</p>
      <dl class="kv">
        <dt>处理对象</dt><dd>${esc(b.username || t('（未知）'))}</dd>
        <dt>封禁状态</dt><dd>${esc(discStatusText(b))}</dd>
        <dt>封禁天数</dt><dd>${esc(discDaysText(b))}</dd>
        <dt>解封时间</dt><dd>${b.alive ? (b.ban_until ? esc(b.ban_until) : t('不会自动解封（永久）')) : t('已结束')}</dd>
        ${b.ip_banned ? '<dt>来源地址</dt><dd>已一并封禁</dd>' : ''}
      </dl>
      ${reasons.length ? '<div class="banlock-reasons">' + reasons.map(r => '<span class="disc-chip">' + esc(r) + '</span>').join('') + '</div>' : ''}
      ${b.note ? '<div class="disc-note"><span class="disc-note-k">补充说明</span>' + esc(b.note) + '</div>' : ''}
      <div class="banlock-actions">
        <button class="btn-ghost" id="banGeo">我不是当事人 · 校验位置</button>
        <button class="btn-ghost" id="banSwitch">切换账号登录</button>
      </div>
      <p class="tiny muted">解除限制前，本站功能不可用。若你与该地址的其他使用者并非同一人，可用位置校验恢复访问。</p>
    </div>`;
  document.body.appendChild(el);
  __banEl = el;
  const gb = el.querySelector('#banGeo');
  if (gb) { gb.addEventListener('click', function () { banGeoCheck(el); }); }
  const sw = el.querySelector('#banSwitch');
  if (sw) {
    sw.addEventListener('click', function () {
      try { setToken(''); } catch (e) {}
      location.href = ((typeof window !== 'undefined' && window.__BASE) ? String(window.__BASE) : '') + '/login';
    });
  }
  return el;
}

export function hideBanLock() {
  if (__banEl) { try { __banEl.remove(); } catch (e) {} __banEl = null; }
}

const DISC_SEEN_KEY = 'kimgr_disc_seen';

/**
 * 节日弹窗：当天有节日主题时提示一次（同一天只弹一次）。
 * 内容只说「今天是什么节、启用了哪套主题」，详情走「关于今天」页面。
 */
export function maybeFestivalPopup() {
  const fest = (typeof window !== 'undefined' && window.__FESTIVAL) ? window.__FESTIVAL : null;
  if (!fest || !Array.isArray(fest.today) || !fest.today.length) { return; }
  const sig = String(fest.date || '') + '|' + fest.today.map(function (x) { return x.key; }).join(',');
  try { if (localStorage.getItem('kimgr_fest_pop') === sig) { return; } } catch (e) {}

  const names = fest.today.map(function (x) { return festName(x); }).join(' · ');
  const theme = fest.today.map(function (x) { return t(x.desc); }).join(' ／ ');

  const scrim = document.getElementById('dialogScrim');
  const box = document.getElementById('dialog');
  if (!scrim || !box) { return; }
  box.className = 'dialog glass';
  box.innerHTML = `<h3>${esc(t('今天是') + t('：') + names)}</h3>`
    + `<p class="tiny muted">${esc(theme)}</p>`
    + `<div class="dialog-actions">`
    + `<button class="btn-ghost" data-r="0">${esc(t('知道了'))}</button>`
    + `<button class="btn" data-r="1">${esc(t('看看这个节日'))}</button>`
    + `</div>`;
  scrim.hidden = false;
  const close = function (go) {
    scrim.hidden = true;
    box.innerHTML = '';
    scrim.onclick = null;
    if (go) { document.dispatchEvent(new CustomEvent('kimgr:navigate', { detail: '#/today' })); }
  };
  box.querySelectorAll('[data-r]').forEach(function (b) {
    b.addEventListener('click', function () { close(b.dataset.r === '1'); });
  });
  scrim.onclick = function (e) { if (e.target === scrim) { close(false); } };

  try { localStorage.setItem('kimgr_fest_pop', sig); } catch (e) {}
}

/** 最新通报弹窗（可关闭）。$item 来自 start.php 的 disc。 */
export function showDiscPopup(item) {
  const it = item || {};
  const el = document.createElement('div');
  el.className = 'discpop-scrim';
  el.innerHTML = `
    <div class="discpop dialog">
      <div class="discpop-head">
        <span class="discpop-ic">${I_SHIELD}</span>
        <h3>最新违纪通报</h3>
        <button class="discpop-x" id="discX" aria-label="关闭">${I_CROSS}</button>
      </div>
      <div class="discpop-body">
        <div class="discpop-who">${esc(it.username || t('（未知用户）'))} <span class="tiny muted">· ${esc(it.created || '')}</span></div>
        <div class="discpop-status"><span class="disc-chip ${it.alive ? 'on' : (it.banned ? 'off' : '')}">${esc(discStatusText(it))}</span> <span class="tiny muted">封禁 ${esc(discDaysText(it))}</span></div>
        ${it.note ? '<div class="disc-note"><span class="disc-note-k">补充说明</span>' + esc(it.note) + '</div>' : ''}
        ${(it.reasons && it.reasons.length) ? '<ol class="disc-reasons">' + it.reasons.map(r => '<li>' + esc(r) + '</li>').join('') + '</ol>' : ''}
      </div>
      <div class="discpop-actions">
        <button class="btn-ghost" id="discMore">查看全部</button>
        <button class="btn" id="discOk">我知道了</button>
      </div>
    </div>`;
  document.body.appendChild(el);

  const close = function (go) {
    try { localStorage.setItem(DISC_SEEN_KEY, String(it.id)); } catch (e) {}
    try { el.remove(); } catch (e) {}
    if (go && typeof window.__navigate === 'function') { window.__navigate('#/discipline/' + it.id); }
  };
  const x = el.querySelector('#discX'); if (x) { x.addEventListener('click', () => close(false)); }
  const ok = el.querySelector('#discOk'); if (ok) { ok.addEventListener('click', () => close(false)); }
  const more = el.querySelector('#discMore'); if (more) { more.addEventListener('click', () => close(true)); }
  el.addEventListener('click', e => { if (e.target === el) { close(false); } });
}

/** 有新通报才弹（按 id 缓存「已看过」，同一条件不再重复弹） */
export function maybeDiscPopup(item) {
  const it = item || {};
  if (!it.id) { return false; }
  let seen = 0;
  try { seen = Number(localStorage.getItem(DISC_SEEN_KEY) || 0); } catch (e) {}
  if (Number(it.id) <= seen) { return false; }
  if (__banEl) { return false; }        // 已被全屏说明盖住，不叠加弹窗
  showDiscPopup(it);
  return true;
}

/* ============================================================
 * 开屏活动提示：主题征集
 * ------------------------------------------------------------
 * 同一设备 14 天内只弹一次；关闭即记时间戳，不依赖登录态。
 * 内容与主题目录页共用 honoursCatalog()，避免两处措辞不一致。
 * ============================================================ */
export function maybeCampaignPopup() {
  const KEY = 'kimgr_campaign_themes';
  const COOL = 14 * 86400000;
  let last = 0;
  try { last = Number(localStorage.getItem(KEY) || 0); } catch (e) {}
  const now = Date.now();
  if (last > 0 && now - last < COOL) { return false; }

  const scrim = document.getElementById('dialogScrim');
  const box = document.getElementById('dialog');
  if (!scrim || !box) { return false; }

  const rows = honoursCatalog().map(function (x) {
    return '<li><span class="hd-mark">' + x.html + '</span>'
      + '<span class="hd-txt"><b>' + esc(x.label) + '</b>'
      + '<span class="tiny muted">' + esc(x.hint) + '</span></span></li>';
  }).join('');

  box.className = 'dialog glass';
  box.innerHTML = '<h3>' + t('主题征集进行中') + '</h3>'
    + '<p class="tiny muted">' + t('做一套界面配色交上来，通过后全站可用；够格还有徽标挂在名字旁边，像管理员的标识一样。') + '</p>'
    + '<ul class="honour-list">' + rows + '</ul>'
    + '<p class="tiny muted">' + t('每人默认 1 个投稿名额，被拒不占额度，撤回会把名额还回来。') + '</p>'
    + '<div class="dialog-actions">'
    + '<button class="btn-ghost" data-r="0">' + t('以后再说') + '</button>'
    + '<button class="btn" data-r="1">' + t('去投稿') + '</button>'
    + '</div>';
  scrim.hidden = false;

  const close = function (go) {
    scrim.hidden = true;
    box.innerHTML = '';
    scrim.onclick = null;
    try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
    if (go) { document.dispatchEvent(new CustomEvent('kimgr:navigate', { detail: '#/theme' })); }
  };
  box.querySelectorAll('[data-r]').forEach(function (b) {
    b.addEventListener('click', function () { close(b.dataset.r === '1'); });
  });
  scrim.onclick = function (e) { if (e.target === scrim) { close(false); } };
  return true;
}
