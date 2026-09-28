/**
 * 路由桥：页面模块统一从这里取 navigate，避免与 app.js 形成循环依赖
 */
let impl = function (hash) { history.pushState({ route: String(hash) }, '', '?p=' + encodeURIComponent(String(hash).replace(/^#\/?/, '').split('?')[0] || 'rank')); };

export function setNavigate(fn) { impl = fn; }

export function navigate(hash) { return impl(hash); }
