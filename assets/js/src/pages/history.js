/**
 * 铭记历史 · 攻击事件档案
 * ------------------------------------------------------------
 * 列表（#/history）与详情（#/history/<id>）两套渲染。
 * 详情页：上方是标题与起止时间、始作俑者状态，
 *        中间是梳理文档（按二级标题分节，默认折叠），
 *        下方是讨论区（与违纪通报共用同一套评论）。
 */
import { api, esc, state } from '../core.js';
import { navigate } from '../router.js';
import { mdToHtml } from '../md.js';
import { renderComments } from './detail.js';

function sevText(s) {
  if (s === 'severe') { return '严重'; }
  if (s === 'normal') { return '一般'; }
  return '';
}

function spanText(d) {
  const a = d.started || '—';
  const b = d.ended || '至今';
  return a + ' — ' + b;
}

/* 把渲染好的 HTML 按二级标题切成可折叠分节：
   首个 h2 之前的内容作为「摘要」节，默认展开；其余默认收起。 */
function sectionize(html) {
  const parts = String(html).split(/<h2[^>]*>([\s\S]*?)<\/h2>/);
  let out = '';
  const head = (parts[0] || '').trim();
  if (head) {
    out += '<section class="inc-sec open">' +
      '<button class="inc-head" type="button">摘要<i class="inc-ico"></i></button>' +
      '<div class="inc-body"><div class="inc-inner">' + head + '</div></div></section>';
  }
  for (let i = 1; i < parts.length; i += 2) {
    const title = parts[i] || '';
    const body = parts[i + 1] || '';
    out += '<section class="inc-sec">' +
      '<button class="inc-head" type="button">' + title + '<i class="inc-ico"></i></button>' +
      '<div class="inc-body"><div class="inc-inner">' + body + '</div></div></section>';
  }
  return out;
}

function bindFold(box) {
  box.addEventListener('click', function (e) {
    const btn = e.target.closest('.inc-head');
    if (!btn || !box.contains(btn)) { return; }
    const sec = btn.parentNode;
    sec.classList.toggle('open');
  });
}

/* ---------------- 列表 ---------------- */
export async function renderHistory(container, id) {
  /* #/history 与 #/history/<id> 共用同一个路由名：
     带 id 时直接进详情，避免为「列表/详情」维护两套入口。 */
  if (id) { return renderIncident(container, id); }
  container.innerHTML = `
    <div class="inc-hero">
      <p class="inc-tag">档案</p>
      <h2>铭记历史</h2>
      <p class="tiny muted">这个站被攻击过几次，都在这里。每一次都写明发生了什么、影响是什么、查到了什么、还有什么没查清。</p>
    </div>
    <div id="incList" class="inc-list"><div class="skeleton"></div><div class="skeleton"></div></div>`;

  const list = container.querySelector('#incList');
  try {
    const d = await api('incident', 'list', {});
    const items = d.items || [];
    if (!items.length) {
      list.innerHTML = '<div class="empty"><p>还没有记录</p></div>';
      return;
    }
    list.innerHTML = '';
    items.forEach(function (it) {
      const el = document.createElement('div');
      el.className = 'inc-item';
      el.innerHTML = `
        <div class="inc-it-top">
          <span class="inc-it-title">${esc(it.title)}</span>
          ${it.severity ? '<span class="inc-sev ' + esc(it.severity) + '">' + esc(sevText(it.severity)) + '</span>' : ''}
        </div>
        <div class="tiny muted inc-it-time">${esc(spanText(it))}</div>
        <p class="inc-it-sum">${esc(it.summary)}</p>
        <div class="tiny muted inc-it-foot">
          <span>${it.culprit ? '始作俑者：' + esc(it.culprit) : '始作俑者：未记录'}</span>
          <span>讨论 ${Number(it.comments || 0)}</span>
        </div>`;
      el.addEventListener('click', function () { navigate('#/history/' + it.id); });
      list.appendChild(el);
    });
  } catch (e) {
    list.innerHTML = '<div class="empty"><p>' + esc(e.message) + '</p></div>';
  }
}

/* ---------------- 详情 ---------------- */
export async function renderIncident(container, id) {
  let d = null;
  try {
    d = await api('incident', 'get', { id: id }, { silent: true });
  } catch (e) {
    container.innerHTML = '<div class="empty"><p>' + esc(e.message) + '</p></div>';
    return;
  }

  container.innerHTML = `
    <div class="inc-hero">
      <p class="inc-tag">档案 · ${esc(sevText(d.severity) || '记录')}</p>
      <h2>${esc(d.title)}</h2>
      <div class="inc-meta">
        <span class="mono">${esc(d.started || '—')} — ${esc(d.ended || '至今')}</span>
      </div>
      <div class="inc-culprit">始作俑者：${esc(d.culprit || '未确认')}</div>
      ${d.summary ? '<p class="inc-lead">' + esc(d.summary) + '</p>' : ''}
    </div>
    <div class="inc-doc" id="incDoc"></div>
    <div class="card" style="margin-top:18px">
      <div id="incCmt"></div>
    </div>`;

  const doc = container.querySelector('#incDoc');
  doc.innerHTML = sectionize(mdToHtml(d.doc || ''));
  bindFold(doc);

  await renderComments(container.querySelector('#incCmt'), d.id, 'incident');
}
