/**
 * Kimi游戏榜 前端（单文件构建版）
 * 由 assets/js/src/ 下模块合并而成，避免共享主机的并发模块加载限制。
 * 修改源码后请重新执行 tools/build.py。
 */
/* ========== core.js ========== */
/**
 * 核心：令牌管理、API 封装、UI 工具、启动引导
 * 信任边界：本层只负责「原样传递数据」，一切规整/校验均在后端完成。
 */

const LS_KEY = 'kimgr_token';
const LS_PREFS = 'kimgr_prefs';

const state = {
  token: '',
  role: 'guest',
  uid: 0,
  uid8: '',          // 8 位可逆 UID
  username: '游客',
  avatar: '',
  settings: {},
  csrf: '',
  booted: false,
};

/* ============================================================
 * 图片加载降级
 * ============================================================
 * 本站不再做服务端图片代理（避免被主机判定为代理滥用与流量超支），
 * 所有图片一律浏览器直连。直连失败时不再回源本站重试，只把破损的图隐藏掉，
 * 避免页面留下裂图占位。
 */
function installImageFallback() {
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (!t || t.tagName !== 'IMG') { return; }
    t.style.visibility = 'hidden';
  }, true);
}
installImageFallback();

/* ============================================================
 * 本地存储
 * ============================================================ */
function getToken() {
  try { return localStorage.getItem(LS_KEY) || ''; } catch (e) { return ''; }
}
function setToken(t) {
  try { t ? localStorage.setItem(LS_KEY, t) : localStorage.removeItem(LS_KEY); } catch (e) {}
  state.token = t || '';
}
function getPrefs() {
  try { return JSON.parse(localStorage.getItem(LS_PREFS) || '{}') || {}; } catch (e) { return {}; }
}
function setPrefs(p) {
  try { localStorage.setItem(LS_PREFS, JSON.stringify(p)); } catch (e) {}
}

/** 搜索页 AI 总结是否可见：游客取本机偏好、登录用户取账号设置，默认开 */
function searchAiOn() {
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
  'lobby.php:send':       { label: '发言',     max: 15, window: 60   },
  'comments.php:create': { label: '发表评论', max: 20, window: 60   },
  'ai.php:send_sync':    { label: 'AI 对话',  max: 6,  window: 60   },
  'feedback.php:create': { label: '提交反馈', max: 5,  window: 600  },
  'auth.php:register':   { label: '注册',     max: 10, window: 3600 },
};

/** 读写本机该动作的时间戳队列（只在窗口内的才留） */
function cdStore(file, action, hits) {
  const key = 'kimgr_cd_' + (state.uid || 0) + '_' + file + ':' + action;
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
  throw new ApiError(429, lim.label + '过于频繁，请在 ' + left + ' 秒后再试（每 '
    + Math.round(lim.window / 60) + ' 分钟最多 ' + lim.max + ' 次）');
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
function blockRules() {
  const raw = state.role === 'guest'
    ? String(getPrefs().block_words || '')
    : String((state.settings || {}).block_words || '');
  const out = [];
  raw.split(/\r?\n/).forEach(line => {
    const t = line.trim();
    if (!t || t.length > 100) { return; }
    if (out.length >= 50) { return; }
    const m = t.match(/^\/(.+)\/([imsu]*)$/);
    if (m) { try { out.push(new RegExp(m[1], m[2])); } catch (e) {} }
    else { out.push(t.toLowerCase()); }
  });
  return out;
}

function isBlocked(text) {
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
function setUnauthorizedHandler(fn) { onUnauthorized = fn; }

async function api(file, action, data = null, opts = {}) {
  cdCheck(file, action, data);   // 冷却期内直接拒绝，不发无谓请求
  if (opts.silent !== true) { loading(true); }
  const maxTries = Math.max(1, Number(opts.tries || 3));   // 网络层失败最多尝试 3 次
  let lastErr = null;
  try {
    for (let i = 0; i < maxTries; i++) {
      try {
        const out = await apiOnce(file, action, data, opts);
        cdMark(file, action);
        return out;
      } catch (e) {
        lastErr = e;
        // 仅对「网络层失败」（code=0）重试；HTTP/业务错误立即抛出
        if (!e || e.code !== 0 || opts.retry === false || i === maxTries - 1) { break; }
        try { console.warn('[api] 第 ' + (i + 1) + ' 次失败，准备重试：' + file + '?' + action, e.message); } catch (err) {}
        await new Promise(r => setTimeout(r, 1200 * (i + 1)));
      }
    }
    throw lastErr;
  } finally {
    if (opts.silent !== true) { loading(false); }
  }
}

async function apiOnce(file, action, data = null, opts = {}) {
  const timeoutMs = opts.timeout || 20000;
  const method = opts.method || (data ? 'POST' : 'GET');
  const url = new URL('api/' + file, location.href);
  if (action) url.searchParams.set('action', action);

  const headers = { 'Accept': 'application/json' };
  if (state.token) {
    headers['Authorization'] = 'Bearer ' + state.token;
    headers['X-Token'] = state.token;   // 部分主机会剥离 Authorization，双通道兜底
  }
  if (state.csrf) headers['X-CSRF-Token'] = state.csrf;

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
    if (e && e.name === 'AbortError') { throw new ApiError(0, '请求超时，请重试'); }
    throw new ApiError(0, '网络连接不稳定，请重试');   // code=0 标记网络层失败
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
    if (onUnauthorized) { onUnauthorized(); }
    throw new ApiError(401, '登录态已失效');
  }
  let json;
  try { json = await resp.json(); } catch (e) {
    /* 拿到的是网页而不是数据：多半被主机/WAF 拦下（例如文件被拒返回 403 错误页）。
       必须说清是「被拦」而不是笼统的「请求失败」，否则排查时无从下手。 */
    throw new ApiError(resp.status || 502,
      '服务返回了网页而不是数据（HTTP ' + resp.status + '），可能被主机拦截，请刷新后重试');
  }
  if (!json || json.code === undefined || json.code === null) {
    throw new ApiError(resp.status || 502,
      '服务响应格式异常（HTTP ' + resp.status + '），请刷新后重试');
  }
  if (Number(json.code) !== 0) {
    /* 账号被封停：后端已拒绝本次操作，前台立刻拉起全屏封禁说明 */
    const __bm = String(json.msg || json.error || '');
    if (Number(json.code) === 403 && __bm.indexOf('封停') >= 0 && typeof window.__forceBan === 'function') {
      try { window.__forceBan(); } catch (e) {}
    }
    throw new ApiError(Number(json.code) || resp.status,
      json.msg || json.error || ('请求失败（HTTP ' + resp.status + '）'));
  }
  return json.data;
}

/* 安全 DOM 辅助：元素不存在时不抛错 */
function $(id) { return document.getElementById(id); }
function on(el, ev, fn) {
  if (el && typeof el.addEventListener === 'function') { el.addEventListener(ev, fn); }
  return el;
}

class ApiError extends Error {
  constructor(code, msg) { super(msg); this.code = code; }
}

/* ============================================================
 * 加载反馈（顶部进度条 + 按钮加载态）
 * ============================================================ */
let _loadCount = 0;
function loading(on) {
  _loadCount = Math.max(0, _loadCount + (on ? 1 : -1));
  const bar = document.getElementById('globalBar');
  if (bar) bar.classList.toggle('on', _loadCount > 0);
}

/** 按钮加载态：禁用 + 三点脉冲，不改变宽度 */
function btnLoading(btn, on) {
  if (!btn) { return; }
  btn.classList.toggle('loading', !!on);
  btn.disabled = !!on;
}

/* ============================================================
 * 身份徽章（管理员 / 副管理员）
 * ============================================================ */
const ROLES = {
  admin:    { label: '管理员',   cls: 'bd-admin' },
  subadmin: { label: '副管理员', cls: 'bd-sub' },
};

/** 是否具备后台身份 */
function isAdminish(role) {
  const r = role || state.role;
  return r === 'admin' || r === 'subadmin';
}

/** 身份徽章（内联 SVG，无表情字符）；普通用户/游客返回空串 */
function badge(role) {
  if (role === 'ai') { return '<span class="badge bd-ai" title="官方 AI">AI</span>'; }
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

/** 用户名 + 徽章（统一入口，role 与通报次数由后端下发） */
function userName(name, role, reports) {
  const n = Number(reports || 0);
  const tag = n > 0
    ? '<span class="badge badge-violation" title="累计被通报 ' + n + ' 次">被通报 ' + n + ' 次</span>'
    : '';
  return '<span class="uname">' + esc(name) + '</span>' + badge(role) + tag;
}

/* ============================================================
 * UI 工具
 * ============================================================ */
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function toast(msg, type = '') {
  const wrap = document.getElementById('toastWrap');
  if (!wrap) { return; }
  /* 容器是纵向 flex，新通知追加在末尾即自动向上堆叠。
     同时最多保留 3 条：超出的先移除最旧的一条，避免连点后糊满屏幕。 */
  while (wrap.children.length >= 3) { wrap.firstElementChild.remove(); }
  const el = document.createElement('div');
  el.className = 'toast' + (type === 'err' ? ' err' : '');
  el.textContent = msg;
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
function dialog(title, text, confirmLabel = '确定', opts = {}) {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';   // 保留初始的玻璃质感（此前被抹掉）
    const danger = opts.danger ? 'btn-danger' : '';
    box.innerHTML = `<h3>${esc(title)}</h3><p>${text}</p>
      <div class="dialog-actions">
        <button class="btn-ghost" data-r="0">取消</button>
        <button class="btn ${danger}" data-r="1">${esc(confirmLabel)}</button>
      </div>`;
    scrim.hidden = false;
    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => close(b.dataset.r === '1')));
    scrim.onclick = e => { if (e.target === scrim) close(false); };
  });
}

/** 输入型对话框（用于管理员密钥等），返回 Promise<string|null> */
function prompt_(title, text, confirmLabel = '确定') {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';   // 保留初始的玻璃质感（此前被抹掉）
    box.innerHTML = `<h3>${esc(title)}</h3><p>${text}</p>
      <div class="field"><input class="input" id="dlgInput" type="text" autocomplete="off"></div>
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
async function boot() {
  const t = getToken();
  if (t) { setToken(t); }

  /* 启动载荷：一次请求拿回 csrf、登录态（无令牌则顺带下发游客令牌）、
     站内公告、分类计数，以及（榜单页）首屏第一页。
     此前这些要串行走四个请求，而每次请求后端都要重新引导一遍 —— 首屏慢的主因。 */
  const want = window.__firstPayload || null;
  try {
    const q = { first: want ? 1 : 0 };
    if (want) { q.category = want.category; q.board = want.board; q.size = want.size; }
    const d = await api('start.php', 'app', q, { silent: true, tries: 2 });
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
  try {
    const d = await fetch('api/auth.php?action=csrf', { credentials: 'same-origin' }).then(r => r.json());
    if (d && d.data && d.data.csrf) state.csrf = d.data.csrf;
  } catch (e) {}

  if (t) {
    try {
      const d = await api('auth.php', 'verify');
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
    const d = await api('auth.php', 'guest');
    setToken(d.token);
    state.role = 'guest';
    state.username = d.username || '游客';
    state.avatar = '';
    state.uid = 0;
    state.uid8 = '';
  } catch (e) {
    state.role = 'guest';
  }
  state.booted = true;
  return state;
}

/** 静默重新获取游客令牌（失败返回 false，不抛错） */
async function reauthGuest() {
  try {
    const r = await fetch('api/auth.php?action=guest', { credentials: 'same-origin' });
    const j = await r.json();
    if (j && j.code === 0 && j.data && j.data.token) { setToken(j.data.token); return true; }
  } catch (e) { /* 交给上层处理 */ }
  return false;
}

async function ensureGuestCsrf() {
  try {
    const d = await api('auth.php', 'guest');
    setToken(d.token);
    state.role = 'guest';
  } catch (e) {}
}

function applyIdentity(d) {
  state.role = d.role || 'guest';
  state.uid = d.uid || 0;
  state.uid8 = d.uid8 || '';
  state.username = d.username || '游客';
  state.avatar = d.avatar || '';
  state.settings = d.settings || {};
  if (d.csrf) state.csrf = d.csrf;
}

/* ============================================================
 * 网页通知
 * ============================================================ */
function notify(title, body) {
  if (!('Notification' in window)) return;
  if (state.settings && Number(state.settings.notify) === 0) return;
  if (Notification.permission === 'granted') {
    try { new Notification(title, { body, icon: state.avatar || undefined }); } catch (e) {}
  }
}
async function askNotifyPermission() {
  if (!('Notification' in window)) return false;
  if (Notification.permission === 'granted') return true;
  if (Notification.permission === 'denied') return false;
  try { const r = await Notification.requestPermission(); return r === 'granted'; } catch (e) { return false; }
}

/* ============================================================
 * 图片大图查看：点聊天里的图片全屏看细节
 * 点遮罩或关闭按钮退出，Esc 也能关。同一时刻只保留一个查看层。
 * ============================================================ */
function imageViewer(src) {
  if (!src) { return; }
  const old = document.querySelector('.img-viewer');
  if (old) { old.remove(); }

  const scrim = document.createElement('div');
  scrim.className = 'img-viewer';
  scrim.innerHTML = '<button class="iv-close" type="button" aria-label="关闭">'
    + '<svg viewBox="0 0 24 24" class="ic"><path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z"/></svg>'
    + '</button>'
    + '<img src="' + esc(src) + '" alt="图片" referrerpolicy="no-referrer" draggable="false">';
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
function bindImageViewer(container) {
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
function discStatusText(it) {
  const o = it || {};
  if (!o.banned) { return '未封停账号'; }
  const until = String(o.ban_until || '');
  if (o.alive) { return until ? ('封停中 · 至 ' + until) : '封停中 · 永久'; }
  return until ? ('已解封（原定 ' + until + '）') : '已解封';
}

/** 封禁天数文案 */
function discDaysText(it) {
  const o = it || {};
  if (!o.banned) { return '—'; }
  const d = Number(o.ban_days || 0);
  return d > 0 ? (d + ' 天') : '永久';
}

let __banEl = null;

/** 全屏封禁说明（不可关闭）。$info 为 start.php 下发的 ban 对象。 */
function showBanLock(info) {
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
        <dt>处理对象</dt><dd>${esc(b.username || '（未知）')}</dd>
        <dt>封禁状态</dt><dd>${esc(discStatusText(b))}</dd>
        <dt>封禁天数</dt><dd>${esc(discDaysText(b))}</dd>
        <dt>解封时间</dt><dd>${b.alive ? (b.ban_until ? esc(b.ban_until) : '不会自动解封（永久）') : '已结束'}</dd>
        ${b.ip_banned ? '<dt>来源地址</dt><dd>已一并封禁</dd>' : ''}
      </dl>
      ${reasons.length ? '<div class="banlock-reasons">' + reasons.map(r => '<span class="disc-chip">' + esc(r) + '</span>').join('') + '</div>' : ''}
      ${b.note ? '<div class="disc-note"><span class="disc-note-k">补充说明</span>' + esc(b.note) + '</div>' : ''}
      <div class="banlock-actions"><button class="btn-ghost" id="banSwitch">切换账号登录</button></div>
      <p class="tiny muted">解除限制前，本站功能不可用。</p>
    </div>`;
  document.body.appendChild(el);
  __banEl = el;
  const sw = el.querySelector('#banSwitch');
  if (sw) {
    sw.addEventListener('click', function () {
      try { setToken(''); } catch (e) {}
      location.href = location.pathname + '?p=login';
    });
  }
  armBanDefense();          // 挂上反篡改三道防线
  return el;
}

function hideBanLock() {
  if (__banEl) { try { __banEl.remove(); } catch (e) {} __banEl = null; }
}

const DISC_SEEN_KEY = 'kimgr_disc_seen';

/** 最新通报弹窗（可关闭）。$item 来自 start.php 的 disc。 */
function showDiscPopup(item) {
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
        <div class="discpop-who">${esc(it.username || '（未知用户）')} <span class="tiny muted">· ${esc(it.created || '')}</span></div>
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
function maybeDiscPopup(item) {
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
 * 反篡改（三层）
 * ------------------------------------------------------------
 * 有人用油猴脚本把封禁说明删掉、隐藏、或改样式来绕过限制。三道防线：
 *   ① 监听 DOM：节点被移除、class/style/hidden 被改 → 立刻重挂并记账；
 *   ② 心跳自校验：每 1.5s 检查说明书仍在、可见，且带着本次会话的随机指纹；
 *   ③ 互校验：第二个定时器盯着第一个的「心跳计数」，停表（被 clearInterval）
 *      即视为篡改；同时把被 disconnect 的观察者重新装上。
 * 必须说清：前端只能抬高门槛，真正拦人的是后端 —— 被封时所有 API 一律 403。
 * ============================================================ */
let __defArmed = false;
const __def = { strikes: 0, beat: 0, seen: -1, nonce: '', mo: null };

function armBanDefense() {
  if (__defArmed) { return; }
  __defArmed = true;
  if (!__def.nonce) { __def.nonce = Math.random().toString(36).slice(2, 10); }
  stampLock();
  attachObserver();
  setInterval(heartbeat, 1500);
  setInterval(watchdog, 4000);
}

function stampLock() {
  const el = document.querySelector('.banlock');
  if (el) { el.setAttribute('data-kimgr', __def.nonce); }
}

function lockOk() {
  const el = document.querySelector('.banlock');
  if (!el) { return false; }
  if (el.getAttribute('data-kimgr') !== __def.nonce) { return false; }
  if (el.hasAttribute('hidden')) { return false; }
  const cs = window.getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity || 1) < 0.5) { return false; }
  const r = el.getBoundingClientRect();
  return r.width > 40 && r.height > 40;
}

function reassertLock() {
  if (!state.ban) { return; }
  hideBanLock();
  showBanLock(state.ban);
  stampLock();
}

function banTampered() {
  __def.strikes++;
  reassertLock();
  banWarn(__def.strikes);
  syncBeat();
}

function attachObserver() {
  try { if (__def.mo) { __def.mo.disconnect(); } } catch (e) {}
  try {
    __def.mo = new MutationObserver(function () {
      if (state.ban && !lockOk()) { banTampered(); }
    });
    __def.mo.observe(document.documentElement, {
      childList: true, subtree: true, attributes: true,
      attributeFilter: ['style', 'class', 'hidden', 'data-kimgr'],
    });
  } catch (e) { __def.mo = null; }
}

function heartbeat() {
  __def.beat++;
  if (!state.ban) { return; }
  if (!lockOk()) { banTampered(); }
  stampLock();
  if (!__def.mo) { attachObserver(); }          // 观察者被摘掉 → 重装
}

function watchdog() {
  if (__def.seen === __def.beat) {              // 心跳没推进：被停表了
    if (state.ban) { banTampered(); }
    attachObserver();
  }
  __def.seen = __def.beat;
}

/** 篡改提醒（自绘弹窗，几秒后自动消失；说明不可关闭） */
function banWarn(n) {
  try {
    const el = document.createElement('div');
    el.className = 'banwarn dialog glass';
    el.innerHTML = '<b>检测到试图绕过封禁说明的修改</b>'
      + '<p class="tiny" style="margin:6px 0 0">这是第 ' + n + ' 次。前三次仅提醒；'
      + '之后每次会自动把封禁时间延长 0.05 天。</p>';
    document.body.appendChild(el);
    setTimeout(function () { try { el.remove(); } catch (e) {} }, 4500);
  } catch (e) {}
}

/**
 * 上报一次（同一会话只报一次，避免自己把自己刷成重罚）。
 * 刻意用平常的名字：动作叫 beat、参数与回包都是单字母 —— 越不起眼越不容易被针对性屏蔽。
 */
function syncBeat() {
  const id = (state.ban && state.ban.id) ? state.ban.id : 0;
  if (!id) { return; }
  const k = 'kb1_' + id;
  try { if (sessionStorage.getItem(k)) { return; } sessionStorage.setItem(k, '1'); } catch (e) { return; }
  try {
    api('discipline.php', 'beat', { k: id }, { silent: true, tries: 1 }).then(function (r) {
      if (r && r.a) { toast('封禁时间已延长 0.05 天'); }
    }).catch(function () {});
  } catch (e) {}
}

/* ========== md.js ========== */
/**
 * Markdown 渲染（文档页与 AI 回复共用）
 * ------------------------------------------------------------
 * 行式解析：标题 / 列表 / 引用 / 代码块 / 表格 / 行内语法。
 * 全部内容先转义再拼装，插入的标签固定，用户内容无法逃逸。
 * 第三个环节的 inlineExtra 钩子用于在「已转义的单个行内片段」上追加替换
 * （站内表情、图片链接等），避免调用方重复实现解析。
 */


function mdToHtml(md, inlineExtra) {
  const lines = String(md).split(/\r?\n/);
  let html = '';
  let i = 0;
  let inCode = false, codeBuf = [], inList = '', inTable = false, tableBuf = [];

  /* 先转义 → 再行内语法 → 最后交给调用方的挂载钩子（表情 / 图片等）。
     顺序保证：用户内容永远在转义之后被处理，无法注入标签。 */
  const inline = s => {
    let h = esc(s)
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    return (typeof inlineExtra === 'function') ? inlineExtra(h) : h;
  };

  const flushTable = () => {
    if (!tableBuf.length) return;
    const rows = tableBuf.filter(r => !/^\s*\|?[\s:\-|]+\|?\s*$/.test(r));
    let t = '<table>';
    rows.forEach((r, idx) => {
      const cells = r.replace(/^\||\|$/g, '').split('|').map(c => c.trim());
      const tag = idx === 0 ? 'th' : 'td';
      t += '<tr>' + cells.map(c => `<${tag}>${inline(c)}</${tag}>`).join('') + '</tr>';
    });
    t += '</table>';
    html += t;
    tableBuf = []; inTable = false;
  };

  const closeList = () => { if (inList) { html += '</' + inList + '>'; inList = ''; } };

  for (i = 0; i < lines.length; i++) {
    let line = lines[i];

    if (/^```/.test(line)) {
      if (inCode) { html += '<pre><code>' + esc(codeBuf.join('\n')) + '</code></pre>'; codeBuf = []; inCode = false; }
      else { closeList(); inCode = true; }
      continue;
    }
    if (inCode) { codeBuf.push(line); continue; }

    if (/^\s*\|.*\|\s*$/.test(line)) { inTable = true; tableBuf.push(line); continue; }
    else if (inTable) { flushTable(); }

    /* 分隔线：--- / *** / ___（独占一行）。须在列表规则之前判定，
       否则会被误当成普通段落而原样显示。 */
    if (/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) { closeList(); html += '<hr>'; continue; }

    if (/^####\s+/.test(line)) { closeList(); html += '<h4>' + inline(line.replace(/^####\s+/, '')) + '</h4>'; continue; }
    if (/^###\s+/.test(line)) { closeList(); html += '<h3>' + inline(line.replace(/^###\s+/, '')) + '</h3>'; continue; }
    if (/^##\s+/.test(line)) { closeList(); html += '<h2>' + inline(line.replace(/^##\s+/, '')) + '</h2>'; continue; }
    if (/^#\s+/.test(line)) { closeList(); html += '<h1>' + inline(line.replace(/^#\s+/, '')) + '</h1>'; continue; }
    if (/^\s*>\s?/.test(line)) { closeList(); html += '<blockquote>' + inline(line.replace(/^\s*>\s?/, '')) + '</blockquote>'; continue; }
    if (/^\s*[-*]\s+/.test(line)) {
      if (inList !== 'ul') { closeList(); html += '<ul>'; inList = 'ul'; }
      html += '<li>' + inline(line.replace(/^\s*[-*]\s+/, '')) + '</li>'; continue;
    }
    if (/^\s*\d+[.)]\s+/.test(line)) {
      if (inList !== 'ol') { closeList(); html += '<ol>'; inList = 'ol'; }
      html += '<li>' + inline(line.replace(/^\s*\d+[.)]\s+/, '')) + '</li>'; continue;
    }
    if (/^\s*$/.test(line)) { closeList(); continue; }

    closeList();
    html += '<p>' + inline(line) + '</p>';
  }
  if (inCode) html += '<pre><code>' + esc(codeBuf.join('\n')) + '</code></pre>';
  flushTable();
  closeList();
  return html;
}

/* ========== theme.js ========== */
/**
 * 主题系统：深浅色 / 三主题色（蓝紫、iOS 彩色、自定义）/ 玻璃方案（CSS、WebGL）
 * 两方案共用同一份主题令牌；切换即时生效，不刷新页面。
 */


/* ---------- 限时节日皮肤 ----------
 * 规则只在服务端一处（app/festival.php），这里只读下发结果：
 *   window.__FESTIVAL = { now: 'halloween', list: { halloween: { name, desc, dark }, ... } }
 * now 为空串 = 当前不在任何节日窗口内。每个节日一套独立主题，互不共用。 */
const FEST = (typeof window !== 'undefined' && window.__FESTIVAL) || { now: '', list: {} };

/** 全部节日（含未生效的），键即皮肤 key */
const FESTIVALS = FEST.list || {};
/** 节日皮肤 key 列表 */
const FESTIVAL_KEYS = Object.keys(FESTIVALS);

/** 当前生效的节日 key；无则空串 */
function festivalNow() { return String(FEST.now || ''); }

/** 是否处于节日窗口内：节日皮肤是限时项，只在窗口内出现在外观设置里 */
function festivalInWindow() { return festivalNow() !== ''; }

/** 设计风格：只改变「结构语言」（圆角/边框/阴影/背景/字体），主题色仍由下方 ACCENTS 控制。
    节日皮肤由服务端下发并一并并入；窗口外它不会出现在外观设置中。 */
const SKINS = Object.assign({}, FESTIVALS, {
  glass:  { name: '液态玻璃', desc: '磨砂通透' },
  md3:    { name: 'MD3 材质', desc: 'Material You · 色面层级' },
  pixel:  { name: '像素风',   desc: '8-bit 点阵字 · 台阶角' },
  sketch: { name: '手绘风',   desc: '纸纹 · 手绘标题' },
  brutal: { name: '新粗野',   desc: '黑框 · 硬阴影 · 撞色' },
});

const ACCENTS = {
  // 蓝紫色：以紫为主、偏蓝调
  'blue-purple': { name: '蓝紫色', accent: '#6D3BF5', accent2: '#8B5CF6' },
  // 苹果色：Apple 系统色系（系统蓝为主，联动系统绿）
  'ios-colorful': { name: '苹果色', accent: '#007AFF', accent2: '#34C759' },
  'custom': { name: '自定义', accent: '#6D3BF5', accent2: '#8B5CF6' },
};

function hexToHsl(hex) {
  let h = String(hex || '').replace('#', '');
  if (h.length === 3) h = h.split('').map(c => c + c).join('');
  if (!/^[0-9a-fA-F]{6}$/.test(h)) h = '7C3AED';
  const r = parseInt(h.slice(0, 2), 16) / 255, g = parseInt(h.slice(2, 4), 16) / 255, b = parseInt(h.slice(4, 6), 16) / 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b);
  let hue = 0, s = 0; const l = (max + min) / 2;
  const d = max - min;
  if (d !== 0) {
    s = l > .5 ? d / (2 - max - min) : d / (max + min);
    if (max === r) hue = ((g - b) / d + (g < b ? 6 : 0));
    else if (max === g) hue = (b - r) / d + 2;
    else hue = (r - g) / d + 4;
    hue *= 60;
  }
  return { h: Math.round(hue), s: Math.round(s * 100), l: Math.round(l * 100) };
}

function hsl(h, s, l) { return `hsl(${h} ${s}% ${l}%)`; }

/** 应用主题到 DOM */
function applyTheme(opts = {}) {
  const prefs = getPrefs();
  const theme = opts.theme || state.settings.theme || prefs.theme || 'light';
  const skin = opts.skin || state.settings.skin || prefs.skin || 'glass';
  const accent = opts.accent || state.settings.accent || prefs.accent || 'blue-purple';
  const custom = opts.custom || state.settings.accent_custom || prefs.accent_custom || '#7C3AED';

  const resolvedTheme = theme === 'system'
    ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
    : theme;

  const root = document.documentElement;
  root.dataset.theme = resolvedTheme;
  root.dataset.accent = accent;
  /* 设计风格不由脚本切换：每种风格是独立的 CSS 文件，由服务端按 cookie 加载，
     切换时写入 cookie 并刷新页面（见 mine.js）。这里只读取当前值用于展示。 */
  if (!root.dataset.skin) { root.dataset.skin = SKINS[skin] ? skin : 'glass'; }

  const a = accent === 'custom' ? custom : (ACCENTS[accent] ? ACCENTS[accent].accent : '#7C3AED');
  const { h, s, l } = hexToHsl(a);
  root.style.setProperty('--accent', a);
  root.style.setProperty('--accent-2', hsl(h, Math.min(100, s + 6), Math.min(78, l + 12)));
  root.style.setProperty('--toggle-on', a);
  root.style.setProperty('--slider-fill', a);

  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', a);

  return { theme, accent, custom, skin, resolvedTheme };
}

/** 保存偏好（本地 + 登录态同步后端） */
async function saveTheme(patch) {
  const prefs = getPrefs();
  Object.assign(prefs, patch);
  setPrefs(prefs);
  applyTheme();
  if (state.role !== 'guest') {   // 普通用户 / 副管理员 / 管理员均可同步偏好
    try { await api('profile.php', 'settings', patch); state.settings = Object.assign({}, state.settings, patch); } catch (e) {}
  }
}

function initSystemWatcher() {
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  const handler = () => {
    const t = state.settings.theme || getPrefs().theme || 'light';
    if (t === 'system') applyTheme();
  };
  if (mq.addEventListener) mq.addEventListener('change', handler);
  else if (mq.addListener) mq.addListener(handler);
}

/* ========== transitions.js ========== */
/**
 * 页面转场、页面缓存与预见式返回
 * ------------------------------------------------------------
 * 三件事：
 * 1) 页面缓存：已渲染的页面连同滚动位置一起留住。返回时直接恢复，
 *    不重新请求接口、不重建 DOM，因此不会「自动刷新」、不会丢滚动位置。
 * 2) 方向转场：用浏览器原生 View Transitions 实现前进/返回两套动画，
 *    返回时上一页从左侧滑回（「从哪来回哪去」）。
 * 3) 预见式返回：从左边缘右滑时页面实时跟手位移，同时把上一页从缓存
 *    取出铺在身后一起位移 —— 手指拖到哪儿就能看到上一页。
 *
 * 全部能力都做了降级：浏览器不支持时退化为无动画的普通切换，不影响功能。
 */

/* ============================================================
 * 页面缓存（LRU）
 * ============================================================ */
const cache = new Map();
const CACHE_MAX = 8;

function cacheGet(key) { return cache.get(key) || null; }

function cacheSet(key, node, scrollTop) {
  cache.delete(key);                                   // 重新插入，维持 LRU 顺序
  cache.set(key, { node: node, scrollTop: scrollTop || 0 });
  while (cache.size > CACHE_MAX) {
    const oldest = cache.keys().next().value;
    cache.delete(oldest);
  }
}

function cacheTouch(key) {
  const v = cache.get(key);
  if (v) { cache.delete(key); cache.set(key, v); }
}

function cacheDrop(key) { cache.delete(key); }

/** 找最近一次访问过的其它页面（用于返回预览） */
function cachePrev(key) {
  const keys = Array.from(cache.keys());
  for (let i = keys.length - 1; i >= 0; i--) {
    if (keys[i] !== key) { return cache.get(keys[i]); }
  }
  return null;
}

/* ============================================================
 * 转场
 * ============================================================ */
let animOn = true;
function setNavAnim(on) { animOn = !!on; }
function navAnimOn() { return animOn; }

function reduceMotion() {
  return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}

/**
 * 带方向地执行一次页面切换。
 * dir: 'fwd'（前进） | 'back'（返回）
 * update: 返回 Promise 或同步完成 DOM 更新的函数
 */
async function runTransition(dir, update) {
  /* 文档隐藏时开转场必然被跳过，浏览器会以 InvalidStateError 拒绝 ready —— 直接走无动画切换 */
  if (!animOn || document.hidden || reduceMotion() || typeof document.startViewTransition !== 'function') {
    await update();
    return;
  }
  const root = document.documentElement;
  root.dataset.navDir = dir;
  const done = function () { try { delete root.dataset.navDir; } catch (e) {} };

  /* 新签名支持 types（Chrome 125+）；老实现只接受回调 */
  let t = null;
  try {
    t = document.startViewTransition({ update: update, types: [dir] });
  } catch (e1) {
    try { t = document.startViewTransition(update); } catch (e2) { t = null; }
  }

  if (t) {
    /* ready 在「转场被跳过」时会 reject（InvalidStateError: Document hidden 等）。
       我们从不 await 它 —— 不接住就会冒成「未处理的 Promise 错误」；
       而转场被跳过根本不是错误，页面照样完成切换。 */
    if (t.ready && typeof t.ready.catch === 'function') { t.ready.catch(function () {}); }
    if (t.finished && typeof t.finished.then === 'function') {
      await t.finished.catch(function () {});      // 中途被中止同样不该冒泡
    } else {
      await update();
    }
  } else {
    await update();                                 // 原生 API 不可用：退化为无动画切换
  }
  done();
}

/* ============================================================
 * 预见式返回：左边缘右滑跟手 + 上一页预览
 * ============================================================ */
/**
 * @param view       主内容容器（被拖动的元素）
 * @param opts.canBack   () => boolean，当前是否允许返回（主标签页不允许）
 * @param opts.onPreview () => node|null，取出上一页节点用于预览
 * @param opts.onCommit  () => void，手势达到阈值、真正执行返回
 */
function enablePredictiveBack(view, opts) {
  const EDGE = 36;      // 触发边缘宽度
  const THRESH = 78;    // 提交阈值
  let tracking = false, startX = 0, startY = 0, dx = 0, decided = false;
  let preview = null, holder = null;

  function cleanup() {
    view.style.transition = '';
    view.style.transform = '';
    view.style.willChange = '';
    if (holder && holder.parentNode) { holder.parentNode.removeChild(holder); }
    holder = null; preview = null;
    tracking = false; decided = false; dx = 0;
  }

  view.addEventListener('touchstart', function (e) {
    if (!opts.canBack || !opts.canBack()) { return; }
    if (e.touches.length !== 1) { return; }
    const t = e.touches[0];
    if (t.clientX > EDGE) { return; }
    tracking = true; decided = false; dx = 0;
    startX = t.clientX; startY = t.clientY;
    view.style.transition = 'none';
    view.style.willChange = 'transform';
  }, { passive: true });

  view.addEventListener('touchmove', function (e) {
    if (!tracking) { return; }
    const t = e.touches[0];
    const mx = t.clientX - startX;
    const my = t.clientY - startY;

    /* 先判断意图：纵向滑动为主则放弃（避免与页面滚动打架） */
    if (!decided) {
      if (Math.abs(my) > Math.abs(mx) && Math.abs(my) > 8) { cleanup(); return; }
      if (Math.abs(mx) > 6) { decided = true; } else { return; }
    }

    dx = Math.max(0, mx);
    view.style.transform = dx > 0 ? 'translateX(' + dx + 'px)' : '';

    /* 首次拖动时把上一页铺到身后 */
    if (!holder && dx > 4 && opts.onPreview) {
      preview = opts.onPreview();
      if (preview && preview.node) {
        holder = document.createElement('div');
        holder.className = 'back-preview';
        const clone = preview.node.cloneNode(true);
        /* 预览是「影子副本」：必须清掉 id 与交互标记，否则与当前页 DOM 冲突 */
        clone.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        clone.removeAttribute('id');
        clone.setAttribute('aria-hidden', 'true');
        clone.style.pointerEvents = 'none';
        holder.appendChild(clone);
        document.body.appendChild(holder);
        /* 用负边距把预览放在当前页左侧，形成「从哪来回哪去」的层次 */
        holder.style.transform = 'translateX(-30%) scale(.94)';
      }
    }
    if (holder) {
      const p = Math.min(dx / 260, 1);
      holder.style.transform = 'translateX(' + (-30 + p * 30) + '%) scale(' + (0.94 + p * 0.06) + ')';
      holder.style.opacity = String(0.55 + p * 0.45);
    }
  }, { passive: true });

  view.addEventListener('touchend', function () {
    if (!tracking) { return; }
    const commit = dx >= THRESH;
    if (commit) {
      /* 手势达成：动画退出后真正返回 */
      view.style.transition = 'transform .22s cubic-bezier(.2,.8,.2,1)';
      view.style.transform = 'translateX(100%)';
      if (holder) {
        holder.style.transition = 'transform .22s cubic-bezier(.2,.8,.2,1), opacity .22s';
        holder.style.transform = 'translateX(0) scale(1)';
        holder.style.opacity = '1';
      }
      setTimeout(function () {
        cleanup();
        if (opts.onCommit) { opts.onCommit(); }
      }, 200);
    } else {
      view.style.transition = 'transform .24s cubic-bezier(.2,.8,.2,1)';
      view.style.transform = '';
      if (holder) {
        holder.style.transition = 'transform .24s cubic-bezier(.2,.8,.2,1), opacity .24s';
        holder.style.transform = 'translateX(-30%) scale(.94)';
        holder.style.opacity = '0';
      }
      setTimeout(cleanup, 240);
    }
  }, { passive: true });

  view.addEventListener('touchcancel', cleanup, { passive: true });
}

/* ============================================================
 * 共享元素（「从哪来回哪去」的空间连续）
 * ------------------------------------------------------------
 * 列表项封面与详情页首图共用同一个 view-transition-name，
 * 于是进入时封面「长大」成详情图，返回时沿原路径缩回原处。
 * 两个槽位分别记录列表侧与详情侧的元素：同一时刻各页只有一个同名元素，
 * 符合 View Transitions 的唯一性要求；换一个作品时旧元素自动摘名。
 * ============================================================ */
let heroSrc = null, heroDst = null;

function namer(slot) {
  return function (el) {
    if (slot.prev && slot.prev !== el) {
      try { slot.prev.style.viewTransitionName = ''; } catch (e) {}
    }
    if (el) { try { el.style.viewTransitionName = 'hero'; } catch (e) {} }
    slot.prev = el || null;
  };
}
const setSrc = namer({ prev: null });
const setDst = namer({ prev: null });

function setHeroSrc(el) { heroSrc = el; setSrc(el); }
function setHeroDst(el) { heroDst = el; setDst(el); }
function clearHero() {
  if (heroSrc) { try { heroSrc.style.viewTransitionName = ''; } catch (e) {} }
  if (heroDst) { try { heroDst.style.viewTransitionName = ''; } catch (e) {} }
  heroSrc = null; heroDst = null;
}

/* ========== captcha.js ========== */
/**
 * 人机验证（Cap PoW 双实例）
 * ------------------------------------------------------------
 * 两个实例都提供配套前端脚本，直接用它们的组件，不自己实现 PoW 求解器：
 *   主通道 captcha.gurl.eu.org —— 官方 cap-widget（Web Component，事件驱动）
 *   备通道 cap-pow.wuw.li     —— 实例自带 cap-pow.js（需同时加载它的 CSS）
 *
 * 主通道 3 秒内没渲染出内容（脚本被墙、实例挂了、shadowRoot 为空）就切备通道。
 * 两个通道的 token 不通用，所以要连 channel 一起交回服务端。
 *
 * 最终 token 是一次性的：一次提交失败后必须重新验证，取新 token。
 */
const CH = { token: '', channel: '' };
let activeChannel = '';

function captchaConfig() {
  return (typeof window !== 'undefined' && window.__CAPTCHA) || { on: false, channels: {} };
}
function captchaToken() { return CH.token; }
function captchaChannel() { return CH.channel; }

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.onload = () => resolve(true);
    s.onerror = () => reject(new Error('脚本加载失败'));
    document.head.appendChild(s);
  });
}

/** 重置：提交失败后调用，token 已失效，必须重新获取 */
function resetCaptcha(host) {
  CH.token = '';
  CH.channel = '';
  activeChannel = '';
  if (host) { mountCaptcha(host, host.__onCapToken); }
}

/**
 * 把验证组件挂到 host 里。onChange(token, channel) 在验证通过后被调用
 * （token 为空串表示已重置 / 尚未通过）。
 */
function mountCaptcha(host, onChange) {
  if (!host) { return; }
  host.__onCapToken = onChange || host.__onCapToken;
  const cb = (t, c) => { CH.token = t; CH.channel = c; if (host.__onCapToken) { host.__onCapToken(t, c); } };

  const cfg = captchaConfig();
  if (!cfg.on) { host.innerHTML = ''; return; }
  /* 同一个宿主已挂过就不重复挂；宿主被重建（切登录/注册）时会自然重来 */
  if (host.querySelector('#cap-widget') || host.querySelector('.cap-wrap')) { return; }
  CH.token = '';
  CH.channel = '';
  cb('', '');

  const std = (cfg.channels || {}).standard || {};
  const php = (cfg.channels || {}).php || {};
  host.innerHTML = `
    <div class="cap-box">
      <div id="capStd"></div>
      <div id="capPhp" style="display:none"></div>
      <div class="tiny muted" id="capHint" style="margin-top:6px">正在加载人机验证…</div>
    </div>`;

  const hint = host.querySelector('#capHint');

  function switchToPhp(why) {
    if (activeChannel === 'php') { return; }
    activeChannel = 'php';
    CH.token = '';
    cb('', '');
    host.querySelector('#capStd').style.display = 'none';
    const box = host.querySelector('#capPhp');
    box.style.display = 'block';
    if (!php.script) { hint.textContent = '人机验证暂不可用，请稍后再试'; return; }
    hint.textContent = '主通道不可用（' + why + '），已切换到备用通道';
    if (php.css && !document.querySelector('link[data-cap-php]')) {
      const l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = php.css;
      l.setAttribute('data-cap-php', '1');
      document.head.appendChild(l);
    }
    /* PHP 实例的组件结构固定，class 名不能改 */
    box.innerHTML = `
      <div class="cap-wrap">
        <div class="captcha">
          <div class="cap-ct" id="cap-ct" role="button" tabindex="0" aria-label="点击进行人机验证">
            <div class="cap-cb">
              <div class="cap-check">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="4,12 9,17 20,6"></polyline></svg>
              </div>
              <svg class="cap-ring" viewBox="0 0 32 32" aria-hidden="true">
                <circle class="cap-ring-bg" cx="16" cy="16" r="14"></circle>
                <circle class="cap-ring-fg" cx="16" cy="16" r="14"></circle>
              </svg>
            </div>
            <div class="cap-lw"><span class="cap-label active">验证你是人类</span></div>
          </div>
        </div>
      </div>`;
    loadScript(php.script).then(() => {
      const wait = (n) => {
        if (window.CapPow) {
          window.CapPow.onDone = (token) => { hint.textContent = '验证通过'; cb(token, 'php'); };
          window.CapPow.onFail = (msg) => { hint.textContent = '验证失败：' + (msg || '未知'); cb('', ''); };
        } else if (n < 30) { setTimeout(() => wait(n + 1), 300); }
        else { hint.textContent = '备用通道加载超时，请刷新重试'; }
      };
      wait(0);
    }).catch(() => { hint.textContent = '备用通道加载失败，请刷新重试'; });
  }

  /* 主通道：官方 cap-widget，脚本加载后自动挂载 */
  if (!std.script) { switchToPhp('未配置'); return; }
  const w = document.createElement('cap-widget');
  w.id = 'cap-widget';
  if (std.api) { w.setAttribute('data-cap-api-endpoint', std.api); }
  w.addEventListener('solve', (e) => { hint.textContent = '验证通过'; cb(e.detail.token, 'standard'); });
  w.addEventListener('progress', (e) => { hint.textContent = '计算中… ' + Math.round(e.detail.progress) + '%'; });
  w.addEventListener('error', (e) => { hint.textContent = '验证出错：' + ((e.detail && e.detail.message) || '未知'); });
  host.querySelector('#capStd').appendChild(w);

  activeChannel = 'standard';
  loadScript(std.script).catch(() => switchToPhp('脚本加载失败'));
  /* 3 秒健康检查：没渲染出来就切备通道 */
  setTimeout(() => {
    if (activeChannel !== 'standard') { return; }
    const inst = host.querySelector('#cap-widget');
    const ok = inst && (inst.shadowRoot || inst.children.length > 0);
    if (!ok) { switchToPhp('未渲染'); }
    else if (hint.textContent === '正在加载人机验证…') { hint.textContent = '点击下方按钮完成验证'; }
  }, 3000);
}

/* ========== pages/rank.js ========== */
/**
 * 榜单页
 */





const CATS = [
  { k: 'game', name: '游戏榜' },
  { k: 'tool', name: '工具榜' },
  { k: 'literature', name: '文学榜' },
  { k: 'fanart', name: '二创榜' },
];
const BOARDS = [
  { k: 'total', name: '总榜' },
  { k: 'vote', name: '投票榜' },
  { k: 'gods', name: '诸神榜' },
  { k: 'cold', name: '冷门榜' },
];
const AI_SUM_FOLD = 240;      // AI 总结折叠阈值（纯文本字数）
const PAGE_SIZE = 12;  // 小分页：首屏更快，一次别拉太多（服务端按此值返回）

async function renderRank(container, ctx) {
  const params = (ctx && ctx.params) || {};
  let cat = params.category || 'all';
  let board = params.board || 'total';
  const q = params.q || '';
  let page = 1;
  let loading = false;
  let done = false;
  let totalCount = 0;
  let shownCount = 0;

  /* 搜索页顶部的 AI 总结：仅在有关键词、且用户没关掉时出现 */
  const showAi = q !== '' && searchAiOn();

  container.innerHTML = `
    ${showAi ? `<div class="ai-sum" id="aiSum" hidden>
      <div class="ai-sum-head">
        <span class="ai-sum-tag">AI</span>
        <span class="ai-sum-title">关于「${esc(q)}」的总结</span>
        <span class="tiny muted ai-sum-hint">AI 生成，仅供参考</span>
      </div>
      <div class="ai-sum-body" id="aiSumBody">
        <div class="ai-sum-loading"><i></i><i></i><i></i></div>
      </div>
      <button class="link ai-sum-toggle" id="aiSumToggle" hidden>展开全部</button>
    </div>` : ''}
    ${q ? `<div class="search-note">
      <span>搜索「<b>${esc(q)}</b>」的结果</span>
      <button class="link" id="clearSearch" style="border:0;background:0">清除搜索</button>
    </div>` : ''}
    <div class="seg" id="boardSeg">${BOARDS.map(b => `<button data-b="${b.k}" class="${b.k === board ? 'on' : ''}">${b.name}</button>`).join('')}</div>
    <div class="cat-tabs" id="catTabs">
      <button class="cat-tab ${cat === 'all' ? 'on' : ''}" data-c="all"><span class="n" id="cntAll">·</span><span>全部</span></button>
      ${CATS.map(c => `<button class="cat-tab ${cat === c.k ? 'on' : ''}" data-c="${c.k}"><span class="n" data-cnt="${c.k}">·</span><span>${c.name}</span></button>`).join('')}
    </div>
    <div class="rank-list" id="rankList"></div>
    <div class="rank-more">
      <div class="tiny" id="rankFoot"></div>
    </div>
  `;

  if (showAi) { loadSearchAi(container, q); }   // 与榜单并行，不阻塞首屏

  const list = container.querySelector('#rankList');
  const foot = container.querySelector('#rankFoot');
  function paintFoot() {
    if (totalCount <= 0) { foot.textContent = ''; return; }
    if (loading) { foot.textContent = '加载中…'; return; }
    foot.textContent = done
      ? ('已显示全部 ' + shownCount + ' 件')
      : ('已显示 ' + shownCount + ' / 共 ' + totalCount + ' 件 · 继续下滑自动加载');
  }

  async function load(reset) {
    if (loading) return;
    if (reset) { page = 1; done = false; list.innerHTML = ''; }
    if (done) return;
    loading = true;
    if (reset) list.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
    try {
      let d = null;
      /* 首屏：启动载荷已把「同参数的第一页」带回来了，直接用，省掉一次往返 */
      if (reset && q === '' && state.firstPage
          && state.firstPage.cat === cat && state.firstPage.board === board) {
        d = state.firstPage;
        state.firstPage = null;
      }
      if (!d) {
        d = await api('works.php', 'list', { category: cat, board: board, q: q, page: page, size: PAGE_SIZE });
      }
      if (reset) { list.innerHTML = ''; shownCount = 0; }
      const items = d.items || [];
      totalCount = Number(d.total || 0);
      if (!items.length && page === 1) {
        list.innerHTML = '<div class="empty"><p>这里还没有作品</p></div>';
      }
      items.forEach((w, idx) => list.appendChild(rankRow(w, (page - 1) * PAGE_SIZE + idx + 1)));
      shownCount += items.length;
      done = !d.has_more;
      page++;
    } catch (e) {
      if (reset) list.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`;
      foot.textContent = '加载失败：' + e.message;
    } finally {
      loading = false;
      paintFoot();
    }
  }

  /* 分类计数：启动载荷已经带回（服务端 60 秒缓存），直接落屏，不再为首屏多打一个请求。
     万一落到旧后端（没带计数），再补一次查询。 */
  function paintCounts(cats) {
    let total = 0;
    Object.keys(cats || {}).forEach(k => {
      total += Number(cats[k]) || 0;
      const el = container.querySelector(`[data-cnt="${k}"]`);
      if (el) { el.textContent = cats[k]; }
    });
    const all = container.querySelector('#cntAll');
    if (all) { all.textContent = total; }
  }
  paintCounts(state.categories);
  if (!state.categories || !Object.keys(state.categories).length) {
    api('site.php', 'bootstrap', null, { silent: true }).then(d => paintCounts(d && d.categories)).catch(() => {});
  }

  container.querySelector('#boardSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-b]'); if (!b) return;
    board = b.dataset.b;
    container.querySelectorAll('#boardSeg button').forEach(x => x.classList.toggle('on', x === b));
    load(true);
  });
  const clearBtn = container.querySelector('#clearSearch');
  if (clearBtn) clearBtn.addEventListener('click', () => navigate('#/rank'));

  container.querySelector('#catTabs').addEventListener('click', e => {
    const b = e.target.closest('[data-c]'); if (!b) return;
    cat = b.dataset.c;
    container.querySelectorAll('#catTabs .cat-tab').forEach(x => x.classList.toggle('on', x === b));
    load(true);
  });

  // 滚动接近底部时自动加载（唯一入口，无需手动按钮）
  const scroller = document.getElementById('view');
  function onScroll() {
    if (!list.isConnected) { scroller.removeEventListener('scroll', onScroll); return; }
    if (done || loading) { return; }
    if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 360) { load(false); }
  }
  scroller.addEventListener('scroll', onScroll, { passive: true });

  await load(true);
}

function rankRow(w, rank) {
  const el = document.createElement('button');
  el.className = 'rank-item';
  el.type = 'button';
  const medal = rank <= 3 ? `m${rank}` : '';
  el.innerHTML = `
    <span class="medal ${medal}">${rank}</span>
    ${w.cover
      ? `<span class="thumb"><img src="${esc(w.cover)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false"></span>`
      : '<span class="thumb ph" aria-hidden="true"></span>'}
    <span class="rank-main">
      <span class="rank-title">${esc(w.title)}</span>
      <span class="rank-meta">
        <span class="chip">${esc(w.category_name)}</span>
        <span>${esc(w.author)}</span>
        <span>热度 ${w.heat}</span>
      </span>
    </span>
    <span class="rank-score">
      <span class="rating r-${esc(w.rating)}">${esc(w.rating)}</span>
      <span class="total">${w.total}</span>
    </span>`;
  el.addEventListener('click', () => {
    setHeroSrc(el.querySelector('.thumb'));        // 共享元素：卡片封面
    navigate('#/detail/' + w.id);
  });
  return el;
}

/* ============================================================
 * 搜索页顶部的 AI 总结卡片
 * ------------------------------------------------------------
 * 单轮生成（模型只看一次站内搜索结果，无工具、无追问），
 * 服务端会缓存同样的关键词，命中缓存秒回且不消耗任何额度。
 * 生成失败 / 站点当日额度用尽 / 无命中条目 → 直接撤掉卡片，
 * 不留空壳、不弹错误，搜索本身不受影响。
 * ============================================================ */
async function loadSearchAi(container, q) {
  const card = container.querySelector('#aiSum');
  const body = container.querySelector('#aiSumBody');
  const toggle = container.querySelector('#aiSumToggle');
  if (!card || !body || !toggle) { return; }

  let d;
  try {
    d = await api('ai.php', 'search_ai', { q: q }, { silent: true, timeout: 40000 });
  } catch (e) { card.remove(); return; }

  const answer = String((d && d.answer) || '').trim();
  if (answer === '') { card.remove(); return; }

  body.innerHTML = mdToHtml(answer);
  card.hidden = false;

  /* 折叠判定按纯文本字数，与字体加载、屏幕宽度无关，结果稳定 */
  const plain = body.textContent.replace(/\s+/g, '');
  if (plain.length <= AI_SUM_FOLD) { return; }

  card.classList.add('collapsed');
  toggle.hidden = false;
  toggle.textContent = '展开全部';
  toggle.addEventListener('click', () => {
    const folded = card.classList.toggle('collapsed');
    toggle.textContent = folded ? '展开全部' : '收起';
    if (!folded) { card.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
  });
}

/* ========== pages/detail.js ========== */
/**
 * 作品详情页：雷达图 + 六维明细 + 介绍 + 评论区
 */




/** 具备发言资格：普通用户 / 管理员 / 副管理员 */
const canPost = () => state.role === 'user' || isAdminish();
/** 可删除任意评论 */
const canModerate = () => isAdminish();

const DIMS = [
  { k: 'creativity', name: '创意' },
  { k: 'experience', name: '体验' },
  { k: 'depth', name: '深度' },
  { k: 'cost', name: '成本' },
  { k: 'attitude', name: '态度' },
  { k: 'heat', name: '热度' },
];

async function renderDetail(container, ctx) {
  const id = parseInt((ctx.params && ctx.params.id) || '0', 10) || parseInt((ctx.sub || '0'), 10);
  if (!id) { container.innerHTML = '<div class="empty"><p>作品不存在</p></div>'; return; }

  container.innerHTML = '<div class="skeleton" style="height:220px"></div>';
  let d;
  try { d = await api('works.php', 'detail', { id: id }); }
  catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }

  const sd = d.score_detail || {};
  container.innerHTML = `
    <div class="detail-head">
      <button class="back-btn" id="backBtn">
        <svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:currentColor"><path d="M15 5l-7 7 7 7 1.4-1.4L10.8 12l5.6-5.6z"/></svg>
        返回榜单
      </button>
    </div>

    <div class="card">
      <div class="radar-wrap" id="radarWrap"></div>
      <div style="text-align:center;margin-top:6px">
        <div style="font-size:30px;font-weight:800;color:var(--accent)">${d.total}</div>
        <span class="rating r-${esc(d.rating)}">${esc(d.rating)}</span>
      </div>
      <div style="margin-top:12px">
        <div style="font-weight:700;font-size:17px">${esc(d.title)}</div>
        <div class="rank-meta" style="margin-top:5px">
          <span class="chip">${esc(d.category_name)}</span>
          <span>${esc(d.author)}</span>
        </div>
      </div>
    </div>

    ${((d.images && d.images.length) || d.cover) ? `
    <div class="card">
      <div class="card-title">
        <svg viewBox="0 0 24 24" class="ic"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 12h14l-4.5-6-3.5 4.5-2.5-2.5L5 17zm3.5-7a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2z"/></svg>
        作品预览
      </div>
      <div class="shot-grid">
        ${(d.images && d.images.length ? d.images : [d.cover]).map((u, i) => `<button class="shot" data-i="${i}"><img src="${esc(u)}" alt="预览图 ${i + 1}" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false"></button>`).join('')}
      </div>
      <div class="tiny" style="margin-top:8px">点击图片可查看大图</div>
    </div>` : ''}

    <div class="card">
      <div class="card-title">
        <svg viewBox="0 0 24 24" class="ic"><path d="M7 6h10a5 5 0 0 1 5 5v3a4 4 0 0 1-4 4h-1a3 3 0 0 1-2.4-1.2l-.6-.8a2 2 0 0 0-3.2 0l-.6.8A3 3 0 0 1 7.4 18H7a4 4 0 0 1-4-4v-3a5 5 0 0 1 5-5z"/></svg>
        作品详情与介绍
      </div>
      <p class="muted" style="white-space:pre-wrap">${esc(d.intro || '暂无介绍')}</p>
      ${d.link ? `<button class="btn-link" id="openLink" data-href="${esc(d.link)}">
        <svg viewBox="0 0 24 24" class="ic" style="width:15px;height:15px;fill:currentColor"><path d="M10.6 13.4a1 1 0 0 1 0-1.4l4.2-4.2a3 3 0 1 1 4.2 4.2l-1.8 1.8-1.4-1.4 1.8-1.8a1 1 0 0 0-1.4-1.4l-4.2 4.2a1 1 0 0 1-1.4 0zm2.8-2.8a1 1 0 0 1 0 1.4l-4.2 4.2a1 1 0 0 0 1.4 1.4l1.8-1.8 1.4 1.4-1.8 1.8a3 3 0 0 1-4.2-4.2l4.2-4.2a1 1 0 0 1 1.4 0z"/></svg>
        ${d.html_url ? '打开作品（CDN 直链）' : '查看作品分享链接'}
      </button>
      <div class="tiny" style="margin-top:6px;word-break:break-all">${esc(d.link)}</div>` : ''}
      <div class="tiny" style="margin-top:8px">作品 ID：${esc(d.source_id || '')} · 收录于 ${esc(d.added_at || '')}</div>
    </div>

    <div class="card">
      <div class="card-title">${esc(d.category_name)} 六维评分明细</div>
      <div class="dim-grid">
        ${DIMS.map(x => `<div class="dim"><div class="k">${x.name}</div><div class="v">${Number(sd[x.k] || 0)}</div><div class="g">${gradeOf(Number(sd[x.k] || 0))}</div></div>`).join('')}
      </div>
      <div class="tiny" style="margin-top:10px">历史峰值总分 ${d.peak_score} · 更新于 ${esc(d.updated_at)}</div>
      <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
        <button class="btn ${d.voted ? 'btn-ghost' : ''}" id="voteBtn">${d.voted ? '已点赞' : '点赞作品'}</button>
        <span class="tiny">点赞计入「投票榜」，每人每作品限一次</span>
      </div>
    </div>

    <div class="card">
      <div class="card-title">评论区（<span id="cmtTotal">${d.comment_total}</span>）</div>
      <div class="comment-tip">
        <svg viewBox="0 0 24 24" class="ic"><path d="M12 2l8 4v6c0 5-3.4 8.6-8 10-4.6-1.4-8-5-8-10V6l8-4z"/></svg>
        文明发言，共建良好社区
      </div>
      <div id="cmtForm"></div>
      <div id="cmtList"></div>
    </div>
  `;

  /* 共享元素：详情页首图与榜单卡片封面同名 → 进入时长出、返回时缩回原处 */
  setHeroDst(container.querySelector('.shot'));

  container.querySelector('#backBtn').addEventListener('click', () => history.length > 1 ? history.back() : navigate('#/rank'));
  const link = container.querySelector('#openLink');
  if (link) link.addEventListener('click', () => window.open(link.dataset.href || d.link, '_blank', 'noopener'));

  // 预览图灯箱（自绘，非原生 dialog）
  const shots = container.querySelectorAll('.shot');
  if (shots.length) {
    const gallery = (d.images && d.images.length) ? d.images : [d.cover];
    shots.forEach(b => b.addEventListener('click', () => openLightbox(gallery, Number(b.dataset.i))));
  }

  // 雷达图
  container.querySelector('#radarWrap').innerHTML = radarSvg(DIMS.map(x => Number(sd[x.k] || 0)));

  // 点赞
  const voteBtn = container.querySelector('#voteBtn');
  voteBtn.addEventListener('click', async () => {
    if (!canPost()) { toast('登录后才能点赞', 'err'); return; }
    try {
      const r = await api('works.php', 'vote', { id: id });
      voteBtn.textContent = r.voted ? '已点赞' : '点赞作品';
      voteBtn.classList.toggle('btn-ghost', r.voted);
      toast(r.voted ? '已点赞' : '已取消');
    } catch (e) { toast(e.message, 'err'); }
  });

  renderComments(container, id);
}

function gradeOf(v) {
  if (v >= 190) return '超神品';
  if (v >= 160) return '优秀';
  if (v >= 120) return '良好';
  if (v >= 80) return '及格';
  return '待提升';
}

/* ============================================================
 * 雷达图（纯 SVG 自绘）
 * ============================================================ */
function radarSvg(values) {
  const size = 260, cx = size / 2, cy = size / 2, R = 96;
  const n = 6;
  const angle = i => (-Math.PI / 2) + i * (2 * Math.PI / n);
  const pt = (i, r) => [cx + Math.cos(angle(i)) * r, cy + Math.sin(angle(i)) * r];

  let grid = '';
  [0.25, 0.5, 0.75, 1].forEach(f => {
    const pts = [];
    for (let i = 0; i < n; i++) { const p = pt(i, R * f); pts.push(p[0].toFixed(1) + ',' + p[1].toFixed(1)); }
    grid += `<polygon points="${pts.join(' ')}" fill="none" stroke="currentColor" stroke-opacity="${f === 1 ? 0.35 : 0.14}" stroke-width="1"/>`;
  });
  let spokes = '';
  for (let i = 0; i < n; i++) {
    const p = pt(i, R);
    spokes += `<line x1="${cx}" y1="${cy}" x2="${p[0].toFixed(1)}" y2="${p[1].toFixed(1)}" stroke="currentColor" stroke-opacity="0.16"/>`;
  }

  const dataPts = values.map((v, i) => {
    const r = R * Math.max(0, Math.min(1, v / 200));
    const p = pt(i, r);
    return p[0].toFixed(1) + ',' + p[1].toFixed(1);
  });

  let dots = '';
  values.forEach((v, i) => {
    const r = R * Math.max(0, Math.min(1, v / 200));
    const p = pt(i, r);
    dots += `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3.2" fill="#f59e0b"/>`;
  });

  return `<svg viewBox="0 0 ${size} ${size}" style="width:min(260px,72vw);height:auto;color:var(--accent)">
    <g>${grid}${spokes}</g>
    <polygon points="${dataPts.join(' ')}" fill="color-mix(in srgb, var(--accent) 22%, transparent)" stroke="#f59e0b" stroke-width="2"/>
    ${dots}
  </svg>`;
}

/* ============================================================
 * 图片灯箱（自绘）
 * ============================================================ */
function openLightbox(images, index) {
  let cur = index || 0;
  const box = document.createElement('div');
  box.className = 'lightbox';
  box.innerHTML = `
    <button class="lb-close" aria-label="关闭">
      <svg viewBox="0 0 24 24" class="ic"><path d="M6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4 6.4 5z"/></svg>
    </button>
    <img class="lb-img" src="" alt="" draggable="false">
    <div class="lb-bar">
      <button class="lb-prev" aria-label="上一张">上一张</button>
      <span class="lb-idx"></span>
      <button class="lb-next" aria-label="下一张">下一张</button>
    </div>`;
  document.body.appendChild(box);

  const img = box.querySelector('.lb-img');
  const idx = box.querySelector('.lb-idx');
  function paint() {
    img.src = images[cur];
    idx.textContent = (cur + 1) + ' / ' + images.length;
    const multi = images.length > 1;
    box.querySelector('.lb-prev').style.visibility = multi ? 'visible' : 'hidden';
    box.querySelector('.lb-next').style.visibility = multi ? 'visible' : 'hidden';
  }
  paint();

  const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
  const onKey = e => {
    if (e.key === 'Escape') { close(); }
    if (e.key === 'ArrowLeft' && cur > 0) { cur--; paint(); }
    if (e.key === 'ArrowRight' && cur < images.length - 1) { cur++; paint(); }
  };
  box.querySelector('.lb-close').addEventListener('click', close);
  box.querySelector('.lb-prev').addEventListener('click', () => { if (cur > 0) { cur--; paint(); } });
  box.querySelector('.lb-next').addEventListener('click', () => { if (cur < images.length - 1) { cur++; paint(); } });
  box.addEventListener('click', e => { if (e.target === box) { close(); } });
  document.addEventListener('keydown', onKey);
}

/* ============================================================
 * 评论区
 * ============================================================ */
async function renderComments(container, workId, targetType) {
  /* 目标类型三选一，与后端 comment_target_type() 的白名单一致。
     切勿把 discipline_list 降级成 work：列表区固定在 work_id=0，降级后必被后端判为参数错误。 */
  const ttype = ['discipline', 'discipline_list'].indexOf(targetType) >= 0 ? targetType : 'work';
  const form = container.querySelector('#cmtForm');
  const listBox = container.querySelector('#cmtList');

  /* ---------- 发表区 ---------- */
  if (canPost()) {
    form.innerHTML = `
      <div class="field">
        <textarea class="input" id="cmtText" maxlength="300" placeholder="写下你的看法…"></textarea>
        <div class="tiny" style="text-align:right"><span id="cmtCount">0</span>/300</div>
      </div>
      <button class="btn btn-sm" id="cmtSend">发表评论</button>`;
    const ta = form.querySelector('#cmtText');
    ta.addEventListener('input', () => { form.querySelector('#cmtCount').textContent = ta.value.length; });
    form.querySelector('#cmtSend').addEventListener('click', async () => {
      const btn = form.querySelector('#cmtSend');
      const v = ta.value.trim();
      if (!v) { toast('请输入内容', 'err'); return; }
      btnLoading(btn, true);
      try {
        await api('comments.php', 'create', { work_id: workId, target_type: ttype, content: v }, { timeout: 30000 });
        ta.value = ''; form.querySelector('#cmtCount').textContent = '0';
        await refresh(true);
        toast('已发表');
      } catch (e) {
        if (e && e.code === 422) {
          commentReject(form.parentNode, e.message || '内容未通过审核', v,
            () => form.querySelector('#cmtSend').click());
        } else { toast(e.message, 'err'); }
      }
      finally { btnLoading(btn, false); }
    });
  } else {
    form.innerHTML = '<p class="tiny">登录后可发表评论 · <span class="link" id="goLogin">去登录</span></p>';
    form.querySelector('#goLogin').addEventListener('click', () => navigate('#/login'));
  }

  /* ---------- 列表状态 ---------- */
  const PAGE = 5;    // 首屏展示的根评论数
  const FOLD = 2;    // 楼中楼默认展开条数
  const st = { roots: [], total: -1, shown: PAGE };
  const unfolded = {};   // 根评论 id → 是否展开（自动刷新后保持）

  function paint() {
    const roots = st.roots;
    listBox.innerHTML = '';
    if (!roots.length) {
      listBox.innerHTML = '<div class="empty" style="padding:20px">还没有评论，来抢沙发</div>';
      return;
    }
    roots.slice(0, st.shown).forEach(c => {
      listBox.appendChild(commentNode(c, workId, refresh, ttype));
      const reps = c.replies || [];
      if (!reps.length) { return; }
      const wrap = document.createElement('div');
      wrap.className = 'replies';
      let open = !!unfolded[c.id];
      const draw = () => {
        wrap.innerHTML = '';
        (open ? reps : reps.slice(0, FOLD)).forEach(r =>
          wrap.appendChild(commentNode(Object.assign({}, r, { _sub: 1 }), workId, refresh, ttype)));
        if (reps.length > FOLD) {
          const t = document.createElement('button');
          t.className = 'fold-toggle';
          t.textContent = open ? '收起回复' : '展开全部 ' + reps.length + ' 条回复';
          t.addEventListener('click', () => { open = !open; unfolded[c.id] = open; draw(); });
          wrap.appendChild(t);
        }
      };
      draw();
      listBox.appendChild(wrap);
    });
    if (st.shown < roots.length) {
      const more = document.createElement('button');
      more.className = 'btn-ghost btn-sm';
      more.style.cssText = 'width:100%;margin-top:8px';
      more.textContent = '查看更多评论（还有 ' + (roots.length - st.shown) + ' 条）';
      more.addEventListener('click', () => { st.shown = roots.length; paint(); });
      listBox.appendChild(more);
    }
  }

  async function refresh(showSkeleton) {
    if (showSkeleton) { listBox.innerHTML = '<div class="skeleton" style="height:44px"></div>'; }
    try {
      const d = await api('comments.php', 'list', { work_id: workId, target_type: ttype }, showSkeleton ? {} : { silent: true });
      st.roots = d.comments || [];
      st.total = Number(d.total || 0);
      if (st.shown < PAGE) { st.shown = PAGE; }
      paint();
      const tot = container.querySelector('#cmtTotal');
      if (tot) { tot.textContent = st.total; }
    } catch (e) {
      if (showSkeleton) {
        listBox.innerHTML = `<div class="empty"><p>${esc(e.message)}</p><button class="btn-ghost btn-sm" id="cmtRetry">重试</button></div>`;
        const rb = listBox.querySelector('#cmtRetry');
        if (rb) { rb.addEventListener('click', () => refresh(true)); }
      }
    }
  }

  /* ---------- 自动轮询：仅在"有新评论"时重绘，且不打断正在输入 ---------- */
  window.__addPageTimer(setInterval(() => {
    if (document.hidden || !listBox.isConnected) { return; }
    const ae = document.activeElement;
    if (ae && (ae.id === 'cmtText' || ae.id === 'rpText')) { return; }
    const scrim = document.getElementById('dialogScrim');
    if (scrim && !scrim.hidden) { return; }
    api('comments.php', 'list', { work_id: workId, target_type: ttype }, { silent: true, tries: 1 })
      .then(d => {
        if (Number(d.total || 0) === st.total) { return; }   // 无新评论 → 不重绘
        st.roots = d.comments || [];
        st.total = Number(d.total || 0);
        paint();
        const tot = container.querySelector('#cmtTotal');
        if (tot) { tot.textContent = st.total; }
      })
      .catch(() => {});
  }, 25000));

  await refresh(true);
}

/**
 * 评论被审核拦下时的提示条（与对话区同一套机制）。
 * 带「AI 重审」按钮：点它让模型单独复核一次，返回 true / middle / false；
 * false 维持拦截，true 与 middle 都会自动重发（middle 会在评论旁标注「可能有恶意」）。
 */
function commentReject(host, text, content, onPass) {
  if (!host) { return; }
  const old = host.querySelector('.reject-note');
  if (old) { old.remove(); }
  const bar = document.createElement('div');
  bar.className = 'reject-note';
  bar.innerHTML = '<span class="rn-text">' + esc(text) + '</span>'
    + '<button class="btn btn-sm" data-review="1">AI 重审</button>';
  host.insertBefore(bar, host.firstChild);

  const btn = bar.querySelector('[data-review]');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    btn.textContent = '重审中…';
    try {
      const r = await api('recheck.php', 'run', { content: content }, { timeout: 30000 });
      const v = (r && r.verdict) || 'false';
      if (v === 'false') {
        bar.querySelector('.rn-text').textContent = 'AI 复核后仍判为违规，未发表';
        btn.remove();
        return;
      }
      bar.remove();
      if (v === 'middle') { toast('AI 复核通过，将标注「可能有恶意」后发表'); }
      onPass();
    } catch (err) {
      btn.disabled = false;
      btn.textContent = 'AI 重审';
      bar.querySelector('.rn-text').textContent = (err && err.message) ? err.message : '重审失败，请稍后再试';
    }
  });
}

function commentNode(c, workId, reload, ttype) {
  const box = document.createElement('div');
  box.className = 'comment' + (c._sub ? ' sub' : '');
  box.dataset.cid = c.id;
  /* 折叠的两种来源：管理员屏蔽（c.blocked）与你自己的屏蔽词（isBlocked）。
     已删除的评论服务端不会再下发，因此这里完全没有「已删除」分支。 */
  const folded = !!c.blocked || isBlocked(c.content);
  const noteText = c.blocked ? '该评论已被折叠，点击查看' : '已按你的屏蔽规则收起，点击查看';
  const modBtn = canModerate() ? `<button data-act="block">${c.blocked ? '取消屏蔽' : '屏蔽'}</button>` : '';
  box.innerHTML = `
    <span class="av"><img src="${esc(c.avatar)}" alt="" draggable="false"></span>
    <span class="body">
      <span class="head">
        ${userName(c.username, c.role, c.reports)}
        <span class="tiny">${esc(c.time)}</span>
      </span>
      <span class="text">${c.reply_to ? `<span class="reply-to">@${esc(c.reply_to)}</span> ` : ''}${folded
        ? `<span class="blocked-note" data-reveal>${noteText}</span><span class="blocked-body" hidden>${esc(c.content)}</span>`
        : esc(c.content)}</span>
      ${c.flag === 'middle' ? '<span class="msg-flag" title="系统认为这条内容可能有恶意，但仍予放行">可能有恶意'
        + (canModerate() ? ' · <button class="link" data-act="unflag" style="border:0;background:0;font-size:12px;color:inherit;text-decoration:underline">取消标注</button>' : '')
        + '</span>' : ''}
      <span class="ops">
        <button data-act="like">赞 ${c.likes || 0}</button>
        ${canPost() ? '<button data-act="reply">回复</button>' : ''}
        ${(c.mine || canModerate()) ? '<button data-act="del">删除</button>' : ''}
        ${modBtn}
      </span>
    </span>`;

  const rev = box.querySelector('[data-reveal]');
  if (rev) {
    rev.addEventListener('click', () => {
      const body = box.querySelector('.blocked-body');
      body.hidden = !body.hidden;
      rev.textContent = body.hidden ? noteText : '收起';
    });
  }

  box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async ev => {
    ev.stopPropagation();
    const act = b.dataset.act;
    if (act === 'like') {
      if (!canPost()) { toast('登录后才能点赞', 'err'); return; }
      try { const r = await api('comments.php', 'vote', { id: c.id }); b.textContent = '赞 ' + r.count; } catch (e) { toast(e.message, 'err'); }
    } else if (act === 'unflag') {
      try {
        await api('comments.php', 'flag', { id: c.id, on: 0 });
        const f = box.querySelector('.msg-flag'); if (f) { f.remove(); }
        toast('已取消标注');
      } catch (e) { toast(e.message, 'err'); }
    } else if (act === 'del') {
      if (await dialog('删除评论', '确认删除这条评论吗？删除后会移入回收站，页面不再显示。', '删除', { danger: true })) {
        try { await api('comments.php', 'delete', { id: c.id }); toast('已移入回收站'); reload(true); } catch (e) { toast(e.message, 'err'); }
      }
    } else if (act === 'block') {
      try {
        const r = await api('comments.php', 'block', { id: c.id, on: c.blocked ? 0 : 1 });
        toast(r.blocked ? '已屏蔽（折叠显示）' : '已取消屏蔽');
        reload(true);
      } catch (e) { toast(e.message, 'err'); }
    } else if (act === 'reply') {
      const text = await promptReply(c.username);
      if (text) {
        const post = async () => {
          try {
            await api('comments.php', 'create', { work_id: workId, target_type: ttype, content: text, parent_id: c.id }, { timeout: 30000 });
            toast('已回复');
            reload(true);
          } catch (e) {
            if (e && e.code === 422) { commentReject(box, e.message || '内容未通过审核', text, post); }
            else { toast(e.message, 'err'); }
          }
        };
        await post();
      }
    }
  }));

  return box;
}

function promptReply(toName) {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.innerHTML = `<h3>回复${toName ? ' ' + esc(toName) : ''}</h3>
      <div class="field"><textarea class="input" id="rpText" maxlength="300" placeholder="写下回复…"></textarea></div>
      <div class="dialog-actions"><button class="btn-ghost" data-r="0">取消</button><button class="btn" data-r="1">发送</button></div>`;
    scrim.hidden = false;
    const ta = box.querySelector('#rpText'); ta.focus();
    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => close(b.dataset.r === '1' ? ta.value.trim() : null)));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}

/* ========== pages/lobby.js ========== */
/**
 * 对话页：世界对话 / AI 对话
 * 文件名曾为 chat.js：主机拦截路径中含 chat 的请求，故改名（页面路由仍为 #/chat）。
 * 布局：固定头部 + 内部滚动消息区 + 固定输入区（不整页滚动）
 * 游客：仅可查看，输入区锁定（后端同样强制校验）
 *
 * 注意：本文件为合并构建的源文件，勿出现重复函数名（tools/jscheck.py 会校验）。
 */




let mode = 'world';        // world | ai
let EMOJI = null;          // { 名称: 图片路径 }
let PACKS = null;          // [ { id, name, items:[{code,file}] } ] 供分组面板使用

/** 加载本地表情索引（表情已本地化，无防盗链问题） */
async function loadPacks() {
  if (PACKS) { return PACKS; }
  try {
    const r = await fetch('assets/emoji/index.json', { cache: 'force-cache' });
    const d = await r.json();
    PACKS = (d.packs || []).filter(function (p) { return (p.items || []).length; });
    EMOJI = {};
    PACKS.forEach(function (p) {
      (p.items || []).forEach(function (it) { EMOJI[it.code] = 'assets/emoji/' + it.file; });
    });
  } catch (e) { PACKS = []; EMOJI = {}; }
  return PACKS;
}
async function loadEmoji() { await loadPacks(); return EMOJI; }

/**
 * 展开表情选择面板：顶部分组（常用 / 小黄脸 / tv 小电视），下方网格。
 * 表情已达三百余个，平铺一屏会失去可用性，因此按包切换；
 * 网格采用事件委托，切换分组不重复绑定。
 */
async function mountEmojiPicker(picker, input) {
  const packs = await loadPacks();
  picker.dataset.kind = 'emoji';
  if (!packs.length) {
    picker.innerHTML = '<div class="picker-head">未找到表情资源</div>';
    picker.hidden = false;
    return;
  }
  picker.innerHTML =
    '<div class="picker-head">表情（点击插入）</div>' +
    '<div class="pk-tabs">' + packs.map(function (p, i) {
      return '<button class="pk-tab' + (i === 0 ? ' on' : '') + '" data-pk="' + i + '">' +
        esc(p.name) + '<span class="pk-n">' + (p.items || []).length + '</span></button>';
    }).join('') + '</div>' +
    '<div class="picker-grid"></div>';
  picker.hidden = false;
  picker.scrollTop = 0;

  const grid = picker.querySelector('.picker-grid');
  const draw = function (i) {
    grid.innerHTML = (packs[i].items || []).map(function (it) {
      return '<button class="pk-emoji" data-name="' + esc(it.code) + '" title="' + esc(it.code) + '">' +
        '<img src="assets/emoji/' + esc(it.file) + '" alt="' + esc(it.code) + '" loading="lazy" draggable="false"></button>';
    }).join('');
    picker.scrollTop = 0;
  };
  draw(0);

  picker.querySelector('.pk-tabs').addEventListener('click', function (e) {
    const b = e.target.closest('[data-pk]'); if (!b) { return; }
    picker.querySelectorAll('[data-pk]').forEach(function (x) { x.classList.toggle('on', x === b); });
    draw(parseInt(b.dataset.pk, 10) || 0);
  });
  grid.addEventListener('click', function (e) {
    const b = e.target.closest('[data-name]'); if (!b) { return; }
    input.value += '[' + b.dataset.name + ']';
    input.dispatchEvent(new Event('input'));
    input.focus();
  });
}

/** 远程图片：默认直连原图（不消耗主机请求数）；直连失败由全局 error 捕获回退到本站代理 */
function imgSrc(u) {
  if (!u) { return ''; }
  if (/^api\/media\.php/i.test(u)) { return u; }
  if (/^https?:\/\//i.test(u)) { return u; }
  return '';
}

/**
 * 行内富化：站内表情 [名称] + 图片链接 → img。
 * 入参必须是「已转义」的 HTML 片段（Markdown 渲染器与纯文本渲染器共用此函数）。
 */
function richInline(html) {
  let h = html;
  if (EMOJI) {
    h = h.replace(/\[([^\[\]\s]{1,12})\]/g, function (m, name) {
      const f = EMOJI[name];
      return f ? ('<img class="emoji" src="' + f + '" alt="' + esc(name) + '" draggable="false">') : m;
    });
  }
  return h.replace(/(https?:\/\/[^\s<>"']+\.(?:png|jpe?g|gif|webp|avif)(?:\?[^\s<>"']*)?)/gi, function (u) {
    return '<img class="msg-img" src="' + esc(u) + '" alt="图片" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false">';
  });
}

/** 世界对话消息：纯文本 + 表情 + 图片（刻意不渲染 Markdown，避免消息被格式刷屏） */
function renderRich(text) {
  /* @ 提及：与评论回复同款灰字（.reply-to） */
  const h = esc(text).replace(/(^|[\s（(【[>])@([\u4e00-\u9fa5A-Za-z0-9_\-]{1,16})/g,
    (m, p, nm) => p + '<span class="reply-to">@' + nm + '</span>');
  return richInline(h);
}

/** 清洗历史里可能残留的工具标签（旧版本数据），避免裸标签展示 */
/** AI 回复最终渲染：剥离工具标签 → Markdown → 表情 / 图片 */
function renderAiRich(text) {
  return mdToHtml(stripToolTags(text), richInline);
}

/** 流式绘制：以「原始文本」为唯一真源整段重绘（节流调用），额外提示追加在末尾 */
function paintAi(target, raw, tail) {
  const p = aiParts(target);
  p.text.classList.add('md-on');   // Markdown 已渲染，交给块级布局
  p.text.innerHTML = renderAiRich(raw) + (tail || '');
}

function stripToolTags(text) {
  let s = String(text == null ? '' : text);

  /* 裸工具调用（无标签）：模型可能直接输出「工具名 + 空行 + JSON」。
     与后端 works_tool_scan_bare 同规则，作为最后一道兜底，确保指令绝不露出。 */
  const NAMES = 'web_open|webopen|search|get|rank|comments|weather|time';
  const fence = '(?:`{3}[a-zA-Z0-9]*[ \\t]*\\r?\\n)?[ \\t]*';
  const gap = '[ \\t]*[:：]?[ \\t]*(?:\\r?\\n[ \\t]*){0,3}';
  const obj = '\\{[^{}]*(?:\\{[^{}]*\\}[^{}]*)*\\}';
  const tail = '[ \\t]*(?:\\r?\\n[ \\t]*`{3})?';
  const bareRe = new RegExp('(?:^|\\r?\\n)[ \\t]*' + fence + '(' + NAMES + ')' + gap + fence + '(' + obj + ')' + tail, 'gi');
  s = s.replace(bareRe, (m, name, body) => {
    try { const j = JSON.parse(body); if (j && j.action) { return '\n'; } } catch (e) {}
    return m;
  });
  /* 单独的裸工具名整行（如只输出 time） */
  s = s.replace(/(^|\r?\n)[ \t]*(web_open|webopen|search|get|rank|comments|weather|time)[ \t]*[:：]?[ \t]*(?=\r?\n|$)/gi, '$1');

  return s
    .replace(/<Works\s*[\-_ ]?\s*check\s*>[\s\S]*?(?:<\/Works\s*[\-_ ]?\s*check\s*>|$)/gi, '')
    .replace(/<\/?(?:web_open|webopen|search|get|rank|comments|weather|time)\b[^>]*\/?>/gi, '')
    .replace(/<Works\s*[\-_ ]?\s*check\s*>/gi, '')
    .trim();
}

async function renderLobby(container, ctx) {
  container.innerHTML = `
    <div class="seg seg-narrow" id="chatSeg">
      <button data-m="world" class="${mode === 'world' ? 'on' : ''}">世界对话</button>
      <button data-m="ai" class="${mode === 'ai' ? 'on' : ''}">AI 对话</button>
    </div>
    <div id="chatBody" class="chat-body"></div>
  `;
  container.querySelector('#chatSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-m]'); if (!b) return;
    mode = b.dataset.m;
    container.querySelectorAll('#chatSeg button').forEach(x => x.classList.toggle('on', x === b));
    mountBody(container);
  });
  mountBody(container);
}

function mountBody(container) {
  const body = container.querySelector('#chatBody');
  if (mode === 'world') mountWorld(body);
  else mountAi(body);
}

/** 判断消息区是否已接近底部（用户手动上翻时不打扰） */
function atBottom(el) {
  return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}
function toBottom(el) {
  el.scrollTop = el.scrollHeight;
}

/* ============================================================
 * 世界对话（重构版）
 * ------------------------------------------------------------
 * 与后端 api/lobby.php 的契约：
 *   list   { since_id?, limit? }            → { items:[…], degraded? }
 *   send   { type:'text'|'image', content?, media? } → 单条消息
 *   recall { id }                           → 空
 * 消息对象：{ id, uid, username, role, avatar, content,
 *            msg_type, media, recalled, mine, time }
 *
 * 增量推送由全局轮询触发：window.__chatAppend(items) / window.__setPollCursor(id)
 * 设计要点：
 *   - 以 id 去重，杜绝轮询与本地追加造成重复上屏
 *   - 只在「用户本就在底部」时自动贴底，不打扰上翻阅读
 *   - 失败一律就地给出重试入口，绝不静默
 * ============================================================ */
const W_ICON_SMILE = '<svg viewBox="0 0 24 24" class="ic"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM8.5 9.5a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zm7 0a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zM7.6 14.2a1 1 0 0 1 1.4-.1 4.6 4.6 0 0 0 6 0 1 1 0 0 1 1.3 1.5 6.6 6.6 0 0 1-8.6 0 1 1 0 0 1-.1-1.4z"/></svg>';
const W_ICON_IMAGE = '<svg viewBox="0 0 24 24" class="ic"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 12h14l-4.5-6-3.5 4.5-2.5-2.5L5 17zm3.5-7a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2z"/></svg>';
const W_ICON_SEND = '<svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:#fff"><path d="M3 20l18-8L3 4v6l12 2-12 2v6z"/></svg>';

async function mountWorld(body) {
  const canSend = state.role === 'user' || isAdminish();
  window.__chatPollEnabled = true;
  window.__aiBusy = false;

  body.innerHTML = `
    <div class="chat-wrap">
      <div class="chat-stream" id="wStream">
        <div class="skeleton" style="height:54px"></div>
        <div class="skeleton" style="height:54px"></div>
      </div>
      <div class="picker" id="wPicker" hidden></div>
      <div class="chat-input">
        <button class="icon-btn pk-btn" id="wEmoji" aria-label="表情" ${canSend ? '' : 'disabled'}>${W_ICON_SMILE}</button>
        <button class="icon-btn pk-btn" id="wImage" aria-label="图片" ${canSend ? '' : 'disabled'}>${W_ICON_IMAGE}</button>
        <button class="icon-btn pk-btn at-txt" id="wAt" aria-label="@ 官方AI" ${canSend ? '' : 'disabled'}>@</button>
        <div class="cnt" id="wCount">0/500</div>
        <textarea class="input" id="wInput" rows="1" maxlength="500"
          placeholder="${canSend ? '输入消息…' : '游客仅可查看，登录后可发言'}" ${canSend ? '' : 'disabled'}></textarea>
        <button class="btn" id="wSend" aria-label="发送" ${canSend ? '' : 'disabled'}>${W_ICON_SEND}</button>
      </div>
      <div class="identity-tip">
        <span>当前发言身份：${userName(state.username, state.role)}${canSend ? '' : ' · 游客仅可查看'}</span>
        ${canSend ? '' : '<button class="link" id="wLogin" style="border:0;background:0;font-size:12px">登录后可发言</button>'}
      </div>
    </div>`;

  const stream  = body.querySelector('#wStream');
  bindImageViewer(stream);          /* 点图片看大图 */
  const input   = body.querySelector('#wInput');
  const sendBtn = body.querySelector('#wSend');
  const count   = body.querySelector('#wCount');
  const picker  = body.querySelector('#wPicker');

  const seen = new Set();     // 已上屏的 id
  let cursor = 0;             // 已上屏的最大 id

  function stick() { stream.scrollTop = stream.scrollHeight; }

  function render(m) {
    const el = document.createElement('div');
    el.className = 'msg' + (m.mine ? ' mine' : '') + (m.msg_type === 'ai' ? ' ai' : '');
    let inner;
    if (m.recalled) {
      inner = '<span class="recall">该消息已撤回</span>';
    } else if (m.msg_type === 'image' && m.media) {
      inner = '<img class="msg-img" src="' + imgSrc(m.media) + '" alt="图片消息" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false">';
    } else {
      inner = renderRich(m.content);
    }
    const recallBtn = (m.mine && !m.recalled)
      ? ' · <button class="link" data-recall="1" style="border:0;background:0;font-size:12px">撤回</button>' : '';
    /* 单条删除：本人或管理员都能删；官方 AI 的消息只有管理员看得到这个按钮 */
    const delBtn = (!m.recalled && (m.mine || isAdminish()))
      ? ' · <button class="link" data-del="1" style="border:0;background:0;font-size:12px">删除</button>' : '';
    el.innerHTML = '<span class="av"><img src="' + esc(m.avatar) + '" alt="" draggable="false" style="user-select:none"></span>'
      + '<span class="bubble-wrap">'
      +   '<span class="who">' + userName(m.username, m.role) + ' · ' + esc(m.time) + recallBtn + delBtn + '</span>'
      +   '<div class="bubble">' + inner + '</div>'
      +   (!m.recalled && m.flag === 'middle'
            ? '<span class="msg-flag" title="系统认为这条内容可能有恶意，但仍予放行">可能有恶意'
              + (isAdminish() ? ' · <button class="link" data-unflag="1" style="border:0;background:0;font-size:12px;color:inherit;text-decoration:underline">取消标注</button>' : '')
              + '</span>' : '')
      + '</span>';

    const rb = el.querySelector('[data-recall]');
    if (rb) {
      rb.addEventListener('click', async () => {
        rb.disabled = true;
        try {
          await api('lobby.php', 'recall', { id: m.id });
          m.recalled = true;
          const b = el.querySelector('.bubble');
          if (b) { b.innerHTML = '<span class="recall">该消息已撤回</span>'; }
          rb.remove();
        } catch (e) { rb.disabled = false; toast(e.message, 'err'); }
      });
    }

    const db2 = el.querySelector('[data-del]');
    if (db2) {
      db2.addEventListener('click', async () => {
        if (!(await dialog('删除这条消息', '删除后这条消息在对话里不再显示（对所有人）。确认？', '删除', { danger: true }))) { return; }
        db2.disabled = true;
        try {
          await api('lobby.php', 'del', { id: m.id });
          m.recalled = true;
          const b = el.querySelector('.bubble');
          if (b) { b.innerHTML = '<span class="recall">该消息已删除</span>'; }
          const rb2 = el.querySelector('[data-recall]'); if (rb2) { rb2.remove(); }
          db2.remove();
        } catch (e) { db2.disabled = false; toast(e.message, 'err'); }
      });
    }

    /* 管理员：一键取消「可能有恶意」标注（系统误标时用） */
    const ub = el.querySelector('[data-unflag]');
    if (ub) {
      ub.addEventListener('click', async () => {
        ub.disabled = true;
        try {
          await api('lobby.php', 'flag', { id: m.id, on: 0 });
          m.flag = '';
          const f = el.querySelector('.msg-flag'); if (f) { f.remove(); }
          toast('已取消标注');
        } catch (e) { ub.disabled = false; toast(e.message, 'err'); }
      });
    }
    return el;
  }

  function put(m, scroll) {
    if (!m || !(m.id > 0) || seen.has(m.id)) { return; }
    seen.add(m.id);
    if (m.id > cursor) { cursor = m.id; }
    stream.appendChild(render(m));
    if (scroll) { stick(); }
    if (window.__setPollCursor) { window.__setPollCursor(cursor); }
  }

  function emptyNote(text) {
    const d = document.createElement('div');
    d.className = 'empty';
    d.style.padding = '22px';
    d.textContent = text;
    return d;
  }

  function errorNote(msg, onRetry) {
    stream.innerHTML = '';
    const d = document.createElement('div');
    d.className = 'empty';
    const p = document.createElement('p');
    p.textContent = msg;
    const b = document.createElement('button');
    b.className = 'btn-ghost btn-sm';
    b.textContent = '重试';
    b.addEventListener('click', onRetry);
    d.appendChild(p); d.appendChild(b);
    stream.appendChild(d);
  }

  async function load() {
    stream.innerHTML = '<div class="skeleton" style="height:54px"></div><div class="skeleton" style="height:54px"></div>';
    try {
      const d = await api('lobby.php', 'list', { limit: 30 });
      stream.innerHTML = '';
      if (d && d.degraded) { errorNote(d.hint || '对话暂时不可用，请稍后重试', load); return; }
      const items = (d && d.items) || [];
      items.forEach(m => put(m, false));
      if (!items.length) { stream.appendChild(emptyNote('还没有人发言，来发第一条吧')); }
      stick();
    } catch (e) {
      errorNote(e.message || '加载失败', load);
    }
  }

  /**
   * 审核未通过的提示条。
   * 刻意不用 toast —— toast 几秒就没了，而这里要留下一个「AI 重审」按钮，
   * 等用户自己决定是否申诉。点它会让模型单独复核一次，返回 true / middle / false。
   */
  function rejectNote(text, payload) {
    const old = stream.parentNode.querySelector('.reject-note');
    if (old) { old.remove(); }
    const bar = document.createElement('div');
    bar.className = 'reject-note';
    const canReview = !(payload && payload.type === 'image');   // 图片外链没有「重审」可言
    bar.innerHTML = '<span class="rn-text">' + esc(text) + '</span>'
      + (canReview ? '<button class="btn btn-sm" data-review="1">AI 重审</button>' : '');
    stream.after(bar);

    const btn = bar.querySelector('[data-review]');
    if (btn) btn.addEventListener('click', async () => {
      btn.disabled = true;
      btn.textContent = '重审中…';
      try {
        const r = await api('recheck.php', 'run', { content: payload.content }, { timeout: 30000 });
        const v = (r && r.verdict) || 'false';
        if (v === 'false') {
          bar.querySelector('.rn-text').textContent = 'AI 复核后仍判为违规，未发送';
          btn.remove();
          return;
        }
        bar.remove();
        /* true：直接重发；middle：同样重发，消息会带上「可能有恶意」的标注 */
        if (v === 'middle') { toast('AI 复核通过，将标注「可能有恶意」后发送'); }
        submit(payload.type || 'text', payload);
      } catch (e) {
        btn.disabled = false;
        btn.textContent = 'AI 重审';
        bar.querySelector('.rn-text').textContent = (e && e.message) ? e.message : '重审失败，请稍后再试';
      }
    });
  }

  async function submit(type, payload) {
    if (!canSend) { toast('游客仅可查看，登录后可发言', 'err'); return; }
    btnLoading(sendBtn, true);
    try {
      const m = await api('lobby.php', 'send', Object.assign({ type: type }, payload), { timeout: 30000 });
      if (stream.querySelector('.empty')) { stream.innerHTML = ''; seen.clear(); }
      put(m, true);
      if (m && m.ai) { put(m.ai, true); }
      if (m && m.ai_note) { toast(m.ai_note, 'err'); }
      picker.hidden = true;
      const bar = stream.parentNode.querySelector('.reject-note');
      if (bar) { bar.remove(); }
    } catch (e) {
      if (e && e.code === 422) {
        /* 图片同样给一条明确的通知条，而不是静默失败（用户以为发出去了） */
        rejectNote(e.message || '内容未通过审核', { type: type, content: payload.content, media: payload.media });
      } else {
        toast(e.message, 'err');
      }
    }
    finally { btnLoading(sendBtn, false); }
  }

  function sendText() {
    const text = input.value.trim();
    if (!text) { return; }
    input.value = '';
    if (count) { count.textContent = '0/500'; }
    input.style.height = 'auto';
    submit('text', { content: text });
  }

  function pickerSendImage(u) { submit('image', { media: u }); }

  function openImagePicker() {
    if (!picker.hidden && picker.dataset.kind === 'image') { picker.hidden = true; return; }
    picker.dataset.kind = 'image';
    picker.innerHTML = '<div class="picker-head">发送图片</div>'
      + '<div class="picker-body">'
      +   '<input class="input" id="wImgUrl" placeholder="粘贴图片链接（https://…png / jpg）" style="margin-bottom:8px">'
      +   '<div class="row-gap">'
      +     '<button class="btn btn-sm" id="wImgLink">发送链接图片</button>'
      +     '<button class="btn-ghost btn-sm" id="wImgUpBtn">上传本地图片</button>'
      +     '<input type="file" id="wImgFile" accept="image/*" class="sr-only">'
      +   '</div>'
      +   '<div class="tiny" id="wImgHint" style="margin-top:8px">支持 JPG / PNG / GIF / WebP，最大 2MB</div>'
      + '</div>';
    picker.hidden = false;

    const hint = picker.querySelector('#wImgHint');
    const urlIn = picker.querySelector('#wImgUrl');
    picker.querySelector('#wImgLink').addEventListener('click', () => {
      const u = (urlIn.value || '').trim();
      if (!/^https?:\/\//i.test(u)) { hint.textContent = '请输入以 http(s) 开头的图片链接'; return; }
      pickerSendImage(u);
    });
    picker.querySelector('#wImgUpBtn').addEventListener('click', () => picker.querySelector('#wImgFile').click());
    picker.querySelector('#wImgFile').addEventListener('change', async ev => {
      const f = ev.target.files && ev.target.files[0];
      if (!f) { return; }
      if (f.size > 2 * 1024 * 1024) { hint.textContent = '图片不能超过 2MB'; return; }
      hint.textContent = '上传中…';
      try {
        const fd = new FormData();
        fd.append('file', f);
        const resp = await fetch('api/media.php?action=upload', {
          method: 'POST',
          headers: { 'X-Token': state.token, 'X-CSRF-Token': state.csrf },
          body: fd,
          credentials: 'same-origin',
        });
        const j = await resp.json();
        if (!j || j.code !== 0) { throw new Error((j && j.msg) || '上传失败'); }
        pickerSendImage(j.data.url);
      } catch (e) { hint.textContent = '上传失败：' + (e.message || '未知错误'); }
    });
  }

  sendBtn.addEventListener('click', sendText);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendText(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(108, input.scrollHeight) + 'px';
    if (count) { count.textContent = input.value.length + '/500'; }
  });
  input.addEventListener('focus', () => requestAnimationFrame(stick));

  /* ---------- @ 提及（仅世界对话）：输入 @ 弹面板；@ 按钮一键插入 ---------- */
  const AI_MENTION = '@官方AI ';
  function atInsert() {
    const st = input.selectionStart, en = input.selectionEnd, v = input.value;
    input.value = v.slice(0, st) + AI_MENTION + v.slice(en);
    const p = st + AI_MENTION.length;
    input.setSelectionRange(p, p);
    input.focus();
    input.dispatchEvent(new Event('input'));
  }
  function atRange() {
    const pos = input.selectionStart;
    const m = input.value.slice(0, pos).match(/(?:^|[\s（(【[>])@([\u4e00-\u9fa5A-Za-z0-9]{0,7})$/);
    return (m && m[1] !== undefined) ? { start: pos - m[1].length - 1 } : null;
  }
  function atSync() {
    if (!canSend) { return; }
    const r = atRange();
    const q = r ? input.value.slice(r.start + 1, input.selectionStart) : '';
    if (!r || '官方AI'.indexOf(q) !== 0) {
      if (picker.dataset.kind === 'mention') { picker.hidden = true; picker.dataset.kind = ''; }
      return;
    }
    picker.dataset.kind = 'mention';
    picker.innerHTML = '<div class="picker-head">@ 提及（点击插入）</div>'
      + '<div class="picker-body"><button class="btn btn-sm" id="wAtPick">' + esc(AI_MENTION.trim()) + '</button>'
      + '<div class="tiny" style="margin-top:6px">@ 官方 AI，它会回应这条消息</div></div>';
    picker.hidden = false;
    picker.querySelector('#wAtPick').addEventListener('click', () => {
      const st = atRange();
      const pos = input.selectionStart, v = input.value;
      const from = st ? st.start : pos;
      input.value = v.slice(0, from) + AI_MENTION + v.slice(pos);
      const p = from + AI_MENTION.length;
      input.setSelectionRange(p, p);
      picker.hidden = true; picker.dataset.kind = '';
      input.focus();
      input.dispatchEvent(new Event('input'));
    });
  }
  const atBtn = body.querySelector('#wAt');
  if (atBtn) { atBtn.addEventListener('click', atInsert); }
  input.addEventListener('input', atSync);

  const loginBtn = body.querySelector('#wLogin');
  if (loginBtn) { loginBtn.addEventListener('click', () => navigate('#/login')); }

  const emojiBtn = body.querySelector('#wEmoji');
  if (emojiBtn) {
    emojiBtn.addEventListener('click', async () => {
      if (!picker.hidden && picker.dataset.kind === 'emoji') { picker.hidden = true; return; }
      await mountEmojiPicker(picker, input);
    });
  }
  const imageBtn = body.querySelector('#wImage');
  if (imageBtn) { imageBtn.addEventListener('click', openImagePicker); }

  await loadEmoji();
  await load();
}

/* ============================================================
 * AI 对话（流式输出 + 工具调用卡片 + 非流式降级）
 * ============================================================ */
const TOOL_META = {
  search:   { n: '检索站内作品', d: 'M11 4a7 7 0 1 1 0 14 7 7 0 0 1 0-14zm0 2a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.6 11.2 3.2 3.2-1.4 1.4-3.2-3.2z' },
  get:      { n: '读取作品详情', d: 'M4 4h16v16H4V4zm2 2v12h12V6H6zm3 2h6v2H9V8zm0 4h6v2H9v-2z' },
  rank:     { n: '查询榜单排名', d: 'M4 20V10h4v10H4zm6 0V4h4v16h-4zm6 0v-7h4v7h-4z' },
  comments: { n: '读取评论区',   d: 'M4 5h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H9l-4 4V6a1 1 0 0 1 1-1z' },
  web_open: { n: '联网读取网页', d: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm7 9h-3a15 15 0 0 0-1.2-5.4A8 8 0 0 1 19 11zM12 4c1 1.6 1.7 4.2 1.8 7h-3.6C10.3 8.2 11 5.6 12 4zM5 11a8 8 0 0 1 4.2-5.4A15 15 0 0 0 8 11H5zm0 2h3a15 15 0 0 0 1.2 5.4A8 8 0 0 1 5 13zm7 7c-1-1.6-1.7-4.2-1.8-7h3.6c-.1 2.8-.8 5.4-1.8 7zm2.8-1.6A15 15 0 0 0 16 13h3a8 8 0 0 1-4.2 5.4z' },
  weather:  { n: '查询天气',     d: 'M6.5 19a4.5 4.5 0 0 1-.7-8.95A5.6 5.6 0 0 1 16.4 9.2 3.9 3.9 0 0 1 16 17H6.5zm-2 2h13v1.6h-13V21z' },
  time:     { n: '获取当前时间', d: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1.2 4.6v5.1l3.9 2.3-1.1 1.9-5-3V6.6h2.2z' },
};

/** 气泡内容分区：工具卡片区 + 文本区 */
function aiParts(target) {
  if (!target.querySelector('.ai-text')) {
    target.innerHTML = '<div class="tool-cards"></div>'
      + '<div class="ai-text"><span class="typing"><i></i><i></i><i></i></span></div>';
  }
  return { cards: target.querySelector('.tool-cards'), text: target.querySelector('.ai-text') };
}

function addToolCall(cards, action) {
  const meta = TOOL_META[action] || { n: action || '工具调用', d: TOOL_META.search.d };
  const el = document.createElement('div');
  el.className = 'tool-card call';
  el.dataset.action = action || '';
  el.innerHTML = `<svg viewBox="0 0 24 24" class="tc-ic"><path d="${meta.d}"/></svg>
    <span class="tc-body"><b>${esc(meta.n)}</b><span class="tiny">正在调用…</span></span>
    <span class="tc-dot"><i></i><i></i><i></i></span>`;
  cards.appendChild(el);
}

function resolveTool(cards, ev) {
  const list = cards.querySelectorAll('.tool-card.call');
  const el = list[list.length - 1];
  if (!el) { return; }
  el.classList.remove('call');
  el.classList.add(ev.ok ? 'ok' : 'err');
  const sub = el.querySelector('.tiny');
  if (sub) { sub.textContent = (ev.ok ? '已完成 · ' : '失败 · ') + (ev.summary || ''); }
  const dot = el.querySelector('.tc-dot');
  if (dot) { dot.remove(); }
}

const AI_ICON_SEND = '<svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:#fff"><path d="M3 20l18-8L3 4v6l12 2-12 2v6z"/></svg>';
const AI_ICON_STOP = '<svg viewBox="0 0 24 24" class="ic" style="width:16px;height:16px;fill:#fff"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>';

async function mountAi(body) {
  window.__chatPollEnabled = false;         // AI 页不轮询世界对话，避免并发拖累
  if (state.role === 'guest') {
    body.innerHTML = `<div class="block-note">
      <p>AI 对话需要登录后使用</p>
      <button class="btn btn-sm" id="goLogin">去登录</button>
    </div>`;
    body.querySelector('#goLogin').addEventListener('click', () => navigate('#/login'));
    return;
  }

  body.innerHTML = `
    <div class="chat-wrap">
      <div class="chat-stream" id="stream">
        <div class="skeleton" style="height:54px"></div>
      </div>
      <div class="picker" id="pickerAi" hidden></div>
      <div class="model-bar">
        <button type="button" class="model-pick" id="modelBtn" aria-haspopup="listbox" aria-expanded="false">
          <svg viewBox="0 0 24 24" class="mp-ic"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/></svg>
          <span class="mp-name" id="modelBtnName">模型</span>
          <svg viewBox="0 0 24 24" class="mp-chev"><path d="M7 10l5 5 5-5z"/></svg>
        </button>
      </div>
      <div class="model-sheet" id="modelPanel" hidden>
        <div class="ms-head">选择模型</div>
        <div class="ms-list" id="modelList"></div>
      </div>
      <div class="chat-input">
        <button class="icon-btn pk-btn" id="emojiBtnAi" aria-label="表情">
          <svg viewBox="0 0 24 24" class="ic"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM8.5 9.5a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zm7 0a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zM7.6 14.2a1 1 0 0 1 1.4-.1 4.6 4.6 0 0 0 6 0 1 1 0 0 1 1.3 1.5 6.6 6.6 0 0 1-8.6 0 1 1 0 0 1-.1-1.4z"/></svg>
        </button>
        <div class="cnt" id="aiCount">0/2000</div>
        <textarea class="input" id="aiInput" rows="1" maxlength="2000" placeholder="向 AI 提问，可问榜单规则与作品信息…"></textarea>
        <button class="btn" id="aiSend" aria-label="发送">
          <svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:#fff"><path d="M3 20l18-8L3 4v6l12 2-12 2v6z"/></svg>
        </button>
      </div>
      <div class="identity-tip">
        <span id="quotaText">正在读取用量…</span>
        <span class="tiny" id="usageNow"></span>
        <button class="link" id="clearAi" style="border:0;background:0;font-size:12px">清空对话</button>
      </div>
    </div>`;

  const stream = body.querySelector('#stream');
  bindImageViewer(stream);          /* 点图片看大图 */
  const input = body.querySelector('#aiInput');
  const sendBtn = body.querySelector('#aiSend');
  const quotaTip = body.querySelector('#quotaText');
  const usageNow = body.querySelector('#usageNow');
  const aiCnt = body.querySelector('#aiCount');
  const picker = body.querySelector('#pickerAi');
  const modelBtn = body.querySelector('#modelBtn');
  const modelBtnName = body.querySelector('#modelBtnName');
  const modelPanel = body.querySelector('#modelPanel');
  const modelList = body.querySelector('#modelList');
  let aiModels = [];        // 可选免费模型
  let selModel = '';        // '' = 本站默认模型（按 token 计）
  let defaultModelName = '';// 本站默认模型名（取自配置，如 glm-4-flash）
  let lastState = null;     // 最近一次额度快照，用于切换模型后重算显示
  let controller = null;
  let rawText = '';        // 本轮回答的原始文本（渲染的唯一真源）
  let tailHtml = '';       // 追加在正文之后的系统提示（错误等）
  let lastPaint = 0;

  function setSendState(busy) {
    if (busy) {
      sendBtn.innerHTML = AI_ICON_STOP;
      sendBtn.classList.remove('btn');
      sendBtn.classList.add('btn-stop');
    } else {
      sendBtn.innerHTML = AI_ICON_SEND;
      sendBtn.classList.add('btn');
      sendBtn.classList.remove('btn-stop');
    }
  }

  /** 按历史重建消息（清洗旧数据里残留的工具标签） */
  async function loadHistory() {
    try {
      const d = await api('ai.php', 'history');
      stream.innerHTML = '';
      (d.items || []).forEach(m => {
        const text = m.role === 'user' ? m.content : stripToolTags(m.content);
        if (m.role !== 'user' && !text) { return; }
        stream.appendChild(aiMsg(m.role === 'user' ? 'mine' : 'them', text));
      });
      if (!(d.items || []).length) {
        stream.innerHTML = '<div class="empty" style="padding:22px">开始和 AI 聊聊吧，可问榜单排名、作品详情或让我联网查资料</div>';
      }
      requestAnimationFrame(() => toBottom(stream));
    } catch (e) {
      stream.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`;
    }
  }

  /* ---------- 模型选择与额度（自定义控件，非原生） ----------
     默认使用本站模型（GLM，按 token 计）；选中免费模型才按次数计。 */
  const MODEL_LS = 'kimgr_ai_models';
  function selSave() { try { localStorage.setItem(MODEL_LS, JSON.stringify(selModel ? [selModel] : [])); } catch (e) {} }
  function selLoad() {
    try { const a = JSON.parse(localStorage.getItem(MODEL_LS) || '[]'); return (Array.isArray(a) && a.length) ? String(a[0]) : ''; }
    catch (e) { return ''; }
  }
  function labelOf(id) {
    if (!id) { return defaultModelName || '默认模型'; }
    const m = aiModels.find(x => x.id === id);
    return m ? m.name : (defaultModelName || '默认模型');
  }
  function renderModelBtn() { modelBtnName.textContent = labelOf(selModel); }
  function renderModelPanel() {
    const rows = [{ id: '', name: defaultModelName || '默认模型', sub: '本站默认 · 按 token 计', ctx: 0 }]
      .concat(aiModels.map(m => ({ id: m.id, name: m.name, sub: '免费模型 · 按次数计', ctx: m.ctx })));
    modelList.innerHTML = rows.map(r =>
      '<button type="button" class="ms-row' + (r.id === selModel ? ' on' : '') + '" data-id="' + esc(r.id) + '">'
      + '<span class="ms-check"></span>'
      + '<span class="ms-main"><span class="ms-name">' + esc(r.name) + '</span>'
      + '<span class="ms-sub">' + esc(r.sub) + '</span></span>'
      + '<span class="ms-ctx">' + (r.ctx ? Math.round(r.ctx / 1024) + 'K' : '') + '</span>'
      + '</button>').join('');
  }
  function quotaText(d) {
    const site = ' · 全站余 ' + d.site.remaining + '/' + d.site.limit;
    if (d.unlimited) { return '当前身份：' + (state.role === 'subadmin' ? '副管理员' : '管理员') + ' · 无限制' + site; }
    if (selModel && d.online) { return '今日 你 ' + d.user.used + '/' + d.user.limit + ' 次' + site; }
    const t = d.token || {};
    return '今日 ' + (t.today || 0) + '/' + (t.daily_limit || 0) + ' tokens' + site;
  }
  function paintQuota() { if (quotaTip && lastState) { quotaTip.textContent = quotaText(lastState); } }

  async function loadState() {
    try {
      const d = await api('ai.php', 'models', null, { silent: true, timeout: 40000 });
      aiModels = d.models || [];
      defaultModelName = d.default_name || '';
      const saved = selLoad();
      selModel = (saved && aiModels.some(m => m.id === saved)) ? saved : '';
      lastState = d;
      renderModelBtn();
      paintQuota();
    } catch (e) {
      modelBtnName.textContent = defaultModelName || '默认模型';
      if (quotaTip) { quotaTip.textContent = ''; }
    }
  }

  modelBtn.addEventListener('click', () => {
    const open = modelPanel.hidden;
    if (open) { renderModelPanel(); }
    modelPanel.hidden = !open;
    modelBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  modelList.addEventListener('click', e => {
    const b = e.target.closest('[data-id]'); if (!b) return;
    selModel = b.dataset.id || '';
    selSave(); renderModelBtn(); renderModelPanel(); paintQuota();
    modelPanel.hidden = true;
    modelBtn.setAttribute('aria-expanded', 'false');
  });

  /** 非流式兜底：返回完整回答文本。counted = 流式那次服务端是否已受理（受理过才算「已计入」，否则必须重新检查并占用额度） */
  async function sendSync(text, target, counted) {
    const d = await api('ai.php', 'send_sync', { content: text, fallback: counted ? 1 : 0, model: selModel }, { timeout: 180000, silent: true });
    const p = aiParts(target);
    (d.tools || []).forEach(t => { addToolCall(p.cards, t.action); resolveTool(p.cards, t); });
    return d.text || '';
  }

  async function send() {
    if (controller) { controller.abort(); return; }        // 生成中 → 本次点击为「停止」
    const text = input.value.trim();
    if (!text) { return; }
    input.value = '';
    if (aiCnt) { aiCnt.textContent = '0/2000'; }
    input.style.height = 'auto';
    const mineEl = aiMsg('mine', text);
    stream.appendChild(mineEl);
    toBottom(stream);

    const bubble = aiMsg('them', '');
    stream.appendChild(bubble);
    const target = bubble.querySelector('.bubble');
    aiParts(target);
    let firstChunk = true;
    let gotAny = false;        // 是否已收到可见内容
    let gotDone = false;       // 服务端是否正常收尾
    let stopped = false;       // 用户主动停止
    let gateError = null;      // 被闸门拦下（额度用尽 / 登录态失效）：这一条根本没发出去
    let streamAccepted = false;// 服务端是否受理了本次流式（决定降级重放要不要重算额度）
    let lastUsage = null;
    toBottom(stream);

    window.__aiBusy = true;
    controller = new AbortController();
    setSendState(true);

    // 首字守卫：15s 无任何输出 → 中断并转非流式
    const idleTimer = setTimeout(() => { if (!gotAny && controller) { controller.abort(); } }, 15000);

    try {
      const resp = await fetch('api/ai.php?action=send', {
        signal: controller.signal,
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'text/event-stream',
          'Authorization': 'Bearer ' + state.token,
          'X-Token': state.token,
          'X-CSRF-Token': state.csrf,
        },
        body: JSON.stringify({ content: text, model: selModel }),
        credentials: 'same-origin',
      });
      if (!resp.ok || !resp.body) {
        /* 闸门被拒（额度用尽 / 登录态失效 …）：这是「不许发」，不是「通道坏了」。
           必须原样报出原因，并且绝不转非流式重发 —— 否则超额也能照发出去。 */
        let why = '流式通道不可用';
        try { const j = await resp.json(); if (j && j.msg) { why = j.msg; } } catch (e2) {}
        const err = new Error(why);
        err.gate = true;
        throw err;
      }
      streamAccepted = true;     // 服务端已受理：这次的降级才是「已计入额度」的重放

      rawText = ''; tailHtml = ''; lastPaint = 0;
      const reader = resp.body.getReader();
      const decoder = new TextDecoder();
      let buf = '';
      while (true) {
        const r = await reader.read();
        if (r.done) { break; }
        buf += decoder.decode(r.value, { stream: true });
        let idx;
        while ((idx = buf.indexOf('\n\n')) >= 0) {
          const chunk = buf.slice(0, idx);
          buf = buf.slice(idx + 2);
          const line = chunk.trim();
          if (line.indexOf('data:') !== 0) { continue; }
          let ev;
          try { ev = JSON.parse(line.slice(5).trim()); } catch (e) { continue; }

          if (ev.delta) {
            const p = aiParts(target);
            if (firstChunk) { p.text.textContent = ''; firstChunk = false; }
            rawText += ev.delta;
            gotAny = true;
            const now = Date.now();
            if (now - lastPaint > 80) {              // 节流：约 12fps 重绘
              lastPaint = now;
              paintAi(target, rawText, tailHtml);
              if (atBottom(stream)) { toBottom(stream); }
            }
          }
          if (ev.tool_call) {
            const p = aiParts(target);
            addToolCall(p.cards, ev.tool_call.action || '');
            gotAny = true;
            toBottom(stream);
          }
          if (ev.tool_result) {
            resolveTool(aiParts(target).cards, ev.tool_result);
            toBottom(stream);
          }
          if (ev.error) {
            tailHtml += `<span style="color:var(--danger)"><br>${esc(ev.error)}</span>`;
            gotAny = true;
            paintAi(target, rawText, tailHtml);
            if (atBottom(stream)) { toBottom(stream); }
          }
          if (ev.done) { gotDone = true; paintAi(target, rawText, tailHtml); }
          if (ev.usage && ev.usage.total_tokens) { lastUsage = ev.usage; }
        }
      }
    } catch (e) {
      if (e && e.name === 'AbortError') {
        if (!gotAny) {
          stopped = true;
          aiParts(target).text.innerHTML = '<span class="recall">已停止生成</span>';
        }
        // 已有内容的中断 → 交给下面的非流式补齐
      } else if (e && e.gate) {
        gateError = e;           // 被闸门拦下：不做任何兜底
      }
      // 其它网络异常同样走补齐
    } finally {
      clearTimeout(idleTimer);
      controller = null;
      setSendState(false);
    }

    if (gateError) {
      /* 被闸门拦下：这一条根本没发出去，界面不该留下痕迹，内容还给用户 */
      if (mineEl && mineEl.parentNode) { mineEl.parentNode.removeChild(mineEl); }
      bubble.remove();
      input.value = text;
      if (aiCnt) { aiCnt.textContent = text.length + '/2000'; }
      toast(gateError.message, 'err');
    } else if (!stopped && !gotDone) {
      // 未正常收尾（被主机掐断 / 无输出 / 中途断流）→ 非流式补齐完整回答
      try {
        const t = await sendSync(text, target, streamAccepted);
        const p = aiParts(target);
        if (t) { p.text.innerHTML = renderAiRich(t); }
        else if (!gotAny) { p.text.textContent = '（无内容返回，请稍后重试）'; }
      } catch (e) {
        const p = aiParts(target);
        if (gotAny) {
          p.text.insertAdjacentHTML('beforeend',
            ` <span class="tiny" style="color:var(--danger)">· 回答中断</span> <button class="link" id="aiRetry">重试</button>`);
          const rb = p.text.querySelector('#aiRetry');
          if (rb) rb.addEventListener('click', () => { p.text.textContent = ''; rawText = ''; tailHtml = ''; sendAgain(text, target); });
        } else {
          p.text.innerHTML = `<span style="color:var(--danger)">${esc(e.message || '发送失败')}</span>`
            + ' <button class="link" id="aiRetry2">重试</button>';
          const rb = p.text.querySelector('#aiRetry2');
          if (rb) rb.addEventListener('click', () => { p.text.textContent = ''; rawText = ''; tailHtml = ''; sendAgain(text, target); });
        }
      }
    }

    if (lastUsage && usageNow) { usageNow.textContent = '本次 ' + Number(lastUsage.total_tokens) + ' tokens'; }
    window.__aiBusy = false;
    toBottom(stream);
  }

  /** 重试（复用气泡，仅补一次非流式结果） */
  async function sendAgain(text, target) {
    try {
      const t = await sendSync(text, target);
      aiParts(target).text.innerHTML = t ? renderAiRich(t) : '（无内容返回）';
    } catch (e) {
      aiParts(target).text.innerHTML = `<span style="color:var(--danger)">${esc(e.message || '发送失败')}</span>`;
    }
    toBottom(stream);
  }

  sendBtn.addEventListener('click', send);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(108, input.scrollHeight) + 'px';
    if (aiCnt) { aiCnt.textContent = input.value.length + '/2000'; }
  });
  input.addEventListener('focus', () => requestAnimationFrame(() => toBottom(stream)));

  // 清空对话：清空后从服务端重新拉取，确保界面与库一致
  body.querySelector('#clearAi').addEventListener('click', async () => {
    if (!(await dialog('清空对话', '将删除你与 AI 的全部聊天记录，不可恢复。确认？', '清空', { danger: true }))) { return; }
    try {
      await api('ai.php', 'clear');
      if (usageNow) { usageNow.textContent = ''; }
      await loadHistory();
      toast('已清空对话记录');
    } catch (e) { toast(e.message, 'err'); }
  });

  // AI 输入区表情面板
  const emojiBtnAi = body.querySelector('#emojiBtnAi');
  if (emojiBtnAi) {
    emojiBtnAi.addEventListener('click', async () => {
      if (!picker.hidden && picker.dataset.kind === 'emoji') { picker.hidden = true; return; }
      await mountEmojiPicker(picker, input);
    });
  }

  await loadEmoji();
  await loadHistory();
  await loadState();
}

/** AI 消息气泡 */
function aiMsg(side, text) {
  const el = document.createElement('div');
  el.className = 'msg' + (side === 'mine' ? ' mine' : '');
  const who = side === 'mine' ? esc(state.username) : 'AI 助手';
  const avatar = side === 'mine'
    ? '<img src="' + esc(state.avatar || '') + '" alt="" draggable="false" style="user-select:none">'
    : '<span class="ai-av"><svg viewBox="0 0 24 24"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M18 15l.9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z"/></svg></span>';
  el.innerHTML = `
    <span class="av">${avatar}</span>
    <span class="bubble-wrap">
      <span class="who">${who}</span>
      <div class="bubble">${renderAiRich(text)}</div>
    </span>`;
  return el;
}

/* ========== pages/mine.js ========== */
/**
 * 我的页：个人信息 / AI 用量 / IP 与访问记录 / 设置 / 控制面板入口
 */





/** 配额上限展示（数据缺失时返回空串，不显示占位符） */
function limit(key, d) {
  const n = d && d.quota && d.quota.limits ? Number(d.quota.limits[key]) : 0;
  return n > 0 ? ' / ' + n : '';
}

async function renderMine(container) {
  if (state.role === 'guest') {
    container.innerHTML = `
      <div class="card">
        <div class="profile-head">
          <span class="av">${avatarImg('guest')}</span>
          <div><div class="name">游客</div><div class="tiny">当前为游客模式</div></div>
        </div>
        <p class="muted tiny">游客可浏览榜单与世界对话；登录后可评论、点赞、使用 AI 对话。</p>
        <button class="btn" id="goLogin" style="width:100%;margin-top:10px">登录 / 注册</button>
      </div>
      <div class="card">
        <div class="card-title">外观设置</div>
        <div id="guestAppearance"></div>
      </div>
      <div class="card">
        <div class="card-title">内容屏蔽</div>
        <div id="guestBlock"></div>
      </div>
      <div class="card">
        <div class="card-title">搜索</div>
        <div id="guestSearchAi"></div>
      </div>`;
    container.querySelector('#goLogin').addEventListener('click', () => navigate('#/login'));
    mountAppearance(container.querySelector('#guestAppearance'));
    mountBlockList(container.querySelector('#guestBlock'), true);
    mountSearchAi(container.querySelector('#guestSearchAi'), true);
    return;
  }

  let d;
  try { d = await api('profile.php', 'info'); }
  catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }

  const isAdmin = isAdminish();
  const s = d.settings || {};

  container.innerHTML = `
    <div class="card">
      <div class="profile-head">
        <span class="av">${avatarImg(d.username, d.avatar)}</span>
        <div>
          <div class="name">${userName(d.username, d.role)}</div>
          <div class="tiny">${isAdmin ? (d.role === 'subadmin' ? '副管理员账号' : '管理员账号') : ('注册于 ' + esc(d.registered_at || ''))}</div>
        </div>
      </div>
      <div class="uid-row">
        <div class="uid-left">
          <div class="tiny">我的 UID</div>
          <div class="uid8${d.uid8_babao ? ' babao' : ''}">${esc(d.uid8 || '—')}</div>
        </div>
        <button class="btn-ghost btn-sm" id="copyUid">复制</button>
      </div>
      <div class="tiny" style="margin-top:6px">由账号经可逆算法唯一生成${d.uid8_babao ? ' · 管理身份专用豹子号' : ''}，可据此反查账号</div>
      ${isAdmin ? '<button class="btn" id="panelBtn" style="width:100%;margin-top:10px">进入控制面板</button>' : ''}
    </div>

    <div class="card">
      <div class="card-title">AI 用量</div>
      <div class="stat-grid">
        <div class="stat-box"><div class="k">累计 tokens</div><div class="v">${d.usage ? d.usage.tokens : 0}</div></div>
        <div class="stat-box"><div class="k">调用次数</div><div class="v">${d.usage ? d.usage.calls : 0}</div></div>
        <div class="stat-box"><div class="k">今日 tokens</div><div class="v">${d.usage ? d.usage.today : 0}<i class="cap">${limit('daily_tokens', d)}</i></div></div>
        <div class="stat-box"><div class="k">本月 tokens</div><div class="v">${d.usage ? d.usage.month : 0}<i class="cap">${limit('monthly_tokens', d)}</i></div></div>
      </div>
      ${d.checkin ? `
      <div class="checkin-row">
        <div>
          <div class="ck-big">连续签到 ${d.checkin.streak} 天</div>
          <div class="tiny">${d.checkin.unlimited ? '管理员额度无上限，签到仅记录连续天数' : '每日签到：当日 AI 额度 +1000 · 每连续满 7 天：本月额度 +100000'}</div>
        </div>
        <button class="btn" id="checkinBtn"${d.checkin.today ? ' disabled' : ''}>${d.checkin.today ? '已签到' : '签到'}</button>
      </div>` : ''}
      ${isAdmin ? '<div id="top3" style="margin-top:12px"></div>' : ''}
    </div>

    <div class="card">
      <div class="card-title">AI 免费模型</div>
      <div class="stat-grid">
        <div class="stat-box"><div class="k">可选模型</div><div class="v" id="aiModelCount">—</div></div>
        <div class="stat-box"><div class="k">我的今日次数</div><div class="v" id="aiUserQuota">—</div></div>
        <div class="stat-box"><div class="k">全站剩余</div><div class="v" id="aiSiteQuota">—</div></div>
        <div class="stat-box"><div class="k">列表更新于</div><div class="v" id="aiRefreshed">—</div></div>
      </div>
      <div class="tiny muted" style="margin-top:8px">免费模型按次数计：每人每天 2 次、全站每天 50 次；其余情况按 token 额度。</div>
    </div>

    ${isAdmin ? '' : `
    <div class="card">
      <div class="card-title">我的 IP 信息</div>
      <div class="visit-row"><span>脱敏 IP</span><span>${esc(d.ip ? d.ip.masked : '')}</span></div>
      <div class="visit-row"><span>归属地</span><span>${esc(d.ip ? d.ip.location : '')}</span></div>
      <div class="visit-row"><span>运营商</span><span>${esc(d.ip ? d.ip.isp : '')}</span></div>
      <div class="setting-row">
        <span>记录访问信息</span>
        <button class="switch" id="swIp" role="switch" aria-checked="${Number(s.ip_record) === 1}"></button>
      </div>
    </div>

    <div class="card">
      <div class="card-title">访问记录</div>
      <div id="visitList"><div class="skeleton" style="height:40px"></div></div>
    </div>`}

    <div class="card">
      <div class="card-title">外观设置</div>
      <div id="appearance"></div>
    </div>

    <div class="card">
      <div class="card-title">内容屏蔽</div>
      <div id="blockWrap"></div>
    </div>

    <div class="card">
      <div class="card-title">搜索</div>
      <div id="searchAiSet"></div>
    </div>

    <div class="card">
      <div class="setting-row">
        <span>消息网页通知</span>
        <button class="switch" id="swNotify" role="switch" aria-checked="${Number(s.notify) === 1}"></button>
      </div>
    </div>

    <button class="btn-ghost" id="logoutBtn" style="width:100%;margin-bottom:16px">退出登录</button>
  `;

  /* AI 免费模型额度卡片：独立拉取，失败静默（不影响页面其它部分） */
  (async () => {
    try {
      const q = await api('ai.php', 'models', null, { silent: true, timeout: 40000 });
      const set = (sel, v) => { const el = container.querySelector(sel); if (el) { el.textContent = v; } };
      set('#aiModelCount', String((q.models || []).length));
      set('#aiSiteQuota', q.site.remaining + ' / ' + q.site.limit);
      if (q.unlimited) { set('#aiUserQuota', '无限制'); }
      else { set('#aiUserQuota', q.user.used + ' / ' + q.user.limit); }
      set('#aiRefreshed', q.refreshed_at || '—');
    } catch (e) { /* 静默 */ }
  })();

  if (isAdmin) {
    container.querySelector('#panelBtn').addEventListener('click', () => navigate('#/panel'));
    const copyBtn = container.querySelector('#copyUid');
    if (copyBtn) copyBtn.addEventListener('click', async () => {
      const v = String(d.uid8 || '');
      if (!v) { toast('暂无 UID', 'err'); return; }
      try {
        await navigator.clipboard.writeText(v);
        toast('已复制 UID：' + v);
      } catch (e) {
        // 兜底：剪贴板不可用时显示可选中文本
        toast('UID：' + v);
      }
    });
    loadTop3(container.querySelector('#top3'));
  }

  const swIp = container.querySelector('#swIp');
  if (swIp) swIp.addEventListener('click', async () => {
    const on = swIp.getAttribute('aria-checked') === 'true' ? 0 : 1;
    swIp.setAttribute('aria-checked', on ? 'true' : 'false');
    try { await api('profile.php', 'settings', { ip_record: on }); state.settings.ip_record = on; toast('已保存'); }
    catch (e) { toast(e.message, 'err'); }
  });

  const swNotify = container.querySelector('#swNotify');
  if (swNotify) swNotify.addEventListener('click', async () => {
    const on = swNotify.getAttribute('aria-checked') === 'true' ? 0 : 1;
    swNotify.setAttribute('aria-checked', on ? 'true' : 'false');
    try {
      await api('profile.php', 'settings', { notify: on }, { silent: true });
      state.settings.notify = on;
      toast('已保存');
      if (on) { const g = await askNotifyPermission(); if (!g) toast('浏览器未授予通知权限', 'err'); }
    } catch (e) { toast(e.message, 'err'); }
  });

  mountAppearance(container.querySelector('#appearance'));
  mountBlockList(container.querySelector('#blockWrap'), false);
  mountSearchAi(container.querySelector('#searchAiSet'), false);
  loadVisits(container.querySelector('#visitList'));

  const ck = container.querySelector('#checkinBtn');   // 必须在本页容器内查找：渲染时尚未挂载到 document
  if (ck) {
    ck.addEventListener('click', async () => {
      ck.disabled = true;
      try {
        const r = await api('ai.php', 'checkin', {});
        const wb = Number(r.week_bonus) > 0 ? `！连续满 7 天，本月额度 +${r.week_bonus}` : '';
        toast('签到成功：当日额度 +' + r.daily_bonus + wb);
        renderMine(container);   // 刷新连续天数与配额分母
      } catch (e) {
        ck.disabled = false;
        toast(e.message, 'err');
      }
    });
  }

  container.querySelector('#logoutBtn').addEventListener('click', async () => {
    if (await dialog('退出登录', '确认退出当前账号吗？', '退出')) {
      try { await api('auth.php', 'logout'); } catch (e) {}
      setToken('');
      toast('已退出');
      location.reload();
    }
  });
}

/* ---------- 外观设置（深浅色 + 主题色） ---------- */
/**
 * 内容屏蔽设置：每行一条规则，命中则收起该评论。
 * 游客写本机 localStorage，登录用户写账号设置。
 */
/** 写入皮肤 cookie（服务端据此选择加载哪份皮肤文件） */
function setSkinCookie(skin) {
  try {
    document.cookie = 'kimgr_skin=' + encodeURIComponent(skin) + '; path=/; max-age=31536000; SameSite=Lax';
  } catch (e) {}
}

/** 搜索结果页 AI 总结开关：登录用户写账号设置，游客写本机偏好 */
function mountSearchAi(box, isGuest) {
  if (!box) { return; }
  const cur = isGuest ? getPrefs().search_ai : (state.settings || {}).search_ai;
  box.innerHTML = `
    <div class="setting-row">
      <span>搜索结果顶部的 AI 总结</span>
      <button class="switch" id="swSearchAi" role="switch" aria-checked="${Number(cur) !== 0}"></button>
    </div>
    <div class="tiny muted" style="margin-top:6px;line-height:1.6">
      开启后，搜索关键词时顶部会多出一张 AI 总结卡片；关闭则不再显示。
    </div>`;
  const sw = box.querySelector('#swSearchAi');
  sw.addEventListener('click', async () => {
    const next = sw.getAttribute('aria-checked') === 'true' ? 0 : 1;
    sw.setAttribute('aria-checked', next ? 'true' : 'false');
    if (isGuest) {
      setPrefs(Object.assign({}, getPrefs(), { search_ai: next }));
      toast('已保存到本机浏览器');
      return;
    }
    try {
      const st = await api('profile.php', 'settings', { search_ai: next });
      state.settings = Object.assign({}, state.settings, st || {});
      toast('已保存');
    } catch (e) {
      sw.setAttribute('aria-checked', next ? 'false' : 'true');   // 回滚，避免界面与库不一致
      toast(e.message, 'err');
    }
  });
}

function mountBlockList(box, isGuest) {
  if (!box) { return; }
  const raw = isGuest ? String(getPrefs().block_words || '') : String((state.settings || {}).block_words || '');
  box.innerHTML = `
    <div class="tiny" style="margin-bottom:8px;line-height:1.6">
      每行一条规则，命中的评论会自动收起（仅对你生效，不影响他人）。<br>
      普通词语：忽略大小写包含即命中；正则表达式：用斜杠包裹，例如 <b>/广告|引流|私聊/i</b>。<br>
      最多 50 条，每条不超过 100 个字符。
    </div>
    <textarea class="input" id="bwText" rows="4" maxlength="2000" placeholder="加微信&#10;/免费|引流|私聊/i"></textarea>
    <button class="btn" id="bwSave" style="margin-top:10px">保存屏蔽规则</button>
    <button class="btn-ghost" id="bwClear" style="margin-top:10px;margin-left:8px">清空</button>`;
  const ta = box.querySelector('#bwText');
  ta.value = raw;

  const save = async v => {
    if (isGuest) {
      setPrefs(Object.assign({}, getPrefs(), { block_words: v }));
      toast('已保存到本机浏览器');
    } else {
      try {
        const st = await api('profile.php', 'settings', { block_words: v });
        state.settings = Object.assign({}, state.settings, st || {});
        toast('已保存');
      } catch (e) { toast(e.message, 'err'); }
    }
  };

  box.querySelector('#bwSave').addEventListener('click', () => save(ta.value));
  box.querySelector('#bwClear').addEventListener('click', () => { ta.value = ''; save(''); });
}

/** 外观设置里展示的皮肤：当前的限时节日皮肤排在最前，窗口外不出现 */
function skinKeys() {
  const now = festivalNow();
  const keys = Object.keys(SKINS).filter(k => FESTIVAL_KEYS.indexOf(k) < 0);
  return now ? [now].concat(keys) : keys;
}

function mountAppearance(box) {
  if (!box) return;
  const theme = state.settings.theme || 'light';
  const accent = state.settings.accent || 'blue-purple';
  const skin = document.documentElement.dataset.skin || state.settings.skin || 'glass';
  const navOn = getPrefs().nav_anim !== 0;
  box.innerHTML = `
    <div class="setting-row">
      <span>深浅色</span>
      <div class="seg" style="max-width:200px" id="themeSeg">
        <button data-t="light" class="${theme === 'light' ? 'on' : ''}">浅色</button>
        <button data-t="dark" class="${theme === 'dark' ? 'on' : ''}">深色</button>
        <button data-t="system" class="${theme === 'system' ? 'on' : ''}">跟随</button>
      </div>
    </div>
    <div class="setting-row" style="flex-direction:column;align-items:flex-start;gap:8px">
      <span>设计风格</span>
      <div class="skin-row" id="skinRow">
        ${skinKeys().map(k => `<button class="skin-card ${skin === k ? 'on' : ''}" data-s="${k}">
          <span class="skin-prev p-${k}" aria-hidden="true"></span>
          <b>${esc(SKINS[k].name)}</b>
          <span class="tiny">${esc(SKINS[k].desc)}</span>
        </button>`).join('')}
      </div>
    </div>
    <div class="setting-row ${FESTIVAL_KEYS.indexOf(skin) >= 0 ? 'fj-locked' : ''}" style="flex-direction:column;align-items:flex-start;gap:8px">
      <span>主题色（蓝紫色 / 苹果色 / 自定义）</span>
      <div class="swatch-row" id="accentRow">
        <button class="swatch" data-a="blue-purple" title="蓝紫" style="background:#7C3AED"></button>
        <button class="swatch" data-a="ios-colorful" title="iOS 彩色" style="background:#007AFF"></button>
        <button class="swatch" data-a="custom" title="自定义" style="background:conic-gradient(#f43f5e,#f59e0b,#22c55e,#3b82f6,#a855f7,#f43f5e)"></button>
      </div>
      <div id="customBox" hidden>
        <div class="tiny" style="margin:6px 0">自定义颜色（H/S/L 微调）</div>
        <div class="swatch-row" style="gap:12px">
          <div class="slider" data-hsl="h" style="width:150px"><div class="rail"><div class="fill"></div><div class="knob"></div></div></div>
          <div class="slider" data-hsl="s" style="width:150px"><div class="rail"><div class="fill"></div><div class="knob"></div></div></div>
          <div class="slider" data-hsl="l" style="width:150px"><div class="rail"><div class="fill"></div><div class="knob"></div></div></div>
        </div>
        <div class="tiny" id="customHex"></div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
          <input class="input" id="hexInput" placeholder="#6D3BF5" style="max-width:130px;padding:8px 10px;font-size:13px">
          <button class="btn-ghost btn-sm" id="hexApply">应用</button>
        </div>
      </div>
    </div>
    <div class="setting-row">
      <span>返回动画</span>
      <div class="seg" style="max-width:200px" id="navAnimSeg">
        <button data-n="1" class="${navOn ? 'on' : ''}">开启</button>
        <button data-n="0" class="${navOn ? '' : 'on'}">关闭</button>
      </div>
    </div>
    <div class="tiny" style="margin-top:-4px;line-height:1.7">
      开启后：界面切换有方向感，返回时上一页沿原路径滑回（从哪来回哪去），
      返回后恢复原来的位置与内容，不重新加载；从左边缘右滑还能跟手预览上一页。
    </div>`;

  const skinRow = box.querySelector('#skinRow');
  if (skinRow) {
    skinRow.addEventListener('click', async e => {
      const b = e.target.closest('[data-s]'); if (!b) return;
      const sk = b.dataset.s;
      if (!SKINS[sk]) { return; }

      /* 节日皮肤是限时项，不写 kimgr_skin（服务端白名单里没有它们）：
         选中它 = 清除「退出标记」；选其它皮肤 = 记为主动退出，窗口期内不再自动启用。 */
      if (FESTIVAL_KEYS.indexOf(sk) >= 0) {
        try { document.cookie = 'kimgr_skin_optout=; path=/; max-age=0'; } catch (e) {}
        toast('正在切换到「' + SKINS[sk].name + '」…');
        setTimeout(() => location.reload(), 260);
        return;
      }
      try { document.cookie = 'kimgr_skin_optout=1; path=/; max-age=31536000; SameSite=Lax'; } catch (e) {}

      /* 每种风格是一份独立 CSS 文件，由内联脚本按 cookie 选择加载，
         因此切换动作 = 写 cookie（+ 同步账号偏好）→ 刷新页面。 */
      setSkinCookie(sk);
      setPrefs(Object.assign({}, getPrefs(), { skin: sk }));
      if (state.role !== 'guest') {
        try { await api('profile.php', 'settings', { skin: sk }); state.settings.skin = sk; } catch (err) {}
      }
      skinRow.querySelectorAll('.skin-card').forEach(x => x.classList.toggle('on', x === b));
      toast('正在切换到「' + SKINS[sk].name + '」…');
      setTimeout(() => location.reload(), 260);
    });
  }

  box.querySelector('#navAnimSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-n]'); if (!b) return;
    const on = b.dataset.n === '1';
    setPrefs(Object.assign({}, getPrefs(), { nav_anim: on ? 1 : 0 }));
    setNavAnim(on);
    box.querySelectorAll('#navAnimSeg button').forEach(x => x.classList.toggle('on', x === b));
    toast(on ? '已开启返回动画' : '已关闭返回动画');
  });

  box.querySelector('#themeSeg').addEventListener('click', async e => {
    const b = e.target.closest('[data-t]'); if (!b) return;
    box.querySelectorAll('#themeSeg button').forEach(x => x.classList.toggle('on', x === b));
    await saveTheme({ theme: b.dataset.t });
  });

  const row = box.querySelector('#accentRow');
  markSwatch(row, accent);
  const customBox = box.querySelector('#customBox');
  if (accent === 'custom') { customBox.hidden = false; initHsl(box); }

  row.addEventListener('click', async e => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    markSwatch(row, b.dataset.a);
    customBox.hidden = b.dataset.a !== 'custom';
    if (b.dataset.a === 'custom') initHsl(box);
    await saveTheme({ accent: b.dataset.a });
  });
}

function markSwatch(row, accent) {
  row.querySelectorAll('.swatch').forEach(s => s.classList.toggle('on', s.dataset.a === accent));
}

function initHsl(box) {
  if (box.dataset.hslInit === '1') return;
  box.dataset.hslInit = '1';
  const cur = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
  const scr = [box.querySelector('[data-hsl="h"]'), box.querySelector('[data-hsl="s"]'), box.querySelector('[data-hsl="l"]')];

  // 简易 HSL：以滑块位置直接映射并实时写入
  const vals = { h: 265, s: 70, l: 55 };
  const render = () => {
    const hex = `hsl(${vals.h} ${vals.s}% ${vals.l}%)`;
    document.documentElement.style.setProperty('--accent', hex);
    document.documentElement.style.setProperty('--accent-2', `hsl(${(vals.h + 18) % 360} ${Math.min(100, vals.s + 5)}% ${Math.min(80, vals.l + 12)}%)`);
    box.querySelector('#customHex').textContent = hex;
  };
  scr.forEach((el, i) => {
    const key = ['h', 's', 'l'][i];
    const set = (ratio) => {
      const r = Math.max(0, Math.min(1, ratio));
      vals[key] = key === 'h' ? Math.round(r * 359) : Math.round(r * 100);
      el.querySelector('.fill').style.width = (r * 100) + '%';
      el.querySelector('.knob').style.left = (r * 100) + '%';
      render();
    };
    set(key === 'h' ? vals.h / 359 : vals[key] / 100);
    const drag = ev => {
      const rect = el.getBoundingClientRect();
      const x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - rect.left;
      set(x / rect.width);
    };
    el.addEventListener('pointerdown', ev => {
      drag(ev);
      const move = e2 => drag(e2);
      const up = async () => {
        window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up);
        await saveTheme({ accent: 'custom', accent_custom: `hsl(${vals.h} ${vals.s}% ${vals.l}%)` });
      };
      window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    });
  });
  render();

  // 十六进制输入
  const hexInput = box.querySelector('#hexInput');
  const hexApply = box.querySelector('#hexApply');
  const hexEl = box.querySelector('#customHex');
  if (hexInput) { hexInput.value = (hexEl.textContent || '').trim() || '#6D3BF5'; }
  if (hexApply) hexApply.addEventListener('click', async () => {
    let v = (hexInput.value || '').trim();
    if (v.charAt(0) !== '#') { v = '#' + v; }
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) { toast('请输入合法色值，如 #6D3BF5', 'err'); return; }
    // 反推 HSL 滑块位置
    const { h, s: ss, l } = hexToHsl(v);
    vals.h = h; vals.s = ss; vals.l = l;
    [['h', h / 359], ['s', ss / 100], ['l', l / 100]].forEach(([k, ratio], i) => {
      const el = scr[i];
      el.querySelector('.fill').style.width = (ratio * 100) + '%';
      el.querySelector('.knob').style.left = (ratio * 100) + '%';
    });
    render();
    await saveTheme({ accent: 'custom', accent_custom: v });
    toast('已应用自定义颜色');
  });
}

/* ---------- 访问记录 ---------- */
async function loadVisits(box) {
  if (!box) return;
  try {
    const d = await api('profile.php', 'visits');
    box.innerHTML = '';
    if (!d.items.length) { box.innerHTML = '<div class="tiny" style="padding:8px 0">暂无访问记录</div>'; return; }
    d.items.forEach(v => {
      const el = document.createElement('div');
      el.className = 'visit-row';
      el.innerHTML = `<span>${esc(v.location || '未知地区')} · ${esc(v.ip)}</span><span class="tiny">${esc(v.time)}</span>`;
      box.appendChild(el);
    });
  } catch (e) { box.innerHTML = '<div class="tiny">加载失败</div>'; }
}

/* ---------- 管理员：用量 Top3 ---------- */
async function loadTop3(box) {
  if (!box) return;
  try {
    const d = await api('profile.php', 'usage');
    const t3 = d.top3 || [];
    if (!t3.length) { box.innerHTML = '<div class="tiny">暂无用量排行</div>'; return; }
    box.innerHTML = '<div class="tiny" style="margin-bottom:6px">全站用量 Top3</div>' + t3.map((u, i) =>
      `<div class="visit-row"><span>${i + 1}. ${esc(u.username)}</span><span class="tiny">${u.tokens} tokens · ${u.calls} 次</span></div>`).join('');
  } catch (e) {}
}

function avatarImg(name, url) {
  if (url) return `<img src="${url}" alt="" draggable="false" style="user-select:none">`;
  return `<img src="data:image/svg+xml;utf8,${encodeURIComponent(simpleAvatar(name || 'guest'))}" alt="" draggable="false" style="user-select:none">`;
}

/* 前端兜底头像（后端未返回时）；正常由接口下发 SVG */
function simpleAvatar(seed) {
  let h = 0; for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) % 360;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#eceef2"/><rect x="10" y="10" width="20" height="20" fill="hsl(${h},68%,52%)"/><rect x="30" y="30" width="20" height="20" fill="hsl(${h},68%,52%)"/><rect x="50" y="10" width="20" height="20" fill="hsl(${(h+40)%360},72%,62%)"/><rect x="10" y="50" width="20" height="20" fill="hsl(${(h+40)%360},72%,62%)"/><rect x="50" y="50" width="20" height="20" fill="hsl(${h},68%,52%)"/></svg>`;
}

/* ========== pages/login.js ========== */
/**
 * 登录页：密码登录 / 注册 / 游客模式（无验证码）
 */





async function renderLogin(container) {
  let tab = 'password';   // password | guest
  let sub = 'login';      // login | register
  let agreed = false;

  function paint() {
    container.innerHTML = `
      <div class="login-wrap">
        <div class="login-title">登录 kimi游戏榜</div>
        <div class="login-sub">登录后可投票、评论、参与世界对话并使用 AI 助手；游客仅可浏览榜单</div>

        <div class="card">
          <div class="seg" style="margin-bottom:14px">
            <button data-tab="password" class="${tab === 'password' ? 'on' : ''}">密码登录</button>
            <button data-tab="guest" class="${tab === 'guest' ? 'on' : ''}">游客模式</button>
          </div>
          <div id="pane"></div>
        </div>

        <div style="text-align:center;margin-top:14px">
          <button class="btn-ghost btn-sm" id="skipLogin">暂不登录，以游客身份浏览</button>
          <div class="tiny" style="margin-top:8px">不登录默认为游客模式，可随时在「我的」中登录</div>
          <div class="tiny" style="margin-top:10px">
            <span class="link" data-doc="功能说明">《功能说明》</span>
            <span style="opacity:.45;margin:0 4px">·</span>
            <span class="link" data-doc="AI 使用说明">《AI 使用说明》</span>
          </div>
          <div class="tiny muted" style="margin-top:6px">登录后可投票、评论，并使用 AI 助手（免费模型每人每日 2 次）</div>
        </div>
      </div>`;

    container.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { tab = b.dataset.tab; paint(); }));
    container.querySelectorAll('[data-doc]').forEach(l => l.addEventListener('click', () => navigate('#/doc/' + encodeURIComponent(l.dataset.doc))));
    container.querySelector('#skipLogin').addEventListener('click', async () => {
      try { const d = await api('auth.php', 'guest'); await finishLogin(d, true); }
      catch (e) { toast(e.message, 'err'); }
    });
    const pane = container.querySelector('#pane');
    if (tab === 'password') paintPassword(pane);
    else paintGuest(pane);
  }

  function paintPassword(pane) {
    pane.innerHTML = `
      <div class="seg" style="margin-bottom:16px">
        <button data-sub="login" class="${sub === 'login' ? 'on' : ''}">登录</button>
        <button data-sub="register" class="${sub === 'register' ? 'on' : ''}">注册</button>
      </div>
      <div class="field">
        <label>用户名${sub === 'register' ? '（即昵称，全站展示，注册后不可更改）' : ''}</label>
        <input class="input" id="uName" type="text" autocomplete="username"
               placeholder="${sub === 'register' ? '2-64 位，中文、字母、数字或符号均可' : '请输入用户名'}">
      </div>
      <div class="field">
        <label>密码${sub === 'register' ? '（8-64 位，建议同时包含数字和字母）' : ''}</label>
        <div class="input-wrap">
          <input class="input" id="uPwd" type="password" autocomplete="${sub === 'register' ? 'new-password' : 'current-password'}" placeholder="${sub === 'register' ? '设置 8-64 位密码' : '请输入密码'}">
          <button class="icon-btn eye" id="eyeBtn" type="button" aria-label="显示密码">
            <svg viewBox="0 0 24 24" class="ic"><path d="M12 5c5 0 9 4.5 9 7s-4 7-9 7-9-4.5-9-7 4-7 9-7zm0 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0 2a2 2 0 1 1 0 4 2 2 0 0 1 0-4z"/></svg>
          </button>
        </div>
      </div>
      <div class="agree">
        <span class="box ${agreed ? 'on' : ''}" id="agreeBox"><svg viewBox="0 0 24 24" class="ic"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg></span>
        <span>我已阅读并同意<span class="link" data-doc="用户协议">《用户协议》</span>与<span class="link" data-doc="隐私政策">《隐私政策》</span>（本站为个人兴趣分享，请文明互动）</span>
      </div>
      <div id="capBox" style="margin-bottom:12px"></div>
      <button class="btn" id="submitBtn" style="width:100%">${sub === 'register' ? '注册并登录' : '登录'}</button>
      <div class="tiny" style="text-align:center;margin-top:10px">${sub === 'register' ? '密码登录的账号可浏览榜单并参与投票' : '忘记密码请联系管理员'}</div>`;

    pane.querySelectorAll('[data-sub]').forEach(b => b.addEventListener('click', () => { sub = b.dataset.sub; paintPassword(pane); }));
    pane.querySelector('#agreeBox').addEventListener('click', () => { agreed = !agreed; pane.querySelector('#agreeBox').classList.toggle('on', agreed); });
    pane.querySelectorAll('[data-doc]').forEach(l => l.addEventListener('click', () => navigate('#/doc/' + encodeURIComponent(l.dataset.doc))));
    pane.querySelector('#eyeBtn').addEventListener('click', () => {
      const p = pane.querySelector('#uPwd');
      p.type = p.type === 'password' ? 'text' : 'password';
    });
    const capBox = pane.querySelector('#capBox');
    mountCaptcha(capBox, () => { /* token 变化时无需额外动作，提交时统一读取 */ });

    pane.querySelector('#submitBtn').addEventListener('click', async () => {
      const btn = pane.querySelector('#submitBtn');
      if (btn.disabled) { return; }          // 防重复提交
      const name = pane.querySelector('#uName').value;
      const pwd = pane.querySelector('#uPwd').value;
      if (!name || !pwd) { toast('请填写用户名与密码', 'err'); return; }
      if (!agreed) { toast('请先勾选同意协议', 'err'); return; }
      if (captchaConfig().on && !captchaToken()) { toast('请先完成人机验证', 'err'); return; }
      btnLoading(btn, true);
      try {
        const d = await api('auth.php', sub === 'register' ? 'register' : 'login', {
          username: name, password: pwd,
          cap_token: captchaToken(), cap_channel: captchaChannel(),
        });
        await finishLogin(d);
      } catch (e) {
        toast(e.message, 'err');
        btnLoading(btn, false);
        /* 最终 token 是一次性的：失败后必须重新验证拿新 token */
        resetCaptcha(capBox);
      }
    });
  }

  function paintGuest(pane) {
    pane.innerHTML = `
      <div class="guest-box">
        <svg viewBox="0 0 24 24" class="ic" style="width:44px;height:44px;fill:var(--text-3);margin:0 auto 10px"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z"/></svg>
        <div style="font-weight:700;margin-bottom:6px">游客模式</div>
        <p class="muted tiny">无需登录即可浏览全部榜单与作品详情；参与投票、评论与 AI 对话需要登录账号。</p>
        <button class="btn" id="guestBtn" style="width:100%;margin-top:14px">以游客身份进入</button>
      </div>`;
    pane.querySelector('#guestBtn').addEventListener('click', async () => {
      const btn = pane.querySelector('#guestBtn');
      if (btn.disabled) { return; }
      btnLoading(btn, true);
      try { const d = await api('auth.php', 'guest'); await finishLogin(d, true); }
      catch (e) { toast(e.message, 'err'); btnLoading(btn, false); }
    });
  }

  async function finishLogin(d, guest) {
    setToken(d.token);
    state.role = d.role || (guest ? 'guest' : 'user');
    state.uid = d.uid || 0;
    state.username = d.username || '游客';
    // 重新校验以拉取设置与头像
    try {
      const v = await api('auth.php', 'verify');
      state.settings = v.settings || {};
      state.avatar = v.avatar || '';
      state.username = v.username || state.username;
      applyTheme();
    } catch (e) {}
    toast(guest ? '已进入游客模式' : ('欢迎，' + state.username));
    if (Number(state.settings.notify) === 1) askNotifyPermission();
    // 登录后回「我的」；游客进入回榜单
    navigate(guest ? '#/rank' : '#/mine');
    location.reload();
  }

  paint();
}

/* ========== pages/panel.js ========== */
/**
 * 控制面板：二次认证 + 统计 + 作品/用户管理 + 副管理员
 * 视觉：普通后台风格（不使用液态玻璃）
 * 权限：主管理员=全部；副管理员=作品搜索/上传/同步 + 只读数据（登录即入场，无需二次密钥），
 *       社区凭证自备（含 Token 获取脚本下载），各存各的
 */



const PCATS = [['game', '游戏'], ['tool', '工具'], ['literature', '文学'], ['fanart', '二创']];
const PDIMS = [
  { k: 'creativity', n: '创意' }, { k: 'experience', n: '体验' }, { k: 'depth', n: '深度' },
  { k: 'cost', n: '成本' }, { k: 'attitude', n: '态度' }, { k: 'heat', n: '热度' },
];
const catName = c => (PCATS.find(x => x[0] === c) || [, c])[1];
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function renderPanel(container) {
  if (!isAdminish()) {
    container.innerHTML = '<div class="empty"><p>无权限访问</p></div>';
    return;
  }
  const isSub = state.role === 'subadmin';

  // 主管理员需二次密钥验证；副管理员登录时已用密钥，直接入场
  if (!isSub) {
    container.innerHTML = '<div class="skeleton" style="height:120px"></div>';
    let ov;
    try { ov = await api('dashboard.php', 'overview'); }
    catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }
    if (!ov.panel_verified) {
      const key = await prompt_('控制面板验证', '请输入管理员密钥（64 位十六进制，由密钥原文经 sha256 计算得出）。', '验证');
      if (!key) { container.innerHTML = '<div class="empty"><p>已取消验证</p></div>'; return; }
      try { await api('admin.php', 'panel_auth', { key: key }); }
      catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }
      ov = await api('dashboard.php', 'overview');
    }
    container.innerHTML = adminLayout();
    renderOverview(container.querySelector('#ovGrid'), ov, container);
    loadStats('7', container);
    loadUsers(container, '');
    loadSubs(container);
    loadVisitsTop(container);
    bindCommentBin(container);
    bindAnnounce(container);
    bindRefresh(container);
    bindReclassify(container);
    bindDb(container);
    bindBackup(container);
    loadDisc(container);
    loadPeer(container);
    bindAiRank(container);
    startPanelPolling(container);
  } else {
    container.innerHTML = subLayout();
    /* 副管理员的只读数据：总览、趋势、用户列表（不含 IP 与归属地明细）、访问排行。
       任一接口失败都不影响作品上传这条主线。 */
    try {
      const ov = await api('dashboard.php', 'overview');
      const ovGrid = container.querySelector('#ovGrid');
      if (ovGrid) { renderOverview(ovGrid, ov, container); }
    } catch (e) { /* 静默 */ }
    loadStats('7', container);
    loadUsers(container, '', true);
    loadVisitsTop(container);
  }

  const uSearchBtn = container.querySelector('#uSearch');
  if (uSearchBtn && !uSearchBtn.dataset.bound) {
    uSearchBtn.dataset.bound = '1';
    uSearchBtn.addEventListener('click', () =>
      loadUsers(container, container.querySelector('#uq').value.trim(), isSub));
  }

  bindCredential(container);
  bindAddWork(container);
  /* 收录/更新相关的开关与只读排行：副管理员同样可用 */
  bindScore(container);
  bindLink(container);
  bindAiRank(container);
  loadWorks(container, '', isSub);
  container.querySelector('#wSearch').addEventListener('click', () => loadWorks(container, container.querySelector('#wq').value.trim(), isSub, 1));
  const sizeSeg = container.querySelector('#wSizeSeg');
  if (sizeSeg) {
    sizeSeg.addEventListener('click', e => {
      const b = e.target.closest('[data-sz]'); if (!b) return;
      sizeSeg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      loadWorks(container, container.querySelector('#wq').value.trim(), isSub, 1);
    });
  }
}

/* ============================================================
 * 布局
 * ============================================================ */
function adminLayout() {
  return `
    <div class="panel-plain">
      <h3>数据总览</h3>
      <div class="stat-grid" id="ovGrid"></div>
    </div>

    <div class="panel-plain">
      <h3>趋势统计
        <span class="seg" style="float:right;max-width:220px" id="rangeSeg">
          <button data-r="7" class="on">近7天</button>
          <button data-r="30">近30天</button>
          <button data-r="all">全部</button>
        </span>
      </h3>
      <div id="chartArea"><div class="skeleton" style="height:150px"></div></div>
      <div class="legend" id="legend"></div>
    </div>

    <div class="panel-plain">
      <h3>分类分布 / 评级分布</h3>
      <div id="distArea"><div class="skeleton" style="height:120px"></div></div>
    </div>

    ${aiRankBlock()}

    ${credentialBlock()}
    ${addWorkBlock()}
    ${scoreBlock()}
    ${linkBlock()}
    ${refreshBlock()}
    ${reclassifyBlock()}
    ${dbBlock()}
    ${backupBlock()}
    ${workListBlock(true)}

    <div class="panel-plain">
      <h3>用户管理</h3>
      <div class="prow">
        <input class="input" id="uq" placeholder="搜索用户名 / 8 位 UID">
        <button class="btn btn-sm" id="uSearch">搜索</button>
      </div>
      <div id="userList"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="userPager"></div>
    </div>

    ${discBlock()}

    ${peerBlock()}

    ${commentBinBlock()}
    ${visitsBlock()}

    <div class="panel-plain">
      <h3>副管理员</h3>
      <p class="tiny muted">副管理员拥有普通用户全部权限，可搜索与上传作品、回复反馈。<b>升级现有用户时不需要更改密码</b>——副管理员与普通用户同库同通道登录，只有总管理员凭证独立存放。</p>
      <div class="prow">
        <input class="input" id="subName" placeholder="用户名（可填已存在的用户）" autocomplete="off">
        <input class="input" id="subKey" type="password" placeholder="初始密码（仅新建账号时需要，≥8 位）" autocomplete="new-password">
        <button class="btn btn-sm" id="subAdd">添加 / 升级</button>
      </div>
      <div class="tiny" id="subHint" style="margin:-2px 0 10px"></div>
      <div id="subList"><div class="skeleton" style="height:60px"></div></div>
    </div>

    <div class="panel-plain">
      <h3>公告设置</h3>
      <div class="field"><textarea class="input" id="annInput" placeholder="首页横幅公告内容"></textarea></div>
      <button class="btn btn-sm" id="annSave">保存公告</button>
    </div>`;
}

/** 访问排行板块（管理员与副管理员共用） */
function visitsBlock() {
  return `
    <div class="panel-plain">
      <h3>访问排行</h3>
      <p class="tiny muted">按已记录的访问归属地统计，各维度前十五名。</p>
      <div id="visitsTop"><div class="skeleton" style="height:120px"></div></div>
    </div>`;
}

/** 评论回收站（仅主管理员）：删除 = 入回收站，这里可查看、恢复或彻底清空 */
function commentBinBlock() {
  return `
    <div class="panel-plain">
      <h3>评论回收站</h3>
      <p class="tiny muted">
        被删除的评论会进入这里：<b>页面不再显示</b>，但内容仍保留在库中。
        可以恢复到原处，也可以彻底删除；<b>「清空回收站」不可撤销</b>。
      </p>
      <div class="tiny" id="cmBinHead" style="margin-bottom:8px"></div>
      <div id="cmBinList"><div class="skeleton" style="height:80px"></div></div>
      <div class="row-gap" style="margin-top:10px">
        <button class="btn-ghost btn-sm" id="cmBinRefresh">刷新</button>
        <button class="btn-sm" id="cmBinPurge" style="color:#f87171">清空回收站</button>
      </div>
    </div>`;
}

async function loadCommentBin(container) {
  const box = container.querySelector('#cmBinList');
  if (!box) { return; }
  const head = container.querySelector('#cmBinHead');
  try {
    const d = await api('admin.php', 'comments_deleted', { page: 1 });
    if (head) { head.innerHTML = '共 <b>' + Number(d.total) + '</b> 条已删除评论'; }
    if (!d.items.length) { box.innerHTML = '<div class="tiny muted">回收站是空的。</div>'; return; }
    box.innerHTML = '<table class="table"><thead><tr><th>作者</th><th>内容</th><th>所属作品</th><th>删除时间</th><th>操作</th></tr></thead><tbody>'
      + d.items.map(c => `<tr data-cid="${Number(c.id)}">
        <td>${esc(c.username)}<br><span class="tiny muted">${c.by_admin ? '管理员删除' : '本人删除'}</span></td>
        <td style="max-width:340px;word-break:break-word">${esc(c.content).slice(0, 160)}</td>
        <td class="tiny">${esc(c.title)}</td>
        <td class="tiny">${esc(c.time)}</td>
        <td><button class="btn-ghost btn-sm" data-act="restore">恢复</button></td></tr>`).join('')
      + '</tbody></table>';
    box.querySelectorAll('tr[data-cid]').forEach(tr => {
      const b = tr.querySelector('[data-act="restore"]');
      if (!b) { return; }
      b.addEventListener('click', async () => {
        btnLoading(b, true);
        try { await api('admin.php', 'comment_restore', { id: tr.dataset.cid }); toast('已恢复'); loadCommentBin(container); }
        catch (e) { toast(e.message, 'err'); btnLoading(b, false); }
      });
    });
  } catch (e) { box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`; }
}

function bindCommentBin(container) {
  const refresh = container.querySelector('#cmBinRefresh');
  if (refresh) { refresh.addEventListener('click', () => loadCommentBin(container)); }
  const purge = container.querySelector('#cmBinPurge');
  if (purge) {
    purge.addEventListener('click', async () => {
      if (!(await dialog('清空回收站',
          '将<b>彻底删除</b>回收站中的全部评论（含其点赞），<b>此操作不可撤销</b>。确认继续？', '清空', { danger: true }))) { return; }
      btnLoading(purge, true);
      try {
        const r = await api('admin.php', 'comment_purge', {});
        toast('已彻底删除 ' + Number(r.purged) + ' 条');
        loadCommentBin(container);
      } catch (e) { toast(e.message, 'err'); }
      finally { btnLoading(purge, false); }
    });
  }
  loadCommentBin(container);
}

function subLayout() {
  return `
    <div class="panel-plain">
      <h3>副管理员工作台</h3>
      <p class="tiny muted">
        你已以副管理员身份登录：可使用作品搜索与上传、回复反馈，并查看<b>全站只读数据</b>。
        用户 IP 与归属地明细仅总管理员可见；改库、账号处置与公告等操作不可用。
      </p>
    </div>

    <div class="panel-plain">
      <h3>数据总览</h3>
      <div class="stat-grid" id="ovGrid"><div class="skeleton" style="height:60px"></div></div>
    </div>

    <div class="panel-plain">
      <h3>趋势统计
        <span class="seg" style="float:right;max-width:220px" id="rangeSeg">
          <button data-r="7" class="on">近7天</button>
          <button data-r="30">近30天</button>
          <button data-r="all">全部</button>
        </span>
      </h3>
      <div id="chartArea"><div class="skeleton" style="height:150px"></div></div>
      <div class="legend" id="legend"></div>
    </div>

    ${credentialBlock()}
    ${addWorkBlock()}
    ${scoreBlock()}
    ${linkBlock()}
    ${workListBlock(false)}
    ${visitsBlock()}
    ${aiRankBlock()}

    <div class="panel-plain">
      <h3>用户列表（只读）</h3>
      <div class="prow">
        <input class="input" id="uq" placeholder="搜索用户名 / 8 位 UID">
        <button class="btn btn-sm" id="uSearch">搜索</button>
      </div>
      <div id="userList"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="userPager"></div>
    </div>`;
}

function dbBlock() {
  return `
    <div class="panel-plain">
      <h3>数据库更新</h3>
      <p class="tiny muted">
        新增功能若用到新的数据列，需要这些列真的建好才算生效。升级后通常会自动完成，
        万一没赶上（或数据库改表被拒），这里可以手动补上。更新是幂等的，可反复执行。
      </p>
      <div id="dbStatus" class="tiny" style="line-height:1.9;margin-bottom:10px"></div>
      <div class="row-gap">
        <button class="btn-sm btn-ghost" id="dbCheckBtn">检查状态</button>
        <button class="btn btn-sm" id="dbRunBtn">一键更新</button>
      </div>
      <div id="dbResult" hidden style="margin-top:12px"></div>
    </div>`;
}

function bindDb(container) {
  const checkBtn = container.querySelector('#dbCheckBtn');
  const runBtn   = container.querySelector('#dbRunBtn');
  const status   = container.querySelector('#dbStatus');
  const result   = container.querySelector('#dbResult');
  if (!checkBtn) { return; }

  const paint = (d) => {
    const parts = [
      '当前结构版本 <b>' + esc(d.current) + '</b>',
      '程序期望 <b>' + esc(d.expected) + '</b>'
    ];
    status.innerHTML = '<div>' + parts.join(' · ') + '</div>'
      + (d.healthy
          ? '<div style="color:var(--ok,#0a0)">结构完整，' + d.checked + ' 项检查全部通过。</div>'
          : '<div style="color:var(--danger)">缺少 ' + d.missing.length + ' 项：'
            + esc(d.missing.join('、')) + '</div>');
    if (d.last_run) {
      status.innerHTML += '<div class="muted">上次自动检查：' + esc(d.last_run) + '</div>';
    }
  };

  checkBtn.addEventListener('click', async () => {
    checkBtn.disabled = true;
    status.innerHTML = '<div class="skeleton" style="height:40px"></div>';
    try { paint(await api('admin.php', 'db_status', {})); }
    catch (e) { status.innerHTML = '<div style="color:var(--danger)">' + esc(e.message) + '</div>'; }
    checkBtn.disabled = false;
  });

  runBtn.addEventListener('click', async () => {
    if (!(await dialog('更新数据库', '将按当前程序需要补齐缺失的数据表与列。此操作幂等，不会删除已有数据。确定继续？', '开始更新'))) { return; }
    runBtn.disabled = true; checkBtn.disabled = true;
    result.hidden = false;
    result.innerHTML = '<div class="skeleton" style="height:48px"></div>';
    try {
      const d = await api('admin.php', 'db_update', {}, { timeout: 120000 });
      paint(d.after);

      let html = '';
      if (d.error) {
        html += '<div class="tiny" style="color:var(--danger)">执行中断：' + esc(d.error) + '</div>';
      } else if (d.fixed.length) {
        html += '<div class="tiny" style="line-height:1.9">本次补齐 <b>' + d.fixed.length + '</b> 项：'
              + esc(d.fixed.join('、')) + '</div>';
      } else {
        html += '<div class="tiny muted">没有缺失项，结构本来就是完整的。</div>';
      }
      if (d.left.length) {
        html += '<div class="tiny" style="color:var(--danger);margin-top:6px;line-height:1.9">'
              + '仍有 ' + d.left.length + ' 项未能补齐：' + esc(d.left.join('、'))
              + '<br>多半是数据库不允许改表。请联系主机商，或把本页结果发给开发者。</div>';
      } else if (!d.error) {
        html += '<div class="tiny" style="color:var(--ok,#0a0);margin-top:6px">现在结构是完整的。</div>';
      }
      html += '<div class="tiny muted" style="margin-top:6px">耗时 ' + d.elapsed + ' 秒</div>';
      result.innerHTML = html;
      toast(d.left.length ? '更新完成，仍有项目未补齐' : '数据库已是最新');
    } catch (e) {
      result.innerHTML = '<div class="tiny" style="color:var(--danger)">' + esc(e.message) + '</div>';
    }
    runBtn.disabled = false; checkBtn.disabled = false;
  });
}

const fmtBytes = (n) => {
  n = Number(n) || 0;
  if (n < 1024) { return n + ' B'; }
  if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
  return (n / 1048576).toFixed(1) + ' MB';
};

/* 数据库备份：导出全站数据到服务器，再按需下载。仅主管理员可见（副管理员版布局不含本区块） */
function backupBlock() {
  return `
    <div class="panel-plain">
      <h3>数据库备份</h3>
      <p class="tiny muted">
        把全站数据（作品、用户、评论、对话、配置）导出成一个 SQL 文件，存在服务器上
        （该目录已禁止直接用网址访问，下载必须经过管理员鉴权）。备份含账号与评论数据，
        <b>请妥善保管，用完及时删除</b>。
      </p>
      <div id="bkMeta" class="tiny muted" style="margin-bottom:10px"></div>
      <div class="row-gap">
        <button class="btn btn-sm" id="bkRunBtn">立即备份</button>
        <button class="btn-sm btn-ghost" id="bkListBtn">刷新列表</button>
      </div>
      <div id="bkList" style="margin-top:12px"><div class="skeleton" style="height:60px"></div></div>
    </div>`;
}

function bindBackup(container) {
  const runBtn  = container.querySelector('#bkRunBtn');
  const listBtn = container.querySelector('#bkListBtn');
  const meta    = container.querySelector('#bkMeta');
  const box     = container.querySelector('#bkList');
  if (!runBtn) { return; }

  const paint = (d) => {
    meta.innerHTML = d.writable
      ? '共 <b>' + d.items.length + '</b> 份备份 · 单份上限 ' + d.limit_text
      : '<span style="color:var(--danger)">服务器上的备份目录不可写，请检查该目录权限</span>';
    if (!d.items.length) {
      box.innerHTML = '<div class="tiny muted">还没有备份。点「立即备份」生成第一份。</div>';
      return;
    }
    let h = '<table class="table"><thead><tr><th>文件</th><th>大小</th><th>时间</th><th>操作</th></tr></thead><tbody>';
    d.items.forEach(function (it) {
      h += '<tr><td class="tiny">' + esc(it.name) + '</td>'
        + '<td class="tiny">' + fmtBytes(it.bytes) + '</td>'
        + '<td class="tiny">' + esc(it.time) + '</td>'
        + '<td><button class="btn-ghost btn-sm" style="padding:4px 10px" data-dl="' + esc(it.name) + '">下载</button> '
        + '<button class="btn-ghost btn-sm" style="padding:4px 10px" data-del="' + esc(it.name) + '">删除</button></td></tr>';
    });
    box.innerHTML = h + '</tbody></table>';

    box.querySelectorAll('[data-dl]').forEach(function (b) {
      b.addEventListener('click', function () { download(b.getAttribute('data-dl'), b); });
    });
    box.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', async function () {
        const name = b.getAttribute('data-del');
        if (!(await dialog('删除备份', '将永久删除 ' + name + '，无法恢复。确定继续？', '删除'))) { return; }
        b.disabled = true;
        try { await api('admin.php', 'backup_delete', { name: name }); toast('已删除'); load(); }
        catch (e) { toast(e.message, 'err'); b.disabled = false; }
      });
    });
  };

  const load = async () => {
    box.innerHTML = '<div class="skeleton" style="height:60px"></div>';
    try { paint(await api('admin.php', 'backup_list')); }
    catch (e) { box.innerHTML = '<div class="tiny" style="color:var(--danger)">' + esc(e.message) + '</div>'; }
  };

  /* 与「下载 Token 脚本」同一套做法：带鉴权头取回，再交给浏览器落盘 */
  const download = async (name, btn) => {
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = '准备中…';
    try {
      const headers = { 'Accept': 'application/octet-stream' };
      if (state.token) {
        headers['Authorization'] = 'Bearer ' + state.token;
        headers['X-Token'] = state.token;
      }
      const resp = await fetch('api/admin.php?action=backup_download&name=' + encodeURIComponent(name),
        { headers: headers, credentials: 'same-origin' });
      const type = resp.headers.get('Content-Type') || '';
      if (!resp.ok || type.indexOf('json') >= 0) {   // 被拦或鉴权失败时返回的是 JSON
        let msg = '下载失败（HTTP ' + resp.status + '）';
        try { const j = await resp.json(); if (j && j.msg) { msg = j.msg; } } catch (err) {}
        throw new Error(msg);
      }
      const url = window.URL.createObjectURL(await resp.blob());
      const a = document.createElement('a');
      a.href = url;
      a.download = name;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => window.URL.revokeObjectURL(url), 5000);
      toast('已开始下载 ' + name);
    } catch (e) { toast(e.message, 'err'); }
    btn.disabled = false;
    btn.textContent = old;
  };

  runBtn.addEventListener('click', async () => {
    runBtn.disabled = true; listBtn.disabled = true;
    btnLoading(runBtn, true);
    meta.innerHTML = '<span class="muted">正在导出，请勿关闭页面…</span>';
    try {
      const d = await api('admin.php', 'backup', {}, { timeout: 300000, silent: true });
      toast('备份完成');
      meta.innerHTML = '刚生成 <b>' + esc(d.name) + '</b> · ' + fmtBytes(d.bytes)
        + ' · ' + d.tables + ' 张表 / ' + d.rows + ' 行 · 耗时 ' + d.seconds + ' 秒'
        + (d.consistent ? '' : ' · 未启用一致性快照');
    } catch (e) {
      meta.innerHTML = '<span style="color:var(--danger)">' + esc(e.message) + '</span>';
    }
    btnLoading(runBtn, false);
    runBtn.disabled = false; listBtn.disabled = false;
    load();
  });

  listBtn.addEventListener('click', load);
  load();
}

/* 社区凭证：粘贴与「取得」放在同一区块，主管理员与副管理员通用。
   副管理员各存各的（服务端按身份分库），互不共用。 */
function credentialBlock() {
  return `
    <div class="panel-plain">
      <h3>社区凭证</h3>
      <div class="field"><label>Kimi 社区 cookie / token（加密存储，独立不共用）</label>
        <input class="input" id="tokInput" placeholder="粘贴社区登录态 token">
      </div>
      <button class="btn-ghost btn-sm" id="saveTokBtn">仅保存凭证</button>
      <button class="btn-ghost btn-sm" id="tokenScriptBtn">下载 Token 脚本</button>
      <p class="tiny muted" style="margin-top:8px">
        Token 脚本是运行在浏览器里的<b>第三方用户脚本</b>（Tampermonkey），
        用于在社区页面获取你自己的 Token。本站仅提供下载中转，<b>不校验其内容</b>，安装前请自行核对。
      </p>
    </div>`;
}

function addWorkBlock() {
  return `
    <div class="panel-plain">
      <h3>添加作品</h3>
      <div class="seg" style="margin-bottom:10px" id="addModeSeg">
        <button data-m="manual" class="on">手动（按作品 ID）</button>
        <button data-m="auto">自动（同步信息流）</button>
      </div>
      <div class="field" id="workIdField">
        <label>作品 ID</label>
        <input class="input" id="workIdInput" placeholder="输入 Kimi 社区作品 ID" autocomplete="off">
        <div class="sug" id="workSug" hidden></div>
      </div>
      <button class="btn" id="addBtn">开始</button>
      <p class="tiny muted" data-link-state style="margin-top:8px"></p>
    </div>`;
}

/* 评分方式：收录与「全部更新」是否重算评分。
   默认「只更新信息」——站长常按实际情况手动调分，自动重算会把这些改动抹掉，
   所以默认不覆盖评分；且只有总管理员能切换。 */
function scoreBlock() {
  return `
    <div class="panel-plain">
      <h3>评分方式</h3>
      <p class="tiny muted">
        默认「只更新信息」：<b>已收录作品</b>在更新时只改信息（标题、简介、图片、互动数等），
        <b>不覆盖评分</b>，方便按实际情况调分；<b>新收录的作品一律照常评分</b>，不受影响。
        需要把老作品的分数按算法重算时，由总管理员切到「自动评分」再点一次「全部更新」。
        （<b>副管理员只能查看，不能切换</b>。）
      </p>
      <div class="seg" id="scoreSeg" style="margin:10px 0 8px">
        <button data-a="1">自动评分</button>
        <button data-a="0">只更新信息</button>
      </div>
      <p class="tiny" id="scoreNote"></p>
    </div>`;
}

function bindScore(container) {
  const seg = container.querySelector('#scoreSeg');
  const note = container.querySelector('#scoreNote');
  if (!seg) { return; }
  const readonly = (state.role === 'subadmin');   // 副管理员只能看，不能切

  const paint = (auto) => {
    seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.a === (auto ? '1' : '0')));
    note.innerHTML = (auto
      ? '<span style="color:var(--ok,#0a0)">当前：自动评分</span> · 收录与更新都会按算法重算'
      : '<span style="color:var(--accent)">当前：只更新信息</span> · 已收录作品<b>不重算评分</b>（新收录的仍会自动评分）')
      + (readonly ? ' · <b>仅总管理员可切换</b>' : '');
  };
  const lock = (on) => seg.querySelectorAll('button').forEach(b => { b.disabled = on; });
  if (readonly) { lock(true); }

  seg.addEventListener('click', async (e) => {
    if (readonly) { return; }
    const b = e.target.closest('[data-a]');
    if (!b || b.classList.contains('on') || b.disabled) { return; }
    const auto = b.dataset.a === '1';
    lock(true);
    try {
      const d = await api('admin.php', 'score_mode_set', { auto: auto ? 1 : 0 });
      paint(d.auto === true);
      toast(d.auto === true ? '已恢复自动评分' : '已关闭自动评分，之后不再改动评分');
    } catch (e2) {
      toast(e2.message, 'err');
      try { paint((await api('admin.php', 'score_mode', {}, { silent: true })).auto === true); } catch (e3) {}
    }
    lock(false);
  });

  api('admin.php', 'score_mode', {}, { silent: true })
    .then(d => paint(d.auto === true))
    .catch(() => { note.textContent = '读取评分方式失败，刷新页面再试'; });
}

/* 智能链接识别：原页面只是个跳转页时，改用其中的真实地址。默认开启。
   一个开关、三处可见——开关区块本身，以及收录区与批量更新区的状态行。 */
const linkStateText = (on) => '智能链接识别：<b>' + (on ? '已开启' : '已关闭') + '</b>';

function paintLinkState(container, on) {
  container.querySelectorAll('[data-link-state]').forEach(function (el) { el.innerHTML = linkStateText(on); });
  const seg = container.querySelector('#linkSeg');
  if (seg) { seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === (on ? '1' : '0'))); }
  const note = container.querySelector('#linkNote');
  if (note) {
    note.innerHTML = on
      ? '<span style="color:var(--accent)">当前：已开启</span> · 收录与更新时会尝试把跳转页换成真实地址'
      : '<span class="muted">当前：已关闭</span> · 作品页一律按原始地址收录';
  }
}

async function refreshLinkState(container, force) {
  if (container.__linkOn !== undefined && force !== true) { paintLinkState(container, container.__linkOn); return; }
  try {
    const d = await api('admin.php', 'link_mode', {}, { silent: true });
    container.__linkOn = d.on === true;
    paintLinkState(container, container.__linkOn);
  } catch (e) { /* 读不到就不显示，不打扰操作 */ }
}

function linkBlock() {
  return `
    <div class="panel-plain">
      <h3>智能链接识别</h3>
      <p class="tiny muted">
        有些作品的「页面地址」只是个跳转页（一句「正在前往…」，真正的作品在别处）。
        本功能默认开启：收录与批量更新会尝试从这类页面里找出<b>真实地址</b>并替换，
        标题与评分也改按真实页面来算。
      </p>
      <p class="tiny muted">
        判定刻意保守：先确认这页确实像跳转页，再排除常见大站与站内地址，
        <b>只认唯一一个候选链接</b>；识别不出就保持原样，不会乱改。
      </p>
      <div class="seg" id="linkSeg" style="margin:10px 0 8px">
        <button data-v="0">关闭</button>
        <button data-v="1">开启</button>
      </div>
      <p class="tiny" id="linkNote"></p>
    </div>`;
}

function bindLink(container) {
  const seg = container.querySelector('#linkSeg');
  if (!seg) { return; }
  const lock = (on) => seg.querySelectorAll('button').forEach(b => { b.disabled = on; });

  seg.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-v]');
    if (!b || b.classList.contains('on') || b.disabled) { return; }
    const on = b.dataset.v === '1';
    lock(true);
    try {
      const d = await api('admin.php', 'link_mode_set', { on: on ? 1 : 0 });
      container.__linkOn = d.on === true;
      paintLinkState(container, container.__linkOn);
      toast(d.on === true ? '已开启智能链接识别' : '已关闭智能链接识别');
    } catch (e2) {
      toast(e2.message, 'err');
      await refreshLinkState(container, true);
    }
    lock(false);
  });

  refreshLinkState(container);
}

function refreshBlock() {
  return `
    <div class="panel-plain">
      <h3>一键更新全部作品</h3>
      <p class="tiny muted">按社区最新数据更新全部作品（含 CDN 直链）。按排名从高到低分批抓取，可随时停止。</p>
      <p class="tiny muted" data-link-state style="margin-top:6px"></p>
      <div class="prog" id="refreshProg" hidden><div class="prog-bar"><i style="width:0%"></i></div><span class="tiny" id="refreshText"></span></div>
      <button class="btn btn-sm" id="refreshBtn">开始更新</button>
      <button class="btn-ghost btn-sm" id="refreshStop" hidden>停止</button>
    </div>`;
}

function reclassifyBlock() {
  return `
    <div class="panel-plain">
      <h3>一键更换分区</h3>
      <p class="tiny muted">
        按标题与简介自动判定分区，优先级为 <b>二创 ＞ 文学 ＞ 工具 ＞ 其余归游戏</b>。
        游戏不单独判定，是兜底分区——以上三类都不命中就归游戏。
      </p>
      <div class="prog" id="rcProg" hidden><div class="prog-bar"><i style="width:0%"></i></div><span class="tiny" id="rcText"></span></div>
      <div class="row-gap">
        <button class="btn btn-sm" id="rcPreviewBtn">预览变更</button>
        <button class="btn-sm btn-ghost" id="rcApplyBtn" disabled>执行变更</button>
      </div>
      <div id="rcPreview" hidden style="margin-top:12px"></div>

      <hr style="border:0;border-top:1px solid var(--border-soft);margin:18px 0 14px">

      <h3>手工批量迁移</h3>
      <p class="tiny muted">把某个分区的作品整体并入另一个分区，用于「这个分区不想要了，全部并过去」。</p>
      <div class="setting-row" style="border:0;padding:4px 0">
        <span class="muted">从</span>
        <div class="seg seg-mini" id="mvFrom">
          <button data-c="all" class="on">全部</button>
          <button data-c="game">游戏</button>
          <button data-c="tool">工具</button>
          <button data-c="literature">文学</button>
          <button data-c="fanart">二创</button>
        </div>
      </div>
      <div class="setting-row" style="border:0;padding:4px 0">
        <span class="muted">到</span>
        <div class="seg seg-mini" id="mvTo">
          <button data-c="game" class="on">游戏</button>
          <button data-c="tool">工具</button>
          <button data-c="literature">文学</button>
          <button data-c="fanart">二创</button>
        </div>
      </div>
      <button class="btn btn-sm" id="mvBtn" style="margin-top:8px">执行迁移</button>
    </div>`;
}

function workListBlock(editable) {
  return `
    <div class="panel-plain">
      <h3>作品管理</h3>
      <div class="prow">
        <input class="input" id="wq" placeholder="搜索标题 / 作者 / 作品 ID">
        <div class="seg seg-mini" id="wSizeSeg" role="group" aria-label="每页条数">
          <button data-sz="10">10</button>
          <button data-sz="20" class="on">20</button>
          <button data-sz="50">50</button>
        </div>
        <button class="btn btn-sm" id="wSearch">搜索</button>
      </div>
      <div id="workList"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="workPager"></div>
    </div>
    ${editable ? '<div id="editorHost"></div>' : ''}`;
}

/* ============================================================
 * 概览 / 图表
 * ============================================================ */
function renderOverview(box, ov, root) {
  const items = [
    ['用户总量', ov.users_total], ['今日新增', ov.users_today],
    ['在榜作品', ov.works_total], ['总点赞', ov.votes_total],
    ['评论数', ov.comments_total], ['消息数', ov.messages_total],
    ['反馈数', ov.feedback_total], ['累计登录', ov.logins_total],
    ['作品更新', ov.updates_total], ['AI 调用', ov.ai_calls_total],
    ['AI tokens', ov.ai_tokens_total],
    ['本站模型 tokens', ov.ai_tokens_glm], ['模型网关 tokens', ov.ai_tokens_gateway],
  ];
  if (Number(ov.ai_tokens_legacy || 0) > 0) { items.push(['早期未区分 tokens', ov.ai_tokens_legacy]); }
  box.innerHTML = items.map(x => `<div class="stat-box"><div class="k">${esc(x[0])}</div><div class="v">${Number(x[1] || 0)}</div></div>`).join('');
  const seg = (root || document).querySelector('#rangeSeg');
  if (seg && !seg.dataset.bound) {
    seg.dataset.bound = '1';
    seg.addEventListener('click', e => {
      const b = e.target.closest('[data-r]'); if (!b) return;
      seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      loadStats(b.dataset.r, root || document);
    });
  }
}

/* ============================================================
 * AI 用量排行（按通道分账）
 * 两种通道分开看：glm = 本站模型，gateway = 模型网关（免费模型）。
 * 「选了免费模型但实际回退到本站模型」的账记在本站模型，如实反映真实消耗。
 * ============================================================ */
/* AI 用量排行（按通道分账、可翻页）：主管理员与副管理员都能看 */
function aiRankBlock() {
  return `
    <div class="panel-plain">
      <h3>AI 用量排行</h3>
      <div class="seg" id="aiChSeg" style="margin:10px 0 8px">
        <button data-ch="" class="on">全部</button>
        <button data-ch="glm">本站模型</button>
        <button data-ch="gateway">模型网关</button>
      </div>
      <p class="tiny muted" id="aiChNote" style="margin-bottom:8px"></p>
      <div id="aiRank"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="aiRankPager"></div>
    </div>`;
}

function bindAiRank(container) {
  const seg = container.querySelector('#aiChSeg');
  const box = container.querySelector('#aiRank');
  const note = container.querySelector('#aiChNote');
  const pager = container.querySelector('#aiRankPager');
  if (!seg || !box) { return; }

  let ch = '';

  const paintNote = (s) => {
    if (!note || !s) { return; }
    const parts = ['本站模型 ' + Number(s.glm.tokens).toLocaleString() + ' tokens（' + Number(s.glm.calls) + ' 次）',
                   '模型网关 ' + Number(s.gateway.tokens).toLocaleString() + ' tokens（' + Number(s.gateway.calls) + ' 次）'];
    if (Number(s.legacy.tokens) > 0) { parts.push('分账前的旧记录 ' + Number(s.legacy.tokens).toLocaleString() + ' tokens'); }
    note.textContent = parts.join(' · ');
  };

  const load = async (page) => {
    const pg = Math.max(1, Number(page || 1));
    box.innerHTML = '<div class="skeleton" style="height:80px"></div>';
    if (pager) { pager.innerHTML = ''; }
    let d;
    try { d = await api('dashboard.php', 'ai_rank', { ch: ch, page: pg }, { silent: true }); }
    catch (e) { box.innerHTML = '<div class="tiny muted">读取失败，稍后再试</div>'; return; }
    paintNote(d.split);
    const items = (d && d.items) || [];
    if (!items.length) {
      box.innerHTML = '<div class="tiny muted">' + (pg > 1 ? '这一页没有记录了。' : '这条通道还没有用量记录。') + '</div>';
      if (pager && pg > 1) {
        pager.innerHTML = '<button class="btn-ghost btn-sm" data-pg="' + (pg - 1) + '">上一页</button>';
        pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => load(Number(b.dataset.pg))));
      }
      return;
    }
    const max = Math.max.apply(null, items.map(x => Number(x.tokens) || 0)) || 1;
    const from = (pg - 1) * (Number(d.size) || items.length);   // 序号跨页连续
    box.innerHTML = '<table class="table"><thead><tr><th>#</th><th>账号</th><th>tokens</th><th>调用</th></tr></thead><tbody>'
      + items.map((x, i) => `<tr>
        <td class="tiny">${from + i + 1}</td>
        <td>${userName(x.name, 'user')}</td>
        <td>${Number(x.tokens).toLocaleString()}
          <div style="height:4px;margin-top:4px;border-radius:2px;background:var(--border-soft)">
            <i style="display:block;height:100%;width:${Math.max(4, Math.round(Number(x.tokens) / max * 100))}%;border-radius:2px;background:var(--accent)"></i>
          </div>
        </td>
        <td class="tiny">${Number(x.calls)} 次</td>
      </tr>`).join('') + '</tbody></table>';

    if (pager) {
      const pages = Math.max(1, Number(d.total_pages || 1));
      pager.dataset.page = String(pg);
      pager.innerHTML = pages <= 1 ? ''
        : `<button class="btn-ghost btn-sm" data-pg="${pg - 1}" ${pg <= 1 ? 'disabled' : ''}>上一页</button>
           <span class="tiny">第 ${pg} / ${pages} 页 · 共 ${Number(d.total) || 0} 个账号</span>
           <button class="btn-ghost btn-sm" data-pg="${pg + 1}" ${pg >= pages ? 'disabled' : ''}>下一页</button>
           <button class="btn-ghost btn-sm" data-pg="${pages}" ${pg >= pages ? 'disabled' : ''}>末页</button>`;
      pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => load(Number(b.dataset.pg))));
    }
  };

  seg.addEventListener('click', e => {
    const b = e.target.closest('[data-ch]'); if (!b) return;
    seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    ch = b.dataset.ch;
    load(1);          // 换通道回到第一页
  });
  load(1);
}

async function loadStats(range, container) {
  const area = container.querySelector('#chartArea');
  if (!area) { return; }
  /* 图例与分布图的容器只有管理员面板才有（副管理员面板不给分布）——
     必须逐个判空。此前只挡了 chartArea，副管理员一进来就因 distArea 为 null
     抛「Cannot set properties of null」，再被 catch 吞成「加载失败」。 */
  const legend = container.querySelector('#legend');
  const dist   = container.querySelector('#distArea');
  try {
    const d = await api('dashboard.php', 'stats', { range: range });
    const series = d.series || [];
    const metrics = [
      { k: 'signups', n: '注册', c: '#7C3AED' }, { k: 'logins', n: '登录', c: '#007AFF' },
      { k: 'votes', n: '点赞', c: '#f59e0b' }, { k: 'work_updates', n: '作品更新', c: '#10b981' },
      { k: 'ai_calls', n: 'AI 调用', c: '#ef4444' }, { k: 'comments', n: '评论', c: '#8b5cf6' },
      { k: 'messages', n: '消息', c: '#06b6d4' },
    ];
    if (legend) { legend.innerHTML = metrics.map(m => `<span><i style="background:${m.c}"></i>${esc(m.n)}</span>`).join(''); }
    area.innerHTML = lineChart(d.labels || [], series, metrics);
    if (dist) {
      dist.innerHTML = `
        <div class="tiny" style="margin-bottom:6px">作品分类</div>
        ${barChart((d.category_dist || []).map(c => ({ n: catName(c.category), v: Number(c.n) })))}
        <div class="tiny" style="margin:10px 0 6px">评级分布</div>
        ${barChart((d.rating_dist || []).map(r => ({ n: r.rating, v: Number(r.n) })))}`;
    }
  } catch (e) {
    area.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`;
  }
}

function barChart(items) {
  const max = Math.max(1, ...items.map(i => i.v));
  return `<div class="chart-row">${items.map(i =>
    `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px" title="${esc(i.n)}：${i.v}">
       <span class="tiny" style="font-weight:700">${i.v}</span>
       <div class="bar" style="width:100%;height:${Math.max(3, Math.round(i.v / max * 104))}px"></div>
       <span class="tiny">${esc(i.n)}</span>
     </div>`).join('')}</div>`;
}

function lineChart(labels, series, metrics) {
  const W = 700, H = 160, pad = 24;
  const max = Math.max(1, ...series.flatMap(s => metrics.map(m => Number(s[m.k]) || 0)));
  const stepX = series.length > 1 ? (W - pad * 2) / (series.length - 1) : 0;
  let out = `<svg viewBox="0 0 ${W} ${H}" class="line-svg" preserveAspectRatio="none">`;
  for (let g = 0; g <= 4; g++) {
    const y = H - pad - (g / 4) * (H - pad * 2);
    out += `<line x1="${pad}" y1="${y.toFixed(1)}" x2="${W - pad}" y2="${y.toFixed(1)}" stroke="#eceef1"/>`;
    out += `<text x="4" y="${(y + 3).toFixed(1)}" font-size="9" fill="#a0a4ad">${Math.round(max * g / 4)}</text>`;
  }
  metrics.forEach(m => {
    const pts = series.map((s, i) => {
      const x = pad + i * stepX;
      const y = H - pad - ((Number(s[m.k]) || 0) / max) * (H - pad * 2);
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');
    out += `<polyline points="${pts}" fill="none" stroke="${m.c}" stroke-width="2" stroke-linejoin="round"/>`;
  });
  const every = Math.max(1, Math.ceil(series.length / 8));
  series.forEach((s, i) => {
    if (i % every !== 0) return;
    out += `<text x="${(pad + i * stepX).toFixed(0)}" y="${H - 6}" font-size="9" fill="#8a8f99" text-anchor="middle">${esc(labels[i] || '')}</text>`;
  });
  return out + '</svg>';
}

/* ============================================================
 * 凭证 / 添加作品（含快捷搜索）
 * ============================================================ */
function bindCredential(container) {
  const btn = container.querySelector('#saveTokBtn');
  if (btn) {
    btn.addEventListener('click', async () => {
      const t = container.querySelector('#tokInput').value.trim();
      if (!t) { toast('请填写 cookie/token', 'err'); return; }
      try { await api('admin.php', 'token_set', { kimi_token: t }); container.querySelector('#tokInput').value = ''; toast('已加密保存'); }
      catch (e) { toast(e.message, 'err'); }
    });
  }

  /* Token 脚本下载：源站无 CORS 头，跨域下 download 属性会被忽略，故由本站中转取回。
     主管理员与副管理员均可用（各自取得自己的 Token）。 */
  const scriptBtn = container.querySelector('#tokenScriptBtn');
  if (!scriptBtn) return;
  const SCRIPT_SRC = 'https://harbor-ljmr.upma.site/Token_acquisition.js';
  scriptBtn.addEventListener('click', async () => {
    btnLoading(scriptBtn, true);
    try {
      const headers = { 'Accept': 'text/javascript' };
      if (state.token) {
        headers['Authorization'] = 'Bearer ' + state.token;
        headers['X-Token'] = state.token;
      }
      const resp = await fetch('api/admin.php?action=userscript', { headers, credentials: 'same-origin' });
      if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
      const blob = await resp.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'Token_acquisition.js';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => window.URL.revokeObjectURL(url), 5000);
      toast('脚本已开始下载');
    } catch (e) {
      window.open(SCRIPT_SRC, '_blank', 'noopener');   // 中转不通就直接给出源地址
      toast('中转下载失败，已打开源地址', 'err');
    } finally { btnLoading(scriptBtn, false); }
  });
}

function bindAddWork(container) {
  let addMode = 'manual';
  refreshLinkState(container);      // 收录区显示当前开关状态
  const seg = container.querySelector('#addModeSeg');
  seg.addEventListener('click', e => {
    const b = e.target.closest('[data-m]'); if (!b) return;
    addMode = b.dataset.m;
    seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    container.querySelector('#workIdField').hidden = addMode !== 'manual';
  });

  // 快捷搜索作品（按标题/作者/ID）
  const idInput = container.querySelector('#workIdInput');
  const sug = container.querySelector('#workSug');
  let timer = null;
  idInput.addEventListener('input', () => {
    clearTimeout(timer);
    const q = idInput.value.trim();
    if (q.length < 1) { sug.hidden = true; sug.innerHTML = ''; return; }
    timer = setTimeout(async () => {
      try {
        const d = await api('admin.php', 'search_works', { q: q }, { silent: true });
        if (!d.items.length) { sug.hidden = true; sug.innerHTML = ''; return; }
        sug.innerHTML = d.items.map(w =>
          `<button type="button" data-cid="${esc(w.community_id)}"><b>${esc(w.title)}</b>
            <span class="tiny">${esc(w.author)} · ${esc(w.community_id)} · ${w.total}分</span></button>`).join('');
        sug.hidden = false;
        sug.querySelectorAll('[data-cid]').forEach(b => b.addEventListener('click', () => {
          idInput.value = b.dataset.cid; sug.hidden = true;
        }));
      } catch (e) { sug.hidden = true; }
    }, 320);
  });
  idInput.addEventListener('blur', () => setTimeout(() => { sug.hidden = true; }, 160));

  container.querySelector('#addBtn').addEventListener('click', async () => {
    const t = container.querySelector('#tokInput').value.trim();
    const body = { mode: addMode };
    if (t) body.kimi_token = t;
    if (addMode === 'manual') {
      body.work_id = idInput.value.trim();
      if (!body.work_id) { toast('请填写作品 ID', 'err'); return; }
    }
    const btn = container.querySelector('#addBtn');
    btnLoading(btn, true); btn.textContent = '处理中…';
    try {
      const r = await api('admin.php', 'add_work', body, { timeout: 60000 });
      const noScore = r.scored === false ? '（未改动评分）' : '';
      const syncNote = r.scored === false ? '（已有作品未重算评分）' : '';
      const linkNote = r.link_real ? '（已改用真实地址）' : '';
      toast(addMode === 'auto'
        ? ('同步完成：新增 ' + r.inserted + '，更新 ' + r.updated + syncNote)
        : ('收录' + (r.status === 'updated' ? '并更新' : '') + '成功' + noScore + linkNote));
      loadWorks(container, container.querySelector('#wq').value.trim(), state.role === 'subadmin');
    } catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(btn, false); btn.textContent = '开始'; }
  });
}

/* ============================================================
 * 分区：自动判定与批量迁移
 * ------------------------------------------------------------
 * 预览与执行是同一套判定（服务端同一函数），所以「预览看到的」
 * 就是「执行会做的」，不存在预览一次、执行又是另一回事的情况。
 * ============================================================ */
function bindReclassify(container) {
  const pBtn = container.querySelector('#rcPreviewBtn');
  const aBtn = container.querySelector('#rcApplyBtn');
  const prog = container.querySelector('#rcProg');
  const bar  = prog ? prog.querySelector('i') : null;
  const text = container.querySelector('#rcText');
  const box  = container.querySelector('#rcPreview');
  if (!pBtn) { return; }

  const catName = c => ({ game: '游戏', tool: '工具', literature: '文学', fanart: '二创' }[c] || c);

  /* ---------- 预览 ---------- */
  pBtn.addEventListener('click', async () => {
    pBtn.disabled = true;
    box.hidden = false;
    box.innerHTML = '<div class="skeleton" style="height:64px"></div>';
    try {
      const d = await api('admin.php', 'reclassify_preview', {});
      const c = d.counts;
      let html = '<div class="tiny" style="line-height:1.9">'
        + '共扫描 <b>' + c.total + '</b> 件 · 需要变更 <b>' + c.changed + '</b> 件 · '
        + '判定一致 ' + c.keep + ' 件'
        + (c.default_game ? ' · 其中靠默认归入游戏 ' + c.default_game + ' 件' : '')
        + '</div>';

      if (d.sample && d.sample.length) {
        html += '<div style="margin-top:10px;max-height:260px;overflow:auto">'
          + '<table class="table"><thead><tr><th>作品</th><th>现在</th><th>将改为</th></tr></thead><tbody>'
          + d.sample.map(x => '<tr><td>' + esc(x.title) + '</td><td>' + catName(x.from) + '</td><td><b>' + catName(x.to) + '</b></td></tr>').join('')
          + '</tbody></table></div>';
        if (c.changed > d.sample.length) {
          html += '<div class="tiny muted" style="margin-top:6px">仅列出前 ' + d.sample.length + ' 条，执行时会处理全部。</div>';
        }
      } else {
        html += '<div class="tiny muted" style="margin-top:8px">没有需要变更的作品。</div>';
      }

      html += '<details style="margin-top:12px"><summary class="tiny" style="cursor:pointer">判定规则</summary>'
        + '<div class="tiny muted" style="line-height:1.9;margin-top:6px">'
        + Object.keys(d.rules).map(k => '<div><b>' + esc(k) + '</b>：' + esc(d.rules[k]) + '</div>').join('')
        + '</div></details>';

      box.innerHTML = html;
      aBtn.disabled = c.changed === 0;
    } catch (e) {
      box.innerHTML = '<div class="tiny" style="color:var(--danger)">' + esc(e.message) + '</div>';
    }
    pBtn.disabled = false;
  });

  /* ---------- 执行（分批） ---------- */
  let running = false;
  aBtn.addEventListener('click', async () => {
    if (running) { return; }
    if (!(await dialog('执行分区变更', '将按预览结果修改作品分区，确定继续？', '执行'))) { return; }
    running = true; aBtn.disabled = true; pBtn.disabled = true;
    prog.hidden = false;
    let offset = 0, applied = 0;

    while (true) {
      let r;
      try { r = await api('admin.php', 'reclassify_apply', { offset: offset }, { timeout: 90000, silent: true }); }
      catch (e) { text.textContent = '中断：' + e.message; break; }

      applied += r.applied;
      const pct = r.total > 0 ? Math.round(r.done / r.total * 100) : 100;
      bar.style.width = pct + '%';
      text.textContent = pct + '%（' + r.done + '/' + r.total + '）· 已变更 ' + applied + ' 件';

      if (r.finished || r.done <= offset) {
        if (r.finished) { text.textContent += ' · 完成'; toast('分区变更完成，共 ' + applied + ' 件'); }
        break;
      }
      offset = r.done;
      await sleep(200);
    }
    running = false; aBtn.disabled = false; pBtn.disabled = false;
    loadWorks(container, container.querySelector('#wq').value.trim(), false);
  });

  /* ---------- 手工批量迁移 ---------- */
  let mvFrom = 'all', mvTo = 'game';
  const fSeg = container.querySelector('#mvFrom');
  const tSeg = container.querySelector('#mvTo');
  if (fSeg) {
    fSeg.addEventListener('click', e => {
      const b = e.target.closest('[data-c]'); if (!b) { return; }
      mvFrom = b.dataset.c;
      fSeg.querySelectorAll('[data-c]').forEach(x => x.classList.toggle('on', x === b));
    });
  }
  if (tSeg) {
    tSeg.addEventListener('click', e => {
      const b = e.target.closest('[data-c]'); if (!b) { return; }
      mvTo = b.dataset.c;
      tSeg.querySelectorAll('[data-c]').forEach(x => x.classList.toggle('on', x === b));
    });
  }
  const mvBtn = container.querySelector('#mvBtn');
  if (mvBtn) {
    mvBtn.addEventListener('click', async () => {
      if (mvFrom === mvTo) { toast('来源与目标相同', 'err'); return; }
      const fromLabel = mvFrom === 'all' ? '全部作品' : catName(mvFrom) + '类全部作品';
      if (!(await dialog('批量迁移分区', '将把「' + fromLabel + '」迁移到「' + catName(mvTo) + '」，确定？', '迁移'))) { return; }
      mvBtn.disabled = true;
      try {
        const r = await api('admin.php', 'move_category', { from: mvFrom, to: mvTo });
        toast('已迁移 ' + r.moved + ' 件作品');
        loadWorks(container, container.querySelector('#wq').value.trim(), false);
      } catch (e) { toast(e.message, 'err'); }
      mvBtn.disabled = false;
    });
  }
}

/* ============================================================
 * 一键更新（分批 + 进度条）
 * ============================================================ */
function bindRefresh(container) {
  const btn = container.querySelector('#refreshBtn');
  const stopBtn = container.querySelector('#refreshStop');
  const prog = container.querySelector('#refreshProg');
  const bar = prog ? prog.querySelector('i') : null;
  const text = container.querySelector('#refreshText');

  if (!btn) return;
  refreshLinkState(container);      // 更新区显示当前开关状态

  let running = false, stopped = false;

  btn.addEventListener('click', async () => {
    if (running) return;
    running = true; stopped = false;
    btn.disabled = true; stopBtn.hidden = false; prog.hidden = false;
    let plan = [], total = 0, done = 0, okN = 0, failN = 0, links = 0, scoreOn = true;
    const paint = () => {
      const pct = total > 0 ? Math.min(100, Math.round(done / total * 100)) : 100;
      bar.style.width = pct + '%';
      text.textContent = pct + '%（' + done + '/' + total + '）· 成功 ' + okN + ' · 失败 ' + failN
        + (links ? ' · 识别真实链接 ' + links : '')
        + (scoreOn ? '' : ' · 未重算评分');
    };

    /* 第一步：取回按排名（总分从高到低）排好的名单，整轮顺序就此固定 */
    try {
      const p = await api('admin.php', 'refresh_plan', {}, { timeout: 90000, silent: true });
      plan = p.ids || [];
      total = Number(p.total) || plan.length;
      scoreOn = p.scored !== false;
      if (p.capped) { text.textContent = '作品数超过单次上限，本轮先更新前 ' + plan.length + ' 件'; }
    } catch (e) {
      text.textContent = '中断：' + e.message;
      running = false; btn.disabled = false; stopBtn.hidden = true;
      return;
    }
    paint();

    /* 第二步：按名单分批更新，每批 5 件 */
    for (let i = 0; i < plan.length && !stopped; i += 5) {
      let r;
      try { r = await api('admin.php', 'refresh_batch', { ids: plan.slice(i, i + 5) }, { timeout: 90000, silent: true }); }
      catch (e) { text.textContent = '中断：' + e.message; stopped = true; break; }

      okN += r.ok; failN += r.fail; done += Number(r.processed) || 0;
      links += Number(r.links) || 0;
      paint();
      await sleep(260);   // 降低共享主机并发压力
    }
    if (stopped) {
      text.textContent += ' · 已停止';
      toast('已停止更新');
    } else {
      done = Math.max(done, total); paint();
      text.textContent += ' · 完成';
      toast(scoreOn ? '全部作品已更新' : '全部作品已更新（未改动评分）');
    }
    running = false; btn.disabled = false; stopBtn.hidden = true;
    loadWorks(container, container.querySelector('#wq').value.trim(), false);
  });

  stopBtn.addEventListener('click', () => { stopped = true; });
}

/* ============================================================
 * 作品列表 / 编辑
 * ============================================================ */
/** 当前选择的每页条数 */
function workPageSize(container) {
  const on = container.querySelector('#wSizeSeg button.on');
  return on ? Number(on.dataset.sz) : 20;
}

/**
 * 作品编辑器（仅主管理员）：改标题 / 作者 / 分类 / 简介 / 链接 / 封面图集 /
 * 上下架与六维明细，保存走 admin.php?action=work_save。
 *
 * 弹层复用站点通用的 #dialogScrim + #dialog（配 .dialog.wide 宽度），
 * 分类与上下架用站内的分段控件，不用原生 select。
 */
function openEditor(container, id, onSaved) {
  const scrim = document.getElementById('dialogScrim');
  const box = document.getElementById('dialog');
  if (!scrim || !box) { return; }

  api('admin.php', 'work_get', { id: id }).then(w => {
    const sc = w.score || {};
    let cat = String(w.category || 'game');
    let hidden = !!w.hidden;

    box.className = 'dialog glass wide';
    box.innerHTML = `
      <h3>编辑作品 #${Number(w.id)}</h3>
      <p class="tiny muted">社区 ID ${esc(w.community_id)} · 改动六维会重算总分与评级（每维 0–200）</p>

      <div class="field"><label>标题</label>
        <input class="input" id="edTitle" type="text" value="${esc(w.title)}" autocomplete="off"></div>

      <div class="field"><label>作者</label>
        <input class="input" id="edAuthor" type="text" value="${esc(w.author)}" autocomplete="off"></div>

      <div class="field"><label>分类</label>
        <div class="seg" id="edCat">
          ${PCATS.map(c => `<button data-c="${c[0]}" class="${c[0] === cat ? 'on' : ''}">${c[1]}</button>`).join('')}
        </div></div>

      <div class="field"><label>简介</label>
        <textarea class="input" id="edIntro" rows="5">${esc(w.intro)}</textarea></div>

      <div class="field"><label>分享链接</label>
        <input class="input" id="edShare" type="text" value="${esc(w.share_link)}" autocomplete="off"></div>

      <div class="field"><label>社区链接</label>
        <input class="input" id="edHtml" type="text" value="${esc(w.html_url)}" autocomplete="off"></div>

      <div class="field"><label>封面图地址</label>
        <input class="input" id="edCover" type="text" value="${esc(w.cover)}" autocomplete="off"></div>

      <div class="field"><label>图集（每行一个地址，最多 9 张）</label>
        <textarea class="input" id="edGallery" rows="3">${esc(w.gallery)}</textarea></div>

      <div class="field"><label>上架状态</label>
        <div class="seg" id="edHidden">
          <button data-h="0" class="${hidden ? '' : 'on'}">在榜</button>
          <button data-h="1" class="${hidden ? 'on' : ''}">已下架</button>
        </div></div>

      <div class="field"><label>六维明细</label></div>
      <div class="ed-grid" id="edDims">
        ${PDIMS.map(d => `<div>
          <label class="tiny">${d.n}</label>
          <input class="input" data-dim="${d.k}" type="text" inputmode="numeric"
                 value="${Number(sc[d.k] || 0)}" autocomplete="off"></div>`).join('')}
      </div>

      <div class="dialog-actions">
        <button class="btn-ghost" id="edCancel">取消</button>
        <button class="btn" id="edSave">保存</button>
      </div>`;

    scrim.hidden = false;

    const close = () => { scrim.hidden = true; box.className = 'dialog glass'; box.innerHTML = ''; };
    scrim.onclick = e => { if (e.target === scrim) { close(); } };

    box.querySelector('#edCat').addEventListener('click', e => {
      const b = e.target.closest('[data-c]');
      if (!b) { return; }
      cat = b.dataset.c;
      box.querySelectorAll('#edCat button').forEach(x => x.classList.toggle('on', x === b));
    });
    box.querySelector('#edHidden').addEventListener('click', e => {
      const b = e.target.closest('[data-h]');
      if (!b) { return; }
      hidden = b.dataset.h === '1';
      box.querySelectorAll('#edHidden button').forEach(x => x.classList.toggle('on', x === b));
    });
    box.querySelector('#edCancel').addEventListener('click', close);

    box.querySelector('#edSave').addEventListener('click', async () => {
      const btn = box.querySelector('#edSave');
      const val = sel => { const el = box.querySelector(sel); return el ? el.value.trim() : ''; };
      const payload = {
        id: id,
        title: val('#edTitle'), author: val('#edAuthor'), intro: val('#edIntro'),
        category: cat, share_link: val('#edShare'), html_url: val('#edHtml'),
        cover: val('#edCover'), gallery: val('#edGallery'), hidden: hidden ? 1 : 0,
      };
      box.querySelectorAll('[data-dim]').forEach(inp => {
        const n = parseInt(String(inp.value).replace(/[^0-9]/g, ''), 10);
        payload['dim_' + inp.dataset.dim] = isNaN(n) ? 0 : Math.max(0, Math.min(200, n));
      });
      btnLoading(btn, true);
      try {
        await api('admin.php', 'work_save', payload);
        toast('已保存');
        close();
        if (typeof onSaved === 'function') { onSaved(); }
      } catch (e) { toast(e.message, 'err'); btnLoading(btn, false); }
    });
  }).catch(e => toast(e.message, 'err'));
}

async function loadWorks(container, q, readOnly, page) {
  const box = container.querySelector('#workList');
  const pager = container.querySelector('#workPager');
  const pg = Math.max(1, Number(page || 1));
  const size = workPageSize(container);
  try {
    const d = await api('admin.php', 'works', { q: q || '', page: pg, size: size });
    const total = Number(d.total || 0);
    const pages = Math.max(1, Number(d.total_pages || 1));

    if (!d.items.length) {
      box.innerHTML = '<div class="tiny">没有匹配的作品</div>';
    } else {
      box.innerHTML = `<table class="table"><thead><tr><th>标题</th><th>分类</th><th>总分</th><th>状态</th><th>操作</th></tr></thead><tbody>` +
        d.items.map(w => `<tr data-id="${w.id}">
          <td>${esc(w.title)}<div class="tiny">${esc(w.community_id)}</div></td>
          <td>${esc(catName(w.category))}</td>
          <td>${Number(w.total_score)}</td>
          <td>${Number(w.is_hidden) === 1 ? '已下架' : '在榜'}</td>
          <td>${readOnly ? '<span class="tiny">—</span>' : `
            <button class="btn-ghost btn-sm" data-act="edit">编辑</button>
            <button class="btn-ghost btn-sm" data-act="toggle">${Number(w.is_hidden) === 1 ? '恢复' : '下架'}</button>
            <button class="btn-ghost btn-sm" data-act="del">删除</button>`}
          </td></tr>`).join('') + '</tbody></table>';
    }

    if (pager) {
      pager.dataset.page = String(pg);           // 供自动轮询沿用当前页
      pager.innerHTML = total === 0 ? ''
        : `<button class="btn-ghost btn-sm" data-pg="1" ${pg <= 1 ? 'disabled' : ''}>首页</button>
           <button class="btn-ghost btn-sm" data-pg="${pg - 1}" ${pg <= 1 ? 'disabled' : ''}>上一页</button>
           <span class="tiny">第 ${pg} / ${pages} 页 · 共 ${total} 件</span>
           <button class="btn-ghost btn-sm" data-pg="${pg + 1}" ${pg >= pages ? 'disabled' : ''}>下一页</button>
           <button class="btn-ghost btn-sm" data-pg="${pages}" ${pg >= pages ? 'disabled' : ''}>末页</button>`;
      pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => {
        const card = box.closest('.panel-plain');
        loadWorks(container, q, readOnly, Number(b.dataset.pg)).then(() => {
          /* 换页不回到页面头部：仅当列表标题已滚出视口时，回到「作品管理」顶部 */
          if (card) {
            const top = card.getBoundingClientRect().top;
            if (top < 0) { card.scrollIntoView({ block: 'start' }); }
          }
        });
      }));
    }

    if (readOnly || !d.items.length) { return; }
    box.querySelectorAll('tr[data-id]').forEach(tr => {
      const id = tr.dataset.id;
      const hidden = tr.children[3].textContent === '已下架';
      tr.querySelector('[data-act="edit"]').addEventListener('click', () => openEditor(container, id, () => loadWorks(container, q, false, pg)));
      tr.querySelector('[data-act="toggle"]').addEventListener('click', async () => {
        try { await api('admin.php', 'work_save', { id: id, hidden: hidden ? 0 : 1 }); toast('已更新'); loadWorks(container, q, false, pg); }
        catch (e) { toast(e.message, 'err'); }
      });
      tr.querySelector('[data-act="del"]').addEventListener('click', async () => {
        if (await dialog('删除作品', '删除后不可恢复，确认？', '删除', { danger: true })) {
          try { await api('admin.php', 'work_delete', { id: id }); toast('已删除'); loadWorks(container, q, false, pg); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
    });
  } catch (e) {
    box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`;
    if (pager) { pager.innerHTML = ''; }
  }
}

/** 访问排行：国家 / 省份 / 城市 三个维度各前十五 */
async function loadVisitsTop(container) {
  const box = container.querySelector('#visitsTop');
  if (!box || !box.isConnected) { return; }
  try {
    const d = await api('admin.php', 'visits_top');
    const col = (title, arr) => {
      if (!arr || !arr.length) { return `<div class="tp-col"><div class="tp-h">${title}</div><div class="tiny muted">暂无数据</div></div>`; }
      const max = arr.reduce((m, x) => Math.max(m, Number(x.count) || 0), 1);
      const rows = arr.map((x, i) =>
        `<div class="tp-row"><span class="tp-i">${i + 1}</span><span class="tp-n" title="${esc(x.name)}">${esc(x.name)}</span>
         <span class="tp-bar"><i style="width:${Math.max(3, Math.round((Number(x.count) || 0) / max * 100))}%"></i></span>
         <span class="tp-c">${Number(x.count) || 0}</span></div>`).join('');
      return `<div class="tp-col"><div class="tp-h">${title}</div>${rows}</div>`;
    };
    const total = Number(d.total) || 0;
    const located = Number(d.located) || 0;
    let note;
    if (total === 0) {
      note = '暂无访问记录。只有登录用户在「我的 → 记录访问信息」开启后，登录时才会写入一条记录。';
    } else if (located === 0) {
      note = `共 ${total} 条记录，但归属地服务没有返回地区信息（第三方接口不可用或未配置），因此只显示「未知」。`;
    } else {
      note = `累计 ${total} 条记录，其中 ${located} 条已解析归属地${total - located > 0 ? `，${total - located} 条无归属地` : ''}。`;
    }
    const empty = located === 0;
    box.innerHTML = `<div class="tiny muted" style="margin-bottom:10px;line-height:1.6">${note}</div>
      ${empty ? '' : `<div class="tp-grid">
        ${col('国家 / 地区', d.country)}${col('省份', d.province)}${col('城市', d.city)}
      </div>`}`;
  } catch (e) {
    box.innerHTML = `<div class="tiny muted">${esc(e.message || '加载失败')}</div>`;
  }
}

/* ============================================================
 * 违纪通报管理
 * ============================================================
 * 列出全部通报，可「设置」二次调整：理由、说明、封禁天数（重算解封时间）、
 * 立即解封、IP 封禁开关。累计被通报（单次封禁 ≥7 天）达 10 次的账号会被永久删除。
 */

/* ============================================================
 * 站点互通（多站互为镜像）
 * ============================================================
 * 站点列表 + 一键互通。数据传输先压缩再用非对称加密（每站一把 64 位私钥），
 * 两边密钥对不上就同步不了；世界对话 / AI 对话等实时数据不参与。
 */
function peerBlock() {
  return `
    <div class="panel-plain">
      <h3>站点互通</h3>
      <p class="tiny muted">
        把几个站点配成一组、互为镜像：作品、评分、评论、账号双向同步，最终内容一致。
        站间数据先压缩再加密，两边密钥对不上就同步不了；世界对话、AI 对话等实时数据不参与。
      </p>
      <div class="prow" style="flex-wrap:wrap;gap:8px">
        <span class="tiny">本站公钥：<code id="peerMyKey" style="user-select:all">读取中…</code></span>
      </div>
      <div class="prow" style="margin-top:8px;gap:8px">
        <button class="btn btn-sm" id="peerSyncAll">一键互通</button>
        <button class="btn btn-sm" id="peerAdd">添加站点</button>
      </div>
      <div id="peerForm"></div>
      <div id="peerList" style="margin-top:10px"></div>
    </div>`;
}

async function loadPeer(container) {
  const keyEl   = container.querySelector('#peerMyKey');
  const listEl  = container.querySelector('#peerList');
  const formEl  = container.querySelector('#peerForm');
  const syncBtn = container.querySelector('#peerSyncAll');
  const addBtn  = container.querySelector('#peerAdd');
  if (!listEl) { return; }

  const st = await api('admin.php', 'peers_state', {}, { silent: true }).catch(() => null);
  if (!st) { listEl.innerHTML = '<div class="tiny muted">读取失败</div>'; return; }
  keyEl.textContent = !st.sodium
    ? '主机未启用 sodium 扩展，互通不可用'
    : (st.my_key_set ? (st.my_pubkey || '推导失败') : '未配置私钥（请在 config 的 peers.private_key 填 64 位十六进制）');

  let rows = st.peers || [];
  const drawList = () => {
    if (!rows.length) { listEl.innerHTML = '<div class="tiny muted">还没有配置任何站点。</div>'; return; }
    listEl.innerHTML = rows.map(p => `
      <div class="prow" style="padding:8px 0;align-items:center;flex-wrap:wrap;gap:6px">
        <span class="tiny" style="flex:1;min-width:180px">
          <b>${esc(p.name || p.base_url)}</b> · ${esc(p.base_url)}<br>
          <span class="muted">${p.enabled ? '' : '[已停用] '}${p.last_sync_at ? '上次同步 ' + esc(p.last_sync_at) + ' UTC' : '尚未同步'}${p.last_status ? ' · ' + esc(p.last_status) : ''}</span>
        </span>
        <button class="btn btn-sm" data-a="test" data-id="${p.id}">测试</button>
        <button class="btn btn-sm" data-a="edit" data-id="${p.id}">编辑</button>
        <button class="btn btn-sm" data-a="del" data-id="${p.id}">删除</button>
      </div>`).join('');
  };
  drawList();

  const refresh = async () => {
    const s = await api('admin.php', 'peers_state', {}, { silent: true }).catch(() => null);
    if (s && s.peers) { rows = s.peers; drawList(); }
  };

  const openForm = p => {
    p = p || { id: 0, name: '', base_url: '', pubkey: '', enabled: 1 };
    formEl.innerHTML = `
      <div class="panel-plain" style="margin-top:10px">
        <div class="prow"><input class="inp" id="pName" placeholder="站点名称" value="${esc(p.name)}"></div>
        <div class="prow"><input class="inp" id="pUrl" placeholder="https://对端域名（不带结尾斜杠）" value="${esc(p.base_url)}"></div>
        <div class="prow"><input class="inp" id="pKey" placeholder="对端公钥（64 位十六进制）" value="${esc(p.pubkey)}"></div>
        <div class="prow" style="gap:8px">
          <label class="tiny"><input type="checkbox" id="pEn" ${p.enabled ? 'checked' : ''}> 启用</label>
          <button class="btn btn-sm" id="pSave">保存</button>
          <button class="btn btn-sm" id="pCancel">取消</button>
        </div>
      </div>`;
    formEl.querySelector('#pCancel').addEventListener('click', () => { formEl.innerHTML = ''; });
    formEl.querySelector('#pSave').addEventListener('click', async () => {
      const payload = {
        id: p.id,
        name: formEl.querySelector('#pName').value.trim(),
        base_url: formEl.querySelector('#pUrl').value.trim(),
        pubkey: formEl.querySelector('#pKey').value.trim(),
        enabled: formEl.querySelector('#pEn').checked ? 1 : 0,
      };
      try { await api('admin.php', 'peer_save', payload); toast('已保存'); formEl.innerHTML = ''; await refresh(); }
      catch (e) { toast(e.message || '保存失败', 'err'); }
    });
  };

  listEl.addEventListener('click', async e => {
    const b = e.target.closest('button[data-a]');
    if (!b) { return; }
    const id = Number(b.dataset.id);
    if (b.dataset.a === 'edit') { openForm(rows.find(r => Number(r.id) === id)); return; }
    if (b.dataset.a === 'del') {
      if (!(await dialog('删除站点', '将从列表中移除该站点，不影响已同步的数据。确定？', '删除'))) { return; }
      try { await api('admin.php', 'peer_del', { id }); toast('已删除'); await refresh(); }
      catch (e) { toast(e.message || '删除失败', 'err'); }
      return;
    }
    if (b.dataset.a === 'test') {
      b.disabled = true; b.textContent = '测试中…';
      try { await api('admin.php', 'peer_test', { id }, { timeout: 30000 }); toast('连接正常，密钥校验通过'); }
      catch (e) { toast(e.message || '连接失败', 'err'); }
      finally { b.disabled = false; b.textContent = '测试'; }
    }
  });

  addBtn.addEventListener('click', () => openForm(null));

  syncBtn.addEventListener('click', async () => {
    if (!(await dialog('一键互通', '将与列表中所有启用站点双向同步数据，期间不要关闭页面。确定继续？', '开始'))) { return; }
    syncBtn.disabled = true; syncBtn.textContent = '互通中…';
    try {
      const r = await api('admin.php', 'peer_sync', {}, { timeout: 300000 });
      const list = (r.results || []).map(x => x.ok
        ? x.name + '：拉取 ' + x.pulled + ' / 推送 ' + x.pushed + '（' + x.tables + ' 张表）'
        : x.name + '：失败' + (x.msg ? '（' + x.msg + '）' : ''));
      toast(list.length ? list.join('；') : '没有启用中的站点');
      await refresh();
    } catch (e) { toast(e.message || '互通失败', 'err'); }
    finally { syncBtn.disabled = false; syncBtn.textContent = '一键互通'; }
  });
}

function discBlock() {
  return `
    <div class="panel-plain">
      <h3>违纪通报</h3>
      <p class="tiny muted">
        被通报过的用户都在这里。可以随时「设置」调整理由、封禁天数或直接解封。
        累计被通报 <b>10 次</b>（单次封禁 ≥ 7 天才计入）的账号会被<b>永久删除</b>。
      </p>
      <div class="prow"><button class="btn btn-sm" id="discRefresh">刷新列表</button></div>
      <div id="discListBox" style="margin-top:12px"><div class="skeleton" style="height:80px"></div></div>
    </div>`;
}

async function discEditDialog(row) {
  let preset = [];
  try { preset = ((await api('admin.php', 'disc_reasons', {}, { silent: true })).items) || []; } catch (e) { }
  const cur = row.reasons || [];
  const opts = [];
  preset.concat(cur).forEach(r => { if (r && opts.indexOf(r) < 0) { opts.push(r); } });

  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';
    const banState = row.banned ? (row.ban_until ? '封停至 ' + esc(row.ban_until) : '永久封停') : '未封停';

    box.innerHTML = `
      <h3>设置通报 · ${esc(row.username)}</h3>
      <p class="tiny muted">累计 ${Number(row.user_count || 0)} 次 · 当前 ${banState}</p>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">通报理由（可多选）</div>
        <div class="disc-pick" id="edPick">
          ${opts.map(r => `<button type="button" class="disc-opt${cur.indexOf(r) >= 0 ? ' on' : ''}" data-r="${esc(r)}">${esc(r)}</button>`).join('')}
        </div>
        <input class="input" id="edCustom" type="text" maxlength="60" autocomplete="off"
               placeholder="补充一条理由（可留空）" style="margin-top:8px">
      </div>

      <div class="field">
        <textarea class="input" id="edNote" maxlength="500" placeholder="补充说明（可留空）">${esc(row.note || '')}</textarea>
      </div>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">封禁时长（改动会重新计算解封时间）</div>
        <div class="seg" id="edDays">
          <button type="button" data-d="1">1 天</button>
          <button type="button" data-d="7">7 天</button>
          <button type="button" data-d="30">30 天</button>
          <button type="button" data-d="0">永久</button>
          <button type="button" data-d="-1" class="on">不改动</button>
        </div>
        <input class="input" id="edDaysCustom" type="text" inputmode="decimal" autocomplete="off"
               placeholder="或直接填天数，支持小数（如 0.5 = 12 小时、1.5 = 36 小时）" style="margin-top:8px">
      </div>

      <div class="disc-pick">
        <button type="button" class="disc-opt${row.ip_banned ? ' on' : ''}" id="edIp">封禁访问 IP</button>
        <button type="button" class="disc-opt" id="edUnban">立即解封账号</button>
      </div>

      <div class="dialog-actions">
        <button class="btn-ghost" data-r="0">取消</button>
        <button class="btn" data-r="1">保存</button>
      </div>`;

    scrim.hidden = false;
    box.querySelectorAll('#edPick .disc-opt').forEach(b => b.addEventListener('click', () => b.classList.toggle('on')));
    const ipB = box.querySelector('#edIp');
    const unB = box.querySelector('#edUnban');
    ipB.addEventListener('click', () => ipB.classList.toggle('on'));
    unB.addEventListener('click', () => {
      const on = !unB.classList.contains('on');
      unB.classList.toggle('on', on);
      if (on) { box.querySelector('#edDays').querySelectorAll('button').forEach(x => x.classList.toggle('on', x.dataset.d === '-1')); }
    });
    const seg = box.querySelector('#edDays');
    seg.addEventListener('click', e => {
      const b = e.target.closest('[data-d]');
      if (!b) { return; }
      seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      if (b.dataset.d !== '-1') { unB.classList.remove('on'); }
    });

    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.r === '0') { close(null); return; }
      const picked = [];
      box.querySelectorAll('#edPick .disc-opt.on').forEach(x => picked.push(x.dataset.r));
      const custom = String(box.querySelector('#edCustom').value || '').trim();
      if (custom) { picked.push(custom); }
      if (!picked.length) { toast('请至少保留一条理由', 'err'); return; }

      const patch = {
        id: row.id,
        set_reasons: '1', reasons: JSON.stringify(picked),
        set_note: '1', note: String(box.querySelector('#edNote').value || '').trim(),
        set_ip: '1', ip_banned: ipB.classList.contains('on') ? 1 : 0,
      };
      if (unB.classList.contains('on')) {
        patch.set_unban = '1'; patch.unban = 1;
      } else {
        const typed = parseFloat(String(box.querySelector('#edDaysCustom').value || '').trim());
        const cur2 = seg.querySelector('button.on');
        const d = Number.isFinite(typed) && typed >= 0 ? typed : Number(cur2 ? cur2.dataset.d : -1);
        if (d >= 0) { patch.set_days = '1'; patch.ban_days = d; }
      }
      close(patch);
    }));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}

async function loadDisc(container) {
  const box = container.querySelector('#discListBox');
  if (!box) { return; }
  try {
    const d = await api('admin.php', 'disc_list', { page: 1 });
    const items = d.items || [];
    if (!items.length) { box.innerHTML = '<div class="tiny muted">还没有通报记录。</div>'; return; }

    box.innerHTML = '<table class="table"><thead><tr><th>用户</th><th>理由</th><th>处理</th><th>累计</th><th>时间</th><th>操作</th></tr></thead><tbody>'
      + items.map(it => `<tr data-rid="${it.id}">
        <td>${userName(it.username, 'user', it.user_count)}${it.user_alive ? '' : ' <span class="tiny muted">（账号已删）</span>'}</td>
        <td class="tiny">${esc((it.reasons || []).join('；'))}</td>
        <td class="tiny">${it.banned ? (it.ban_until ? '至 ' + esc(it.ban_until) : '永久') : '未封停'}${it.ip_banned ? ' · IP' : ''}</td>
        <td class="tiny">${Number(it.user_count || 0)} 次</td>
        <td class="tiny">${esc(it.created)}</td>
        <td><button class="btn-ghost btn-sm" data-act="edit">设置</button>
            <button class="btn-ghost btn-sm" data-act="revoke">撤销</button></td></tr>`).join('')
      + '</tbody></table>';

    box.querySelectorAll('tr[data-rid]').forEach(tr => {
      const row = items.filter(x => String(x.id) === tr.dataset.rid)[0];
      if (!row) { return; }
      tr.querySelector('[data-act="edit"]').addEventListener('click', async () => {
        const patch = await discEditDialog(row);
        if (!patch) { return; }
        try { await api('admin.php', 'disc_update', patch); toast('已更新该通报'); loadDisc(container); }
        catch (e) { toast(e.message, 'err'); }
      });
      tr.querySelector('[data-act="revoke"]').addEventListener('click', async () => {
        if (!(await dialog('撤销通报', '将删除该通报并解除其账号与 IP 封禁，确认？', '撤销', { danger: true }))) { return; }
        try { await api('admin.php', 'disc_delete', { id: row.id }); toast('已撤销'); loadDisc(container); }
        catch (e) { toast(e.message, 'err'); }
      });

    });
  } catch (e) {
    box.innerHTML = '<div class="tiny">加载失败：' + esc(e.message) + '</div>';
  }
}

/* ============================================================
 * 违纪通报（一键封禁）
 * ============================================================
 * 表单全自绘：理由多选、补充理由、说明、封禁时长（可永久）、封 IP、清理内容。
 * 提交后由后端落实封禁与清理；被通报者再访问站点会被 302 到违纪界面。
 */
async function discDialog(ids, names) {
  let preset = [];
  try { preset = ((await api('admin.php', 'disc_reasons', {}, { silent: true })).items) || []; } catch (e) { }

  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';
    const who = names.map(n => '<b>' + esc(n) + '</b>').join('、');

    box.innerHTML = `
      <h3>违纪通报 · 封禁</h3>
      <p class="tiny muted">对象：${who}</p>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">通报理由（可多选，至少一条）</div>
        <div class="disc-pick" id="discPick">
          ${preset.map((r, i) => `<button type="button" class="disc-opt${i === 0 ? ' on' : ''}" data-r="${esc(r)}">${esc(r)}</button>`).join('')}
        </div>
      </div>

      <div class="field">
        <input class="input" id="discCustom" type="text" maxlength="60" autocomplete="off" placeholder="补充理由（可留空）">
      </div>

      <div class="field">
        <textarea class="input" id="discNote" maxlength="500" placeholder="补充说明（可留空，会显示在通报页上）"></textarea>
      </div>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">封禁时长</div>
        <div class="seg" id="discDays">
          <button type="button" data-d="1">1 天</button>
          <button type="button" data-d="7" class="on">7 天</button>
          <button type="button" data-d="30">30 天</button>
          <button type="button" data-d="0">永久</button>
        </div>
        <input class="input" id="discDaysCustom" type="text" inputmode="decimal" autocomplete="off"
               placeholder="或直接填写天数，支持小数（如 0.5 = 12 小时、1.5 = 36 小时）" style="margin-top:8px">
      </div>

      <div class="disc-pick">
        <button type="button" class="disc-opt on" id="discIp">封禁访问 IP</button>
        <button type="button" class="disc-opt" id="discPurge">删除其全部评论 / 对话 / 图片</button>
      </div>

      <div class="dialog-actions">
        <button class="btn-ghost" data-r="0">取消</button>
        <button class="btn btn-danger" data-r="1">确认通报并封禁</button>
      </div>`;

    scrim.hidden = false;

    /* 多选：理由可多选，开关型按钮独立切换 */
    box.querySelectorAll('#discPick .disc-opt').forEach(b => {
      b.addEventListener('click', () => b.classList.toggle('on'));
    });
    const tog = (id) => box.querySelector('#' + id);
    tog('discIp').addEventListener('click', () => tog('discIp').classList.toggle('on'));
    tog('discPurge').addEventListener('click', () => tog('discPurge').classList.toggle('on'));

    /* 时长：分段按钮单选；自定义输入优先 */
    const seg = box.querySelector('#discDays');
    seg.addEventListener('click', e => {
      const b = e.target.closest('[data-d]');
      if (!b) { return; }
      seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    });

    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.r === '0') { close(null); return; }
      const picked = [];
      box.querySelectorAll('#discPick .disc-opt.on').forEach(x => picked.push(x.dataset.r));
      const custom = String(box.querySelector('#discCustom').value || '').trim();
      if (custom) { picked.push(custom); }
      if (!picked.length) { toast('请至少选择或填写一条理由', 'err'); return; }
      const cur = seg.querySelector('button.on');
      const typed = parseFloat(String(box.querySelector('#discDaysCustom').value || '').trim());
      const days = Number.isFinite(typed) && typed >= 0 ? typed : Number(cur ? cur.dataset.d : 7);
      close({
        reasons: picked,
        note: String(box.querySelector('#discNote').value || '').trim(),
        days: days,
        ban_ip: tog('discIp').classList.contains('on') ? 1 : 0,
        purge: tog('discPurge').classList.contains('on') ? 1 : 0,
      });
    }));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}

/** 一键通报：提交并回显结果（含清理统计） */
async function submitDiscipline(container, ids, names, q, readOnly, pg) {
  const form = await discDialog(ids, names);
  if (!form) { return; }
  try {
    const r = await api('admin.php', 'disc_create', {
      user_ids: JSON.stringify(ids),
      reasons: JSON.stringify(form.reasons),
      note: form.note,
      ban_days: form.days,
      ban_account: 1,
      ban_ip: form.ban_ip,
      purge: form.purge,
    });
    let extra = '';
    if (form.purge) {
      extra = '<br><br>已清理：评论 <b>' + Number(r.purged_comments || 0) + '</b> 条 · 对话 <b>'
            + Number(r.purged_messages || 0) + '</b> 条 · 图片 <b>' + Number(r.purged_images || 0) + '</b> 张';
    }
    await dialog('通报完成',
      '已通报 <b>' + Number(r.created || 0) + '</b> 个用户。'
      + (r.ips_banned ? '<br>封禁 IP <b>' + Number(r.ips_banned) + '</b> 个。' : '')
      + (r.no_ip ? '<br><span class="tiny">其中 ' + Number(r.no_ip) + ' 个暂无可封的 IP，等其下次访问后再操作即可。</span>' : '')
      + extra, '知道了');
    loadUsers(container, q, readOnly, pg);
  } catch (e) {
    toast(e.message, 'err');
  }
}

async function loadUsers(container, q, readOnly, page) {
  const box = container.querySelector('#userList');
  if (!box) return;
  const pager = container.querySelector('#userPager');
  const pg = Math.max(1, Number(page || 1));
  try {
    const d = await api('admin.php', 'users', { q: q, page: pg });
    if (!d.items.length) {
      box.innerHTML = '<div class="tiny">' + (pg > 1 ? '这一页没有用户了。' : '暂无用户') + '</div>';
      if (pager) {
        pager.dataset.page = String(pg);
        pager.innerHTML = pg > 1 ? '<button class="btn-ghost btn-sm" data-pg="' + (pg - 1) + '">上一页</button>' : '';
        pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click',
          () => loadUsers(container, q, readOnly, Number(b.dataset.pg))));
      }
      return;
    }
    const showIp = d.ip_visible === true;
    const opsHead = readOnly ? '' : '<th>操作</th>';
    box.innerHTML = `<div class="tiny" style="margin-bottom:6px">可按用户名或 8 位 UID 搜索${readOnly ? ' · 只读' : ' · 点「通报」可写理由并封禁账号与 IP'}</div>
      <table class="table"><thead><tr><th>用户名</th><th>UID</th><th>注册</th><th>最近登录</th>${showIp ? '<th>最近 IP / 归属地</th>' : ''}<th>AI 用量</th>${opsHead}</tr></thead><tbody>` +
      d.items.map(u => `<tr data-id="${u.id}" data-name="${esc(u.username)}">
        <td>${userName(u.username, u.role, u.reports)}</td>
        <td class="uid-cell">${esc(u.uid8 || '—')}</td>
        <td>${esc(u.created)}</td>
        <td>${esc(u.last_login)}</td>
        ${showIp ? `<td class="tiny">${esc(u.ip_masked || '—')}<br><span class="muted">${esc(u.location || '')}</span></td>` : ''}
        <td>${Number(u.ai_tokens)} tokens / ${Number(u.ai_calls)} 次</td>
        ${readOnly ? '' : `<td>${u.role === 'user'
              ? '<button class="btn-ghost btn-sm" data-act="disc">通报</button>' : ''}
          ${u.role === 'user'
              ? '<button class="btn-ghost btn-sm" data-act="promote">设为副管理员</button>' : ''}
          ${u.role === 'user' ? '<button class="btn-ghost btn-sm" data-act="del">删除</button>' : ''}</td>`}</tr>`).join('') + '</tbody></table>';

    box.querySelectorAll('tr[data-id]').forEach(tr => {
      const uname = tr.dataset.name || '';
      const delBtn = tr.querySelector('[data-act="del"]');
      if (delBtn) delBtn.addEventListener('click', async () => {
        if (await dialog('删除用户', '将删除「' + esc(uname) + '」及其全部数据，不可恢复。确认？', '删除', { danger: true })) {
          try { await api('admin.php', 'user_delete', { id: tr.dataset.id }); toast('已删除'); loadUsers(container, q, readOnly, pg); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
      const discBtn = tr.querySelector('[data-act="disc"]');
      if (discBtn) discBtn.addEventListener('click', () => {
        submitDiscipline(container, [Number(tr.dataset.id)], [uname], q, readOnly, pg);
      });
      const proBtn = tr.querySelector('[data-act="promote"]');
      if (proBtn) proBtn.addEventListener('click', async () => {
        if (!(await dialog('设为副管理员',
            '将「<b>' + esc(uname) + '</b>」升级为副管理员？<br><br>'
            + '· <b>不需要更改密码</b>，其原密码继续可用<br>'
            + '· 评论、对话等数据全部保留<br>'
            + '· UID 换为专属豹子号<br>'
            + '· 获得作品搜索/上传与回复反馈权限', '确认设立'))) { return; }
        btnLoading(proBtn, true);
        try {
          const r = await api('admin.php', 'sub_add', { username: uname, secret: '' });
          toast('已升级为副管理员');
          await dialog('升级完成',
            '「<b>' + esc(r.username) + '</b>」现为副管理员：<b>密码保持不变</b>，数据全部保留。<br><br>'
            + '专属 UID（豹子号）：<b>' + esc(r.uid8 || '—') + '</b>', '知道了');
          loadSubs(container);
          loadUsers(container, q, readOnly, pg);
        } catch (e) { toast(e.message, 'err'); }
        finally { btnLoading(proBtn, false); }
      });
    });

    /* 分页：与作品列表同一套控件 */
    if (pager) {
      const pages = Math.max(1, Number(d.total_pages || 1));
      pager.dataset.page = String(pg);
      pager.innerHTML = pages <= 1 ? ''
        : `<button class="btn-ghost btn-sm" data-pg="${pg - 1}" ${pg <= 1 ? 'disabled' : ''}>上一页</button>
           <span class="tiny">第 ${pg} / ${pages} 页 · 共 ${Number(d.total) || 0} 位</span>
           <button class="btn-ghost btn-sm" data-pg="${pg + 1}" ${pg >= pages ? 'disabled' : ''}>下一页</button>
           <button class="btn-ghost btn-sm" data-pg="${pages}" ${pg >= pages ? 'disabled' : ''}>末页</button>`;
      pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click',
        () => loadUsers(container, q, readOnly, Number(b.dataset.pg))));
    }
  } catch (e) { box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`; }
}

/* ============================================================
 * 副管理员
 * ============================================================ */
async function loadSubs(container) {
  const box = container.querySelector('#subList');
  if (!box) return;
  try {
    const d = await api('admin.php', 'subs');
    /* 豹子号余量：池用尽后新副管理员自动改用普通 8 位 UID，身份与权限不受影响，
       这里把余量摆在眼前，免得加人时对着 UID 发懵。 */
    const bTotal = Number(d.babao_total || 0), bLeft = Number(d.babao_left || 0);
    const poolNote = bTotal <= 0 ? '' : `<div class="tiny" style="margin-bottom:8px;line-height:1.6;${bLeft > 0 ? 'opacity:.75' : 'color:var(--danger)'}">
        豹子号已用 ${bTotal - bLeft}/${bTotal}${bLeft > 0
          ? `，还剩 ${bLeft} 个`
          : '，池已用尽：新加的副管理员会自动改用普通 8 位 UID，身份与权限完全不受影响'}
      </div>`;
    box.innerHTML = poolNote + (d.items.length
      ? `<table class="table"><thead><tr><th>用户名</th><th>UID（豹子号）</th><th>设立时间</th><th>操作</th></tr></thead><tbody>` +
        d.items.map(s => `<tr data-id="${s.id}">
          <td>${userName(s.username, 'subadmin')}</td><td class="uid-cell">${esc(s.uid8 || '—')}</td><td>${esc(s.created)}</td>
          <td>
            <button class="btn-ghost btn-sm" data-act="demote">取消身份</button>
            <button class="btn-ghost btn-sm" data-act="del">删除</button>
          </td></tr>`).join('') + '</tbody></table>'
      : '<div class="tiny">尚未添加副管理员</div>');
    box.querySelectorAll('tr[data-id]').forEach(tr => {
      tr.querySelector('[data-act="demote"]').addEventListener('click', async () => {
        if (await dialog('取消副管理员身份',
            '该账号将变回普通用户：<br>· 评论、对话、反馈等数据<b>全部保留</b><br>· UID 由豹子号换回普通编号<br>· 若该账号是升级而来，仍可用其原密码登录',
            '取消身份')) {
          try { await api('admin.php', 'sub_demote', { id: tr.dataset.id }); toast('已取消副管理员身份'); loadSubs(container); loadUsers(container, container.querySelector('#uq').value.trim()); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
      tr.querySelector('[data-act="del"]').addEventListener('click', async () => {
        if (await dialog('删除副管理员', '将同时删除其账号与全部数据，不可恢复。若只是收回权限，请用「取消身份」。确认？', '删除', { danger: true })) {
          try { await api('admin.php', 'sub_del', { id: tr.dataset.id }); toast('已删除'); loadSubs(container); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
    });
  } catch (e) { box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`; }

  const nameEl = container.querySelector('#subName');
  const keyEl = container.querySelector('#subKey');
  const hintEl = container.querySelector('#subHint');
  if (!nameEl || nameEl.dataset.bound) return;
  nameEl.dataset.bound = '1';

  /* 输入即预检：判断是升级现有用户还是新建账号 */
  let subTimer = null;
  nameEl.addEventListener('input', () => {
    clearTimeout(subTimer);
    const v = nameEl.value.trim();
    if (hintEl) { hintEl.innerHTML = ''; }
    if (!v) { return; }
    subTimer = setTimeout(async () => {
      try {
        const d = await api('admin.php', 'users', { q: v, page: 1 }, { silent: true });
        const hit = (d.items || []).find(u => String(u.username) === v);
        if (!hintEl) { return; }
        if (!hit) {
          hintEl.textContent = '用户名未被占用 → 将创建新账号（需在右侧填写初始密码）';
        } else if (hit.role === 'subadmin') {
          hintEl.innerHTML = '<span style="color:var(--danger)">该账号已是副管理员</span>';
        } else if (hit.role === 'admin') {
          hintEl.innerHTML = '<span style="color:var(--danger)">管理员账号不可转为副管理员</span>';
        } else {
          hintEl.innerHTML = '将<b>升级</b>现有用户「' + esc(hit.username) + '」（UID ' + esc(hit.uid8 || '—')
            + '）→ <b>密码保持不变</b>，数据全部保留，UID 换为豹子号';
        }
      } catch (e) { /* 预检失败静默 */ }
    }, 320);
  });

  container.querySelector('#subAdd').addEventListener('click', async () => {
    const n = nameEl.value.trim(), k = keyEl.value.trim();
    if (!n || !k) { toast('请填写用户名与密钥', 'err'); return; }
    const btn = container.querySelector('#subAdd');
    btnLoading(btn, true);
    try {
      const r = await api('admin.php', 'sub_add', { username: n, secret: k });
      nameEl.value = ''; keyEl.value = ''; if (hintEl) { hintEl.innerHTML = ''; }
      const modeTxt = {
        promoted:          ['现有用户已升级为副管理员', '账号「<b>' + esc(r.username) + '</b>」已升级：<b>密码保持不变</b>（继续用原密码登录），评论、对话等数据全部保留，UID 已换为豹子号。'],
        promoted_resetpwd: ['已升级并重置密码', '账号「<b>' + esc(r.username) + '</b>」已升级，登录密码已重置为你刚填写的密码，数据全部保留。'],
        created:           ['副管理员账号已创建', '账号「<b>' + esc(r.username) + '</b>」已创建，用「用户名 + 初始密码」登录即可。'],
        repaired:          ['已修复该副管理员', '该账号的凭证此前未同步，已自动修复为副管理员身份。'],
      }[r.mode] || ['副管理员已设立', '账号「<b>' + esc(r.username) + '</b>」已设立。'];
      toast(modeTxt[0]);
      await dialog(modeTxt[0],
        modeTxt[1] + '<br><br>专属 UID（豹子号）：<b>' + esc(r.uid8 || '—') + '</b>'
        + '<br><span class="tiny">权限：普通用户全部功能 + 作品搜索/上传 + 回复反馈</span>',
        '知道了');
      loadSubs(container);
      loadUsers(container, container.querySelector('#uq').value.trim());
    } catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(btn, false); }
  });
}

/* ============================================================
 * 公告
 * ============================================================ */
function bindAnnounce(container) {
  const inp = container.querySelector('#annInput');
  if (!inp) return;
  api('admin.php', 'announce_get').then(a => { inp.value = a.content || ''; }).catch(() => {});
  container.querySelector('#annSave').addEventListener('click', async () => {
    try { await api('admin.php', 'announce_set', { content: inp.value }); toast('公告已更新'); }
    catch (e) { toast(e.message, 'err'); }
  });
}

/* ------------------------------------------------------------
 * 控制面板数据自动刷新（默认 30s）
 * 用户正在输入、弹窗打开、标签页不可见时一律跳过，避免打断操作
 * ------------------------------------------------------------ */
function panelBusy(container) {
  const ae = document.activeElement;
  if (ae && container.contains(ae) && /INPUT|TEXTAREA/.test(ae.tagName)) { return true; }
  const scrim = document.getElementById('dialogScrim');
  if (scrim && !scrim.hidden) { return true; }
  return false;
}

function startPanelPolling(container) {
  const tick = async () => {
    if (document.hidden || !container.isConnected || panelBusy(container)) { return; }
    try {
      const qEl  = container.querySelector('#wq');
      const uqEl = container.querySelector('#uq');
      const pgEl = container.querySelector('#workPager');
      const uPgEl = container.querySelector('#userPager');
      const rangeEl = container.querySelector('#rangeSeg button.on');

      const ov = await api('dashboard.php', 'overview', null, { silent: true });
      const ovGrid = container.querySelector('#ovGrid');
      if (ovGrid) { renderOverview(ovGrid, ov, container); }

      loadStats(rangeEl ? rangeEl.dataset.r : '7', container);
      loadWorks(container, qEl ? qEl.value.trim() : '', false,
                pgEl ? Number(pgEl.dataset.page || '1') : 1);
      /* 轮询时保持当前页；副管理员走只读渲染（此前漏传，会短暂显示不可用的操作按钮） */
      loadUsers(container, uqEl ? uqEl.value.trim() : '', state.role === 'subadmin',
                uPgEl ? Number(uPgEl.dataset.page || '1') : 1);
      loadSubs(container);
      loadVisitsTop(container);
    } catch (e) { /* 静默：下一轮再试 */ }
  };
  window.__addPageTimer(setInterval(tick, 30000));
}

/* ========== pages/doc.js ========== */
/**
 * 文档页：直接 fetch assets/docs/*.md 并渲染（不在前端硬编码文案）
 */


const ALLOWED = ['功能说明', 'AI 使用说明', '社区公约', '评分标准', '入榜规则',
  '用户协议', '隐私政策', '更新日志'];

async function renderDoc(container, ctx) {
  const name = decodeURIComponent((ctx.sub || '').trim()) || '评分标准';
  if (ALLOWED.indexOf(name) === -1) {
    container.innerHTML = '<div class="empty"><p>文档不存在</p></div>';
    return;
  }
  container.innerHTML = '<div class="skeleton" style="height:200px"></div>';
  try {
    const resp = await fetch('assets/docs/' + encodeURIComponent(name) + '.md', { cache: 'no-cache' });
    if (!resp.ok) throw new Error('文档加载失败');
    const md = await resp.text();
    const box = document.createElement('div');
    box.className = 'card doc';
    let html = mdToHtml(md);

    /* 更新日志页：顶部标出当前站点版本，便于对照是否已部署到最新 */
    if (name === '更新日志') {
      const v = (window.__SITE__ && window.__SITE__.ver) ? String(window.__SITE__.ver) : '';
      const latest = (md.match(/^##\s*v([0-9]+\.[0-9]+\.[0-9]+)/m) || [])[1] || '';
      const same = v !== '' && latest.indexOf(v) >= 0;
      html = `<div class="doc-ver${same ? ' ok' : ''}">当前站点版本 v${esc(v || '未知')}`
        + (latest && !same ? ` · 日志最新为 v${esc(latest)}（本站可能尚未更新到该版本）` : '')
        + `</div>` + html;
    }

    box.innerHTML = html;
    appendDownload(box, name, md);
    container.innerHTML = '';
    container.appendChild(box);
  } catch (e) {
    container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`;
  }
}

/* 下载（托盘 + 下箭头） */
const ICON_DL = '<svg viewBox="0 0 24 24" class="ic" style="width:16px;height:16px;fill:none;'
  + 'stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round" aria-hidden="true">'
  + '<path d="M12 3.5v10.5M7.8 10.2 12 14.4l4.2-4.2M5 18.5h14"/></svg>';

/**
 * 文档末尾的下载条。原文已在内存中，直接落盘，不产生第二次请求。
 * 文件名取自文档名，内容与页面所见完全一致（未经渲染改写）。
 */
function appendDownload(box, name, md) {
  const bar = document.createElement('div');
  bar.className = 'doc-dl';

  const hint = document.createElement('span');
  hint.className = 'doc-dl-hint';
  hint.textContent = name + '.md · 与页面内容一致';

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-ghost btn-sm';
  btn.setAttribute('aria-label', '下载 ' + name + ' 的原始 md 文件');
  btn.innerHTML = ICON_DL + '下载原始 md 文件';
  btn.addEventListener('click', () => {
    const url = URL.createObjectURL(new Blob([md], { type: 'text/markdown;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name + '.md';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
    toast('已开始下载 ' + name + '.md');
  });

  bar.appendChild(hint);
  bar.appendChild(btn);
  box.appendChild(bar);
}

/* ---------- 轻量 Markdown 渲染（覆盖本文档用到的语法） ---------- */

/* ========== pages/feedback.js ========== */
/**
 * 反馈页：提交反馈（公开/私密）；管理员视角为「回复反馈」
 */



async function renderFeedback(container) {
  const isAdmin = isAdminish();
  container.innerHTML = `
    <div class="card">
      <div class="card-title">${isAdmin ? '回复反馈' : '反映问题'}</div>
      ${isAdmin || state.role === 'user' ? `
        <div class="field">
          <textarea class="input" id="fbText" maxlength="1000" placeholder="描述你遇到的问题或建议…"></textarea>
        </div>
        <div class="setting-row" style="border:0;padding:4px 0">
          <span class="muted">可见范围</span>
          <div class="seg" style="max-width:200px" id="visSeg">
            <button data-v="1" class="on">公开</button>
            <button data-v="0">仅管理员可见</button>
          </div>
        </div>
        <button class="btn btn-sm" id="fbSend" style="margin-top:10px">提交反馈</button>
      ` : '<p class="tiny muted">登录后可提交反馈。</p>'}
    </div>
    <div class="fb-list-head">
      <span>${isAdmin ? '全部反馈' : '反馈记录'}</span>
    </div>
    <div id="fbList"></div>`;

  let vis = 1;
  const seg = container.querySelector('#visSeg');
  if (seg) seg.addEventListener('click', e => {
    const b = e.target.closest('[data-v]'); if (!b) return;
    vis = Number(b.dataset.v);
    container.querySelectorAll('#visSeg button').forEach(x => x.classList.toggle('on', x === b));
  });

  const send = container.querySelector('#fbSend');
  if (send) send.addEventListener('click', async () => {
    const text = container.querySelector('#fbText').value.trim();
    if (text.length < 2) { toast('内容太短', 'err'); return; }
    btnLoading(send, true);
    try { await api('feedback.php', 'create', { content: text, is_public: vis }); toast('已提交'); container.querySelector('#fbText').value = ''; load(); }
    catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(send, false); }
  });

  async function load() {
    const list = container.querySelector('#fbList');
    list.innerHTML = '<div class="skeleton" style="height:70px"></div>';
    try {
      const d = await api('feedback.php', 'list');
      list.innerHTML = '';
      if (!d.items.length) { list.innerHTML = '<div class="empty" style="padding:26px">暂无反馈</div>'; return; }
      d.items.forEach(f => list.appendChild(fbNode(f, isAdmin, load)));
    } catch (e) { list.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; }
  }

  load();
}

function fbNode(f, isAdmin, reload) {
  const el = document.createElement('div');
  el.className = 'card';
  const badge = f.public ? '<span class="chip">公开</span>' : '<span class="chip" style="background:color-mix(in srgb,#f59e0b 16%,transparent);color:#b45309">私密</span>';
  el.innerHTML = `
    <div class="rank-meta" style="margin:0 0 6px">${badge}${userName(f.username, f.role)}<span class="tiny">${esc(f.time)}</span></div>
    <div style="white-space:pre-wrap">${esc(f.content)}</div>
    ${f.reply ? `<div style="margin-top:10px;padding:9px 12px;border-radius:11px;background:color-mix(in srgb,var(--accent) 9%,transparent)">
        <div class="tiny" style="color:var(--accent);margin-bottom:3px">管理员回复 · ${esc(f.replied_at)}</div>
        <div style="white-space:pre-wrap">${esc(f.reply)}</div></div>` : ''}
    <div class="ops" style="display:flex;gap:12px;margin-top:9px;font-size:13px">
      ${isAdmin ? '<button class="link" data-act="reply" style="border:0;background:0">回复</button>' : ''}
      ${f.can_deleted ? '<button class="link" data-act="del" style="border:0;background:0;color:var(--danger)">删除</button>' : ''}
    </div>`;

  const reply = el.querySelector('[data-act="reply"]');
  if (reply) reply.addEventListener('click', async () => {
    const t = await promptText();
    if (!t) return;
    try { await api('feedback.php', 'reply', { id: f.id, content: t }); toast('已回复'); reload(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const del = el.querySelector('[data-act="del"]');
  if (del) del.addEventListener('click', async () => {
    if (await dialog('删除反馈', '确认删除？', '删除', { danger: true })) {
      try { await api('feedback.php', 'delete', { id: f.id }); toast('已删除'); reload(); }
      catch (e) { toast(e.message, 'err'); }
    }
  });
  return el;
}

function promptText() {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.innerHTML = `<h3>回复反馈</h3>
      <div class="field"><textarea class="input" id="fbReply" maxlength="1000" placeholder="输入回复…"></textarea></div>
      <div class="dialog-actions"><button class="btn-ghost" data-r="0">取消</button><button class="btn" data-r="1">发送</button></div>`;
    scrim.hidden = false;
    const ta = box.querySelector('#fbReply'); ta.focus();
    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => close(b.dataset.r === '1' ? ta.value.trim() : null)));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}

/* ========== pages/violation.js ========== */
/**
 * 违纪通报页面
 * ------------------------------------------------------------
 * 两个入口共用一套渲染：
 *   · renderViolation —— 被通报者访问站点时被 302 到这里（?violation=<id>），
 *     顶部给出说明，下方逐条列出理由，再往下是评论区；
 *   · renderDiscipline —— 公开的通报列表与单条详情（#/discipline[/id]）。
 */




function reasonList(reasons) {
  if (!reasons || !reasons.length) { return '<p class="tiny muted">（未填写理由）</p>'; }
  return '<ol class="disc-reasons">' + reasons.map(r => '<li>' + esc(r) + '</li>').join('') + '</ol>';
}

/** 一句话封禁状态胶囊 */
function discChip(it) {
  const cls = it.alive ? 'on' : (it.banned ? 'off' : '');
  return '<span class="disc-chip ' + cls + '">' + esc(discStatusText(it)) + '</span>';
}

function banText(row) {
  if (!row.banned) { return '未封停账号'; }
  if (!row.ban_until) { return '账号已封停 · 永久'; }
  return '账号已封停 · 至 ' + esc(row.ban_until);
}

/** 被封禁时给本人的一张说明卡：封禁原因 / 天数 / 解封时间，一眼看全 */
function banCard(row) {
  const days = Number(row.ban_days || 0);
  const until = row.ban_until || '';
  return `
    <div class="card" style="margin-top:12px">
      <h3 style="margin-top:0">封禁信息</h3>
      <dl class="kv">
        <dt>封禁原因</dt><dd>${reasonListInline(row.reasons)}</dd>
        <dt>封禁天数</dt><dd>${days > 0 ? days + ' 天' : '永久'}</dd>
        <dt>解封时间</dt><dd>${until ? esc(until) : '不会自动解封（永久）'}</dd>
        ${row.ip_banned ? '<dt>来源地址</dt><dd>已一并封禁</dd>' : ''}
        ${Number(row.user_count || 0) > 0 ? '<dt>累计通报</dt><dd>' + Number(row.user_count) + ' 次</dd>' : ''}
      </dl>
    </div>`;
}

function reasonListInline(reasons) {
  if (!reasons || !reasons.length) { return '未填写'; }
  return esc(reasons.join('；'));
}

/** 违纪界面 / 通报详情：$mine 为真时是「你被通报了」的口吻 */
async function paintDetail(container, id, mine) {
  const d = await api('discipline.php', 'get', { id: id }, { silent: true });
  const wrap = document.createElement('div');

  wrap.innerHTML = `
    <div class="violation-hero">
      <p class="vh-tag">${mine ? '你的访问已被限制' : '公开通报'}</p>
      <h2>违纪通报</h2>
      <p class="tiny">因下列原因，本站已对相关账号与来源地址作出处理。</p>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="row" style="align-items:center;gap:10px">
        <img class="avatar" src="${esc(d.avatar)}" width="40" height="40" draggable="false" alt="">
        <div style="flex:1">
          <div><b>${esc(d.username || '（未知用户）')}</b></div>
          <div class="tiny muted">通报于 ${esc(d.created)} · 浏览 ${Number(d.views || 0)}</div>
        </div>
      </div>
    </div>

    ${mine ? banCard(d) : ''}

    <div class="card" style="margin-top:12px">
      <h3 style="margin-top:0">通报理由</h3>
      ${reasonList(d.reasons)}
      ${d.note ? '<div class="disc-note"><span class="disc-note-k">补充说明</span>' + esc(d.note) + '</div>' : ''}
      <div class="disc-status">${discChip(d)}<span class="tiny muted">封禁 ${esc(discDaysText(d))}${d.ip_banned ? ' · 来源地址已封禁' : ''}</span></div>
    </div>

    ${mine ? '' : '<p class="tiny muted" style="margin-top:12px">相关讨论在「违纪通报」列表页下方。</p>'}
    ${mine ? '' : '<div class="card" style="margin-top:12px"><h3 style="margin-top:0">评论 <span class="tiny muted" id="cmtTotal">0</span></h3><div id="cmtForm"></div><div id="cmtList" style="margin-top:12px"></div></div>'}
    `;

  container.appendChild(wrap);

  /* 被通报者看的是自己的处理结果，不参与讨论 —— 评论区对他不可见（服务端同样拦）。 */
  if (mine) { return; }

  const cmt = wrap.querySelector('#cmtForm');
  if (cmt) {
    try {
      await renderComments(cmt.parentNode, d.id, 'discipline');
    } catch (e) {
      const box = wrap.querySelector('#cmtList');
      if (box) { box.innerHTML = '<div class="empty tiny">评论加载失败</div>'; }
    }
  }
}

/** 被通报者被拦下后进入的界面：默认取 302 带来的 id */
async function renderViolation(container, ctx) {
  const params = (ctx && ctx.params) || {};
  const id = Number((ctx && ctx.sub) || params.violation || params.id || 0);
  if (id <= 0) {
    container.innerHTML = '<div class="empty"><p>没有可显示的通报</p></div>';
    return;
  }
  await paintDetail(container, id, true);
}

/** 公开的通报列表；带 id 参数时直接看单条 */
async function renderDiscipline(container, ctx) {
  const sub = String((ctx && ctx.sub) || '');
  if (/^\d+$/.test(sub)) { await paintDetail(container, Number(sub), false); return; }

  const box = document.createElement('div');
  box.innerHTML = '<h2 style="margin:0 0 4px">违纪通报</h2>'
    + '<p class="tiny muted" style="margin:0 0 14px">这里记录本站对违规账号的处理，点开可查看详情与讨论。</p>'
    + '<div id="discList"><div class="skeleton" style="height:64px"></div><div class="skeleton"></div></div>';
  container.appendChild(box);

  const list = box.querySelector('#discList');
  try {
    const d = await api('discipline.php', 'list', { page: 1 }, { silent: true });
    const items = d.items || [];
    if (!items.length) { list.innerHTML = '<div class="empty">还没有通报记录</div>'; return; }
    list.innerHTML = '';
    items.forEach(it => {
      const el = document.createElement('div');
      el.className = 'card disc-item';
      el.innerHTML = `
        <div class="row" style="align-items:center;gap:10px">
          <img class="avatar" src="${esc(it.avatar)}" width="36" height="36" draggable="false" alt="">
          <div style="flex:1;min-width:0">
            <div><b>${esc(it.username || '（未知用户）')}</b> ${discChip(it)}</div>
            <div class="tiny muted">${esc(it.created)} · 封禁 ${esc(discDaysText(it))} · 评论 ${Number(it.comments || 0)}</div>
          </div>
        </div>
        ${reasonList(it.reasons.slice(0, 3))}`;
      el.addEventListener('click', () => navigate('#/discipline/' + it.id));
      list.appendChild(el);
    });

  } catch (e) {
    list.innerHTML = '<div class="empty"><p>' + esc(e.message) + '</p></div>';
  }
}

/* ========== app.js ========== */
/**
 * 应用入口：启动引导、路由、底栏、抽屉、搜索、通知轮询
 */















const view = document.getElementById('view');

/* 开源仓库：目录里的「渗透测试」直接跳这里 */
const REPO_URL = 'https://github.com/silverbullet-liang/kimi-game-rank';

/* 当前页的缓存键：滚动位置实时写回缓存（单一监听，避免重复注册） */
let scrollKey = null;
view.addEventListener('scroll', function () {
  if (!scrollKey) { return; }
  const rec = cacheGet(scrollKey);
  if (rec) { rec.scrollTop = view.scrollTop; }
}, { passive: true });

/* ============================================================
 * 路由
 * ============================================================ */
/** 主 Tab 页面（不需要返回按钮） */
const MAIN_TABS = { rank: 1, chat: 1, mine: 1 };

const routes = {
  rank: renderRank,
  detail: renderDetail,
  chat: renderLobby,
  mine: renderMine,
  login: renderLogin,
  panel: renderPanel,
  doc: renderDoc,
  feedback: renderFeedback,
  about: renderAbout,
  violation: renderViolation,
  discipline: renderDiscipline,
};

let currentPage = '';

/* ---------- URL ↔ 路由 ----------
 * 对外的地址形如 ?p=work&id=12（旧式 #/work?id=12 链接仍可访问并自动转换）。
 * 之所以用查询参数而非 #：任何浏览器、任何设备打开该地址都能直达同一界面。
 * 敏感参数（控制面板密钥等）一律不写入 URL，只走请求体。 */
function routeToUrl(hash) {
  const raw = String(hash || '').replace(/^#\/?/, '');
  const [path, query] = raw.split('?');
  const seg = String(path || '').split('/').filter(Boolean);
  const sp = new URLSearchParams(query || '');
  const url = new URL(location.href);
  url.search = '';
  url.searchParams.set('p', seg[0] || 'rank');
  if (seg.length > 1) { url.searchParams.set('arg', seg.slice(1).join('/')); }
  sp.forEach((v, k) => { if (k && k !== 'p' && k !== 'arg') { url.searchParams.set(k, v); } });
  return url.pathname + url.search;
}

/* 敏感界面：不接受通过地址参数直达，必须由站内入口进入（控制面板另有二次验证） */
const SENSITIVE_PAGES = ['panel'];

function readRoute() {
  if (location.hash && location.hash.length > 1) { return location.hash; }   // 旧式链接优先
  const u = new URL(location.href);
  const qs = new URLSearchParams(u.search);
  const KNOWN = ['rank', 'detail', 'chat', 'mine', 'login', 'doc', 'feedback', 'about', 'violation', 'discipline'];
  let p = qs.get('p') || '';
  let arg = qs.get('arg') || '';
  qs.delete('p');
  qs.delete('arg');
  /* 宽容写法：?p=chat 等价于裸参数 ?chat；?doc=入榜规则 等价于 ?p=doc&arg=入榜规则 */
  if (!p) {
    for (let i = 0; i < KNOWN.length; i++) {
      const k = KNOWN[i];
      if (qs.has(k)) { p = k; const v = qs.get(k) || ''; if (v) { arg = v; } qs.delete(k); break; }
    }
  }
  if (!p) { return ''; }
  const q = qs.toString();
  return '#/' + p + (arg ? '/' + arg : '') + (q ? '?' + q : '');
}

function parseHash() {
  const raw = readRoute().replace(/^#\/?/, '');
  const [path, query] = raw.split('?');
  const seg = path.split('/').filter(Boolean);
  const params = {};
  (query || '').split('&').filter(Boolean).forEach(kv => {
    const [k, v] = kv.split('=');
    params[decodeURIComponent(k)] = decodeURIComponent(v || '');
  });
  return { name: seg[0] || 'mine', sub: seg[1] || '', params };
}

async function navigate(hash) {
  const url = routeToUrl(hash);
  if (location.pathname + location.search === url) { await route('replace'); return; }
  history.pushState({ route: String(hash) }, '', url);
  await route('push');
}

/* 路由序号：用于取消被新导航取代的旧渲染（并发安全） */
let routeSeq = 0;

/* ---------- 页面级定时器：换页/切换视图时统一清理，避免泄漏与并发叠加 ---------- */
window.__pageTimers = [];
function clearPageTimers() {
  (window.__pageTimers || []).forEach(t => { try { clearInterval(t); clearTimeout(t); } catch (e) {} });
  window.__pageTimers = [];
  window.__chatPollEnabled = false;
}
window.__addPageTimer = function (t) { (window.__pageTimers = window.__pageTimers || []).push(t); return t; };

/* 取「上一页」缓存节点用于返回预览；指定 key 时优先跳过它本身 */
function prevPageForKey(key) {
  if (key) { const p = cachePrev(key); if (p) { return p; } }
  return cachePrev('\u0000');
}

/* 页面缓存键：路由名 + 子路径 + 参数 */
function routeKeyOf(name, sub, params) {
  let q = '';
  try { q = new URLSearchParams(params || {}).toString(); } catch (e) {}
  return name + '|' + (sub || '') + '|' + q;
}

async function route(navType) {
  clearPageTimers();                            // 离开上一页时清理其轮询定时器
  let { name, sub, params } = parseHash();
  /* 被通报封禁不再改地址，改由「全屏封禁说明」盖住整站（见 syncBanLock）；
     后端同样拒绝所有 API，所以改地址、换设备都绕不过去。管理员不会命中，服务端已排除。 */
  const fn = routes[name] || routes.rank;
  const key = routeKeyOf(name, sub, params);
  currentPage = name;
  setActiveTab(name);
  updateBackBtn(name);
  closeDrawer();
  syncBanLock();          // 封禁状态随时刷新：任何时候都盖住整站（登录页除外）

  // 聊天页需要内部独立滚动：锁定外层滚动
  view.classList.toggle('view-locked', name === 'chat');

  /* 返回（traverse）且命中缓存 → 直接恢复缓存节点：
     不请求接口、不重建 DOM、滚动位置原样还原，因此不会「自动刷新」。 */
  if (navType === 'traverse') {
    const hit = cacheGet(key);
    if (hit && hit.node) {
      ++routeSeq;                               // 使进行中的渲染作废
      view.replaceChildren(hit.node);
      view.scrollTop = hit.scrollTop || 0;
      cacheTouch(key);
      scrollKey = key;                          // 恢复后滚动位置继续写回缓存
      if (typeof hit.node.__onResume === 'function') {
        try { hit.node.__onResume(); } catch (e) {}   // 页面可选的恢复钩子（重启轮询等）
      }
      return;
    }
  }

  const seq = ++routeSeq;

  // 1) 先展示骨架（消除白屏）
  view.innerHTML = pageSkeleton();
  view.scrollTop = 0;

  // 2) 页面在「独立容器」内渲染，不直接触碰 view——避免并发互相清空 DOM
  const holder = document.createElement('div');

  try {
    await fn(holder, { sub, params });
  } catch (e) {
    if (seq !== routeSeq) { return; }            // 已被更新的导航取代，静默丢弃
    view.innerHTML = `<div class="empty">
      <p>页面加载失败</p>
      <p class="tiny">${esc(e && e.message ? e.message : '未知错误')}</p>
      <button class="btn btn-sm" id="routeRetry" style="margin-top:12px">重新加载</button>
    </div>`;
    const rb = view.querySelector('#routeRetry');
    if (rb) rb.addEventListener('click', function () { route('replace'); });
    return;
  }

  if (seq !== routeSeq) { return; }              // 渲染期间发生了新导航 → 丢弃本次结果

  // 3) 数据已就绪 → 用转场包裹「原子替换」：一次性换掉骨架，杜绝中间态。
  //    转场只包住替换这一步（毫秒级），因此不会出现「旧页定格等接口」的延迟感。
  await runTransition(navType === 'traverse' ? 'back' : 'fwd', function () {
    view.replaceChildren(holder);
    view.scrollTop = 0;
  });

  // 4) 记住滚动位置，供返回时原样恢复
  scrollKey = key;
  cacheSet(key, holder, 0);
}

window.__reRenderCurrent = function () { route(); };

/** 进入页面时的通用骨架（消除白屏） */
function pageSkeleton() {
  return '<div class="skeleton" style="height:72px"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
}

function updateBackBtn(name) {
  const btn = document.getElementById('btnBack');
  if (!btn) { return; }
  const show = !MAIN_TABS[name];
  btn.hidden = !show;
  btn.onclick = function () {
    if (history.length > 1) { history.back(); }
    else { navigate('#/' + (name === 'panel' || name === 'feedback' || name === 'login' ? 'mine' : 'rank')); }
  };
}

function setActiveTab(name) {
  const map = { rank: 'rank', detail: 'rank', chat: 'chat', mine: 'mine', panel: 'mine', login: 'mine', feedback: 'mine', doc: 'mine' };
  const tab = map[name] || 'rank';
  document.querySelectorAll('.tabbar .tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
}

/* ============================================================
 * 抽屉
 * ============================================================ */
const drawer = document.getElementById('drawer');
const drawerScrim = document.getElementById('drawerScrim');

function icon(path) { return `<svg viewBox="0 0 24 24" class="ic"><path d="${path}"/></svg>`; }
const I = {
  rank: 'M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 5.2 12H4a3 3 0 0 1 0-6h2V3z',
  search: 'M10 4a6 6 0 1 0 3.7 10.7l4.3 4.3 1.4-1.4-4.3-4.3A6 6 0 0 0 10 4zm0 2a4 4 0 1 1 0 8 4 4 0 0 1 0-8z',
  vote: 'M12 2l3 6 6.5.9-4.7 4.6 1.1 6.5L12 17l-5.9 3 1.1-6.5L2.5 8.9 9 8z',
  crown: 'M4 8l4 4 4-6 4 6 4-4-1.5 10h-13L4 8z',
  snow: 'M12 3v18M4.2 7.5l15.6 9M19.8 7.5l-15.6 9',
  mail: 'M3 6h18v12H3V6zm2 2l7 5 7-5',
  rule: 'M6 3h9l4 4v14H6V3zm8 1v4h4',
  list: 'M4 6h16M4 12h16M4 18h10',
  users: 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm7 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3 20c0-3 3-5 6-5s6 2 6 5H3zm13-5c2.5 0 5 1.4 5 4h-5V15z',
  info: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z',
  login: 'M10 3h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-8v-2h8V5h-8V3zM3 12l5-5v3h6v4H8v3l-5-5z',
  logout: 'M14 3h6v18h-6v-2h4V5h-4V3zM10 7l-5 5 5 5v-3h6v-4h-6V7z',
  doc: 'M6 2h8l4 4v16H6V2zm7 1.5V7h3.5L13 3.5zM8 11h8v2H8v-2zm0 4h8v2H8v-2z',
  plug: 'M9 2v5H7V2h2zm6 0v5h-2V2h2zM5 9h14v3a7 7 0 0 1-7 7 7 7 0 0 1-7-7V9zm5 11h4v2h-4v-2z',
  heart: 'M12 21s-7.5-4.7-9.3-9A5.5 5.5 0 0 1 12 6.6 5.5 5.5 0 0 1 21.3 12c-1.8 4.3-9.3 9-9.3 9z',
  lock: 'M12 2a5 5 0 0 1 5 5v2h1a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2h1V7a5 5 0 0 1 5-5zm0 2a3 3 0 0 0-3 3v2h6V7a3 3 0 0 0-3-3zm0 9a1.6 1.6 0 0 1 .9 2.9V18h-1.8v-2.1A1.6 1.6 0 0 1 12 13z',
  gear: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm9 4l-2 1.5.3 2.5-2 1-1.8-1.8-2.3.8L12 18l-1.2-2-2.3-.8L6.7 17l-2-1 .3-2.5L3 12l2-1.5L4.7 8l2-1 1.8 1.8 2.3-.8L12 6l1.2 2 2.3.8L17.3 7l2 1-.3 2.5L21 12z',
  shield: 'M12 2 4 5.2v5.9c0 4.7 3.3 8.8 8 10.9 4.7-2.1 8-6.2 8-10.9V5.2L12 2z',
};

function drawerItems() {
  const isAdmin = state.role === 'admin' || state.role === 'subadmin';
  const items = [
    { k: 'total', label: '总排行榜', ic: I.rank, go: '#/rank' },
    { k: 'search', label: '搜索作品', ic: I.search, act: 'search' },
    { k: 'vote', label: '投票榜', ic: I.vote, go: '#/rank?board=vote' },
    { k: 'gods', label: '诸神榜', ic: I.crown, go: '#/rank?board=gods' },
    { k: 'cold', label: '冷门榜', ic: I.snow, go: '#/rank?board=cold' },
    { k: 'feedback', label: isAdmin ? '回复反馈' : '反映问题', ic: I.mail, go: '#/feedback' },
    { k: 'help', label: '功能说明', ic: I.doc, go: '#/doc/功能说明' },
    { k: 'aihelp', label: 'AI 使用说明', ic: I.plug, go: '#/doc/AI 使用说明' },
    { k: 'charter', label: '社区公约', ic: I.users, go: '#/doc/社区公约' },
    { k: 'discipline', label: '违纪通报', ic: I.shield, go: '#/discipline' },
    { k: 'score', label: '评分标准', ic: I.rule, go: '#/doc/评分标准' },
    { k: 'join', label: '入榜规则', ic: I.list, go: '#/doc/入榜规则' },
    { k: 'terms', label: '用户协议', ic: I.doc, go: '#/doc/用户协议' },
    { k: 'privacy', label: '隐私政策', ic: I.lock, go: '#/doc/隐私政策' },
    { k: 'changelog', label: '更新日志', ic: I.doc, go: '#/doc/更新日志' },
    { k: 'pentest', label: '渗透测试', ic: I.shield, url: REPO_URL },
    { k: 'admins', label: '管理者名单', ic: I.users, go: '#/about' },
    { k: 'about', label: '关于', ic: I.info, go: '#/about' },
  ];
  if (state.role === 'user' || state.role === 'guest') {
    items.push({ k: 'auth', label: '用户登录 / 注册', ic: I.login, go: '#/login' });
  } else {
    items.push({ k: 'panel', label: '控制面板', ic: I.gear, go: '#/panel' });
    items.push({ k: 'logout', label: '退出登录', ic: I.logout, act: 'logout' });
  }
  return items;
}

function renderDrawer() {
  const list = document.getElementById('drawerList');
  list.innerHTML = '';
  drawerItems().forEach(it => {
    const b = document.createElement('button');
    b.className = 'drawer-item';
    b.type = 'button';
    b.innerHTML = icon(it.ic) + `<span>${it.label}</span>`;
    b.addEventListener('click', async () => {
      closeDrawer();
      if (it.go) { navigate(it.go); return; }
      if (it.url) { window.open(it.url, '_blank', 'noopener'); return; }
      if (it.act === 'search') { openSearch(); return; }
      if (it.act === 'logout') {
        if (await dialog('退出登录', '确认退出当前账号吗？', '退出')) {
          try { await api('auth.php', 'logout'); } catch (e) {}
          setToken('');
          toast('已退出');
          location.reload();
        }
      }
    });
    list.appendChild(b);
  });
}

function openDrawer() { drawer.hidden = false; drawerScrim.hidden = false; }
function closeDrawer() { drawer.hidden = true; drawerScrim.hidden = true; }

/* ============================================================
 * 搜索
 * ============================================================ */
const searchPanel = document.getElementById('searchPanel');
function openSearch() {
  searchPanel.hidden = false;
  document.getElementById('searchInput').focus();
}
function closeSearch() { searchPanel.hidden = true; }

/* ============================================================
 * 事件绑定
 * ============================================================ */
function bindEvents() {
  document.querySelectorAll('.tabbar .tab').forEach(t => {
    on(t, 'click', () => navigate(t.dataset.route));
  });
  on($('btnMenu'), 'click', openDrawer);
  on(drawerScrim, 'click', closeDrawer);
  on($('drawerClose'), 'click', closeDrawer);

  on($('btnSearch'), 'click', openSearch);
  on($('searchClose'), 'click', closeSearch);
  const doSearch = () => {
    const q = document.getElementById('searchInput').value.trim();
    closeSearch();
    if (q) navigate('#/rank?q=' + encodeURIComponent(q));
  };
  on($('searchGo'), 'click', doSearch);
  on($('searchInput'), 'keydown', e => { if (e.key === 'Enter') doSearch(); });

  on($('brandHome'), 'click', () => navigate('#/rank'));

  on($('btnTheme'), 'click', () => {
    const cur = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
    saveTheme({ theme: cur === 'dark' ? 'light' : 'dark' });
    renderDrawer();
  });

  /* 返回：traverse —— 命中缓存直接恢复，不重新加载 */
  window.addEventListener('popstate', function () { route('traverse'); });
  window.addEventListener('hashchange', function () { route('replace'); });    // 兼容旧式 #/ 链接

  /* 预见式返回：左边缘右滑跟手，身后实时预览上一页 */
  enablePredictiveBack(view, {
    canBack: function () { return !MAIN_TABS[currentPage]; },
    onPreview: function () { return prevPageForKey(scrollKey); },
    onCommit: function () {
      if (history.length > 1) { history.back(); }
      else { navigate('#/rank'); }
    }
  });
}

/* ============================================================
 * 公告
 * ============================================================ */
/* 公告已由服务端渲染进页面；启动载荷若带回更新的内容就就地更新。
   这里不再单独发请求 —— 它原本排在首屏关键路径上，白白多一个往返。 */
function applyAnnounce() {
  try {
    if (state.announce === undefined || state.announce === null) { return; }
    const bar = document.getElementById('announceBar');
    const txt = document.getElementById('announceText');
    if (!bar || !txt) { return; }
    const content = String(state.announce || '').trim() || txt.textContent.trim();
    if (content) { txt.textContent = content; bar.hidden = false; }
    else { bar.hidden = true; }
  } catch (e) {}
}

/* ============================================================
 * 世界对话新消息通知（轮询）
 * ============================================================ */
let lastMsgId = 0;
let pollTimer = null;
const POLL_BASE = 30000;      // 基础间隔 30s（共享主机并发敏感，宁可慢一点）
let pollDelay = POLL_BASE;
let pollStarted = false;

/** 聊天页可回写游标，避免首轮重复拉取 */
window.__setPollCursor = function (id) { if (Number(id) > lastMsgId) { lastMsgId = Number(id); } };

function startPolling() {
  if (pollStarted) return;
  pollStarted = true;
  schedulePoll();
}

function schedulePoll() {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    // 仅聊天页 + 页面可见时轮询；其余场景完全不发请求
    const canPoll = !document.hidden && currentPage === 'chat'
      && window.__chatPollEnabled === true && !window.__aiBusy;
    if (canPoll) {
      try {
        const d = await api('lobby.php', 'list', { since_id: lastMsgId, limit: 20 }, { silent: true, tries: 1 });
        const items = d.items || [];
        if (items.length) {
          const fresh = items.filter(m => m.id > lastMsgId);
          lastMsgId = items[items.length - 1].id;
          if (window.__chatAppend) { window.__chatAppend(fresh); }
        }
        pollDelay = POLL_BASE;                       // 成功 → 恢复基础间隔
      } catch (e) {
        pollDelay = Math.min(90000, Math.round(pollDelay * 1.7));   // 失败 → 退避，上限 90s
      }
    } else {
      pollDelay = POLL_BASE;
    }
    schedulePoll();
  }, pollDelay);
}

/* ============================================================
 * 关于页
 * ============================================================ */
/**
 * 管理者名单的渲染（纯函数，便于单独校验）。
 * 只认服务端给的 label / name 两个字段——uid、凭证之类的字段即使被塞进响应也不会显示。
 */
function adminListHtml(items) {
  if (!items || !items.length) {
    return '<p class="tiny muted">名单暂时取不到，刷新页面再试。</p>';
  }
  return items.map(function (a) {
    return '<div class="credit-row"><span class="credit-k">' + esc(a.label) + '</span>'
      + '<span class="credit-v">' + esc(a.name) + '</span></div>';
  }).join('');
}

function renderAbout(container) {
  container.innerHTML = `
    <div class="card">
      <div class="card-title">${icon(I.info)}关于本站</div>
      <p class="muted">Kimi游戏榜 —— 面向 Kimi 社区的六维综合评分排行榜，收录社区优质作品，评分由公开算法自动计算。</p>
      <p class="tiny">版本 ${window.__SITE__.ver} · <span class="link" id="changelogLink">查看更新日志</span> · 数据来源：Kimi 社区公开接口</p>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.users)}参考与借鉴</div>
      <div class="credit-row"><span class="credit-k">参考 / 借鉴</span><span class="credit-v">普普通通的人（Kimi 社区游戏排行榜）</span></div>
      <p class="tiny muted">榜单的形态与选题受社区同好整理的游戏榜单启发；本站的抓取、六维评分算法与全部代码均为自行实现。</p>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.users)}鸣谢</div>
      <p class="muted">谢谢经常给本站反馈问题、提建议的伙伴 —— 你们的每一条反馈，都让这个榜单变好一点点。</p>
      <div class="credit-row"><span class="credit-k">反馈贡献</span><span class="credit-v">小李v</span></div>
      <div class="credit-row"><span class="credit-k">反馈贡献</span><span class="credit-v">starclimber</span></div>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.heart)}支持一下</div>
      <p class="muted">这个榜单是我利用业余时间、靠 AI 一点点写出来的：作品抓取、六维评分、AI 对话与工具调用，烧的都是我自己的额度。它现在完全免费、也不打算收费。</p>
      <p class="muted">如果它帮到了你，欢迎赞助一点，让服务器和 API 能继续跑下去。</p>

      <div class="donate-box">
        <div class="donate-head">求助力 · 一起免费领 ima 算力</div>
        <p class="tiny">邀你一起创建 ima 专属知识伙伴，免费领新人算力福利 —— 你领到的同时，也等于给这个站点续了命。</p>
        <div class="donate-link" id="inviteLink">https://ima.qq.com/copilot-invite-reward-token/assist/V_5sR6nXkLv5CRsf8PTJnQ</div>
        <div class="row-gap">
          <button class="btn btn-sm" id="copyInvite">复制邀请链接</button>
          <button class="btn-ghost btn-sm" id="openInvite">打开活动页</button>
        </div>
      </div>
      <p class="tiny">谢谢每一个点开这里的人。</p>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.users)}管理者名单</div>
      <div id="adminList"><div class="skeleton" style="height:56px"></div></div>
      <p class="tiny">名单为<b>实时读取</b>；如需反馈问题，请在「${(state.role === 'admin' || state.role === 'subadmin') ? '回复反馈' : '反映问题'}」中提交。</p>
    </div>`;

  const clLink = container.querySelector('#changelogLink');
  if (clLink) { clLink.addEventListener('click', () => navigate('#/doc/更新日志')); }

  /* 管理者名单：实时读取。走公开只读接口，与控制面板那条链路完全分开 */
  const alBox = container.querySelector('#adminList');
  if (alBox) {
    api('site.php', 'admins')
      .then(function (d) { alBox.innerHTML = adminListHtml(d.items); })
      .catch(function () { alBox.innerHTML = adminListHtml(null); });
  }

  const INVITE = 'https://ima.qq.com/copilot-invite-reward-token/assist/V_5sR6nXkLv5CRsf8PTJnQ';
  const copyBtn = container.querySelector('#copyInvite');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(INVITE); toast('邀请链接已复制，谢谢支持'); }
      catch (e) { toast('复制失败，请手动长按链接复制', 'err'); }
    });
  }
  const openBtn = container.querySelector('#openInvite');
  if (openBtn) {
    openBtn.addEventListener('click', () => window.open(INVITE, '_blank', 'noopener'));
  }
}

/* ============================================================
 * 回到顶部
 * ============================================================ */
function bindToTop() {
  const btn = document.getElementById('toTop');
  const view = document.getElementById('view');
  if (!btn || !view) { return; }
  view.addEventListener('scroll', () => {
    btn.hidden = view.scrollTop < 400;
  }, { passive: true });
  btn.addEventListener('click', () => view.scrollTo({ top: 0, behavior: 'smooth' }));
}

/* ============================================================
 * 违纪通报：全屏封禁说明 + 最新通报弹窗
 * ============================================================ */
/** 按当前封禁状态显示 / 收起全屏封禁说明（登录页除外，便于换账号登录）。 */
function syncBanLock() {
  /* 登录页只在「尚未登录」时豁免 —— 被封的来源地址要能进登录页换账号；
     一旦以被封账号登录成功，就该盖上全屏说明（说明里自带「切换账号登录」）。 */
  const exempt = (currentPage === 'login' && state.role === 'guest');
  if (state.ban && !exempt) { showBanLock(state.ban); }
  else { hideBanLock(); }
}

/** 后端判定封停时由 core.api 触发：补一次最新状态并立刻上锁 */
window.__forceBan = function () { refreshDiscState(true); };

/** 拉最新封禁与通报状态（start.php 在封禁白名单内，被封时也能取到）。
 *  防抖：封禁后页面上会有多个 403，不能让它们各拉一次（共享主机尤其敏感）。 */
let __discLast = 0;
async function refreshDiscState(force) {
  const now = Date.now();
  if (now - __discLast < (force ? 4000 : 8000)) { return; }
  __discLast = now;
  try {
    const d = await api('start.php', 'app', null, { silent: true, tries: 1 });
    state.ban  = (d && d.ban)  ? d.ban  : null;
    state.disc = (d && d.disc) ? d.disc : null;
    if (force || state.ban) { syncBanLock(); }
    maybeDiscPopup(state.disc);
  } catch (e) {}
}

/* ============================================================
 * 启动
 * ============================================================ */
async function main() {
  // 单例守卫：模块被重复加载时只初始化一次（防止双份事件绑定导致的重复提交）
  if (window.__KIMI_BOOTED__) { return; }
  window.__KIMI_BOOTED__ = true;
  initSystemWatcher();

  setUnauthorizedHandler(() => {
    if (state.role === 'guest') { return; }        // 游客已在 core.js 内静默续期，走到这里无需处理
    setToken('');
    state.role = 'guest';
    state.username = '游客';
    state.uid = 0;
    state.uid8 = '';
    state.settings = {};
    toast('登录态已失效，已切换为游客模式', 'err');
    /* 不再 location.reload()：就地重渲染当前页，避免 401 → 重载 → 401 的请求风暴 */
    setTimeout(() => { if (typeof window.__reRenderCurrent === 'function') { window.__reRenderCurrent(); } }, 200);
  });

  /* 首屏参数：榜单页让启动载荷直接把第一页带回来，省掉一次往返 */
  const firstRoute = parseHash();
  if (firstRoute.name === 'rank') {
    window.__firstPayload = {
      category: firstRoute.params.category || 'all',
      board: firstRoute.params.board || 'total',
      size: PAGE_SIZE,
    };
  }

  await boot();
  applyTheme();
  /* 返回动画开关（本机偏好，默认开启） */
  setNavAnim(getPrefs().nav_anim !== 0);

  /* 风格由 cookie 决定（服务端据此加载对应皮肤文件）。两种来源需要回写 cookie：
     1) 登录账号里保存的偏好（跨设备同步）
     2) 老版本存在本机偏好里的选择（迁移：旧版用 localStorage 即时切换，不写 cookie）
     回写后下次刷新生效，本次不刷新，避免闪屏。 */
  try {
    const VALID = ['glass', 'md3', 'pixel', 'sketch', 'brutal'];
    let want = (state.settings && state.settings.skin) || '';
    if (VALID.indexOf(want) < 0) {
      const prefs = JSON.parse(localStorage.getItem('kimgr_prefs') || '{}') || {};
      want = VALID.indexOf(prefs.skin) >= 0 ? prefs.skin : '';
    }
    const cur = document.documentElement.dataset.skin || '';
    if (want && cur !== want) {
      document.cookie = 'kimgr_skin=' + encodeURIComponent(want) + '; path=/; max-age=31536000; SameSite=Lax';
    }
  } catch (e) {}
  bindEvents();
  renderDrawer();
  applyAnnounce();
  syncBanLock();
  maybeDiscPopup(state.disc);
  /* 回到前台时刷新一次封禁状态与最新通报 —— 不做常驻轮询，省主机请求 */
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { refreshDiscState(); }
  });
  /* 首屏地址规范化：hash / 裸参数统一改为 ?p= 形式；无参数默认「我的」。
     敏感界面不接受通过地址直达（站内入口仍可正常进入）。 */
  const initial = readRoute();
  const initialPage = String(initial || '').replace(/^#\/?/, '').split('?')[0].split('/')[0];
  const safeInitial = (initial && SENSITIVE_PAGES.indexOf(initialPage) < 0) ? initial : '#/mine';
  history.replaceState({}, '', routeToUrl(safeInitial));
  await route();
  startPolling();

  // 首次交互时请求通知权限
  bindToTop();   // 通知权限改为在「我的 → 设置」中显式开启
}

main();
