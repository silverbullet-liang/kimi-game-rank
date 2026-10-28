import { SITE_BASE } from './base.js';
/**
 * 路由桥：页面模块统一从这里取 navigate，避免与 app.js 形成循环依赖
 */
const BASE = SITE_BASE;

/* 兜底实现：路由（#/name/arg?q）→ 普通路径（/name/arg?q）。app.js 启动后会用 setNavigate 覆盖 */
let impl = function (hash) {
  const raw = String(hash || '').replace(/^#\/?/, '');
  const i = raw.indexOf('?');
  const seg = (i < 0 ? raw : raw.slice(0, i)).split('/').filter(Boolean);
  const q = i < 0 ? '' : raw.slice(i);
  const RM = (typeof window !== 'undefined' && window.__ROUTES) ? window.__ROUTES : {};
  const SEG = RM.seg || {};
  const DOC = RM.docSlug || {};
  const name = seg[0] || 'rank';
  let arg = seg.slice(1).join('/');
  if (name === 'doc' && arg) { arg = DOC[arg] || arg; }
  const path = '/' + encodeURIComponent(SEG[name] || name) + (arg ? '/' + arg.split('/').map(encodeURIComponent).join('/') : '');
  history.pushState({ route: String(hash) }, '', BASE + path + q);
};

export function setNavigate(fn) { impl = fn; }

export function navigate(hash) { return impl(hash); }
