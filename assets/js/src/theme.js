/**
 * 主题系统：深浅色 / 三主题色（蓝紫、iOS 彩色、自定义）/ 玻璃方案（CSS、WebGL）
 * 两方案共用同一份主题令牌；切换即时生效，不刷新页面。
 */
import { state, getPrefs, setPrefs, api } from './core.js';

/* ---------- 限时节日皮肤 ----------
 * 规则只在服务端一处（app/festival.php），这里只读下发结果：
 *   window.__FESTIVAL = { now: 'halloween', list: { halloween: { name, desc, dark }, ... } }
 * now 为空串 = 当前不在任何节日窗口内。每个节日一套独立主题，互不共用。 */
const FEST = (typeof window !== 'undefined' && window.__FESTIVAL) || { now: '', list: {} };

/** 全部节日（含未生效的），键即皮肤 key */
export const FESTIVALS = FEST.list || {};
/** 节日皮肤 key 列表 */
export const FESTIVAL_KEYS = Object.keys(FESTIVALS);

/** 当前生效的节日 key；无则空串 */
export function festivalNow() { return String(FEST.now || ''); }

/** 是否处于节日窗口内：节日皮肤是限时项，只在窗口内出现在外观设置里 */
export function festivalInWindow() { return festivalNow() !== ''; }

/** 设计风格：只改变「结构语言」（圆角/边框/阴影/背景/字体），主题色仍由下方 ACCENTS 控制。
    节日皮肤由服务端下发并一并并入；窗口外它不会出现在外观设置中。 */
export const SKINS = Object.assign({}, FESTIVALS, {
  glass:  { name: '液态玻璃', desc: '磨砂通透' },
  md3:    { name: 'MD3 材质', desc: 'Material You · 色面层级' },
  pixel:  { name: '像素风',   desc: '8-bit 点阵字 · 台阶角' },
  sketch: { name: '手绘风',   desc: '纸纹 · 手绘标题' },
  brutal: { name: '新粗野',   desc: '黑框 · 硬阴影 · 撞色' },
});

export const ACCENTS = {
  // 蓝紫色：以紫为主、偏蓝调
  'blue-purple': { name: '蓝紫色', accent: '#6D3BF5', accent2: '#8B5CF6' },
  // 苹果色：Apple 系统色系（系统蓝为主，联动系统绿）
  'ios-colorful': { name: '苹果色', accent: '#007AFF', accent2: '#34C759' },
  'custom': { name: '自定义', accent: '#6D3BF5', accent2: '#8B5CF6' },
};

export function hexToHsl(hex) {
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

export function hsl(h, s, l) { return `hsl(${h} ${s}% ${l}%)`; }

/** 应用主题到 DOM */
export function applyTheme(opts = {}) {
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
export async function saveTheme(patch) {
  const prefs = getPrefs();
  Object.assign(prefs, patch);
  setPrefs(prefs);
  applyTheme();
  if (state.role !== 'guest') {   // 普通用户 / 副管理员 / 管理员均可同步偏好
    try { await api('profile.php', 'settings', patch); state.settings = Object.assign({}, state.settings, patch); } catch (e) {}
  }
}

export function initSystemWatcher() {
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  const handler = () => {
    const t = state.settings.theme || getPrefs().theme || 'light';
    if (t === 'system') applyTheme();
  };
  if (mq.addEventListener) mq.addEventListener('change', handler);
  else if (mq.addListener) mq.addListener(handler);
}
