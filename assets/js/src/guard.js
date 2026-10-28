/**
 * 运行时完整性守卫（v3.51.0）
 * ------------------------------------------------------------
 * 借鉴「反反广告」的两条经典手法，用途相反 —— 它们用 bait 与 DOM 观测抓拦截器，
 * 这里用同样的观测能力抓「我们自己被改」：
 *   1) 关键节点被移除 / 被替换（减法监控）；
 *   2) 原生 API 被 hook（fetch / XHR / localStorage.setItem 不再是 native code）。
 *
 * 刻意**只做减法**：页面上新增的第三方节点（图片下载器、翻译、样式管理器等扩展注入）
 * 一律不记录、不干预 —— 正常扩展照常工作，这是白名单，而不是黑名单。
 * 命中只上报一次，不阻断任何功能：真正的权限判断始终在服务端。
 */
import { API_BASE } from './base.js';

const WATCH_NODES = ['#app', '#view', '.tabbar', '#btnMenu'];

const WATCH_NATIVES = [
  ['fetch', function () { return window.fetch; }],
  ['XMLHttpRequest.open', function () { return XMLHttpRequest.prototype.open; }],
  ['XMLHttpRequest.send', function () { return XMLHttpRequest.prototype.send; }],
  ['localStorage.setItem', function () { return Storage.prototype.setItem; }],
];

let reported = false;
let timer = null;

function isNative(fn) {
  try {
    return /\{\s*\[native code\]\s*\}[\s\S]*$/.test(Function.prototype.toString.call(fn));
  } catch (e) {
    return true;                     // 取不到描述就当作正常，避免误报
  }
}

/** 扫描一次：返回 {nodes: [缺失的选择器], natives: [被替换的 API]} */
export function guardScan() {
  const nodes = [];
  for (let i = 0; i < WATCH_NODES.length; i++) {
    const sel = WATCH_NODES[i];
    if (!document.querySelector(sel)) { nodes.push(sel); }
  }
  const natives = [];
  for (let i = 0; i < WATCH_NATIVES.length; i++) {
    const pair = WATCH_NATIVES[i];
    let fn = null;
    try { fn = pair[1](); } catch (e) { fn = null; }
    if (typeof fn !== 'function') { continue; }
    /* 自家埋点（monitor.js 对 fetch / XHR 的包装）带 __kimgr 标记：区分「我们自己的包装」与「外来 hook」 */
    if (fn.__kimgr) { continue; }
    if (!isNative(fn)) { natives.push(pair[0]); }
  }
  return { nodes: nodes, natives: natives };
}

function report(hit) {
  if (reported) { return; }
  if (!hit.nodes.length && !hit.natives.length) { return; }
  reported = true;
  try {
    const body = JSON.stringify({
      nodes: hit.nodes.slice(0, 6),
      natives: hit.natives.slice(0, 6),
      ua: String(navigator.userAgent || '').slice(0, 180),
      at: Date.now(),
    });
    const url = API_BASE + '/site?action=harden';
    if (navigator.sendBeacon) {
      navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
    } else {
      fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true })
        .catch(function () {});
    }
  } catch (e) { /* 上报失败不影响任何功能 */ }
  try { window.__HARDEN = hit; } catch (e) {}
}

export function guardStart() {
  const once = function () {
    const hit = guardScan();
    report(hit);
    if (reported && timer) { clearInterval(timer); timer = null; }
  };
  setTimeout(once, 1200);                                  // 等首屏渲染完再看
  timer = setInterval(function () {
    if (document.visibilityState === 'visible') { once(); }
  }, 60000);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { once(); }
  });
}
