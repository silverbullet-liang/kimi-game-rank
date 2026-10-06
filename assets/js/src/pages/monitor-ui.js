/**
 * 控制面板「网页异常监测」区块（仅主管理员）
 * 数据来自 api/monitor.php：stats / events / settings / purge
 * 视觉：普通后台风格（与 panel.js 其它区块一致，非液态玻璃）
 */
import { api, esc, toast, btnLoading } from '../core.js';

const MON_TABS = [
  ['overview', '总览'], ['js', 'JS 错误'], ['api', '接口'],
  ['perf', '加载性能'], ['resource', '资源'], ['settings', '设置'],
];
const monState = { range: '7d', tab: 'overview' };

export function monitorBlock() {
  return `
    <div class="panel-plain">
      <h3>网页异常监测
        <span class="seg" style="float:right;max-width:300px" id="monRange">
          <button data-r="1h">1 时</button>
          <button data-r="24h">24 时</button>
          <button data-r="7d" class="on">7 天</button>
          <button data-r="30d">30 天</button>
        </span>
      </h3>
      <p class="tiny muted" style="margin:0 0 10px">前端 SDK 采集的真实用户错误、接口与加载性能；数据已脱敏，含阈值告警。</p>
      <div class="seg mon-tabs" id="monTabs">
        ${MON_TABS.map(([k, n]) => `<button data-t="${k}"${k === 'overview' ? ' class="on"' : ''}>${n}</button>`).join('')}
      </div>
      <div id="monBody"><div class="skeleton" style="height:120px"></div></div>
    </div>`;
}

export function mountMonitor(container) {
  const tabs = container.querySelector('#monTabs');
  const rng = container.querySelector('#monRange');
  if (!tabs) { return; }
  tabs.addEventListener('click', e => {
    const b = e.target.closest('[data-t]'); if (!b) { return; }
    monState.tab = b.dataset.t;
    tabs.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    monRender(container);
  });
  if (rng) {
    rng.addEventListener('click', e => {
      const b = e.target.closest('[data-r]'); if (!b) { return; }
      monState.range = b.dataset.r;
      rng.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      monRender(container);
    });
  }
  monRender(container);
}

async function monRender(container) {
  const box = container.querySelector('#monBody');
  if (!box) { return; }
  box.innerHTML = '<div class="skeleton" style="height:120px"></div>';
  try {
    if (monState.tab === 'overview') { return await monOverview(box); }
    if (monState.tab === 'settings') { return await monSettings(box); }
    await monEvents(box, monState.tab);
  } catch (e) {
    box.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`;
  }
}

function monKpi(label, value) {
  return `<div class="mon-kpi"><div class="mk-v">${esc(String(value))}</div><div class="mk-l">${esc(label)}</div></div>`;
}
function monListCard(title, rows) {
  const items = (rows && rows.length)
    ? rows.map(r => `<li><span class="mon-li-n" title="${esc(String(r[0]))}">${esc(String(r[0] || '—'))}</span><b>${esc(String(r[1]))}</b></li>`).join('')
    : '<li class="tiny muted">暂无数据</li>';
  return `<div class="mon-card"><div class="mon-card-t">${esc(title)}</div><ul class="mon-ul">${items}</ul></div>`;
}
function monTrend(trend) {
  if (!trend || !trend.length) { return '<div class="mon-card"><div class="mon-card-t">访问趋势（PV）</div><div class="tiny muted" style="padding:10px 0">暂无数据</div></div>'; }
  const w = 640, h = 120, pad = 8;
  const maxV = Math.max(1, ...trend.map(t => Number(t.pv) || 0));
  const step = trend.length > 1 ? (w - pad * 2) / (trend.length - 1) : 0;
  const pts = trend.map((t, i) => (pad + i * step).toFixed(1) + ',' + (h - pad - ((Number(t.pv) || 0) / maxV) * (h - pad * 2)).toFixed(1)).join(' ');
  return `<div class="mon-card"><div class="mon-card-t">访问趋势（PV）</div>
    <svg viewBox="0 0 ${w} ${h}" class="mon-trend" preserveAspectRatio="none"><polyline points="${pts}" fill="none" stroke="var(--accent)" stroke-width="2"/></svg>
    <div class="tiny muted">${esc(String(trend[0].d))} → ${esc(String(trend[trend.length - 1].d))} · 峰值 ${maxV}</div></div>`;
}

async function monOverview(box) {
  const d = await api('monitor.php', 'stats', { range: monState.range });
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
  const d = await api('monitor.php', 'events', { kind: kind, range: monState.range, page: 1 });
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
    return `<tr><td class="mon-ell" title="${esc(x.name)}">${esc(x.name)}</td><td class="mon-ell" title="${esc(x.msg || '')}">${esc(x.msg || '')}</td><td>${x.n}</td><td>${x.uv || 0}</td><td class="tiny">${esc(String(x.last || ''))}</td></tr>`;
  }).join('') : `<tr><td class="tiny muted" colspan="${head.length}">暂无数据</td></tr>`;

  let detail = '';
  if (kind !== 'api' && kind !== 'perf' && kind !== 'custom') {
    detail = `<div class="mon-card"><div class="mon-card-t">明细</div>
      <table class="table"><thead><tr><th>时间</th><th>位置</th><th>信息</th><th>浏览器</th></tr></thead><tbody>` +
      (items.length ? items.map(it => `<tr><td class="tiny">${esc(it.time)}</td><td class="mon-ell" title="${esc(it.name)}">${esc(it.name)}</td><td class="mon-ell" title="${esc(it.msg)}">${esc(it.msg)}</td><td class="tiny">${esc(it.browser)}</td></tr>`).join('')
        : '<tr><td class="tiny muted" colspan="4">暂无数据</td></tr>') +
      '</tbody></table></div>';
  }

  box.innerHTML = `<div class="mon-card"><div class="mon-card-t">聚合（TOP ${groups.length}）</div>
      <table class="table"><thead><tr>${head.map(t => '<th>' + t + '</th>').join('')}</tr></thead><tbody>${rows}</tbody></table></div>
    ${detail}`;
}

async function monSettings(box) {
  const d = await api('monitor.php', 'settings');
  box.innerHTML = `
    <div class="mon-card">
      <div class="mon-card-t">采集设置</div>
      <div class="setting-row"><span>启用采集</span>
        <div class="seg" id="monEnabled" style="max-width:160px">
          <button data-v="1"${d.enabled === '1' ? ' class="on"' : ''}>开</button>
          <button data-v="0"${d.enabled !== '1' ? ' class="on"' : ''}>关</button>
        </div></div>
      <div class="setting-row"><span>采样率（%）</span><input class="input" id="monSample" type="number" min="1" max="100" value="${Number(d.sample || 100)}" style="max-width:130px"></div>
      <div class="setting-row"><span>数据保留（天）</span><input class="input" id="monKeep" type="number" min="1" max="90" value="${Number(d.keep_days || 7)}" style="max-width:130px"></div>
      <div class="setting-row"><span>JS 错误率告警阈值（%）</span><input class="input" id="monThErr" type="number" min="0" max="100" value="${Number(d.alert_error_rate || 5)}" style="max-width:130px"></div>
      <div class="setting-row"><span>慢接口占比告警阈值（%）</span><input class="input" id="monThSlow" type="number" min="0" max="100" value="${Number(d.alert_slow_ratio || 20)}" style="max-width:130px"></div>
      <div style="display:flex;gap:10px;margin-top:12px">
        <button class="btn btn-sm" id="monSave">保存</button>
        <button class="btn-ghost btn-sm" id="monPurge">清理过期明细</button>
      </div>
    </div>`;
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
