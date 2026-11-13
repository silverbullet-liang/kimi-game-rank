/**
 * 主题目录（独立页面 #/theme）
 * ------------------------------------------------------------
 * 上：提交自己的主题（每账号默认 1 个额度，管理员可提到 3；被拒不计）
 * 下：已通过的主题列表（推荐位置顶高亮）+ 数量 + 搜索
 *     每张卡片两个动作：存为我的（云端，攒成自己的主题）/ 应用（只在本机生效）
 * 审核、推荐与额度在控制面板里做，与本页无关。
 */
import { api, state, esc, toast, dialog, btnLoading, honourBadge, userHonours } from '../core.js';
import { navigate } from '../router.js';
import { SITE_BASE } from '../base.js';

const PAGE = 20;
const MAX_BYTES = 1048576;
/* 设计规范：直接指向仓库里的规范文件 */
const SPEC_URL = 'https://github.com/silverbullet-liang/kimi-game-rank/blob/main/themes/SPEC.md';
const tq = { q: '', page: 1, pages: 0, items: [], total: 0, loading: false };

function statusTag(s) {
  const map = { pending: ['wait', t('待审核')], approved: ['ok', t('已通过')], rejected: ['bad', t('未通过')] };
  const x = map[s] || ['wait', s];
  return '<span class="th-tag ' + x[0] + '">' + esc(x[1]) + '</span>';
}

function kb(n) {
  const b = Number(n) || 0;
  return b >= 1024 ? (b / 1024).toFixed(1) + ' KB' : b + ' B';
}

export async function renderTheme(container) {
  const logged = state.role !== 'guest';
  tq.q = ''; tq.page = 1; tq.pages = 0; tq.items = []; tq.total = 0;

  container.innerHTML = `
    <div class="th-head">
      <div>
        <div class="th-title">${t('主题目录')}<span class="th-live">${t('征集进行中')}</span></div>
        <div class="tiny muted">${t('把你做好的主题交上来，审核通过后出现在下面，所有人都能一键应用。')}</div>
      </div>
      <div class="th-head-right">
        <a class="th-spec" href="${SITE_BASE}/themes.html">${IC('doc.changelog', 'ico-sm')}<span>${t('活动页')}</span></a>
        <a class="th-spec" href="${SPEC_URL}" target="_blank" rel="noopener">${IC('doc.features', 'ico-sm')}<span>${t('设计规范')}</span></a>
        <div class="th-total"><b id="thTotal">·</b><span>${t('个主题')}</span></div>
      </div>
    </div>

    ${logged ? `
    <div class="card" id="thSubCard">
      <div class="card-title">${IC('theme.palette')}${t('提交主题')}</div>
      <div class="th-quota" id="thQuota">${t('读取投稿额度…')}</div>
      <div class="th-hon" id="thHon"></div>
      <div class="th-mine" id="thMine"></div>
      <div class="th-actions">
        <button class="btn btn-sm" id="thPick">${IC('field.upload', 'ico-sm')}<span>${t('选择 JSON 文件')}</span></button>
        <button class="btn-ghost btn-sm" id="thPasteToggle">${IC('action.edit', 'ico-sm')}<span>${t('粘贴 JSON')}</span></button>
        <input type="file" id="thFile" accept=".json,application/json" hidden>
      </div>
      <div id="thPasteWrap" hidden>
        <textarea class="input" id="thText" rows="7" placeholder='{"schema": "theme/1", "id": "my-theme", "name": "我的主题", "colour": "#7C3AED"}'></textarea>
        <button class="btn btn-sm" id="thSend" style="margin-top:8px">${IC('action.send', 'ico-sm')}<span>${t('提交审核')}</span></button>
      </div>
      <div class="tiny muted" style="margin-top:8px">${t('文件最大 1 MB，需符合主题 JSON 规范；同名的 id 只能出现一次。')}</div>
    </div>` : `
    <div class="card">
      <p class="muted tiny">${t('登录后可以提交自己的主题。游客也能浏览目录并直接应用别人的主题。')}</p>
      <button class="btn btn-sm" id="thLogin">${IC('user.login', 'ico-sm')}<span>${t('去登录')}</span></button>
    </div>`}

    <div class="card">
      <div class="th-search">
        ${IC('field.search', 'ico-sm')}
        <input class="input" id="thQ" type="search" placeholder="${t('搜索主题名、作者或 id')}" autocomplete="off">
      </div>
      <div id="thList" class="th-list"><div class="skeleton" style="height:64px"></div><div class="skeleton" style="height:64px;margin-top:8px"></div></div>
      <button class="btn-ghost btn-sm" id="thMore" style="width:100%;margin-top:10px" hidden>${IC('action.expand', 'ico-sm')}<span>${t('加载更多')}</span></button>
    </div>`;

  const listBox = container.querySelector('#thList');
  const moreBtn = container.querySelector('#thMore');
  const totalEl = container.querySelector('#thTotal');

  if (!logged) {
    const lb = container.querySelector('#thLogin');
    if (lb) { lb.addEventListener('click', () => navigate('#/login')); }
  }

  /* ---------- 列表 ---------- */

  function card(row) {
    const feat = Number(row.featured) === 1;
    return `<div class="th-card${feat ? ' is-feat' : ''}" data-id="${row.id}">
      <span class="th-dot" style="background:${esc(row.accent || '#8A8F9C')}"></span>
      <span class="th-body">
        <b>${feat ? '<span class="th-feat">' + esc(t('推荐')) + '</span>' : ''}${esc(row.name || row.theme_id)}</b>
        <span class="tiny muted">${esc(row.author)}${userHonours(row.author)} · ${esc(row.created)} · ${kb(row.bytes)} · ${esc(row.theme_id)}</span>
      </span>
      <span class="th-ops">
        <button class="btn btn-sm" data-act="save">${IC('action.download', 'ico-sm')}<span>${t('存为我的')}</span></button>
        <button class="btn-ghost btn-sm" data-act="apply">${IC('field.image', 'ico-sm')}<span>${t('应用')}</span></button>
      </span>
    </div>`;
  }

  function paint() {
    if (!tq.items.length) {
      listBox.innerHTML = '<div class="empty">' + IC('state.empty') + '<p>'
        + esc(tq.q ? t('没有匹配的主题') : t('目录还是空的，来提交第一个主题')) + '</p></div>';
      moreBtn.hidden = true;
      return;
    }
    listBox.innerHTML = tq.items.map(card).join('');
    moreBtn.hidden = tq.page >= tq.pages;
  }

  async function load(reset) {
    if (tq.loading) { return; }
    tq.loading = true;
    if (reset) { tq.page = 1; }
    try {
      const r = await api('theme', 'list', { q: tq.q, page: tq.page, size: PAGE });
      tq.total = Number(r.total || 0);
      tq.pages = Number(r.pages || 0);
      const items = r.items || [];
      tq.items = reset ? items : tq.items.concat(items);
      totalEl.textContent = tq.total;
      paint();
    } catch (e) {
      listBox.innerHTML = '<div class="empty">' + IC('state.error') + '<p>' + esc(e.message) + '</p></div>';
    } finally {
      tq.loading = false;
    }
  }

  listBox.addEventListener('click', async (e) => {
    const btn = e.target.closest ? e.target.closest('[data-act]') : null;
    if (!btn) { return; }
    const row = btn.closest('.th-card');
    const id = Number(row && row.dataset.id);
    if (!id) { return; }
    const act = btn.dataset.act;
    btnLoading(btn, true);
    try {
      const one = await api('theme', 'one', { id: id });
      const TJ = window.ThemeJSON;
      if (!TJ) { toast(t('主题功能未就绪，请刷新页面'), 'err'); return; }
      const r = TJ.applySave ? TJ.applySave(one.json) : TJ.applyRaw(one.json);
      if (!r.ok) { toast(t('主题未通过校验') + '：' + ((r.errors && r.errors[0]) || ''), 'err'); return; }
      if (act === 'save') {
        if (state.role === 'guest') {
          toast(t('已应用到本机；登录后才会存入「我的主题」'), 'warn');
        } else {
          try {
            await api('theme', 'save', { json: one.json });
            toast(t('已存为我的主题，可在「我的 → 外观设置」里切换'));
          } catch (err2) {
            toast(t('已应用到本机，但云端保存失败') + '：' + ((err2 && err2.message) || ''), 'err');
          }
        }
      } else {
        toast(t('已应用该主题'));
      }
      if (r.warns && r.warns.length) { toast(t('提示') + '：' + r.warns[0], 'warn'); }
    } catch (err) {
      toast((err && err.message) || t('操作失败'), 'err');
    } finally {
      btnLoading(btn, false);
    }
  });

  moreBtn.addEventListener('click', () => { tq.page += 1; load(false); });

  const qEl = container.querySelector('#thQ');
  let qt = 0;
  qEl.addEventListener('input', () => {
    clearTimeout(qt);
    qt = setTimeout(() => { tq.q = qEl.value.trim(); load(true); }, 320);
  });

  load(true);

  if (!logged) { return; }

  /* ---------- 我的投稿与额度 ---------- */

  const quotaEl = container.querySelector('#thQuota');
  const mineEl = container.querySelector('#thMine');
  const subCard = container.querySelector('#thSubCard');

  function quotaLine(quota, used) {
    const q = Number(quota || 1);
    const u = Number(used || 0);
    const left = Math.max(0, q - u);
    return '<div class="th-qbar">'
      + '<span>' + t('投稿额度') + ' <b>' + u + ' / ' + q + '</b></span>'
      + '<span class="tiny muted">' + (left > 0
        ? t('还可以提交 ') + left + t(' 个')
        : t('额度已用完，撤回一份后可再提交')) + '</span>'
      + '</div>';
  }

  function mineRow(it) {
    return `<div class="th-mrow">
      <span class="th-dot" style="background:${esc(it.accent || '#8A8F9C')}"></span>
      <span class="th-body">
        <b>${esc(it.name || it.theme_id)}</b>
        <span class="tiny muted">${esc(it.created)} · ${kb(it.bytes)} · ${esc(it.theme_id)}</span>
        ${it.status === 'rejected' && it.reason ? '<span class="tiny th-reason">' + esc(t('原因：')) + esc(it.reason) + '</span>' : ''}
      </span>
      ${statusTag(it.status)}
      <button class="link th-del" data-withdraw="${it.id}">${IC('action.delete', 'ico-xs')}<span>${t('撤回')}</span></button>
    </div>`;
  }

  async function loadMine() {
    try {
      const r = await api('theme', 'mine');
      quotaEl.innerHTML = quotaLine(r.quota, r.used);
      const honEl = container.querySelector('#thHon');
      if (honEl) {
        const ks = r.kinds || [];
        honEl.innerHTML = ks.length
          ? ks.map(function (k) { return honourBadge(k); }).join('')
          : '<span class="tiny muted">' + t('还没有徽章：第一套主题通过审核后就会出现在名字旁边') + '</span>';
      }
      const items = r.items || [];
      mineEl.innerHTML = items.length
        ? items.map(mineRow).join('')
        : '<div class="tiny muted th-none">' + t('还没有提交过主题。') + '</div>';
      subCard.classList.toggle('th-full', Number(r.remaining || 0) <= 0);
    } catch (e) {
      quotaEl.textContent = t('额度读取失败') + '：' + (e.message || '');
    }
  }

  mineEl.addEventListener('click', async (e) => {
    const b = e.target.closest ? e.target.closest('[data-withdraw]') : null;
    if (!b) { return; }
    const id = Number(b.dataset.withdraw || 0);
    if (!id) { return; }
    const yes = await dialog(t('撤回主题'), t('撤回后这份主题会从目录里删除，投稿额度随之释放。确认撤回？'), t('撤回'), { danger: 1 });
    if (!yes) { return; }
    try {
      await api('theme', 'withdraw', { id: id });
      toast(t('已撤回'));
      await loadMine();
      await load(true);
    } catch (err) {
      toast((err && err.message) || t('操作失败'), 'err');
    }
  });

  loadMine();

  /* ---------- 提交 ---------- */

  const fileEl = container.querySelector('#thFile');
  const pasteWrap = container.querySelector('#thPasteWrap');
  const textEl = container.querySelector('#thText');
  const sendBtn = container.querySelector('#thSend');

  container.querySelector('#thPick').addEventListener('click', () => fileEl.click());
  container.querySelector('#thPasteToggle').addEventListener('click', () => {
    pasteWrap.hidden = !pasteWrap.hidden;
  });

  async function doSubmit(text) {
    if (!text) { toast(t('主题内容为空'), 'err'); return; }
    if (text.length > MAX_BYTES) { toast(t('主题文件超过 1 MB'), 'err'); return; }
    sendBtn && btnLoading(sendBtn, true);
    try {
      const r = await api('theme', 'submit', { json: text }, { timeout: 30000 });
      toast(t('已提交，等待审核'));
      textEl.value = '';
      pasteWrap.hidden = true;
      await loadMine();
      if (r && r.remaining !== undefined) { toast(t('剩余额度') + '：' + r.remaining); }
    } catch (e) {
      toast((e && e.message) || t('提交失败'), 'err');
    } finally {
      sendBtn && btnLoading(sendBtn, false);
    }
  }

  if (sendBtn) { sendBtn.addEventListener('click', () => doSubmit(textEl.value.trim())); }

  fileEl.addEventListener('change', () => {
    const f = fileEl.files && fileEl.files[0];
    if (!f) { return; }
    if (f.size > MAX_BYTES) { toast(t('主题文件超过 1 MB'), 'err'); fileEl.value = ''; return; }
    const reader = new FileReader();
    reader.onload = () => { doSubmit(String(reader.result || '')); };
    reader.onerror = () => toast(t('读取文件失败'), 'err');
    reader.readAsText(f);
    fileEl.value = '';
  });
}
