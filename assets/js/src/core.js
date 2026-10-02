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
  username: '游客',
  avatar: '',
  settings: {},
  csrf: '',
  booted: false,
};

/* ============================================================
 * 图片加载回退
 * ============================================================
 * 站点默认让浏览器直连原图：绝大多数图床不校验 Referer，直连更快，也不消耗
 * 主机的请求数与流量额度。只有确知有 Referer 防盗链的域名（由后端 img_src()
 * 判断）才直接给出本站代理地址；其余图片若直连失败，这里统一回退到代理重试
 * 一次，从而不必为「以防万一」而把全量图片都压到服务端。
 */
export function installImageFallback() {
  var mark = function (t) { t.dataset.fb = '1'; t.src = 'api/img.php?u=' + encodeURIComponent(t.currentSrc || t.src || ''); };
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (!t || t.tagName !== 'IMG' || t.dataset.fb) { return; }
    if (!/^https?:\/\//i.test(t.currentSrc || t.src || '')) { return; }
    mark(t);
  }, true);
}
installImageFallback();

/* ============================================================
 * 本地存储
 * ============================================================ */
export function getToken() {
  try { return localStorage.getItem(LS_KEY) || ''; } catch (e) { return ''; }
}
export function setToken(t) {
  try { t ? localStorage.setItem(LS_KEY, t) : localStorage.removeItem(LS_KEY); } catch (e) {}
  state.token = t || '';
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
export function blockRules() {
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

export async function api(file, action, data = null, opts = {}) {
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
    throw new ApiError(Number(json.code) || resp.status,
      json.msg || json.error || ('请求失败（HTTP ' + resp.status + '）'));
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
}

/* ============================================================
 * 身份徽章（管理员 / 副管理员）
 * ============================================================ */
export const ROLES = {
  admin:    { label: '管理员',   cls: 'bd-admin' },
  subadmin: { label: '副管理员', cls: 'bd-sub' },
};

/** 是否具备后台身份 */
export function isAdminish(role) {
  const r = role || state.role;
  return r === 'admin' || r === 'subadmin';
}

/** 身份徽章（内联 SVG，无表情字符）；普通用户/游客返回空串 */
export function badge(role) {
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
export function userName(name, role, reports) {
  const n = Number(reports || 0);
  const tag = n > 0
    ? '<span class="badge badge-violation" title="累计被通报 ' + n + ' 次">被通报 ' + n + ' 次</span>'
    : '';
  return '<span class="uname">' + esc(name) + '</span>' + badge(role) + tag;
}

/* ============================================================
 * UI 工具
 * ============================================================ */
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
export function dialog(title, text, confirmLabel = '确定', opts = {}) {
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
export function prompt_(title, text, confirmLabel = '确定') {
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
export async function boot() {
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
export function bindImageViewer(container) {
  if (!container) { return; }
  container.addEventListener('click', e => {
    const img = e.target && e.target.closest ? e.target.closest('img.msg-img') : null;
    if (img && img.src) { imageViewer(img.src); }
  });
}
