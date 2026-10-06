/**
 * 网页异常监测 · 独立页面（仅管理员）
 * ------------------------------------------------------------
 * 与站点主题/皮肤完全隔离：固定「蓝白 / 蓝黑」两套配色，可切换，互不影响。
 * 数据来自 api/monitor.php（stats / events / settings / purge）。
 */
import { api, esc, isAdminish, toast, btnLoading } from '../core.js';

const MT_KEY = 'kimgr_mon_theme';
const MON_TABS = [
  ['overview', '总览'], ['js', 'JS 错误'], ['api', '接口'],
  ['perf', '加载性能'], ['resource', '资源'], ['settings', '设置'],
];
const st = { range: '7d', tab: 'overview', theme: 'light' };

export async function renderMonitor(container) {
  if (!isAdminish()) {
    container.innerHTML = '<div class="empty"><p>无权限访问</p></div>';
    return;
  }
  try { st.theme = localStorage.getItem(MT_KEY) === 'dark' ? 'dark' : 'light'; } catch (e) {}

  container.innerHTML = `
    <div class="mon-app" data-mon="${st.theme}">
      <div class="mon-top">
        <div class="mon-title">网页异常监测</div>
        <div class="mon-top-r">
          <div class="mon-seg" id="monRange">
            <button data-r="1h">1 时</button>
            <button data-r="24h">24 时</button>
            <button data-r="7d" class="on">7 天</button>
            <button data-r="30d">30 天</button>
          </div>
          <button class="mon-theme" id="monTheme" type="button">${st.theme === 'dark' ? '蓝白' : '蓝黑'}</button>
        </div>
      </div>
      <div class="mon-seg mon-tabs" id="monTabs">
        ${MON_TABS.map(([k, n]) => `<button type="button" data-t="${k}"${k === 'overview' ? ' class="on"' : ''}>${n}</button>`).join('')}
      </div>
      <div id="monBody"><div class="mon-sk"></div></div>
    </div>`;

  const app = container.querySelector('.mon-app');
  const themeBtn = container.querySelector('#monTheme');
  themeBtn.addEventListener('click', () => {
    st.theme = st.theme === 'dark' ? 'light' : 'dark';
    app.dataset.mon = st.theme;
    themeBtn.textContent = st.theme === 'dark' ? '蓝白' : '蓝黑';
    try { localStorage.setItem(MT_KEY, st.theme); } catch (e) {}
  });

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

async function monRenderBody(container) {
  const box = container.querySelector('#monBody');
  if (!box) { return; }
  box.innerHTML = '<div class="mon-sk"></div>';
  try {
    if (st.tab === 'overview') { return await monOverview(box); }
    if (st.tab === 'settings') { return await monSettings(box); }
    await monEvents(box, st.tab);
  } catch (e) {
    box.innerHTML = `<div class="mon-empty">${esc(e.message)}</div>`;
  }
}

function monKpi(label, value) {
  return `<div class="mon-kpi"><div class="mk-v">${esc(String(value))}</div><div class="mk-l">${esc(label)}</div></div>`;
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
function monTrend(trend) {
  if (!trend || !trend.length) { return monCard('访问趋势（PV）', '<div class="mon-empty">暂无数据</div>'); }
  const w = 640, h = 120, pad = 8;
  const maxV = Math.max(1, ...trend.map(t => Number(t.pv) || 0));
  const step = trend.length > 1 ? (w - pad * 2) / (trend.length - 1) : 0;
  const pts = trend.map((t, i) => (pad + i * step).toFixed(1) + ',' + (h - pad - ((Number(t.pv) || 0) / maxV) * (h - pad * 2)).toFixed(1)).join(' ');
  return monCard('访问趋势（PV）',
    `<svg viewBox="0 0 ${w} ${h}" class="mon-trend" preserveAspectRatio="none"><polyline points="${pts}" fill="none" stroke="var(--macc)" stroke-width="2"/></svg>
     <div class="mon-sub">${esc(String(trend[0].d))} → ${esc(String(trend[trend.length - 1].d))} · 峰值 ${maxV}</div>`);
}

async function monOverview(box) {
  const d = await api('monitor.php', 'stats', { range: st.range });
  const k = d.kpi || {};
  box.innerHTML = `
    <div class="mon-kpis">
      ${monKpi('访问量 PV', Number(k.pv || 0))}
      ${monKpi('访客 UV', Number(k.uv || 0))}
      ${monKpi('平均加载', Number(k.load || 0) + ' ms')}
      ${monKpi('JS 错误', Number(k.js || 0) + ' · ' + Number(k.js_rate || 0) + '%')}
      ${monKpi('接口成功率', Number(k.api_succ || 0) + '%')}
      ${monKpi('慢接口占比', Number(k.slow_ratio || 0) + '%')}
      ${monKpi('Apdex', Number(k.apdex || 0))}
      ${monKpi('接口调用', Number(k.api_total || 0))}
    </div>
    ${monTrend(d.trend)}
    <div class="mon-cards">
      ${monListCard('慢页面 TOP5', (d.top_slow_page || []).map(x => [x.name, (x.avg_ms || 0) + ' ms']))}
      ${monListCard('JS 错误 TOP5', (d.top_error || []).map(x => [x.msg || x.name, x.n + ' 次']))}
      ${monListCard('慢接口 TOP5', (d.top_slow_api || []).map(x => [x.name, (x.avg_ms || 0) + ' ms']))}
      ${monListCard('访问量 TOP5', (d.top_pv || []).map(x => [x.name, x.n]))}
      ${monListCard('浏览器分布', (d.browsers || []).map(x => [x.name, x.n]))}
      ${monListCard('操作系统分布', (d.systems || []).map(x => [x.name, x.n]))}
      ${monListCard('网络分布', (d.nets || []).map(x => [x.name, x.n]))}
      ${monListCard('资源加载失败', (d.res_fail || []).map(x => [x.name, x.n + ' 次']))}
    </div>`;
}

async function monEvents(box, kind) {
  const d = await api('monitor.php', 'events', { kind: kind, range: st.range, page: 1 });
  const groups = d.groups || [];
  const items = d.items || [];
  const HEADS = {
    api: ['接口', '次数', '平均耗时', '慢', '失败'],
    perf: ['指标', '次数', '平均', '最大'],
    custom: ['名称', '次数', '平均', '最大'],
    resource: ['位置', '信息', '次数', '影响 UV', '最近'],
    js: ['位置', '错误信息', '次数', '影响 UV', '最近'],
  };
  const head = HEADS[kind] || HEADS.js;
  const rows = groups.length ? groups.map(x => {
    if (kind === 'api') { return `<tr><td class="mon-ell" title="${esc(x.name)}">${esc(x.name)}</td><td>${x.n}</td><td>${x.avg_ms} ms</td><td>${x.slow}</td><td>${x.fails}</td></tr>`; }
    if (kind === 'perf') { return `<tr><td>${esc(x.name)}</td><td>${x.n}</td><td>${x.avg_ms} ms</td><td>${x.max_ms} ms</td></tr>`; }
    if (kind === 'custom') { return `<tr><td>${esc(x.name)}</td><td>${x.n}</td><td>${x.avg_v}</td><td>${x.max_v}</td></tr>`; }
    return `<tr><td class="mon-ell" title="${esc(x.name)}">${esc(x.name)}</td><td class="mon-ell" title="${esc(x.msg || '')}">${esc(x.msg || '')}</td><td>${x.n}</td><td>${x.uv || 0}</td><td>${esc(String(x.last || ''))}</td></tr>`;
  }).join('') : `<tr><td class="mon-empty" colspan="${head.length}">暂无数据</td></tr>`;

  let detail = '';
  if (kind !== 'api' && kind !== 'perf' && kind !== 'custom') {
    detail = monCard('明细',
      `<table class="table"><thead><tr><th>时间</th><th>位置</th><th>信息</th><th>浏览器</th></tr></thead><tbody>` +
      (items.length ? items.map(it => `<tr><td>${esc(it.time)}</td><td class="mon-ell" title="${esc(it.name)}">${esc(it.name)}</td><td class="mon-ell" title="${esc(it.msg)}">${esc(it.msg)}</td><td>${esc(it.browser)}</td></tr>`).join('')
        : '<tr><td class="mon-empty" colspan="4">暂无数据</td></tr>') +
      '</tbody></table>');
  }

  box.innerHTML =
    monCard('聚合（TOP ' + groups.length + '）',
      `<table class="table"><thead><tr>${head.map(t => '<th>' + t + '</th>').join('')}</tr></thead><tbody>${rows}</tbody></table>`) +
    detail;
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
      <div class="mon-row"><span>数据保留（天）</span><input class="mon-input" id="monKeep" type="number" min="1" max="90" value="${Number(d.keep_days || 7)}"></div>
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
