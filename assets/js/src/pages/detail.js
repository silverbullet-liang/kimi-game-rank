/**
 * 作品详情页：雷达图 + 六维明细 + 介绍 + 评论区
 */
import { api, state, esc, toast, dialog, btnLoading, userName, isAdminish, isBlocked } from '../core.js';
import { setHeroDst } from '../transitions.js';
import { navigate } from '../router.js';

/** 具备发言资格：普通用户 / 管理员 / 副管理员 */
const canPost = () => state.role === 'user' || isAdminish();
/** 可删除任意评论 */
const canModerate = () => isAdminish();

const DIMS = [
  { k: 'creativity', name: '创意' },
  { k: 'experience', name: '体验' },
  { k: 'depth', name: '深度' },
  { k: 'cost', name: '成本' },
  { k: 'attitude', name: '态度' },
  { k: 'heat', name: '热度' },
];

export async function renderDetail(container, ctx) {
  const id = parseInt((ctx.params && ctx.params.id) || '0', 10) || parseInt((ctx.sub || '0'), 10);
  if (!id) { container.innerHTML = '<div class="empty"><p>作品不存在</p></div>'; return; }

  container.innerHTML = '<div class="skeleton" style="height:220px"></div>';
  let d;
  try { d = await api('works.php', 'detail', { id: id }); }
  catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }

  const sd = d.score_detail || {};
  container.innerHTML = `
    <div class="detail-head">
      <button class="back-btn" id="backBtn">
        <svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:currentColor"><path d="M15 5l-7 7 7 7 1.4-1.4L10.8 12l5.6-5.6z"/></svg>
        返回榜单
      </button>
    </div>

    <div class="card">
      <div class="radar-wrap" id="radarWrap"></div>
      <div style="text-align:center;margin-top:6px">
        <div style="font-size:30px;font-weight:800;color:var(--accent)">${d.total}</div>
        <span class="rating r-${esc(d.rating)}">${esc(d.rating)}</span>
      </div>
      <div style="margin-top:12px">
        <div style="font-weight:700;font-size:17px">${esc(d.title)}</div>
        <div class="rank-meta" style="margin-top:5px">
          <span class="chip">${esc(d.category_name)}</span>
          <span>${esc(d.author)}</span>
        </div>
      </div>
    </div>

    ${((d.images && d.images.length) || d.cover) ? `
    <div class="card">
      <div class="card-title">
        <svg viewBox="0 0 24 24" class="ic"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 12h14l-4.5-6-3.5 4.5-2.5-2.5L5 17zm3.5-7a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2z"/></svg>
        作品预览
      </div>
      <div class="shot-grid">
        ${(d.images && d.images.length ? d.images : [d.cover]).map((u, i) => `<button class="shot" data-i="${i}"><img src="${esc(u)}" alt="预览图 ${i + 1}" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false"></button>`).join('')}
      </div>
      <div class="tiny" style="margin-top:8px">点击图片可查看大图</div>
    </div>` : ''}

    <div class="card">
      <div class="card-title">
        <svg viewBox="0 0 24 24" class="ic"><path d="M7 6h10a5 5 0 0 1 5 5v3a4 4 0 0 1-4 4h-1a3 3 0 0 1-2.4-1.2l-.6-.8a2 2 0 0 0-3.2 0l-.6.8A3 3 0 0 1 7.4 18H7a4 4 0 0 1-4-4v-3a5 5 0 0 1 5-5z"/></svg>
        作品详情与介绍
      </div>
      <p class="muted" style="white-space:pre-wrap">${esc(d.intro || '暂无介绍')}</p>
      ${d.link ? `<button class="btn-link" id="openLink" data-href="${esc(d.link)}">
        <svg viewBox="0 0 24 24" class="ic" style="width:15px;height:15px;fill:currentColor"><path d="M10.6 13.4a1 1 0 0 1 0-1.4l4.2-4.2a3 3 0 1 1 4.2 4.2l-1.8 1.8-1.4-1.4 1.8-1.8a1 1 0 0 0-1.4-1.4l-4.2 4.2a1 1 0 0 1-1.4 0zm2.8-2.8a1 1 0 0 1 0 1.4l-4.2 4.2a1 1 0 0 0 1.4 1.4l1.8-1.8 1.4 1.4-1.8 1.8a3 3 0 0 1-4.2-4.2l4.2-4.2a1 1 0 0 1 1.4 0z"/></svg>
        ${d.html_url ? '打开作品（CDN 直链）' : '查看作品分享链接'}
      </button>
      <div class="tiny" style="margin-top:6px;word-break:break-all">${esc(d.link)}</div>` : ''}
      <div class="tiny" style="margin-top:8px">作品 ID：${esc(d.source_id || '')} · 收录于 ${esc(d.added_at || '')}</div>
    </div>

    <div class="card">
      <div class="card-title">${esc(d.category_name)} 六维评分明细</div>
      <div class="dim-grid">
        ${DIMS.map(x => `<div class="dim"><div class="k">${x.name}</div><div class="v">${Number(sd[x.k] || 0)}</div><div class="g">${gradeOf(Number(sd[x.k] || 0))}</div></div>`).join('')}
      </div>
      <div class="tiny" style="margin-top:10px">历史峰值总分 ${d.peak_score} · 更新于 ${esc(d.updated_at)}</div>
      <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
        <button class="btn ${d.voted ? 'btn-ghost' : ''}" id="voteBtn">${d.voted ? '已点赞' : '点赞作品'}</button>
        <span class="tiny">点赞计入「投票榜」，每人每作品限一次</span>
      </div>
    </div>

    <div class="card">
      <div class="card-title">评论区（<span id="cmtTotal">${d.comment_total}</span>）</div>
      <div class="comment-tip">
        <svg viewBox="0 0 24 24" class="ic"><path d="M12 2l8 4v6c0 5-3.4 8.6-8 10-4.6-1.4-8-5-8-10V6l8-4z"/></svg>
        文明发言，共建良好社区
      </div>
      <div id="cmtForm"></div>
      <div id="cmtList"></div>
    </div>
  `;

  /* 共享元素：详情页首图与榜单卡片封面同名 → 进入时长出、返回时缩回原处 */
  setHeroDst(container.querySelector('.shot'));

  container.querySelector('#backBtn').addEventListener('click', () => history.length > 1 ? history.back() : navigate('#/rank'));
  const link = container.querySelector('#openLink');
  if (link) link.addEventListener('click', () => window.open(link.dataset.href || d.link, '_blank', 'noopener'));

  // 预览图灯箱（自绘，非原生 dialog）
  const shots = container.querySelectorAll('.shot');
  if (shots.length) {
    const gallery = (d.images && d.images.length) ? d.images : [d.cover];
    shots.forEach(b => b.addEventListener('click', () => openLightbox(gallery, Number(b.dataset.i))));
  }

  // 雷达图
  container.querySelector('#radarWrap').innerHTML = radarSvg(DIMS.map(x => Number(sd[x.k] || 0)));

  // 点赞
  const voteBtn = container.querySelector('#voteBtn');
  voteBtn.addEventListener('click', async () => {
    if (!canPost()) { toast('登录后才能点赞', 'err'); return; }
    try {
      const r = await api('works.php', 'vote', { id: id });
      voteBtn.textContent = r.voted ? '已点赞' : '点赞作品';
      voteBtn.classList.toggle('btn-ghost', r.voted);
      toast(r.voted ? '已点赞' : '已取消');
    } catch (e) { toast(e.message, 'err'); }
  });

  renderComments(container, id);
}

function gradeOf(v) {
  if (v >= 190) return '超神品';
  if (v >= 160) return '优秀';
  if (v >= 120) return '良好';
  if (v >= 80) return '及格';
  return '待提升';
}

/* ============================================================
 * 雷达图（纯 SVG 自绘）
 * ============================================================ */
function radarSvg(values) {
  const size = 260, cx = size / 2, cy = size / 2, R = 96;
  const n = 6;
  const angle = i => (-Math.PI / 2) + i * (2 * Math.PI / n);
  const pt = (i, r) => [cx + Math.cos(angle(i)) * r, cy + Math.sin(angle(i)) * r];

  let grid = '';
  [0.25, 0.5, 0.75, 1].forEach(f => {
    const pts = [];
    for (let i = 0; i < n; i++) { const p = pt(i, R * f); pts.push(p[0].toFixed(1) + ',' + p[1].toFixed(1)); }
    grid += `<polygon points="${pts.join(' ')}" fill="none" stroke="currentColor" stroke-opacity="${f === 1 ? 0.35 : 0.14}" stroke-width="1"/>`;
  });
  let spokes = '';
  for (let i = 0; i < n; i++) {
    const p = pt(i, R);
    spokes += `<line x1="${cx}" y1="${cy}" x2="${p[0].toFixed(1)}" y2="${p[1].toFixed(1)}" stroke="currentColor" stroke-opacity="0.16"/>`;
  }

  const dataPts = values.map((v, i) => {
    const r = R * Math.max(0, Math.min(1, v / 200));
    const p = pt(i, r);
    return p[0].toFixed(1) + ',' + p[1].toFixed(1);
  });

  let dots = '';
  values.forEach((v, i) => {
    const r = R * Math.max(0, Math.min(1, v / 200));
    const p = pt(i, r);
    dots += `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3.2" fill="#f59e0b"/>`;
  });

  return `<svg viewBox="0 0 ${size} ${size}" style="width:min(260px,72vw);height:auto;color:var(--accent)">
    <g>${grid}${spokes}</g>
    <polygon points="${dataPts.join(' ')}" fill="color-mix(in srgb, var(--accent) 22%, transparent)" stroke="#f59e0b" stroke-width="2"/>
    ${dots}
  </svg>`;
}

/* ============================================================
 * 图片灯箱（自绘）
 * ============================================================ */
function openLightbox(images, index) {
  let cur = index || 0;
  const box = document.createElement('div');
  box.className = 'lightbox';
  box.innerHTML = `
    <button class="lb-close" aria-label="关闭">
      <svg viewBox="0 0 24 24" class="ic"><path d="M6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4 6.4 5z"/></svg>
    </button>
    <img class="lb-img" src="" alt="" draggable="false">
    <div class="lb-bar">
      <button class="lb-prev" aria-label="上一张">上一张</button>
      <span class="lb-idx"></span>
      <button class="lb-next" aria-label="下一张">下一张</button>
    </div>`;
  document.body.appendChild(box);

  const img = box.querySelector('.lb-img');
  const idx = box.querySelector('.lb-idx');
  function paint() {
    img.src = images[cur];
    idx.textContent = (cur + 1) + ' / ' + images.length;
    const multi = images.length > 1;
    box.querySelector('.lb-prev').style.visibility = multi ? 'visible' : 'hidden';
    box.querySelector('.lb-next').style.visibility = multi ? 'visible' : 'hidden';
  }
  paint();

  const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
  const onKey = e => {
    if (e.key === 'Escape') { close(); }
    if (e.key === 'ArrowLeft' && cur > 0) { cur--; paint(); }
    if (e.key === 'ArrowRight' && cur < images.length - 1) { cur++; paint(); }
  };
  box.querySelector('.lb-close').addEventListener('click', close);
  box.querySelector('.lb-prev').addEventListener('click', () => { if (cur > 0) { cur--; paint(); } });
  box.querySelector('.lb-next').addEventListener('click', () => { if (cur < images.length - 1) { cur++; paint(); } });
  box.addEventListener('click', e => { if (e.target === box) { close(); } });
  document.addEventListener('keydown', onKey);
}

/* ============================================================
 * 评论区
 * ============================================================ */
async function renderComments(container, workId) {
  const form = container.querySelector('#cmtForm');
  const listBox = container.querySelector('#cmtList');

  /* ---------- 发表区 ---------- */
  if (canPost()) {
    form.innerHTML = `
      <div class="field">
        <textarea class="input" id="cmtText" maxlength="300" placeholder="写下你的看法…"></textarea>
        <div class="tiny" style="text-align:right"><span id="cmtCount">0</span>/300</div>
      </div>
      <button class="btn btn-sm" id="cmtSend">发表评论</button>`;
    const ta = form.querySelector('#cmtText');
    ta.addEventListener('input', () => { form.querySelector('#cmtCount').textContent = ta.value.length; });
    form.querySelector('#cmtSend').addEventListener('click', async () => {
      const btn = form.querySelector('#cmtSend');
      const v = ta.value.trim();
      if (!v) { toast('请输入内容', 'err'); return; }
      btnLoading(btn, true);
      try {
        await api('comments.php', 'create', { work_id: workId, content: v }, { timeout: 30000 });
        ta.value = ''; form.querySelector('#cmtCount').textContent = '0';
        await refresh(true);
        toast('已发表');
      } catch (e) {
        if (e && e.code === 422) {
          commentReject(form.parentNode, e.message || '内容未通过审核', v,
            () => form.querySelector('#cmtSend').click());
        } else { toast(e.message, 'err'); }
      }
      finally { btnLoading(btn, false); }
    });
  } else {
    form.innerHTML = '<p class="tiny">登录后可发表评论 · <span class="link" id="goLogin">去登录</span></p>';
    form.querySelector('#goLogin').addEventListener('click', () => navigate('#/login'));
  }

  /* ---------- 列表状态 ---------- */
  const PAGE = 5;    // 首屏展示的根评论数
  const FOLD = 2;    // 楼中楼默认展开条数
  const st = { roots: [], total: -1, shown: PAGE };
  const unfolded = {};   // 根评论 id → 是否展开（自动刷新后保持）

  function paint() {
    const roots = st.roots;
    listBox.innerHTML = '';
    if (!roots.length) {
      listBox.innerHTML = '<div class="empty" style="padding:20px">还没有评论，来抢沙发</div>';
      return;
    }
    roots.slice(0, st.shown).forEach(c => {
      listBox.appendChild(commentNode(c, workId, refresh));
      const reps = c.replies || [];
      if (!reps.length) { return; }
      const wrap = document.createElement('div');
      wrap.className = 'replies';
      let open = !!unfolded[c.id];
      const draw = () => {
        wrap.innerHTML = '';
        (open ? reps : reps.slice(0, FOLD)).forEach(r =>
          wrap.appendChild(commentNode(Object.assign({}, r, { _sub: 1 }), workId, refresh)));
        if (reps.length > FOLD) {
          const t = document.createElement('button');
          t.className = 'fold-toggle';
          t.textContent = open ? '收起回复' : '展开全部 ' + reps.length + ' 条回复';
          t.addEventListener('click', () => { open = !open; unfolded[c.id] = open; draw(); });
          wrap.appendChild(t);
        }
      };
      draw();
      listBox.appendChild(wrap);
    });
    if (st.shown < roots.length) {
      const more = document.createElement('button');
      more.className = 'btn-ghost btn-sm';
      more.style.cssText = 'width:100%;margin-top:8px';
      more.textContent = '查看更多评论（还有 ' + (roots.length - st.shown) + ' 条）';
      more.addEventListener('click', () => { st.shown = roots.length; paint(); });
      listBox.appendChild(more);
    }
  }

  async function refresh(showSkeleton) {
    if (showSkeleton) { listBox.innerHTML = '<div class="skeleton" style="height:44px"></div>'; }
    try {
      const d = await api('comments.php', 'list', { work_id: workId }, showSkeleton ? {} : { silent: true });
      st.roots = d.comments || [];
      st.total = Number(d.total || 0);
      if (st.shown < PAGE) { st.shown = PAGE; }
      paint();
      const tot = container.querySelector('#cmtTotal');
      if (tot) { tot.textContent = st.total; }
    } catch (e) {
      if (showSkeleton) {
        listBox.innerHTML = `<div class="empty"><p>${esc(e.message)}</p><button class="btn-ghost btn-sm" id="cmtRetry">重试</button></div>`;
        const rb = listBox.querySelector('#cmtRetry');
        if (rb) { rb.addEventListener('click', () => refresh(true)); }
      }
    }
  }

  /* ---------- 自动轮询：仅在"有新评论"时重绘，且不打断正在输入 ---------- */
  window.__addPageTimer(setInterval(() => {
    if (document.hidden || !listBox.isConnected) { return; }
    const ae = document.activeElement;
    if (ae && (ae.id === 'cmtText' || ae.id === 'rpText')) { return; }
    const scrim = document.getElementById('dialogScrim');
    if (scrim && !scrim.hidden) { return; }
    api('comments.php', 'list', { work_id: workId }, { silent: true, tries: 1 })
      .then(d => {
        if (Number(d.total || 0) === st.total) { return; }   // 无新评论 → 不重绘
        st.roots = d.comments || [];
        st.total = Number(d.total || 0);
        paint();
        const tot = container.querySelector('#cmtTotal');
        if (tot) { tot.textContent = st.total; }
      })
      .catch(() => {});
  }, 25000));

  await refresh(true);
}

/**
 * 评论被审核拦下时的提示条（与对话区同一套机制）。
 * 带「AI 重审」按钮：点它让模型单独复核一次，返回 true / middle / false；
 * false 维持拦截，true 与 middle 都会自动重发（middle 会在评论旁标注「可能有恶意」）。
 */
function commentReject(host, text, content, onPass) {
  if (!host) { return; }
  const old = host.querySelector('.reject-note');
  if (old) { old.remove(); }
  const bar = document.createElement('div');
  bar.className = 'reject-note';
  bar.innerHTML = '<span class="rn-text">' + esc(text) + '</span>'
    + '<button class="btn btn-sm" data-review="1">AI 重审</button>';
  host.insertBefore(bar, host.firstChild);

  const btn = bar.querySelector('[data-review]');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    btn.textContent = '重审中…';
    try {
      const r = await api('recheck.php', 'run', { content: content }, { timeout: 30000 });
      const v = (r && r.verdict) || 'false';
      if (v === 'false') {
        bar.querySelector('.rn-text').textContent = 'AI 复核后仍判为违规，未发表';
        btn.remove();
        return;
      }
      bar.remove();
      if (v === 'middle') { toast('AI 复核通过，将标注「可能有恶意」后发表'); }
      onPass();
    } catch (err) {
      btn.disabled = false;
      btn.textContent = 'AI 重审';
      bar.querySelector('.rn-text').textContent = (err && err.message) ? err.message : '重审失败，请稍后再试';
    }
  });
}

function commentNode(c, workId, reload) {
  const box = document.createElement('div');
  box.className = 'comment' + (c._sub ? ' sub' : '');
  box.dataset.cid = c.id;
  /* 折叠的两种来源：管理员屏蔽（c.blocked）与你自己的屏蔽词（isBlocked）。
     已删除的评论服务端不会再下发，因此这里完全没有「已删除」分支。 */
  const folded = !!c.blocked || isBlocked(c.content);
  const noteText = c.blocked ? '该评论已被折叠，点击查看' : '已按你的屏蔽规则收起，点击查看';
  const modBtn = canModerate() ? `<button data-act="block">${c.blocked ? '取消屏蔽' : '屏蔽'}</button>` : '';
  box.innerHTML = `
    <span class="av"><img src="${esc(c.avatar)}" alt="" draggable="false"></span>
    <span class="body">
      <span class="head">
        ${userName(c.username, c.role)}
        <span class="tiny">${esc(c.time)}</span>
      </span>
      <span class="text">${c.reply_to ? `<span class="reply-to">@${esc(c.reply_to)}</span> ` : ''}${folded
        ? `<span class="blocked-note" data-reveal>${noteText}</span><span class="blocked-body" hidden>${esc(c.content)}</span>`
        : esc(c.content)}</span>
      ${c.flag === 'middle' ? '<span class="msg-flag" title="AI 复核认为可能有恶意，但仍予放行">可能有恶意</span>' : ''}
      <span class="ops">
        <button data-act="like">赞 ${c.likes || 0}</button>
        ${canPost() ? '<button data-act="reply">回复</button>' : ''}
        ${(c.mine || canModerate()) ? '<button data-act="del">删除</button>' : ''}
        ${modBtn}
      </span>
    </span>`;

  const rev = box.querySelector('[data-reveal]');
  if (rev) {
    rev.addEventListener('click', () => {
      const body = box.querySelector('.blocked-body');
      body.hidden = !body.hidden;
      rev.textContent = body.hidden ? noteText : '收起';
    });
  }

  box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async ev => {
    ev.stopPropagation();
    const act = b.dataset.act;
    if (act === 'like') {
      if (!canPost()) { toast('登录后才能点赞', 'err'); return; }
      try { const r = await api('comments.php', 'vote', { id: c.id }); b.textContent = '赞 ' + r.count; } catch (e) { toast(e.message, 'err'); }
    } else if (act === 'del') {
      if (await dialog('删除评论', '确认删除这条评论吗？删除后会移入回收站，页面不再显示。', '删除', { danger: true })) {
        try { await api('comments.php', 'delete', { id: c.id }); toast('已移入回收站'); reload(true); } catch (e) { toast(e.message, 'err'); }
      }
    } else if (act === 'block') {
      try {
        const r = await api('comments.php', 'block', { id: c.id, on: c.blocked ? 0 : 1 });
        toast(r.blocked ? '已屏蔽（折叠显示）' : '已取消屏蔽');
        reload(true);
      } catch (e) { toast(e.message, 'err'); }
    } else if (act === 'reply') {
      const text = await promptReply(c.username);
      if (text) {
        const post = async () => {
          try {
            await api('comments.php', 'create', { work_id: workId, content: text, parent_id: c.id }, { timeout: 30000 });
            toast('已回复');
            reload(true);
          } catch (e) {
            if (e && e.code === 422) { commentReject(box, e.message || '内容未通过审核', text, post); }
            else { toast(e.message, 'err'); }
          }
        };
        await post();
      }
    }
  }));

  return box;
}

function promptReply(toName) {
  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.innerHTML = `<h3>回复${toName ? ' ' + esc(toName) : ''}</h3>
      <div class="field"><textarea class="input" id="rpText" maxlength="300" placeholder="写下回复…"></textarea></div>
      <div class="dialog-actions"><button class="btn-ghost" data-r="0">取消</button><button class="btn" data-r="1">发送</button></div>`;
    scrim.hidden = false;
    const ta = box.querySelector('#rpText'); ta.focus();
    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => close(b.dataset.r === '1' ? ta.value.trim() : null)));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}
