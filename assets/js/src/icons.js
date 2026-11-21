/**
 * 图标注册表 —— 全站图标唯一来源
 * ------------------------------------------------------------
 * 三种来源：
 *   ① 内置：24×24 viewBox 的 SVG path（默认值，永远可用）
 *   ② 主题覆盖：主题 JSON 按「位置名」注入 img: / data: / css| 前缀
 *   ③ 静态槽位：页面写死的位置用 <span class="ic-slot" data-ic="位置名">，
 *      主题切换后由 MutationObserver 自动换掉，无需重绘整页。
 * 页面统一走 T(slot, base) 或 IC(slot)。
 */
const I = {
  rank: 'M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 5.2 12H4a3 3 0 0 1 0-6h2V3z',
  play: 'M8 5v14l11-7z',
  send: 'M3 20l18-8L3 4v6l12 2-12 2v6z',
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
  /* —— 以下为 v3.61.3 新增：把 75 个位置全部落到真实图标 —— */
  chevronLeft: 'M15 5l-7 7 7 7 1.4-1.4L10.8 12l5.6-5.6z',
  chevronDown: 'M7 10l5 5 5-5z',
  close: 'M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z',
  more: 'M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
  star: 'M12 2.6l3 6.1 6.7 1-4.9 4.7 1.2 6.6L12 17.8 5.9 21l1.2-6.6-4.9-4.7 6.7-1z',
  comment: 'M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 4V6a2 2 0 0 1 2-2z',
  reply: 'M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z',
  copy: 'M8 3h9a2 2 0 0 1 2 2v11h-2V5H8V3zM5 7h9a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z',
  share: 'M18 16a3 3 0 0 0-2 .8l-7-4a3 3 0 0 0 0-1.6l7-4A3 3 0 1 0 15 5l-7 4a3 3 0 0 0 0 2l7 4A3 3 0 1 0 18 16z',
  edit: 'M3 17.2V21h3.8L17.8 10 14 6.2 3 17.2zM20.7 7.1a1 1 0 0 0 0-1.4l-2.4-2.4a1 1 0 0 0-1.4 0l-1.8 1.8L18.9 8.9l1.8-1.8z',
  trash: 'M6 7h12l-1 13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7zm3-4h6l1 2h4v2H4V5h4l1-2z',
  flag: 'M5 3h10l4 4v6h-7l-3 3H5V3zm2 2v9h1l3-3h6V8l-3-3H7z',
  stop: 'M6 6h12v12H6z',
  retry: 'M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z',
  download: 'M11 3h2v9l3.5-3.5 1.4 1.4L12 16 6.1 9.9l1.4-1.4L11 12V3zM5 19h14v2H5z',
  expand: 'M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z',
  eye: 'M12 5c5 0 9 4.5 9 7s-4 7-9 7-9-4.5-9-7 4-7 9-7zm0 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
  eyeOff: 'M2 4.3 3.4 3l17.6 17.6-1.4 1.3-3.2-3.2A9.9 9.9 0 0 1 12 19c-5 0-9-4.5-9-7 0-1.4 1.3-3.4 3.4-4.8L2 5.6V4.3zM12 7c5 0 9 4.5 9 7 0 1.2-.9 2.6-2.4 3.7l-3-3A4 4 0 0 0 12 7z',
  smile: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM8.5 9.5a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zm7 0a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zM7.6 14.2a1 1 0 0 1 1.4-.1 4.6 4.6 0 0 0 3.4 1.5 4.6 4.6 0 0 0 3.4-1.5 1 1 0 0 1 1.4 1.4A6.6 6.6 0 0 1 12 17a6.6 6.6 0 0 1-4.8-2.8z',
  image: 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 12h14l-4.5-6-3.5 4.5-2.5-2.5L5 17zm3.5-7a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2z',
  video: 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 2v10h14V7H5zm3.4 1.2 6.2 3.8-6.2 3.8V8.2z',
  upload: 'M12 3l5.4 5.6L16 10l-4-4v8h-2V6l-4 4-1.4-1.4L12 3zM5 19h14v2H5z',
  at: 'M16 12v1.5a4.5 4.5 0 0 1-9 0V12a5 5 0 1 1 10 0v1a2.5 2.5 0 1 1-5 0v-1h2v1a.5.5 0 0 0 1 0V12a3 3 0 1 0-6 0v1.5a2.5 2.5 0 0 0 5 0V12h2z',
  cpu: 'M9 2h2v2h2V2h2v2h3a1 1 0 0 1 1 1v3h2v2h-2v2h2v2h-2v3a1 1 0 0 1-1 1h-3v2h-2v-2h-2v2H9v-2H6a1 1 0 0 1-1-1v-3H3v-2h2v-2H3V8h2V5a1 1 0 0 1 1-1h3V2zm-2 5v10h10V7H7z',
  brain: 'M12 3a7 7 0 0 0-4 12.7V19a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3.3A7 7 0 0 0 12 3zM9 22h6v1H9v-1z',
  filter: 'M3 5h18l-7 8v6l-4 2v-8L3 5z',
  sort: 'M3 6h13v2H3V6zm0 5h10v2H3v-2zm0 5h7v2H3v-2zM17 8l4 4h-3v6h-2v-6h-3l4-4z',
  refresh: 'M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z',
  pause: 'M7 5h3v14H7V5zm7 0h3v14h-3V5z',
  fullscreen: 'M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z',
  mute: 'M4 9h4l5-4v14l-5-4H4V9zm12.5 3 2.8-2.8 1.4 1.4-2.8 2.8 2.8 2.8-1.4 1.4-2.8-2.8-2.8 2.8-1.4-1.4 2.8-2.8-2.8-2.8 1.4-1.4 2.8 2.8z',
  spinner: 'M12 3a9 9 0 1 0 9 9h-2a7 7 0 1 1-7-7V3z',
  inbox: 'M5 3h14l3 10v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6L5 3zm0 2L3 13h5l1.5 2h5L16 13h5L19 5H5z',
  alert: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z',
  check: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1 14.4-4-4 1.4-1.4 2.6 2.6 5.6-5.6L18 9.4l-7 7z',
  warning: 'M12 2 1 21h22L12 2zm1 15h-2v-2h2v2zm0-4h-2V9h2v4z',
  offline: 'M2.1 3.5 3.5 2.1l18.4 18.4-1.4 1.4-3-3H12v-2h3.6l-2.2-2.2H12v-2h.1L12 10.9A6 6 0 0 0 8.5 17H8v2h1.5A8 8 0 0 0 4.2 12.3L2.1 3.5zM12 4c3.5 0 6.7 1.4 9 3.6l-1.5 1.5A9.9 9.9 0 0 0 12 6 9.9 9.9 0 0 0 4.5 9.1L3 7.6A12.9 12.9 0 0 1 12 4z',
  badge: 'M12 2l2.4 2 3.1-.3.9 3 2.7 1.7-1.4 2.9 1.4 2.9-2.7 1.7-.9 3-3.1-.3L12 22l-2.4-2-3.1.3-.9-3-2.7-1.7L4.3 12 2.9 9.1l2.7-1.7.9-3 3.1.3L12 2zm-1 13.5 5-5-1.4-1.4L11 12.7 8.4 10.1 7 11.5l4 4z',
  palette: 'M12 3a9 9 0 0 0 0 18h1.5a2 2 0 0 0 0-4H12a2 2 0 0 1 0-4h2a7 7 0 0 0 7-7c0-1.7-1.3-3-3-3h-2a1 1 0 0 1-1-1c0-1.1-.9-2-2-2h-1zM7.5 10a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm3-4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm3.5 4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z',
  globe: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm6.9 7h-3a15.6 15.6 0 0 0-1.4-3.6A8 8 0 0 1 18.9 9zM12 4.1c.7 1 1.3 2.4 1.7 4.9h-3.4c.4-2.5 1-3.9 1.7-4.9zM4.1 11h3c.1 1.2.3 2.4.7 3.5H4.5a8 8 0 0 1-.4-3.5zm3.5 5.5h2.4c.4 1.3.9 2.5 1.5 3.3A8 8 0 0 1 7.6 16.5zM9.1 6.4A15.6 15.6 0 0 0 7.7 9h-3a8 8 0 0 1 4.4-2.6zM17 15.5h3a8 8 0 0 1-4.5 2.6c.6-1 1.1-2.1 1.5-3.4.3-1.1.5-2.2.6-3.2h-.1c-.1 1.2-.2 2.4-.5 3.4zM14.4 4.4c.6.8 1.1 2 1.5 3.6h3.3a8 8 0 0 0-3.3-3.3l-1.5-.3z',
  sun: 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10zM12 1h1.5v3H12V1zm0 19h1.5v3H12v-3zM1 12h3v1.5H1V12zm19 0h3v1.5h-3V12zM4.2 2.8 5.6 4.2 3.4 6.4 2 5zm16.4 12.4 1.4 1.4-2.2 2.2-1.4-1.4zM22 5l-1.4-1.4-2.2 2.2 1.4 1.4zM3.4 17.6l2.2 2.2L7 18.4 4.8 16.2z',
  moon: 'M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z',
  auto: 'M12 2a10 10 0 1 0 0 20V2zm0 2v16a8 8 0 0 1 0-16z',
  menu: 'M4 7h16v2H4V7zm0 4h16v2H4v-2zm0 4h16v2H4v-2z',
  plus: 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5z',
  activity: 'M3 12h4l3 8 4-16 3 8h4v2h-5.5l-1.7-4.6L10 20l-3-6H3z',

  /* 登录设备图标：形状差异要一眼能分，不靠颜色 */
  monitor: 'M3 4h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1h-7v3h3v2H7v-2h3v-3H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm1 2v8h16V6H4z',
  phone: 'M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm0 2v14h8V4H8zm3 15.2h2v1.4h-2v-1.4z',
  tablet: 'M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm0 2v14h12V4H6zm5 15h2v1.4h-2v-1.4z',
  tv: 'M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-6.6l2.6 3-1.6 1.2L11 17.5l-3.4 3.7L6 20l2.6-3H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm0 2v9h16V6H4z',
  car: 'M5.5 6l1.6-3h9.8l1.6 3H20a2 2 0 0 1 2 2v9a1 1 0 0 1-1 1h-1v2h-2v-2H6v2H4v-2H3a1 1 0 0 1-1-1V8a2 2 0 0 1 2-2h1.5zM8 8.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm8 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z',
  kimi: 'M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-1.2 5.5v9l2.6-2.6 2 2.6 1.7-1.3-2-2.6 3.1-.6-1-2.3-6.4 1.4V7.5z',};

/**
 * 位置 → 内置图标键。共 75 个位置，与 theme-json.js 的 SLOTS 一一对应。
 * 主题 JSON 的 icons 字段用这些键做位置名。
 */
const SLOT_KEYS = {
  'nav.total': 'rank', 'nav.vote': 'vote', 'nav.gods': 'crown', 'nav.cold': 'snow',
  'nav.chat': 'comment', 'nav.submit': 'plus', 'nav.mine': 'users', 'nav.help': 'doc',
  'nav.feedback': 'mail', 'nav.admin': 'shield', 'nav.monitor': 'activity', 'nav.today': 'info',
  'action.back': 'chevronLeft', 'action.close': 'close', 'action.more': 'more',
  'action.vote': 'heart', 'action.star': 'star', 'action.comment': 'comment',
  'action.reply': 'reply', 'action.copy': 'copy', 'action.share': 'share',
  'action.external': 'plug', 'action.edit': 'edit', 'action.delete': 'trash',
  'action.report': 'flag', 'action.send': 'send', 'action.stop': 'stop',
  'action.retry': 'retry', 'action.download': 'download', 'action.expand': 'expand',
  'tab.rank': 'rank', 'tab.chat': 'comment', 'tab.mine': 'users',
  'ui.menu': 'menu', 'ui.theme': 'gear',
  'field.search': 'search', 'field.password': 'eye', 'field.emoji': 'smile',
  'field.image': 'image', 'field.video': 'video', 'field.upload': 'upload',
  'field.mention': 'at', 'field.model': 'cpu', 'field.think': 'brain',
  'field.filter': 'filter', 'field.sort': 'sort', 'field.refresh': 'refresh',
  'media.play': 'play', 'media.pause': 'pause', 'media.fullscreen': 'fullscreen',
  'media.mute': 'mute',
  'state.loading': 'spinner', 'state.empty': 'inbox', 'state.error': 'alert',
  'state.success': 'check', 'state.warn': 'warning', 'state.offline': 'offline',
  'user.login': 'login', 'user.logout': 'logout', 'user.avatar': 'users',
  'user.admin': 'shield', 'user.verified': 'badge',
  'theme.light': 'sun', 'theme.dark': 'moon', 'theme.auto': 'auto',
  'theme.palette': 'palette', 'theme.lang': 'globe',
  'doc.community': 'rule', 'doc.terms': 'doc', 'doc.privacy': 'lock', 'doc.features': 'doc',
  'doc.criteria': 'rule', 'doc.ranking': 'list', 'doc.changelog': 'clock', 'doc.ai': 'info',
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
const STATIC_ORIG = new WeakMap();

/**
 * 生成一个可被主题替换的图标位。
 *   IC('action.vote')            → 内置心形 path
 *   IC('action.vote','tiny')     → 额外加尺寸修饰类
 * 生成的元素带 data-ic，主题切换后由观察器自动换掉，无需重绘整页。
 */
function IC(slot, mod) {
  var key = SLOT_KEYS[slot];
  var inner = '<svg viewBox="0 0 24 24" class="ic"><path d="' + escAttrJs(key ? I[key] : '') + '"/></svg>';
  return '<span class="ic-slot' + (mod ? ' ' + mod : '') + '" data-ic="' + escAttrJs(slot) + '">' + inner + '</span>';
}

/** 给单个节点套用覆盖（有覆盖则替换，无覆盖则还原） */
function icApplyNode(el) {
  if (!el || el.nodeType !== 1) { return; }
  var slot = el.getAttribute('data-ic');
  if (!slot) { return; }
  if (!STATIC_ORIG.has(el)) { STATIC_ORIG.set(el, el.innerHTML); }
  var ovr = TIcon(slot);
  if (ovr) {
    if (el.getAttribute('data-ic-ovr') !== ovr) {
      el.innerHTML = icon(ovr);
      el.setAttribute('data-ic-ovr', ovr);
    }
  } else if (el.getAttribute('data-ic-ovr')) {
    el.innerHTML = STATIC_ORIG.get(el);
    el.removeAttribute('data-ic-ovr');
  }
}

function refreshStaticIcons() {
  var nodes = document.querySelectorAll('[data-ic]');
  for (var i = 0; i < nodes.length; i++) { icApplyNode(nodes[i]); }
}

/* 空状态统一带图标：.empty 里没有 state.empty 时自动补一个，主题可整体替换。
   跳过已经手工加过的（例如评论列表、聊天页）以及空的占位容器。 */
function decorateEmpties(root) {
  var scope = root && root.querySelectorAll ? root : document;
  var nodes = scope.querySelectorAll('.empty');
  for (var i = 0; i < nodes.length; i++) {
    var el = nodes[i];
    if (el.querySelector('[data-ic="state.empty"]')) { continue; }
    if (el.querySelector('[data-ic]')) { continue; }
    el.insertAdjacentHTML('afterbegin', IC('state.empty'));
  }
}

/* 动态插入的节点（聊天、评论、弹窗都是 innerHTML 拼出来的）也要能换图标 */
let _icObserver = null;
function icObserve() {
  if (_icObserver || typeof MutationObserver === 'undefined' || !document.body) { return; }
  _icObserver = new MutationObserver(function (muts) {
    for (var i = 0; i < muts.length; i++) {
      var added = muts[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        var n = added[j];
        if (n.nodeType !== 1) { continue; }
        icApplyNode(n);
        if (n.classList && n.classList.contains('empty')) { decorateEmpties(n.parentNode || document); }
        if (n.querySelectorAll) {
          var sub = n.querySelectorAll('[data-ic]');
          for (var k = 0; k < sub.length; k++) { icApplyNode(sub[k]); }
        }
      }
    }
  });
  _icObserver.observe(document.body, { childList: true, subtree: true });
  decorateEmpties(document);
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
  try { refreshStaticIcons(); icObserve(); } catch (e) {}
}

export { I, T, icon, IC, TIcon, SLOT_KEYS, applyThemeIcons, refreshStaticIcons, decorateEmpties };

/* 页面一就绪就开观察：空状态图标与动态插入的槽位不必等主题切换 */
if (typeof document !== 'undefined') {
  if (document.body) { icObserve(); }
  else { document.addEventListener('DOMContentLoaded', icObserve); }
}
