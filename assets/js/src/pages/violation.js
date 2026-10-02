/**
 * 违纪通报页面
 * ------------------------------------------------------------
 * 两个入口共用一套渲染：
 *   · renderViolation —— 被通报者访问站点时被 302 到这里（?violation=<id>），
 *     顶部给出说明，下方逐条列出理由，再往下是评论区；
 *   · renderDiscipline —— 公开的通报列表与单条详情（#/discipline[/id]）。
 */
import { api, esc, toast, $, on, btnLoading, userName, badge, state } from '../core.js';
import { navigate } from '../router.js';
import { renderComments } from './detail.js';

function reasonList(reasons) {
  if (!reasons || !reasons.length) { return '<p class="tiny muted">（未填写理由）</p>'; }
  return '<ol class="disc-reasons">' + reasons.map(r => '<li>' + esc(r) + '</li>').join('') + '</ol>';
}

function banText(row) {
  if (!row.banned) { return '未封停账号'; }
  if (!row.ban_until) { return '账号已封停 · 永久'; }
  return '账号已封停 · 至 ' + esc(row.ban_until);
}

/** 违纪界面 / 通报详情：$mine 为真时是「你被通报了」的口吻 */
async function paintDetail(container, id, mine) {
  const d = await api('discipline.php', 'get', { id: id }, { silent: true });
  const wrap = document.createElement('div');

  wrap.innerHTML = `
    <div class="violation-hero">
      <p class="vh-tag">${mine ? '你的访问已被限制' : '公开通报'}</p>
      <h2>违纪通报</h2>
      <p class="tiny">因下列原因，本站已对相关账号与来源地址作出处理。</p>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="row" style="align-items:center;gap:10px">
        <img class="avatar" src="${esc(d.avatar)}" width="40" height="40" draggable="false" alt="">
        <div style="flex:1">
          <div><b>${esc(d.username || '（未知用户）')}</b></div>
          <div class="tiny muted">通报于 ${esc(d.created)} · 浏览 ${Number(d.views || 0)}</div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:12px">
      <h3 style="margin-top:0">通报理由</h3>
      ${reasonList(d.reasons)}
      ${d.note ? '<p class="tiny" style="margin-top:10px">补充说明：' + esc(d.note) + '</p>' : ''}
      <p class="tiny muted" style="margin-top:10px">${esc(banText(d))}${d.ip_banned ? ' · 来源地址已封禁' : ''}</p>
    </div>

    <div class="card" id="discCmt" style="margin-top:12px">
      <h3 style="margin-top:0">评论 <span class="tiny muted" id="cmtTotal">0</span></h3>
      <div id="cmtForm"></div>
      <div id="cmtList" style="margin-top:12px"></div>
    </div>`;

  container.appendChild(wrap);

  try {
    await renderComments(wrap.querySelector('#discCmt'), d.id, 'discipline');
  } catch (e) {
    const box = wrap.querySelector('#cmtList');
    if (box) { box.innerHTML = '<div class="empty tiny">评论加载失败</div>'; }
  }
}

/** 被通报者被拦下后进入的界面：默认取 302 带来的 id */
export async function renderViolation(container, ctx) {
  const params = (ctx && ctx.params) || {};
  const id = Number((ctx && ctx.sub) || params.violation || params.id || 0);
  if (id <= 0) {
    container.innerHTML = '<div class="empty"><p>没有可显示的通报</p></div>';
    return;
  }
  await paintDetail(container, id, true);
}

/** 公开的通报列表；带 id 参数时直接看单条 */
export async function renderDiscipline(container, ctx) {
  const sub = String((ctx && ctx.sub) || '');
  if (/^\d+$/.test(sub)) { await paintDetail(container, Number(sub), false); return; }

  const box = document.createElement('div');
  box.innerHTML = '<h2 style="margin:0 0 4px">违纪通报</h2>'
    + '<p class="tiny muted" style="margin:0 0 14px">这里记录本站对违规账号的处理，每一条都可以评论。</p>'
    + '<div id="discList"><div class="skeleton" style="height:64px"></div><div class="skeleton"></div></div>';
  container.appendChild(box);

  const list = box.querySelector('#discList');
  try {
    const d = await api('discipline.php', 'list', { page: 1 }, { silent: true });
    const items = d.items || [];
    if (!items.length) { list.innerHTML = '<div class="empty">还没有通报记录</div>'; return; }
    list.innerHTML = '';
    items.forEach(it => {
      const el = document.createElement('div');
      el.className = 'card disc-item';
      el.innerHTML = `
        <div class="row" style="align-items:center;gap:10px">
          <img class="avatar" src="${esc(it.avatar)}" width="36" height="36" draggable="false" alt="">
          <div style="flex:1;min-width:0">
            <div><b>${esc(it.username || '（未知用户）')}</b>${it.banned ? ' <span class="tiny muted">· 已封停</span>' : ''}</div>
            <div class="tiny muted">${esc(it.created)} · 评论 ${Number(it.comments || 0)}</div>
          </div>
        </div>
        ${reasonList(it.reasons.slice(0, 3))}`;
      el.addEventListener('click', () => navigate('#/discipline/' + it.id));
      list.appendChild(el);
    });
  } catch (e) {
    list.innerHTML = '<div class="empty"><p>' + esc(e.message) + '</p></div>';
  }
}
