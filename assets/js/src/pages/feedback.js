/**
 * 反馈页：提交反馈（公开/私密）；管理员视角为「回复反馈」
 */
import { api, state, esc, toast, dialog, btnLoading, userName, isAdminish, oidTag } from '../core.js';
import { navigate } from '../router.js';

export async function renderFeedback(container) {
  const isAdmin = isAdminish();
  container.innerHTML = `
    <div class="card">
      <div class="card-title">${isAdmin ? '回复反馈' : '反映问题'}</div>
      ${isAdmin || state.role === 'user' ? `
        <div class="field">
          <textarea class="input" id="fbText" maxlength="1000" placeholder="描述你遇到的问题或建议…"></textarea>
        </div>
        <div class="setting-row" style="border:0;padding:4px 0">
          <span class="muted">可见范围</span>
          <div class="seg" style="max-width:200px" id="visSeg">
            <button data-v="1" class="on">公开</button>
            <button data-v="0">仅管理员可见</button>
          </div>
        </div>
        <button class="btn btn-sm" id="fbSend" style="margin-top:10px">提交反馈</button>
      ` : '<p class="tiny muted">登录后可提交反馈。</p>'}
    </div>
    <div class="fb-list-head">
      <span>${isAdmin ? '全部反馈' : '反馈记录'}</span>
    </div>
    <div id="fbList"></div>`;

  let vis = 1;
  const seg = container.querySelector('#visSeg');
  if (seg) seg.addEventListener('click', e => {
    const b = e.target.closest('[data-v]'); if (!b) return;
    vis = Number(b.dataset.v);
    container.querySelectorAll('#visSeg button').forEach(x => x.classList.toggle('on', x === b));
  });

  const send = container.querySelector('#fbSend');
  if (send) send.addEventListener('click', async () => {
    const text = container.querySelector('#fbText').value.trim();
    if (text.length < 2) { toast('内容太短', 'err'); return; }
    btnLoading(send, true);
    try { await api('feedback.php', 'create', { content: text, is_public: vis }); toast('已提交'); container.querySelector('#fbText').value = ''; load(); }
    catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(send, false); }
  });

  async function load() {
    const list = container.querySelector('#fbList');
    list.innerHTML = '<div class="skeleton" style="height:70px"></div>';
    try {
      const d = await api('feedback.php', 'list');
      list.innerHTML = '';
      if (!d.items.length) { list.innerHTML = '<div class="empty" style="padding:26px">暂无反馈</div>'; return; }
      d.items.forEach(f => list.appendChild(fbNode(f, isAdmin, load)));
    } catch (e) { list.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; }
  }

  load();
}

function fbNode(f, isAdmin, reload) {
  const el = document.createElement('div');
  el.className = 'card';
  const badge = f.public ? '<span class="chip">公开</span>' : '<span class="chip" style="background:color-mix(in srgb,#f59e0b 16%,transparent);color:#b45309">私密</span>';
  el.innerHTML = `
    <div class="rank-meta" style="margin:0 0 6px">${badge}${userName(f.username, f.role)}<span class="tiny">${esc(f.time)}</span>${oidTag(f.oid)}</div>
    <div style="white-space:pre-wrap">${esc(f.content)}</div>
    ${f.reply ? `<div style="margin-top:10px;padding:9px 12px;border-radius:11px;background:color-mix(in srgb,var(--accent) 9%,transparent)">
        <div class="tiny" style="color:var(--accent);margin-bottom:3px">管理员回复 · ${esc(f.replied_at)}</div>
        <div style="white-space:pre-wrap">${esc(f.reply)}</div></div>` : ''}
    <div class="ops" style="display:flex;gap:12px;margin-top:9px;font-size:13px">
      ${isAdmin ? '<button class="link" data-act="reply" style="border:0;background:0">回复</button>' : ''}
      ${f.can_deleted ? '<button class="link" data-act="del" style="border:0;background:0;color:var(--danger)">删除</button>' : ''}
    </div>`;

  const reply = el.querySelector('[data-act="reply"]');
  if (reply) reply.addEventListener('click', async () => {
    const t = await promptText();
    if (!t) return;
    try { await api('feedback.php', 'reply', { id: f.id, content: t }); toast('已回复'); reload(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const del = el.querySelector('[data-act="del"]');
  if (del) del.addEventListener('click', async () => {
    if (await dialog('删除反馈', '确认删除？', '删除', { danger: true })) {
      try { await api('feedback.php', 'delete', { id: f.id }); toast('已删除'); reload(); }
      catch (e) { toast(e.message, 'err'); }
    }
  });
  return el;
}

function promptText() {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.innerHTML = `<h3>回复反馈</h3>
      <div class="field"><textarea class="input" id="fbReply" maxlength="1000" placeholder="输入回复…"></textarea></div>
      <div class="dialog-actions"><button class="btn-ghost" data-r="0">取消</button><button class="btn" data-r="1">发送</button></div>`;
    scrim.hidden = false;
    const ta = box.querySelector('#fbReply'); ta.focus();
    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => close(b.dataset.r === '1' ? ta.value.trim() : null)));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}
