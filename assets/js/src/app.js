import { SITE_BASE } from './base.js';
/**
 * 应用入口：启动引导、路由、底栏、抽屉、搜索、通知轮询
 */
import { i18nInit, t, applyI18nDom, applyI18nText, watchI18n } from './i18n.js';
import { boot, state, api, toast, dialog, prompt_, setToken, setUnauthorizedHandler, notify, getPrefs, setPrefs, $, on, askNotifyPermission, esc, showBanLock, hideBanLock, maybeDiscPopup, readBanCache, readBanCacheAsync, saveBanCache } from './core.js';
import { applyTheme, saveTheme, initSystemWatcher, ACCENTS } from './theme.js';
import { setNavigate } from './router.js';
import { guardStart } from './guard.js';
import { cacheGet, cacheSet, cacheTouch, cachePrev, runTransition, enablePredictiveBack, setNavAnim } from './transitions.js';

import { renderRank, PAGE_SIZE } from './pages/rank.js';
import { renderDetail } from './pages/detail.js';
import { renderLobby } from './pages/lobby.js';
import { renderMine } from './pages/mine.js';
import { renderLogin } from './pages/login.js';
import { renderPanel } from './pages/panel.js';
import { renderMonitor } from './pages/monitor.js';
import { renderDoc } from './pages/doc.js';
import { renderFeedback } from './pages/feedback.js';
import { renderSubmit } from './pages/submit.js';
import { renderViolation, renderDiscipline } from './pages/violation.js';

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
  monitor: renderMonitor,
  doc: renderDoc,
  feedback: renderFeedback,
  submit: renderSubmit,
  about: renderAbout,
  violation: renderViolation,
  discipline: renderDiscipline,
};

let currentPage = '';

/* ---------- URL ↔ 路由 ----------
 * 对外一律是普通路径：/rank 、/detail/12 、/doc/入榜规则 、/chat?q=关键词。
 * 部署在子目录时由服务端下发 window.__BASE 作前缀，前端不必猜。
 * 旧地址（?p=detail&arg=12、#/detail/12）仍可打开：服务端 301，前端另有兜底。
 * 敏感参数（控制面板密钥等）一律不写入 URL，只走请求体。 */
const URL_BASE = SITE_BASE;

/* 路由段映射由服务端下发（来源 app/seo.php 的 seo_route_segments / seo_doc_slugs）：
   内部标识 rank/detail/doc… 与对外地址段 rankings/works/docs… 一一对应，前端不再硬编码路径名。 */
const RM = (typeof window !== 'undefined' && window.__ROUTES) ? window.__ROUTES : {};
const SEG_OF   = RM.seg || {};
const NAME_OF  = RM.name || {};
const DOC_SLUG = RM.docSlug || {};
const DOC_NAME = RM.docName || {};

/** 路由（#/name/arg?q）→ 地址（/name/arg?q） */
function routeToUrl(hash) {
  const raw = String(hash || '').replace(/^#\/?/, '');
  const [path, query] = raw.split('?');
  const seg = String(path || '').split('/').filter(Boolean);
  const sp = new URLSearchParams(query || '');
  sp.delete('p');
  sp.delete('arg');
  const name = seg[0] || 'rank';
  let arg = seg.slice(1).join('/');
  /* 文档地址用英文短名：/docs/admission 而不是 /docs/入榜规则 */
  if (name === 'doc' && arg) { arg = DOC_SLUG[arg] || arg; }
  let url = URL_BASE + '/' + encodeURIComponent(SEG_OF[name] || name);
  if (arg) { url += '/' + arg.split('/').map(encodeURIComponent).join('/'); }
  const q = sp.toString();
  return q ? url + '?' + q : url;
}

/* 敏感界面：不接受通过地址参数直达，必须由站内入口进入（控制面板另有二次验证） */
const SENSITIVE_PAGES = ['panel'];

function readRoute() {
  if (location.hash && location.hash.length > 1) { return location.hash; }   // 旧式 #/ 链接优先
  const KNOWN = ['rank', 'detail', 'chat', 'mine', 'login', 'submit', 'doc', 'panel', 'monitor', 'feedback', 'about', 'violation', 'discipline'];

  /* 主路径：/rank 、/detail/12 、/doc/入榜规则 */
  let path = location.pathname;
  if (URL_BASE && path.indexOf(URL_BASE) === 0) { path = path.slice(URL_BASE.length); }
  const seg = path.split('/').filter(Boolean).map(s => {
    try { return decodeURIComponent(s); } catch (e) { return s; }
  });
  const seg0 = seg[0] || '';
  let name = NAME_OF[seg0] || (KNOWN.indexOf(seg0) >= 0 ? seg0 : '');   // 段 → 内部名（旧段同名亦可）
  let arg = seg.slice(1).join('/');
  if (name === 'doc' && arg) { arg = DOC_NAME[arg] || arg; }

  /* 兜底：查询式旧地址（服务端已 301，这里只处理脚本内手拼的情况） */
  const qs = new URLSearchParams(location.search);
  if (KNOWN.indexOf(name) < 0) {
    let p = qs.get('p') || '';
    if (!p) {
      for (let i = 0; i < KNOWN.length; i++) {
        const k = KNOWN[i];
        if (qs.has(k)) { p = k; const v = qs.get(k) || ''; if (v) { arg = v; } qs.delete(k); break; }
      }
    }
    if (!p) { return ''; }
    const sp = p.split('/');
    name = sp[0];
    if (!arg && sp.length > 1) { arg = sp[1]; }
  }
  qs.delete('p');
  qs.delete('arg');
  return '#/' + name + (arg ? '/' + arg : '') + (qs.toString() ? '?' + qs.toString() : '');
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

/* 替换 #view 的内容：内核对 replaceChildren 的支持不一 —— 旧内核缺失时调用会抛
   「replaceChildren is not a function」，整页切换随之失败（监控里已收到此类上报）。
   能用则用，不能用退回「逐个移除 + 追加」的等效写法。 */
function setViewNode(el, node) {
  if (typeof el.replaceChildren === 'function') { el.replaceChildren(node); return; }
  while (el.firstChild) { el.removeChild(el.firstChild); }
  el.appendChild(node);
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
  renderDrawer();               // 身份可能已变（登录 / 退出）：抽屉即时更新，无需整页刷新
  state.ban = readBanCache();   // 同步存储（localStorage / sessionStorage / Cookie / window.name）先上锁
  syncBanLock();          // 封禁状态随时刷新：任何时候都盖住整站（登录页除外）
  /* 异步存储（IndexedDB / Cache Storage）再补一次：任一处留有记录都持续拦截 */
  readBanCacheAsync().then(function (b) {
    if (b && !state.ban) { state.ban = b; syncBanLock(); }
  });

  // 聊天页需要内部独立滚动：锁定外层滚动
  view.classList.toggle('view-locked', name === 'chat');

  /* 返回（traverse）且命中缓存 → 直接恢复缓存节点：
     不请求接口、不重建 DOM、滚动位置原样还原，因此不会「自动刷新」。 */
  if (navType === 'traverse') {
    const hit = cacheGet(key);
    if (hit && hit.node) {
      ++routeSeq;                               // 使进行中的渲染作废
      setViewNode(view, hit.node);
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
    applyI18nText(holder);   // 模板串里的界面文案：渲染后按字典替换文本节点
  } catch (e) {
    if (seq !== routeSeq) { return; }            // 已被更新的导航取代，静默丢弃
    view.innerHTML = `<div class="empty">
      <p>页面加载失败</p>
      <p class="tiny">${esc(e && e.message ? e.message : t('未知错误'))}</p>
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
    setViewNode(view, holder);
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
    else { navigate('#/' + (name === 'panel' || name === 'monitor' || name === 'feedback' || name === 'login' ? 'mine' : 'rank')); }
  };
}

function setActiveTab(name) {
  const map = { rank: 'rank', detail: 'rank', chat: 'chat', mine: 'mine', panel: 'mine', monitor: 'mine', login: 'mine', feedback: 'mine', doc: 'mine' };
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
  play: 'M8 5v14l11-7z',
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
    { k: 'total', label: t('总排行榜'), ic: I.rank, go: '#/rank' },
    { k: 'search', label: t('搜索作品'), ic: I.search, act: 'search' },
    { k: 'submit', label: t('提交作品'), ic: I.list, go: '#/submit' },
    { k: 'vote', label: t('投票榜'), ic: I.vote, go: '#/rank?board=vote' },
    { k: 'gods', label: t('诸神榜'), ic: I.crown, go: '#/rank?board=gods' },
    { k: 'cold', label: t('冷门榜'), ic: I.snow, go: '#/rank?board=cold' },
    { k: 'feedback', label: isAdmin ? '回复反馈' : t('反映问题'), ic: I.mail, go: '#/feedback' },
    { k: 'help', label: t('功能说明'), ic: I.doc, go: '#/doc/功能说明' },
    { k: 'aihelp', label: t('AI 使用说明'), ic: I.plug, go: '#/doc/AI 使用说明' },
    { k: 'charter', label: t('社区公约'), ic: I.users, go: '#/doc/社区公约' },
    { k: 'discipline', label: t('违纪通报'), ic: I.shield, go: '#/discipline' },
    { k: 'score', label: t('评分标准'), ic: I.rule, go: '#/doc/评分标准' },
    { k: 'join', label: t('入榜规则'), ic: I.list, go: '#/doc/入榜规则' },
    { k: 'terms', label: t('用户协议'), ic: I.doc, go: '#/doc/用户协议' },
    { k: 'privacy', label: t('隐私政策'), ic: I.lock, go: '#/doc/隐私政策' },
    { k: 'changelog', label: t('更新日志'), ic: I.doc, go: '#/doc/更新日志' },
    { k: 'pentest', label: t('渗透测试'), ic: I.shield, url: REPO_URL },
    { k: 'admins', label: t('管理者名单'), ic: I.users, go: '#/about' },
    { k: 'about', label: t('关于'), ic: I.info, go: '#/about' },
  ];
  if (state.role === 'user' || state.role === 'guest') {
    items.push({ k: 'auth', label: t('用户登录 / 注册'), ic: I.login, go: '#/login' });
  } else {
    items.push({ k: 'panel', label: t('控制面板'), ic: I.gear, go: '#/panel' });
    items.push({ k: 'monitor', label: t('异常监测'), ic: I.shield, go: '#/monitor' });
    items.push({ k: 'logout', label: t('退出登录'), ic: I.logout, act: 'logout' });
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
        if (await dialog(t('退出登录'), t('确认退出当前账号吗？'), t('退出'))) {
          try { await api('auth', 'logout'); } catch (e) {}
          setToken('');
          /* 就地切到游客态：重绘抽屉并跳转，不再整页刷新 */
          state.role = 'guest'; state.uid = 0; state.username = t('游客');
          state.settings = {}; state.avatar = '';
          toast('已退出');
          renderDrawer();
          navigate('#/rank');
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

  /* 站内链接（文档正文、服务端渲染内容里的 /rank、/doc/xxx 等）走前端路由，
     不再整页刷新；外链、新标签打开、组合键点击一律放行 */
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || (a.target && a.target !== '_self')) { return; }
    const href = a.getAttribute('href') || '';
    if (href.charAt(0) !== '/') { return; }
    const path = href.slice(URL_BASE.length);
    if (!/^\/(rank|detail|chat|mine|login|doc|feedback|about|violation|discipline)(\/|$|\?)/.test(path)) { return; }
    e.preventDefault();
    navigate(path);
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
        const d = await api('lobby', 'list', { since_id: lastMsgId, limit: 20 }, { silent: true, tries: 1 });
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
    return '<p class="tiny muted">' + t('名单暂时取不到，刷新页面再试。') + '</p>';
  }
  return items.map(function (a) {
    return '<div class="credit-row"><span class="credit-k">' + esc(a.label) + '</span>'
      + '<span class="credit-v">' + esc(a.name) + '</span></div>';
  }).join('');
}

function renderAbout(container) {
  const isAdmin = state.role === 'admin' || state.role === 'subadmin';
  container.innerHTML = `
    <div class="card">
      <div class="card-title">${icon(I.info)}${t('关于本站')}</div>
      <p class="muted">${t('Kimi游戏榜 —— 面向 Kimi 社区的六维综合评分排行榜，收录社区优质作品，评分由公开算法自动计算。')}</p>
      <p class="tiny">${t('版本 ')}${window.__SITE__.ver} · <span class="link" id="changelogLink">${t('查看更新日志')}</span> · ${t('数据来源：Kimi 社区公开接口')}</p>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.play)}${t('宣传视频')}</div>
      <div class="video-embed">
        <iframe src="https://player.bilibili.com/player.html?bvid=BV1AinG6fEUo&page=1&high_quality=1&danmaku=1&autoplay=0"
                scrolling="no" frameborder="no" allowfullscreen="true"
                referrerpolicy="no-referrer-when-downgrade" loading="lazy"
                title="${t('Kimi 游戏榜宣传视频')}"></iframe>
      </div>
      <p class="tiny muted">${t('8 分钟讲清楚评分怎么算、等级怎么划、作品怎么收录')} · BV1AinG6fEUo</p>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.users)}${t('参考与借鉴')}</div>
      <div class="credit-row"><span class="credit-k">${t('参考 / 借鉴')}</span><span class="credit-v">${t('普普通通的人（Kimi 社区游戏排行榜）')}</span></div>
      <p class="tiny muted">${t('榜单的形态与选题受社区同好整理的游戏榜单启发；本站的抓取、六维评分算法与全部代码均为自行实现。')}</p>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.rule)}${t('规划和制作')}</div>
      <div class="credit-row"><span class="credit-k">${t('规划')}</span><span class="credit-v">银色子弹-silver、glm-5.3-flash</span></div>
      <div class="credit-row"><span class="credit-k">${t('制作')}</span><span class="credit-v">deepseek-v4.1-flash、glm-5.3-flash</span></div>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.users)}${t('鸣谢')}</div>
      <p class="muted">${t('谢谢经常给本站反馈问题、提建议的伙伴 —— 你们的每一条反馈，都让这个榜单变好一点点。')}</p>
      <div class="credit-row"><span class="credit-k">${t('反馈贡献')}</span><span class="credit-v">小李v</span></div>
      <div class="credit-row"><span class="credit-k">${t('反馈贡献')}</span><span class="credit-v">starclimber</span></div>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.heart)}${t('支持一下')}</div>
      <p class="muted">${t('这个榜单是我利用业余时间、靠 AI 一点点写出来的：作品抓取、六维评分、AI 对话与工具调用，烧的都是我自己的额度。它现在完全免费、也不打算收费。')}</p>
      <p class="muted">${t('如果它帮到了你，欢迎赞助一点，让服务器和 API 能继续跑下去。')}</p>

      <div class="donate-box">
        <div class="donate-head">${t('求助力 · 一起免费领 ima 算力')}</div>
        <p class="tiny">${t('邀你一起创建 ima 专属知识伙伴，免费领新人算力福利 —— 你领到的同时，也等于给这个站点续了命。')}</p>
        <div class="donate-link" id="inviteLink">https://ima.qq.com/copilot-invite-reward-token/assist/V_5sR6nXkLv5CRsf8PTJnQ</div>
        <div class="row-gap">
          <button class="btn btn-sm" id="copyInvite">${t('复制邀请链接')}</button>
          <button class="btn-ghost btn-sm" id="openInvite">${t('打开活动页')}</button>
        </div>
      </div>
      <p class="tiny">${t('谢谢每一个点开这里的人。')}</p>
    </div>

    <div class="card">
      <div class="card-title">${icon(I.users)}${t('管理者名单')}</div>
      <div id="adminList"><div class="skeleton" style="height:56px"></div></div>
      <p class="tiny">${t('名单为实时读取；如需反馈问题，请在「')}<b>${isAdmin ? t('回复反馈') : t('反映问题')}</b>${t('」中提交。')}</p>
    </div>`;

  const clLink = container.querySelector('#changelogLink');
  if (clLink) { clLink.addEventListener('click', () => navigate('#/doc/更新日志')); }

  /* 管理者名单：实时读取。走公开只读接口，与控制面板那条链路完全分开 */
  const alBox = container.querySelector('#adminList');
  if (alBox) {
    api('site', 'admins')
      .then(function (d) { alBox.innerHTML = adminListHtml(d.items); })
      .catch(function () { alBox.innerHTML = adminListHtml(null); });
  }

  const INVITE = 'https://ima.qq.com/copilot-invite-reward-token/assist/V_5sR6nXkLv5CRsf8PTJnQ';
  const copyBtn = container.querySelector('#copyInvite');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(INVITE); toast(t('邀请链接已复制，谢谢支持')); }
      catch (e) { toast(t('复制失败，请手动长按链接复制'), 'err'); }
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
/* ============================================================
 * 存量用户名待更新提醒
 * ------------------------------------------------------------
 * 用户名规范收紧前注册的名字可能含换行或异常装饰字符。后端在身份载荷里下发
 * name_fix = { current, suggest }，这里弹输入框（预填建议名），用户确认后调
 * 自助改名接口。点「取消」= 稍后再说，不阻断任何功能，刷新后仍会再提醒。
 * ============================================================ */
let __nameFixShown = false;
function maybeNameFix() {
  const fix = state.nameFix;
  if (!fix || !fix.suggest || __nameFixShown) { return; }
  if (state.role !== 'user' && state.role !== 'subadmin') { return; }
  __nameFixShown = true;
  prompt_(t('请更新你的用户名'),
    '你现在的用户名是「<b>' + esc(fix.current) + '</b>」，其中含有换行或异常装饰字符，'
    + '不符合当前的用户名规范，可能导致页面显示异常。<br>已为你填好一个建议名，可直接使用或自行修改。',
    t('保存并继续'), fix.suggest).then(async function (v) {
    if (v === null) { return; }                    // 稍后再说
    const name = String(v).trim();
    if (!name) { toast('用户名不能为空', 'err'); __nameFixShown = false; return; }
    try {
      const r = await api('auth', 'rename', { username: name });
      state.username = r.username || name;
      state.nameFix = null;
      try {
        const vv = await api('auth', 'verify');   // 头像按用户名生成，一并刷新
        state.avatar = vv.avatar || '';
        state.settings = vv.settings || state.settings;
        state.nameFix = vv.name_fix || null;
      } catch (e) {}
      toast('用户名已更新为「' + state.username + '」');
      renderDrawer();
      if (typeof window.__reRenderCurrent === 'function') { window.__reRenderCurrent(); }
    } catch (e) {
      toast(e.message, 'err');
      __nameFixShown = false;                      // 失败允许立即重试
    }
  });
}
window.__nameFixCheck = maybeNameFix;             // 登录成功后由登录页调用

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
    const d = await api('start', 'app', null, { silent: true, tries: 1 });
    state.ban  = (d && d.ban)  ? d.ban  : null;
    state.disc = (d && d.disc) ? d.disc : null;
    saveBanCache(state.ban);      // 缓存封禁状态：刷新 / 切页立即可见，解封自动清除
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

  await i18nInit();        // 语言：本地选择 > 浏览器首选 > 中文；译文取 assets/i18n/
  applyI18nDom(document);  // 服务端渲染的静态文案（底部导航等）
  applyI18nText(document.body); // 首屏已有的文本节点（公告横幅等不在路由容器里）
  watchI18n(document.body);     // 异步插入的内容（列表、公告、人机验证组件）也要翻译

  setNavigate(navigate);
  initSystemWatcher();

  setUnauthorizedHandler(() => {
    if (state.role === 'guest') { return; }        // 游客已在 core.js 内静默续期，走到这里无需处理
    setToken('');
    state.role = 'guest';
    state.username = t('游客');
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
  /* 首屏地址规范化：旧式 hash / 查询参数一律改写为普通路径；根地址即榜单。
     敏感界面不接受通过地址直达（站内入口仍可正常进入）。 */
  const initial = readRoute() || '#/rank';
  const initialPage = String(initial || '').replace(/^#\/?/, '').split('?')[0].split('/')[0];
  const safeInitial = (SENSITIVE_PAGES.indexOf(initialPage) < 0) ? initial : '#/mine';
  history.replaceState({}, '', routeToUrl(safeInitial));
  await route();
  maybeNameFix();          // 存量用户名待更新：提醒并引导改名（不阻断功能）
  startPolling();
  guardStart();            // 运行时完整性守卫：只记录、不干预（扩展注入一律放行）

  // 首次交互时请求通知权限
  bindToTop();   // 通知权限改为在「我的 → 设置」中显式开启
}

main();

export { state, icon, I, navigate };
