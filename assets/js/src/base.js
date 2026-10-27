/**
 * 站点路径前缀 —— 路径式路由（/detail/12、/doc/入榜规则）下，浏览器会以「当前页面目录」
 * 为基准解析相对地址，`assets/js/app.js` 会变成 `/detail/assets/js/app.js`。
 * 因此静态资源与接口一律走绝对路径，子目录部署时自动带上前缀（由 index.php 下发）。
 */
export const SITE_BASE  = (typeof window !== 'undefined' && window.__BASE) ? String(window.__BASE) : '';
export const API_BASE   = SITE_BASE + '/api';
export const ASSET_BASE = SITE_BASE + '/assets';
