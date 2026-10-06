/*! monitor.js —— 网页异常监测 SDK（Web APM）
 *  纯 ES5，兼容低版本浏览器内核；
 *  采集：JS 错误 / 未处理 Promise / 资源加载失败 / 接口(XHR+fetch) / 页面性能 / PV / 环境；
 *  上报：批量队列 → sendBeacon ▸ XHR ▸ 静默丢弃，绝不影响主站。
 *  自定义埋点：window.__mon.track(name, value)
 */
(function () {
  if (window.__mon && window.__mon.__v) { return; }
  var CFG = window.__MON_CFG__ || {};
  var END = CFG.endpoint || 'api/monitor.php';
  var INTERVAL = CFG.interval || 10000;
  var MAXBATCH = CFG.maxBatch || 25;
  var V = '1.0.0';

  function now() { return +(new Date()); }
  function str(s, n) { s = (s == null ? '' : String(s)); return (n && s.length > n) ? s.slice(0, n) : s; }
  function addEvt(el, type, fn, cap) {
    try {
      if (el.addEventListener) { el.addEventListener(type, fn, !!cap); }
      else if (el.attachEvent) { el.attachEvent('on' + type, fn); }
    } catch (e) {}
  }
  function pathOf(u) {
    try {
      var s = String(u);
      if (s.indexOf('//') >= 0 && document.createElement) {
        var a = document.createElement('a'); a.href = s;
        return (a.pathname || s) + (a.search ? '' : '');
      }
      return s.split('?')[0];
    } catch (e) { return String(u).split('?')[0]; }
  }

  /* ---------- 环境探测 ---------- */
  var UA = navigator.userAgent || '';
  function browserOf(ua) {
    var m;
    if ((m = ua.match(/MicroMessenger\/([\d.]+)/))) { return 'WeChat ' + m[1]; }
    if ((m = ua.match(/QQBrowser\/([\d.]+)/)))      { return 'QQ ' + m[1]; }
    if ((m = ua.match(/UCBrowser\/([\d.]+)/)))      { return 'UC ' + m[1]; }
    if ((m = ua.match(/Edg\/([\d.]+)/)))            { return 'Edge ' + m[1]; }
    if ((m = ua.match(/OPR\/([\d.]+)/)) || (m = ua.match(/Opera\/([\d.]+)/))) { return 'Opera ' + m[1]; }
    if ((m = ua.match(/Firefox\/([\d.]+)/)))        { return 'Firefox ' + m[1]; }
    if ((m = ua.match(/Chrome\/([\d.]+)/)))         { return 'Chrome ' + m[1]; }
    if ((m = ua.match(/Version\/([\d.]+).*Safari/))){ return 'Safari ' + m[1]; }
    if (ua.indexOf('MSIE') >= 0 || ua.indexOf('Trident') >= 0) { return 'IE'; }
    return 'Other';
  }
  function osOf(ua) {
    if (/Windows NT/.test(ua)) { return 'Windows'; }
    if (/Android/.test(ua))    { return 'Android'; }
    if (/iPhone|iPad|iPod/.test(ua)) { return 'iOS'; }
    if (/Mac OS X/.test(ua))   { return 'macOS'; }
    if (/Linux/.test(ua))      { return 'Linux'; }
    return 'Other';
  }
  function netOf() {
    try {
      var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      if (c && c.effectiveType) { return c.effectiveType; }
      if (c && c.type) { return c.type; }
    } catch (e) {}
    return '';
  }
  var PAGE = (location.pathname || '') + (location.search || '');
  var ENV = {
    page: str(PAGE, 191),
    browser: str(browserOf(UA), 32),
    os: str(osOf(UA), 32),
    screen: window.screen ? (screen.width + 'x' + screen.height) : '',
    net: str(netOf(), 16),
    uid: (CFG.uid | 0) || 0
  };

  /* ---------- 会话 id ---------- */
  var SID = (function () {
    var k = 'kimgr_sid', v = '';
    try { v = localStorage.getItem(k) || ''; } catch (e) {}
    if (!v) {
      v = 's' + now().toString(36) + Math.random().toString(36).slice(2, 8);
      try { localStorage.setItem(k, v); } catch (e) {
        try { var d = new Date(); d.setTime(d.getTime() + 7 * 864e5);
          document.cookie = k + '=' + v + ';expires=' + d.toUTCString() + ';path=/'; } catch (e2) {}
      }
    }
    return str(v, 40);
  })();

  /* ---------- 队列 + 去重 ---------- */
  var queue = [], seen = {};
  function push(kind, ev) {
    ev.kind = kind;
    if (ev.name != null) { ev.name = str(ev.name, 191); }
    if (ev.msg != null)  { ev.msg  = str(ev.msg, 255); }
    if (ev.stack != null){ ev.stack = str(ev.stack, 2000); }
    var key = kind + '|' + (ev.name || '') + '|' + (ev.msg || '') + '|' + (ev.v1 || 0);
    var s = seen[key];
    if (s && s.t > now() - 5000 && queue[s.i]) {
      queue[s.i].v2 = (queue[s.i].v2 || 0) + 1;   // 5 秒内同类合并计数
      s.t = now();
      return;
    }
    seen[key] = { i: queue.length, t: now() };
    queue.push(ev);
    if (queue.length >= MAXBATCH) { flush(false); }
  }

  /* ---------- 上报 ---------- */
  var sending = false;
  function flush(beacon) {
    if (!queue.length || sending) { return; }
    sending = true;
    var batch = queue.splice(0, queue.length); seen = {};
    var payload = { sid: SID, page: ENV.page, browser: ENV.browser, os: ENV.os, screen: ENV.screen, net: ENV.net, uid: ENV.uid, events: batch };
    var url = END + '?action=collect';
    var json;
    try { json = JSON.stringify(payload); } catch (e) { sending = false; return; }

    if (beacon && navigator.sendBeacon) {
      try {
        if (window.Blob) {
          if (navigator.sendBeacon(url, new Blob([json], { type: 'application/json' }))) { sending = false; return; }
        } else if (navigator.sendBeacon(url, json)) { sending = false; return; }
      } catch (e) {}
    }
    try {
      var xhr = window.XMLHttpRequest ? new XMLHttpRequest()
              : (window.ActiveXObject ? new ActiveXObject('Microsoft.XMLHTTP') : null);
      if (xhr) {
        xhr.open('POST', url, true);
        if (xhr.setRequestHeader) { xhr.setRequestHeader('Content-Type', 'application/json'); }
        xhr.onreadystatechange = function () { if (xhr.readyState === 4) { sending = false; } };
        xhr.send(json);
        return;
      }
    } catch (e) {}
    sending = false;
  }

  /* ---------- 采集：JS 错误 / 资源失败 ---------- */
  var RES = /^(IMG|SCRIPT|LINK|VIDEO|AUDIO|SOURCE|IFRAME|OBJECT|EMBED)$/;
  function onError(e) {
    try {
      var t = e.target || e.srcElement;
      var tn = (t && t.tagName) ? String(t.tagName).toUpperCase() : '';
      if (tn && tn !== 'WINDOW' && RES.test(tn)) {
        var u = t.src || t.href || '';
        if (u && u.indexOf(END) < 0) {
          push('resource', { name: pathOf(u), msg: tn + ' 加载失败', status: 'fail', level: 'warn' });
        }
        return;
      }
      var msg = e.message || (e.error && e.error.message) || 'script error';
      var stack = (e.error && e.error.stack) ? String(e.error.stack) : '';
      push('js', { name: pathOf(e.filename) || ENV.page, msg: msg, stack: stack, v1: e.lineno || 0, status: 'error' });
    } catch (ex) {}
  }
  function onReject(e) {
    try {
      var r = e.reason;
      var msg = (r && (r.message || r.msg)) || String(r || 'unhandledrejection');
      push('js', { name: ENV.page, msg: 'Promise: ' + msg, stack: (r && r.stack) ? String(r.stack) : '', status: 'error' });
    } catch (ex) {}
  }

  /* ---------- 采集：接口（XHR + fetch） ---------- */
  function isSelf(u) { var s = String(u || ''); return !s || s.indexOf(END) >= 0 || s.indexOf('action=collect') >= 0; }
  function reportApi(u, method, status, ms) {
    var ok = (status >= 200 && status < 400);
    push('api', { name: pathOf(u), msg: method + ' ' + (status || 'ERR'), v1: ms, v2: status || 0,
      status: ok ? 'ok' : 'fail', level: (ms > 1000 || !ok) ? 'warn' : 'info' });
  }
  try {
    var XP = window.XMLHttpRequest && window.XMLHttpRequest.prototype;
    if (XP && XP.open && XP.send) {
      var oOpen = XP.open, oSend = XP.send;
      XP.open = function (m, u) { try { this.__mu = u; this.__mm = m; this.__md = 0; } catch (e) {} return oOpen.apply(this, arguments); };
      XP.send = function () {
        var x = this;
        if (!isSelf(x.__mu)) {
          var t0 = now();
          var done = function () {
            if (x.__md) { return; } x.__md = 1;
            var st = 0; try { st = x.status || 0; } catch (e) {}
            reportApi(x.__mu, x.__mm || 'GET', st, now() - t0);
          };
          if (x.addEventListener) {
            x.addEventListener('load', done); x.addEventListener('error', done);
            x.addEventListener('abort', done); x.addEventListener('timeout', done);
          } else {
            var old = x.onreadystatechange;
            x.onreadystatechange = function () { if (x.readyState === 4) { done(); } if (old) { try { old.apply(this, arguments); } catch (e) {} } };
          }
        }
        return oSend.apply(this, arguments);
      };
    }
  } catch (e) {}
  try {
    var oFetch = window.fetch;
    if (oFetch) {
      window.fetch = function (input, init) {
        var u = (typeof input === 'string') ? input : (input && input.url) || '';
        var self = isSelf(u), t0 = now();
        var pr = oFetch.apply(this, arguments);
        if (!self && pr && pr.then) {
          pr.then(function (resp) { reportApi(u, (init && init.method) || 'GET', (resp && resp.status) || 0, now() - t0); return resp; },
                  function (err) { reportApi(u, (init && init.method) || 'GET', 0, now() - t0); throw err; });
        }
        return pr;
      };
    }
  } catch (e) {}

  /* ---------- 采集：页面性能 ---------- */
  function perf() {
    try {
      var t = (window.performance && performance.timing) ? performance.timing : null;
      if (t && t.navigationStart) {
        var nav = t.navigationStart;
        if (t.loadEventEnd) { push('perf', { name: 'load', v1: t.loadEventEnd - nav, status: 'ok' }); }
        if (t.domContentLoadedEventEnd) { push('perf', { name: 'tti', v1: t.domContentLoadedEventEnd - nav, status: 'ok' }); }
      }
      if (window.performance && performance.getEntriesByType) {
        var ps = performance.getEntriesByType('paint') || [];
        for (var i = 0; i < ps.length; i++) {
          if (ps[i].name === 'first-contentful-paint') { push('perf', { name: 'fcp', v1: Math.round(ps[i].startTime), status: 'ok' }); }
        }
      }
    } catch (e) {}
  }
  try {
    if (window.PerformanceObserver) {
      var lcp = 0;
      var po = new PerformanceObserver(function (list) {
        var es = (list.getEntries ? list.getEntries() : []) || [];
        for (var i = 0; i < es.length; i++) { lcp = es[i].startTime || lcp; }
      });
      try { po.observe({ type: 'largest-contentful-paint', buffered: true }); } catch (e) {}
      addEvt(window, 'pagehide', function () { if (lcp) { push('perf', { name: 'lcp', v1: Math.round(lcp), status: 'ok' }); } });
    }
  } catch (e) {}

  /* ---------- 启动 ---------- */
  addEvt(window, 'error', onError, true);
  addEvt(window, 'unhandledrejection', onReject);
  if (!window.addEventListener && window.attachEvent) {
    window.attachEvent('onerror', function (m, s, l) { onError({ message: m, filename: s, lineno: l }); });
  }

  push('pv', { name: ENV.page, status: 'ok' });   // PV
  addEvt(window, 'load', function () { setTimeout(perf, 0); });

  setInterval(function () { flush(false); }, INTERVAL);
  addEvt(document, 'visibilitychange', function () { if (document.visibilityState === 'hidden') { flush(true); } });
  addEvt(window, 'pagehide', function () { flush(true); });

  window.__mon = { __v: V, track: function (n, v) { push('custom', { name: str(n, 191), v1: (v | 0) || 0, status: 'ok' }); }, flush: flush };
})();
