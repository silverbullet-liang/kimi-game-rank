/**
 * 网页异常监测 · 独立页面（仅管理员）
 * ------------------------------------------------------------
 * 与站点主题/皮肤完全隔离：固定「蓝白 / 蓝黑」两套配色，可切换，互不影响。
 * 数据来自 api/monitor.php（stats / events / settings / purge）。
 */
import { api, esc, isAdminish, toast, btnLoading, state } from '../core.js';
import { MON_DEFAULT } from '../config.js';

const MON_TABS = [
  ['overview', '总览'], ['js', 'JS 错误'], ['api', '接口'], ['srv', '服务端'],
  ['perf', '加载性能'], ['resource', '资源'], ['session', '会话追踪'],
  ['custom', '自定义上报'], ['alerts', '告警'], ['settings', '设置'],
];
const st = { range: '7d', tab: 'overview', sessOnly: 'all' };

export async function renderMonitor(container) {
  if (!isAdminish()) {
    container.innerHTML = '<div class="empty"><p>无权限访问</p></div>';
    return;
  }
  container.innerHTML = `
    <div class="mon-app">
      <div class="mon-top">
        <div class="mon-title">网页异常监测</div>
        <div class="mon-top-r">
          <div class="mon-seg" id="monRange">
            <button data-r="1h">1 时</button>
            <button data-r="24h">24 时</button>
            <button data-r="7d" class="on">7 天</button>
            <button data-r="30d">30 天</button>
          </div>
          <button class="mon-btn ghost" id="monExport" type="button">导出 JSON</button>
        </div>
      </div>
      <div class="mon-seg mon-tabs" id="monTabs">
        ${MON_TABS.map(([k, n]) => `<button type="button" data-t="${k}"${k === 'overview' ? ' class="on"' : ''}>${n}</button>`).join('')}
      </div>
      <div id="monBody"><div class="mon-sk"></div></div>
    </div>`;

  const exportBtn = container.querySelector('#monExport');
  if (exportBtn) { exportBtn.addEventListener('click', () => monExport(exportBtn)); }

  const tabs = container.querySelector('#monTabs');
  tabs.addEventListener('click', e => {
    const b = e.target.closest('[data-t]'); if (!b) { return; }
    st.tab = b.dataset.t;
    tabs.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    monRenderBody(container);
  });
  const rng = container.querySelector('#monRange');
  rng.addEventListener('click', e => {
    const b = e.target.closest('[data-r]'); if (!b) { return; }
    st.range = b.dataset.r;
    rng.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    monRenderBody(container);
  });

  monRenderBody(container);
}

/** 一键导出当前范围的监测数据（JSON 文件） */
async function monExport(btn) {
  btnLoading(btn, true);
  try {
    const url = new URL('api/monitor.php', location.href);
    url.searchParams.set('action', 'export');
    url.searchParams.set('range', st.range);
    const h = { 'Accept': 'application/json' };
    if (state.token) { h['Authorization'] = 'Bearer ' + state.token; h['X-Token'] = state.token; }
    if (state.csrf) { h['X-CSRF-Token'] = state.csrf; }
    const r = await fetch(url, { headers: h, credentials: 'same-origin' });
    if (!r.ok) { throw new Error('导出失败（HTTP ' + r.status + '）'); }
    const blob = await r.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'kimi-game-rank-monitor-' + new Date().toISOString().slice(0, 10) + '.json';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    toast('已导出当前范围数据');
  } catch (e) { toast(e.message, 'err'); }
  finally { btnLoading(btn, false); }
}

async function monRenderBody(container) {
  const box = container.querySelector('#monBody');
  if (!box) { return; }
  box.innerHTML = '<div class="mon-sk"></div>';
  try {
    if (st.tab === 'overview') { return await monOverview(box); }
    if (st.tab === 'settings') { return await monSettings(box); }
    if (st.tab === 'srv') { return await monSrv(box); }
    if (st.tab === 'session') { return await monSessions(box); }
    if (st.tab === 'alerts') { return await monAlerts(box); }
    await monEvents(box, st.tab);
  } catch (e) {
    box.innerHTML = `<div class="mon-empty">${esc(e.message)}</div>`;
  }
}

/** 单个指标卡；tone: '' | 'ok' | 'warn' | 'bad' —— 决定数值配色 */
function monKpi(label, value, tone) {
  const t = tone ? ' mk-' + tone : '';
  return `<div class="mon-kpi${t}"><div class="mk-v">${esc(String(value))}</div><div class="mk-l">${esc(label)}</div></div>`;
}
/** 指标分组（带小标题），一眼分清「流量 / 性能 / 错误」 */
function monKpiGroup(title, items) {
  return `<div class="mon-kgroup"><div class="mon-kgroup-t">${esc(title)}</div>
    <div class="mon-kpis">${items.join('')}</div></div>`;
}
/** 顶部健康总览：把最该关注的指标聚成一句结论 + 状态色 */
function monHealth(kpis) {
  const fmt = (list, cls) => list
    .map(k => `<i class="${cls}">${esc(k.label)} ${esc(String(k.value))}</i>`).join(' · ');
  const bad = kpis.filter(k => k.tone === 'bad');
  const warn = kpis.filter(k => k.tone === 'warn');
  if (bad.length) {
    return `<div class="mon-health bad"><span class="mh-dot"></span>
      <b>需要处理</b><span class="mh-txt">${fmt(bad, 'mh-bad')}${warn.length ? '；' + fmt(warn, 'mh-warn') : ''}</span></div>`;
  }
  if (warn.length) {
    return `<div class="mon-health warn"><span class="mh-dot"></span>
      <b>有波动</b><span class="mh-txt">${fmt(warn, 'mh-warn')}</span></div>`;
  }
  return `<div class="mon-health ok"><span class="mh-dot"></span>
    <b>一切正常</b><span class="mh-txt">当前范围内未发现异常指标</span></div>`;
}
/** 阈值判定：>0 即异常 / 超过阈值即警告 */
function toneOf(v, warnAt, badAt) {
  const n = Number(v) || 0;
  return n >= badAt ? 'bad' : (n >= warnAt ? 'warn' : '');
}
function monCard(title, inner) {
  return `<div class="mon-card"><div class="mon-card-t">${esc(title)}</div>${inner}</div>`;
}
function monListCard(title, rows) {
  const items = (rows && rows.length)
    ? rows.map(r => `<li><span class="mon-li-n" title="${esc(String(r[0]))}">${esc(String(r[0] || '—'))}</span><b>${esc(String(r[1]))}</b></li>`).join('')
    : '<li class="mon-li-n">暂无数据</li>';
  return monCard(title, `<ul class="mon-ul">${items}</ul>`);
}
/** 面积趋势图（网格 + 峰值点 + 均值/峰值副标）—— unit 决定副标单位 */
function monAreaChart(trend, getV, unit) {
  if (!trend || !trend.length) { return '<div class="mon-empty">暂无数据</div>'; }
  const w = 640, h = 140, padX = 6, padT = 14, padB = 10;
  const vals = trend.map(t => Number(getV(t)) || 0);
  const maxV = Math.max(1, ...vals);
  const avg = vals.reduce((a, b) => a + b, 0) / vals.length;
  const step = trend.length > 1 ? (w - padX * 2) / (trend.length - 1) : 0;
  const y = v => (h - padB - (v / maxV) * (h - padT - padB));
  const pts = vals.map((v, i) => (padX + i * step).toFixed(1) + ',' + y(v).toFixed(1));
  const area = 'M' + padX + ',' + (h - padB) + ' L' + pts.join(' L') + ' L' + (padX + (vals.length - 1) * step).toFixed(1) + ',' + (h - padB) + ' Z';
  const grid = [0.25, 0.5, 0.75].map(r => `<line x1="0" y1="${(padT + (h - padT - padB) * r).toFixed(1)}" x2="${w}" y2="${(padT + (h - padT - padB) * r).toFixed(1)}" class="mon-grid"/>`).join('');
  const iMax = vals.indexOf(maxV);
  const px = (padX + iMax * step).toFixed(1), py = y(maxV).toFixed(1);
  return `<svg viewBox="0 0 ${w} ${h}" class="mon-trend" preserveAspectRatio="none">
      <defs><linearGradient id="monArea" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="var(--macc)" stop-opacity=".38"/>
        <stop offset="100%" stop-color="var(--macc)" stop-opacity="0"/></linearGradient></defs>
      ${grid}
      <path d="${area}" fill="url(#monArea)"/>
      <polyline points="${pts.join(' ')}" fill="none" stroke="var(--macc)" stroke-width="2.2" stroke-linejoin="round"/>
      <circle cx="${px}" cy="${py}" r="3.4" class="mon-peak"/>
    </svg>
    <div class="mon-sub"><span>${esc(String(trend[0].d))} → ${esc(String(trend[trend.length - 1].d))}</span>
      <span>峰值 <b>${maxV}${esc(unit)}</b></span><span>均值 <b>${avg.toFixed(1)}${esc(unit)}</b></span></div>`;
}

function monTrend(trend) {
  if (!trend || !trend.length) { return monCard('访问趋势（PV）', '<div class="mon-empty">暂无数据</div>'); }
  return monCard('访问趋势（PV）', monAreaChart(trend, t => t.pv, ''));
}

async function monOverview(box) {
  const d = await api('monitor.php', 'stats', { range: st.range });
  const k = d.kpi || {};
  const js = Number(k.js || 0), succ = Number(k.api_succ || 100), slowR = Number(k.slow_ratio || 0);
  box.innerHTML = `
    ${monHealth([
      { label: 'JS 错误', value: js, tone: toneOf(js, 1, 20) },
      { label: '接口成功率', value: succ + '%', tone: succ < 95 ? 'bad' : (succ < 99 ? 'warn' : '') },
      { label: '慢接口占比', value: slowR + '%', tone: toneOf(slowR, 5, 15) },
    ])}
    ${monKpiGroup('流量', [
      monKpi('访问量 PV', Number(k.pv || 0)),
      monKpi('访客 UV', Number(k.uv || 0)),
      monKpi('接口调用', Number(k.api_total || 0)),
    ])}
    ${monKpiGroup('性能', [
      monKpi('平均加载', Number(k.load || 0) + ' ms'),
      monKpi('Apdex', Number(k.apdex || 0), Number(k.apdex || 1) < 0.85 ? 'warn' : ''),
      monKpi('慢接口占比', slowR + '%', toneOf(slowR, 5, 15)),
    ])}
    ${monKpiGroup('错误', [
      monKpi('JS 错误', js, toneOf(js, 1, 20)),
      monKpi('接口成功率', succ + '%', succ < 95 ? 'bad' : (succ < 99 ? 'warn' : '')),
    ])}
    ${monTrend(d.trend)}
    <div class="mon-cards">
      ${monListCard('慢页面 TOP5', (d.top_slow_page || []).map(x => [x.name, (x.avg_ms || 0) + ' ms']))}
      ${monListCard('JS 错误 TOP5', (d.top_error || []).map(x => [x.msg || x.name, x.n + ' 次']))}
      ${monListCard('慢接口 TOP5', (d.top_slow_api || []).map(x => [x.name, (x.avg_ms || 0) + ' ms']))}
      ${monListCard('访问量 TOP5', (d.top_pv || []).map(x => [x.name, x.n]))}
      ${monListCard('浏览器分布', (d.browsers || []).map(x => [x.name, x.n]))}
      ${monListCard('操作系统分布', (d.systems || []).map(x => [x.name, x.n]))}
      ${monListCard('网络分布', (d.nets || []).map(x => [x.name, x.n]))}
      ${monListCard('屏幕分辨率', (d.screens || []).map(x => [x.name, x.n]))}
      ${monListCard('资源加载失败', (d.res_fail || []).map(x => [x.name, x.n + ' 次']))}
    </div>`;
}

function monSrvTrend(trend, slowMs) {
  if (!trend || !trend.length) { return monCard('平均耗时趋势', '<div class="mon-empty">暂无数据</div>'); }
  return monCard('平均耗时趋势（慢查询阈值 ' + Number(slowMs || 200) + ' ms）',
    monAreaChart(trend, t => t.avg_ms, ' ms'));
}

async function monSrv(box) {
  const d = await api('monitor.php', 'srv', { range: st.range });
  const k = d.kpi || {};
  const track = d.track_urls || [];
  const routes = d.routes || [];
  const n5 = Number(k.code5 || 0), errs = Number(k.errs || 0), slowR = Number(k.slow_ratio || 0), slowQ = Number(k.slow_q || 0);
  const apdex = Number(k.apdex || 1), apdexT = Number(d.apdex_t || MON_DEFAULT.apdexT);
  box.innerHTML = `
    ${monHealth([
      { label: '5xx', value: n5, tone: toneOf(n5, 1, 5) },
      { label: '异常', value: errs, tone: toneOf(errs, 1, 10) },
      { label: '慢请求占比', value: slowR + '%', tone: toneOf(slowR, 5, 15) },
      { label: 'Apdex', value: apdex, tone: apdex < 0.85 ? 'warn' : '' },
    ])}
    ${monKpiGroup('流量', [
      monKpi('请求数', Number(k.n || 0)),
      monKpi('平均 DB 耗时', Number(k.db_avg || 0) + ' ms'),
      monKpi('平均查询数', Number(k.db_n || 0)),
    ])}
    ${monKpiGroup('性能', [
      monKpi('平均耗时', Number(k.avg || 0) + ' ms'),
      monKpi('P95 耗时', Number(k.p95 || 0) + ' ms'),
      monKpi('最慢', Number(k.max || 0) + ' ms'),
      monKpi('Apdex T=' + apdexT + 'ms', apdex, apdex < 0.85 ? 'warn' : ''),
    ])}
    ${monKpiGroup('错误', [
      monKpi('慢请求占比', slowR + '%', toneOf(slowR, 5, 15)),
      monKpi('慢查询数', slowQ, toneOf(slowQ, 10, 50)),
      monKpi('5xx', n5, toneOf(n5, 1, 5)),
      monKpi('异常', errs, toneOf(errs, 1, 10)),
    ])}
    ${monSrvTrend(d.trend, d.slow_ms)}
    ${monCard('按路由（' + routes.length + '）', `<table class="table"><thead><tr>
        <th>路由</th><th>请求</th><th>平均</th><th>最大</th><th>慢</th><th>5xx</th><th>慢查询</th><th>异常</th></tr></thead>
        <tbody>${routes.length ? routes.map(r => `<tr data-route="${esc(r.route)}" class="mon-click">
          <td class="mon-ell" title="${esc(r.route)}">${track.indexOf(r.route) >= 0 ? '<b class="mon-track">' + esc(r.route) + '</b>' : esc(r.route)}</td>
          <td class="mon-n">${Number(r.n).toLocaleString()}</td><td class="mon-n">${r.avg_ms} ms</td>
          <td class="mon-n">${r.max_ms} ms</td>
          <td class="mon-n">${Number(r.slow) ? '<b class="mon-warnv">' + r.slow + '</b>' : '0'}</td>
          <td class="mon-n">${Number(r.code5) ? '<b class="mon-bad">' + r.code5 + '</b>' : '0'}</td>
          <td class="mon-n">${Number(r.slow_q) ? '<b class="mon-warnv">' + r.slow_q + '</b>' : '0'}</td>
          <td class="mon-n">${Number(r.errs) ? '<b class="mon-bad">' + r.errs + '</b>' : '0'}</td></tr>`).join('')
        : '<tr><td class="mon-empty" colspan="8">暂无数据</td></tr>'}</tbody></table>`)}
    <div class="mon-cards">
      ${monListCard('慢查询 TOP', (d.slow_top || []).map(x => [(x.slow_sql || '').slice(0, 56), x.n + ' 次']))}
      ${monListCard('异常 TOP', (d.errors || []).map(x => [String(x.route || '') + ' · ' + String(x.err || '').slice(0, 36), x.n + ' 次']))}
    </div>
    <div id="monSrvDetail"></div>`;

  box.querySelectorAll('tr[data-route]').forEach(tr =>
    tr.addEventListener('click', () => monSrvList(box, tr.dataset.route)));
}

async function monSrvList(box, route) {
  const el = box.querySelector('#monSrvDetail'); if (!el) { return; }
  el.innerHTML = '<div class="mon-sk"></div>';
  try {
    const d = await api('monitor.php', 'srvlist', { route: route, range: st.range, page: 1 });
    const items = d.items || [];
    el.innerHTML = monCard('请求明细 · ' + route + '（共 ' + Number(d.total || 0) + '）',
      '<table class="table"><thead><tr><th>时间</th><th>方法</th><th>状态</th><th>耗时</th><th>DB</th><th>查询</th><th>慢</th><th>内存</th><th>信息</th></tr></thead><tbody>' +
      (items.length ? items.map(it => `<tr class="${(it.err || it.code >= 500) ? 'mon-unacked' : ''}">
        <td class="mon-t">${esc(it.time)}</td><td>${esc(it.method)}</td>
        <td class="mon-n">${Number(it.code) >= 500 ? '<b class="mon-bad">' + it.code + '</b>' : it.code}</td>
        <td class="mon-n">${it.dur} ms</td>
        <td class="mon-n">${it.db} ms</td><td class="mon-n">${it.db_n}</td>
        <td class="mon-n">${Number(it.slow_n) ? '<b class="mon-warnv">' + it.slow_n + '</b>' : '0'}</td>
        <td class="mon-n">${Math.round(Number(it.mem || 0) / 1024)} MB</td>
        <td class="mon-ell" title="${esc(it.err || it.slow_sql || '')}">${esc(it.err || it.slow_sql || '')}</td></tr>`).join('')
      : '<tr><td class="mon-empty" colspan="9">暂无数据</td></tr>') + '</tbody></table>');
  } catch (e) {
    el.innerHTML = '<div class="mon-empty">' + esc(e.message) + '</div>';
  }
}

async function monSessions(box) {
  const only = st.sessOnly || 'all';
  let page = 1, loading = false, done = false, total = 0, shown = 0;

  box.innerHTML =
    monCard('筛选', `<div class="mon-seg" id="monSessOnly">
      <button type="button" data-o="all"${only === 'all' ? ' class="on"' : ''}>全部会话</button>
      <button type="button" data-o="bad"${only === 'bad' ? ' class="on"' : ''}>仅异常</button>
    </div>`) +
    monCard('会话列表',
      `<div class="mon-scroll"><table class="table"><thead><tr><th>会话</th><th>用户</th><th>版本</th>
        <th>最近活动</th><th>时长</th><th>PV</th><th>JS</th><th>接口</th><th>慢</th><th>失败</th><th>异常</th>
        <th>浏览器</th><th>屏幕</th></tr></thead>
        <tbody id="monSessBody"></tbody></table></div>`) +
    '<div class="mon-moreline tiny" id="monSessFoot"></div>' +
    '<div id="monSessDetail"></div>';

  const body = box.querySelector('#monSessBody');
  const foot = box.querySelector('#monSessFoot');

  function row(sv) {
    const who = Number(sv.uid) > 0 ? (sv.user || ('#' + sv.uid)) : '游客';
    const userCell = `<td class="mon-ell" title="${esc(sv.uid8 || who)}">${esc(who)}`
      + (sv.uid8 ? ' <span class="mon-mut">' + esc(sv.uid8) + '</span>' : '') + '</td>';
    return `<tr data-sid="${esc(sv.sid)}" class="mon-click">
      <td class="mon-ell" title="${esc(sv.sid)}">${esc(sv.sid)}</td>
      ${userCell}
      <td class="mon-n">${sv.version ? 'v' + esc(sv.version) : '—'}</td>
      <td class="mon-t">${esc(sv.last)}</td><td class="mon-n">${sv.dur}s</td><td class="mon-n">${sv.pv}</td>
      <td class="mon-n">${Number(sv.js) ? '<b class="mon-warnv">' + sv.js + '</b>' : '0'}</td>
      <td class="mon-n">${sv.api}</td>
      <td class="mon-n">${Number(sv.apislow) ? '<b class="mon-warnv">' + sv.apislow + '</b>' : '0'}</td>
      <td class="mon-n">${Number(sv.apifail) ? '<b class="mon-bad">' + sv.apifail + '</b>' : '0'}</td>
      <td class="mon-n">${sv.bad ? '<b class="mon-bad">' + sv.bad + '</b>' : '0'}</td>
      <td>${esc(sv.browser)}</td><td>${esc(sv.screen || '')}</td></tr>`;
  }

  function paintFoot() {
    if (total <= 0) { foot.textContent = ''; return; }
    foot.textContent = done
      ? ('已显示全部 ' + shown + ' 个会话')
      : (loading ? '加载中…' : ('已显示 ' + shown + ' / 共 ' + total + ' 个会话 · 继续下滑自动加载'));
  }

  async function load(reset) {
    if (loading || (done && !reset)) { return; }
    if (reset) { page = 1; done = false; shown = 0; body.innerHTML = ''; }
    loading = true; paintFoot();
    try {
      const d = await api('monitor.php', 'sessions', { range: st.range, only: only, page: page });
      const items = d.items || [];
      total = Number(d.total || 0);
      if (!items.length && page === 1) {
        body.innerHTML = '<tr><td class="mon-empty" colspan="13">暂无数据</td></tr>';
      }
      items.forEach(sv => body.insertAdjacentHTML('beforeend', row(sv)));
      shown += items.length;
      done = !d.has_more;
      page++;
    } catch (e) {
      foot.textContent = '加载失败：' + e.message;
    } finally {
      loading = false; paintFoot();
    }
  }

  const seg = box.querySelector('#monSessOnly');
  seg.addEventListener('click', e => {
    const b = e.target.closest('[data-o]'); if (!b) { return; }
    st.sessOnly = b.dataset.o;
    monSessions(box);
  });
  body.addEventListener('click', e => {
    const tr = e.target.closest('tr[data-sid]'); if (!tr) { return; }
    monSessionDetail(box, tr.dataset.sid);
  });
  /* 滑动自动分页：贴近底部即续拉下一页（提前 900px，越灵敏越好） */
  const scroller = document.getElementById('view') || box;
  function onScroll() {
    if (!body.isConnected) { scroller.removeEventListener('scroll', onScroll); return; }
    if (done || loading) { return; }
    if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 900) { load(false); }
  }
  scroller.addEventListener('scroll', onScroll, { passive: true });
  await load(true);
}

async function monSessionDetail(box, sid) {
  const el = box.querySelector('#monSessDetail'); if (!el) { return; }
  el.innerHTML = '<div class="mon-sk"></div>';
  const KIND = { pv: '访问', js: 'JS', api: '接口', resource: '资源', perf: '性能', custom: '自定义', error: '错误' };
  try {
    const d = await api('monitor.php', 'session', { sid: sid, range: st.range });
    const items = d.items || [];
    const who = Number(d.uid) > 0 ? (d.user ? esc(d.user) : ('#' + d.uid)) : '游客';
    const meta = '<div class="mon-sess-meta">用户：<b>' + who + '</b>'
      + (d.uid8 ? '（UID ' + esc(d.uid8) + '）' : '')
      + (d.version ? ' · 访问版本：<b>v' + esc(d.version) + '</b>' : '') + '</div>';
    el.innerHTML = monCard('会话时间线 · ' + sid,
      meta + '<ul class="mon-tl">' + (items.length ? items.map(it =>
        `<li class="lvl-${esc(it.level)}">
          <span class="mon-tl-t">${esc(it.time)}</span>
          <span class="mon-tl-k">${esc(KIND[it.kind] || it.kind)}</span>
          <span class="mon-tl-n mon-ell" title="${esc(it.name)}">${esc(it.name)}</span>
          <span class="mon-tl-m mon-ell" title="${esc(it.msg)}">${esc(it.msg || '')}${it.v1 ? ' · ' + it.v1 + (it.kind === 'api' ? ' ms' : '') : ''}${it.version ? ' · v' + esc(it.version) : ''}</span>
        </li>`).join('') : '<li class="mon-empty">暂无数据</li>') + '</ul>');
  } catch (e) {
    el.innerHTML = '<div class="mon-empty">' + esc(e.message) + '</div>';
  }
}

async function monAlerts(box) {
  const d = await api('monitor.php', 'alerts');
  const items = d.items || [];
  const rows = items.length ? items.map(a => `<tr class="${a.acked ? '' : 'mon-unacked'}">
      <td>${esc(a.time)}</td><td>${esc(a.level)}</td>
      <td class="mon-ell" title="${esc(a.message)}">${esc(a.message)}</td>
      <td>${a.value}</td><td>${a.threshold}</td>
      <td>${a.acked ? '已确认' : `<button class="mon-btn ghost" type="button" data-ack="${a.id}">确认</button>`}</td></tr>`).join('')
    : '<tr><td class="mon-empty" colspan="6">暂无数据</td></tr>';
  box.innerHTML = monCard('告警记录（未确认 ' + (Number(d.unacked) || 0) + '）',
    `<table class="table"><thead><tr><th>时间</th><th>级别</th><th>内容</th><th>实测</th><th>阈值</th><th>操作</th></tr></thead>
      <tbody>${rows}</tbody></table>`);
  box.querySelectorAll('[data-ack]').forEach(b => b.addEventListener('click', async () => {
    try { await api('monitor.php', 'alert_ack', { id: b.dataset.ack }); toast('已确认'); monAlerts(box); }
    catch (e) { toast(e.message, 'err'); }
  }));
}

async function monEvents(box, kind) {
  const HEADS = {
    api: ['接口', '次数', '平均耗时', '慢', '失败'],
    perf: ['指标', '次数', '平均', '最大'],
    custom: ['名称', '次数', '平均', '最大'],
    resource: ['位置', '信息', '次数', '影响 UV', '最近'],
    js: ['位置', '错误信息', '次数', '影响 UV', '最近'],
  };
  const head = HEADS[kind] || HEADS.js;
  const isDetail = (kind !== 'api' && kind !== 'perf' && kind !== 'custom');

  const d = await api('monitor.php', 'events', { kind: kind, range: st.range, page: 1 });
  const groups = d.groups || [];
  const rows = groups.length ? groups.map(x => {
    if (kind === 'api') { return `<tr><td class="mon-ell" title="${esc(x.name)}">${esc(x.name)}</td><td>${x.n}</td><td>${x.avg_ms} ms</td><td>${x.slow}</td><td>${x.fails}</td></tr>`; }
    if (kind === 'perf') { return `<tr><td>${esc(x.name)}</td><td>${x.n}</td><td>${x.avg_ms} ms</td><td>${x.max_ms} ms</td></tr>`; }
    if (kind === 'custom') { return `<tr><td>${esc(x.name)}</td><td>${x.n}</td><td>${x.avg_v}</td><td>${x.max_v}</td></tr>`; }
    return `<tr><td class="mon-ell" title="${esc(x.name)}">${esc(x.name)}</td><td class="mon-ell" title="${esc(x.msg || '')}">${esc(x.msg || '')}</td><td>${x.n}</td><td>${x.uv || 0}</td><td>${esc(String(x.last || ''))}</td></tr>`;
  }).join('') : `<tr><td class="mon-empty" colspan="${head.length}">暂无数据</td></tr>`;

  box.innerHTML =
    monCard('聚合（TOP ' + groups.length + '）',
      `<div class="mon-scroll"><table class="table"><thead><tr>${head.map(t => '<th>' + t + '</th>').join('')}</tr></thead><tbody>${rows}</tbody></table></div>`) +
    (isDetail ? monCard('明细',
      `<div class="mon-scroll"><table class="table"><thead><tr><th>时间</th><th>位置</th><th>信息</th><th>版本</th><th>浏览器</th></tr></thead>
        <tbody id="monEvtBody"></tbody></table></div><div class="mon-moreline tiny" id="monEvtFoot"></div>`) : '');

  if (!isDetail) { return; }

  const body = box.querySelector('#monEvtBody');
  const foot = box.querySelector('#monEvtFoot');
  let page = 2, loading = false, done = false, shown = 0;
  let total = Number(d.total || 0);

  function detailRow(it) {
    return `<tr><td class="mon-t">${esc(it.time)}</td>
      <td class="mon-ell" title="${esc(it.name)}">${esc(it.name)}</td>
      <td class="mon-ell" title="${esc(it.msg)}">${esc(it.msg)}</td>
      <td class="mon-n">${it.version ? 'v' + esc(it.version) : '—'}</td>
      <td>${esc(it.browser)}</td></tr>`;
  }
  function paintFoot() {
    if (total <= 0) { foot.textContent = ''; return; }
    foot.textContent = done
      ? ('已显示全部 ' + shown + ' 条')
      : (loading ? '加载中…' : ('已显示 ' + shown + ' / 共 ' + total + ' 条 · 继续下滑自动加载'));
  }
  function append(items) {
    if (!items.length) { return; }
    items.forEach(it => body.insertAdjacentHTML('beforeend', detailRow(it)));
    shown += items.length;
  }
  append(d.items || []);
  done = !d.has_more;
  if (shown === 0) { body.innerHTML = '<tr><td class="mon-empty" colspan="5">暂无数据</td></tr>'; }
  paintFoot();

  async function more() {
    if (loading || done) { return; }
    loading = true; paintFoot();
    try {
      const r = await api('monitor.php', 'events', { kind: kind, range: st.range, page: page });
      append(r.items || []);
      done = !r.has_more;
      page++;
    } catch (e) { foot.textContent = '加载失败：' + e.message; }
    finally { loading = false; paintFoot(); }
  }
  /* 滑动自动分页：贴近底部即续拉下一页（提前 900px） */
  const scroller = document.getElementById('view') || box;
  function onScroll() {
    if (!body.isConnected) { scroller.removeEventListener('scroll', onScroll); return; }
    if (done || loading) { return; }
    if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 900) { more(); }
  }
  scroller.addEventListener('scroll', onScroll, { passive: true });
}

async function monSettings(box) {
  const d = await api('monitor.php', 'settings');
  box.innerHTML = monCard('采集设置', `
      <div class="mon-row"><span>启用采集</span>
        <div class="mon-seg" id="monEnabled">
          <button type="button" data-v="1"${d.enabled === '1' ? ' class="on"' : ''}>开</button>
          <button type="button" data-v="0"${d.enabled !== '1' ? ' class="on"' : ''}>关</button>
        </div></div>
      <div class="mon-row"><span>采样率（%）</span><input class="mon-input" id="monSample" type="number" min="1" max="100" value="${Number(d.sample || 100)}"></div>
      <div class="mon-row"><span>数据保留（天）</span><input class="mon-input" id="monKeep" type="number" min="1" max="90" value="${Number(d.keep_days || MON_DEFAULT.keepDays)}"></div>
      <div class="mon-row"><span>服务端采样率（%）</span><input class="mon-input" id="monSrvSample" type="number" min="1" max="100" value="${Number(d.srv_sample || MON_DEFAULT.sample)}"></div>
      <div class="mon-row"><span>慢查询阈值（ms）</span><input class="mon-input" id="monSrvSlow" type="number" min="1" max="60000" value="${Number(d.srv_slow_ms || MON_DEFAULT.slowMs)}"></div>
      <div class="mon-row"><span>单请求耗时告警（ms）</span><input class="mon-input" id="monSrvAlert" type="number" min="100" max="60000" value="${Number(d.srv_slow_alert_ms || MON_DEFAULT.slowAlertMs)}"></div>
      <div class="mon-row"><span>Apdex 基线（ms）</span><input class="mon-input" id="monApdexT" type="number" min="100" max="10000" value="${Number(d.apdex_t || MON_DEFAULT.apdexT)}"></div>
      <div class="mon-row"><span>重点接口白名单</span><input class="mon-input" id="monTrack" type="text" placeholder="逗号分隔，如 api/works.php" value="${esc(d.track_urls || '')}"></div>
      <div class="mon-row"><span>JS 错误率告警阈值（%）</span><input class="mon-input" id="monThErr" type="number" min="0" max="100" value="${Number(d.alert_error_rate || 5)}"></div>
      <div class="mon-row"><span>慢接口占比告警阈值（%）</span><input class="mon-input" id="monThSlow" type="number" min="0" max="100" value="${Number(d.alert_slow_ratio || 20)}"></div>
      <div class="mon-actions">
        <button class="mon-btn" id="monSave" type="button">保存</button>
        <button class="mon-btn ghost" id="monPurge" type="button">清理过期明细</button>
      </div>`);

  const enSeg = box.querySelector('#monEnabled');
  let enabled = d.enabled === '1' ? '1' : '0';
  enSeg.addEventListener('click', e => {
    const b = e.target.closest('[data-v]'); if (!b) { return; }
    enabled = b.dataset.v;
    enSeg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
  });
  box.querySelector('#monSave').addEventListener('click', async ev => {
    btnLoading(ev.target, true);
    try {
      await api('monitor.php', 'settings', {
        save: '1', enabled: enabled,
        sample: Number(box.querySelector('#monSample').value || 100),
        keep_days: Number(box.querySelector('#monKeep').value || 7),
        alert_error_rate: Number(box.querySelector('#monThErr').value || 5),
        alert_slow_ratio: Number(box.querySelector('#monThSlow').value || 20),
        srv_sample: Number(box.querySelector('#monSrvSample').value || MON_DEFAULT.sample),
        srv_slow_ms: Number(box.querySelector('#monSrvSlow').value || MON_DEFAULT.slowMs),
        srv_slow_alert_ms: Number(box.querySelector('#monSrvAlert').value || MON_DEFAULT.slowAlertMs),
        apdex_t: Number(box.querySelector('#monApdexT').value || MON_DEFAULT.apdexT),
        track_urls: String(box.querySelector('#monTrack').value || '').trim(),
      });
      toast('已保存');
    } catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(ev.target, false); }
  });
  box.querySelector('#monPurge').addEventListener('click', async ev => {
    btnLoading(ev.target, true);
    try { const r = await api('monitor.php', 'purge', {}); toast('已清理 ' + (Number(r.deleted) || 0) + ' 条'); }
    catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(ev.target, false); }
  });
}
