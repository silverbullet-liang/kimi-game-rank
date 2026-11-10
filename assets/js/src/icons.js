/**
 * 图标注册表 —— 全站图标唯一来源
 * ------------------------------------------------------------
 * 两种形态：
 *   ① 内置：24×24 viewBox 的 SVG path（默认值，永远可用）
 *   ② 覆盖：主题 JSON 按「位置名」注入，值为 img: / data: / css| 前缀
 * 页面统一走 T(slot, I.xxx)，未覆盖时原样返回内置 path，零额外开销。
 */
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
  clock: 'M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm1 3v5.6l4 2.3-1 1.7-5-2.9V7h2z',
};

/** 位置 → 内置图标的键。主题 JSON 的 slot 名就是这些 key。 */
const SLOT_KEYS = {
  'nav.total': 'rank', 'nav.vote': 'vote', 'nav.gods': 'crown', 'nav.cold': 'snow',
  'nav.chat': 'list', 'nav.submit': 'list', 'nav.mine': 'users', 'nav.help': 'doc',
  'nav.feedback': 'mail', 'nav.admin': 'shield', 'nav.monitor': 'shield', 'nav.today': 'info',
  'action.search': 'search', 'action.vote': 'heart', 'action.external': 'plug',
  'action.send': 'play', 'action.back': 'logout',
  'user.login': 'login', 'user.logout': 'logout', 'user.avatar': 'users',
  'user.admin': 'shield', 'user.verified': 'shield',
  'doc.community': 'rule', 'doc.terms': 'doc', 'doc.privacy': 'lock', 'doc.features': 'doc',
  'doc.criteria': 'rule', 'doc.ranking': 'list', 'doc.changelog': 'clock', 'doc.ai': 'info',
  'theme.light': 'gear', 'theme.dark': 'gear', 'theme.auto': 'gear',
  'theme.palette': 'gear', 'theme.lang': 'gear',
  'state.loading': 'clock', 'state.empty': 'list', 'state.error': 'info',
  'state.success': 'info', 'state.warn': 'info', 'state.offline': 'info',
  'tab.rank': 'rank', 'tab.chat': 'list', 'tab.mine': 'users',
  'ui.menu': 'list', 'ui.theme': 'gear',
  'field.search': 'search', 'field.refresh': 'clock',
};

/** 当前生效的覆盖：slot → img: / data: / css|slot / path */
let OVR = {};

/** 取某位置的图标值；未覆盖时返回内置值 */
function T(slot, base) {
  var v = OVR[slot];
  return (typeof v === 'string' && v) ? v : base;
}

/**
 * 渲染图标。
 *   'img:URL' / 'data:image/...' → <img>
 *   'css|<slot>'                 → <i>（样式由主题注入的 .ti-<slot> 规则提供）
 *   其它（SVG path）              → <svg><path>
 */
function icon(x) {
  var s = (x == null) ? '' : String(x);
  if (s.slice(0, 4) === 'img:') {
    return '<img class="ic ic-img" src="' + escAttrJs(s.slice(4)) + '" alt="" draggable="false" referrerpolicy="no-referrer">';
  }
  if (s.slice(0, 5) === 'data:') {
    return '<img class="ic ic-img" src="' + escAttrJs(s) + '" alt="" draggable="false" referrerpolicy="no-referrer">';
  }
  if (s.slice(0, 4) === 'css|') {
    /* slot 里的点不能进 class 名：nav.total → ti-nav-total */
    return '<i class="ic ti-' + s.slice(4).replace(/\./g, '-') + '"></i>';
  }
  return '<svg viewBox="0 0 24 24" class="ic"><path d="' + escAttrJs(s) + '"/></svg>';
}

/** 属性转义（不依赖 DOM） */
function escAttrJs(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/'/g, '&#39;');
}

/** 取某位置的覆盖值（没有则空串） */
function TIcon(slot) {
  var v = OVR[slot];
  return (typeof v === 'string') ? v : '';
}

/* 页面上写死的图标（底部 tab、菜单按钮等 HTML 里的 SVG）：
   主题覆盖时才替换，清除主题时还原原样。 */
const STATIC_ORIG = new Map();

function refreshStaticIcons() {
  var nodes = document.querySelectorAll('[data-ic]');
  for (var i = 0; i < nodes.length; i++) {
    var el = nodes[i];
    var slot = el.getAttribute('data-ic');
    if (!STATIC_ORIG.has(el)) { STATIC_ORIG.set(el, el.innerHTML); }
    var ovr = TIcon(slot);
    el.innerHTML = ovr ? icon(ovr) : STATIC_ORIG.get(el);
  }
}

/** 主题应用时注入覆盖表；传空对象即恢复默认 */
function applyThemeIcons(map) {
  OVR = {};
  if (map && typeof map === 'object') {
    for (var k in map) {
      if (!Object.prototype.hasOwnProperty.call(map, k)) { continue; }
      if (Object.prototype.hasOwnProperty.call(SLOT_KEYS, k) && typeof map[k] === 'string' && map[k]) {
        OVR[k] = map[k];
      }
    }
  }
  /* 新位置不在 SLOT_KEYS 里也能覆盖（tab.* / ui.* 由 data-ic 直接消费） */
  if (map && typeof map === 'object') {
    for (var k2 in map) {
      if (!Object.prototype.hasOwnProperty.call(map, k2)) { continue; }
      if (typeof map[k2] === 'string' && map[k2] && !OVR[k2]) { OVR[k2] = map[k2]; }
    }
  }
  try { refreshStaticIcons(); } catch (e) {}
}
