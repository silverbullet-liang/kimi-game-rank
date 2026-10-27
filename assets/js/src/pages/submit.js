/**
 * 提交作品页（登录用户）
 * 流程：填登录态 → 搜 Kimi 社区 → 选中作品自动填表 → 确认提交
 * 作品 ID 只能由搜索结果带入，不允许手填
 */
import { api, state, toast, esc, btnLoading, dialog } from '../core.js';
import { t } from '../i18n.js';
import { navigate } from '../router.js';

const QR_LIB = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
const KIMI_AUTH = 'https://auth.kimi.com/api/account.gateway.v1.AuthService/';

/* 设备标识：官方扫码链接带 device_id，缺它时手机端可能认不出会话 */
function qrDeviceId() {
  let id = '';
  try { id = localStorage.getItem('kimgr_qrdev') || ''; } catch (e) {}
  if (!id) {
    id = 'web' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    try { localStorage.setItem('kimgr_qrdev', id); } catch (e) {}
  }
  return id;
}

/* 备用通道：本站代理受主机出站限制时，浏览器直连官方认证接口（其 CORS 为 *） */
async function kimiAuth(method, body) {
  const r = await fetch(KIMI_AUTH + method, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  if (!r.ok) { throw new Error('HTTP ' + r.status); }
  return await r.json();
}

function qrContent(code) {
  return 'https://www.kimi.com/wechat/mp/auth?id=' + encodeURIComponent(code)
    + '&device_id=' + encodeURIComponent(qrDeviceId());
}

function sbLoadQR(cb) {
  if (window.QRCode) { cb(true); return; }
  const s = document.createElement('script');
  s.src = QR_LIB;
  s.onload = () => cb(!!window.QRCode);
  s.onerror = () => cb(false);
  document.head.appendChild(s);
}

export async function renderSubmit(container) {
  if (state.role === 'guest') {
    container.innerHTML = `
      <div class="card">
        <div class="card-title">提交作品</div>
        <p class="muted">登录后才能提交作品。游客可以先搜索榜单，看看想找的作品在不在。</p>
        <button class="btn" id="sbGoLogin" type="button" style="margin-top:12px">去登录 / 注册</button>
      </div>`;
    container.querySelector('#sbGoLogin').addEventListener('click', () => navigate('#/login'));
    return;
  }

  container.innerHTML = `
    <div class="card">
      <div class="card-title">第一步 · 登录态</div>
      <p class="tiny muted">搜索和读取作品都要用到它。只用于本次操作，不会保存。</p>
      <div class="field">
        <textarea class="input" id="sbToken" rows="3" placeholder="${t('粘贴 Kimi accessToken')}"></textarea>
        <div class="sb-row">
          <button class="btn btn-sm btn-ghost" id="sbQr" type="button">扫码登录</button>
          <span class="tiny muted">扫码或粘贴，二选一</span>
        </div>
        <div id="sbQrBox" class="sb-qr" hidden>
          <div id="sbQrImg" class="sb-qr-img"></div>
          <div class="tiny muted" id="sbQrTip">用 Kimi App 扫码授权</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">第二步 · 搜索作品</div>
      <p class="tiny muted">在 Kimi 社区里搜，选中后自动填好下面的表单。</p>
      <div class="field">
        <div class="sb-row">
          <input class="input" id="sbSearch" style="flex:1;min-width:0" placeholder="${t('作品名称 / 关键词')}" autocomplete="off">
          <button class="btn btn-sm" id="sbSearchGo" type="button">${t('搜索')}</button>
        </div>
      </div>
      <div id="sbHits"></div>
    </div>

    <div class="card">
      <div class="card-title">第三步 · 确认信息</div>
      <div class="field">
        <label>作品名称 <span class="req">*</span></label>
        <input class="input" id="sbName" maxlength="120" placeholder="${t('选中作品后自动填入，可修改')}">
      </div>
      <div class="field">
        <label>作品 ID <span class="req">*</span></label>
        <input class="input" id="sbId" readonly placeholder="${t('由上方搜索结果自动填入')}">
      </div>
      <div class="field">
        <label>封面链接</label>
        <input class="input" id="sbCover" maxlength="500" placeholder="${t('自动带入作品封面，可修改')}" autocomplete="off">
      </div>
      <button class="btn" id="sbSubmit" type="button">提交并收录</button>
      <div class="tiny muted" id="sbHint" style="margin-top:10px">每个账号每天可提交 2 次。提交后立即抓取并评分。</div>
    </div>`;

  const $ = (id) => container.querySelector('#' + id);
  const hits = $('sbHits');
  const hint = $('sbHint');
  const tokenEl = $('sbToken');

  /* ---------- 扫码登录 ---------- */
  let qrTimer = 0;
  const stopQR = () => { if (qrTimer) { clearInterval(qrTimer); qrTimer = 0; } };

  $('sbQr').addEventListener('click', async () => {
    const box = $('sbQrBox');
    if (!box.hidden) { box.hidden = true; stopQR(); return; }
    btnLoading($('sbQr'), true);
    try {
      let d = null;
      try {
        const r = await api('submit', 'qr_new', null, { silent: true });
        if (r && r.code) { d = { code: r.code, content: qrContent(r.code) }; }   // 统一带 device_id
      } catch (e) { /* 主机侧取不到 → 浏览器直连 */ }
      if (!d) {
        const j = await kimiAuth('CreateLoginQRCode', {});
        if (!j || !j.code) { throw new Error(t('扫码会话创建失败')); }
        d = { code: j.code, content: qrContent(j.code) };
      }
      btnLoading($('sbQr'), false);
      box.hidden = false;
      $('sbQrImg').innerHTML = '';
      $('sbQrTip').textContent = t('用 Kimi App 扫码授权');
      sbLoadQR((ok) => {
        if (ok) {
          new window.QRCode($('sbQrImg'), { text: d.content, width: 168, height: 168, correctLevel: window.QRCode.CorrectLevel.M });
        } else {
          $('sbQrImg').innerHTML = '<div class="tiny muted" style="word-break:break-all">二维码组件加载失败，可打开：' + esc(d.content) + '</div>';
        }
      });
      let left = 60;
      qrTimer = setInterval(async () => {
        left--;
        if (left <= 0) { stopQR(); $('sbQrTip').textContent = t('二维码已过期，请重新生成'); return; }
        try {
          let r = null;
          try { r = await api('submit', 'qr_poll', { code: d.code }, { silent: true }); }
          catch (e) { try { r = await kimiAuth('GetLoginQRCodeStatus', { code: d.code }); } catch (e2) { r = null; } }
          if (!r) { return; }
          if (r.accessToken && !r.token) { r.token = r.accessToken; }
          /* 官方状态值形如 STATUS_PENDING / STATUS_SUCCESS：去掉前缀再比较 */
          const st = String(r.status || '').toUpperCase().replace(/^STATUS_/, '');
          if (st === 'SUCCESS') {
            const tok = String(r.token || r.accessToken || '').trim();
            stopQR();
            box.hidden = true;
            if (tok) {
              tokenEl.value = tok;
              tokenEl.dispatchEvent(new Event('input', { bubbles: true }));   // 让页面上的其它校验感知到已填
              $('sbQrTip').textContent = t('已获取登录态');
              toast(t('登录成功，登录态已自动填入'));
            } else {
              $('sbQrTip').textContent = t('扫码成功，但没取到登录态，请改用粘贴');
            }
          } else if (st === 'SCANNED') {
            $('sbQrTip').textContent = t('已扫码，请在手机上确认');
          } else if (st === 'EXPIRED' || st === 'CANCELLED') {
            stopQR();
            $('sbQrTip').textContent = t('二维码已过期，请重新生成');
          }
        } catch (e) { /* 继续轮询 */ }
      }, 2500);
    } catch (e) {
      btnLoading($('sbQr'), false);
      toast(e.message || t('扫码服务不可用，请粘贴登录态'), 'bad');
    }
  });

  /* ---------- Kimi 社区搜索 ---------- */
  let timer = 0, seq = 0;
  const runSearch = async () => {
    {
      const q = $('sbSearch').value.trim();
      const token = tokenEl.value.trim();
      if (!token) { hits.innerHTML = '<p class="tiny muted">请先在上方填写登录态，然后才能搜索社区。</p>'; return; }
      const my = ++seq;
      hits.innerHTML = '<p class="tiny muted">搜索中…</p>';
      try {
        const d = await api('submit', 'community_search', { q, token }, { silent: true });
        if (my !== seq) { return; }
        const list = (d && d.list) || [];
        if (!list.length) { hits.innerHTML = '<p class="tiny muted">社区里没有找到匹配的作品。</p>'; return; }
        hits.innerHTML = list.map(it => `
          <div class="sb-hit${it.exists ? ' is-exists' : ''}" data-id="${esc(it.community_id)}"
               data-title="${esc(it.title)}" data-cover="${esc(it.cover || '')}">
            ${it.cover ? `<img src="${esc(it.cover)}" alt="" loading="lazy" draggable="false">` : '<span class="sb-noimg"></span>'}
            <div class="sb-hit-main">
              <b>${esc(it.title)}</b>
              <span class="tiny muted">${esc(it.author || '')} · ${esc(it.community_id)}</span>
            </div>
            <span class="sb-tag">${it.exists ? '已在榜' : t('选择')}</span>
          </div>`).join('');
      } catch (e) {
        if (my === seq) { hits.innerHTML = '<p class="tiny muted">' + esc(e.message || t('搜索失败')) + '</p>'; }
      }
    }
  };
  $('sbSearch').addEventListener('input', () => {
    clearTimeout(timer);
    if (!$('sbSearch').value.trim()) { hits.innerHTML = ''; return; }
    timer = setTimeout(runSearch, 380);
  });
  $('sbSearch').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); runSearch(); }
  });
  $('sbSearchGo').addEventListener('click', () => { clearTimeout(timer); runSearch(); });

  hits.addEventListener('click', (e) => {
    const row = e.target.closest('.sb-hit');
    if (!row) { return; }
    $('sbId').value = row.dataset.id;
    $('sbName').value = row.dataset.title || '';
    $('sbCover').value = row.dataset.cover || '';
    hint.textContent = t('已选择：') + row.dataset.title + t('。确认后提交。');
    hits.querySelectorAll('.sb-hit').forEach((el) => el.classList.toggle('picked', el === row));
    if (row.classList.contains('is-exists')) {
      toast('该作品已在榜，可直接查看详情', 'bad');
    }
  });

  /* ---------- 提交 ---------- */
  $('sbSubmit').addEventListener('click', async () => {
    const name = $('sbName').value.trim();
    const wid = $('sbId').value.trim();
    const cover = $('sbCover').value.trim();
    const token = tokenEl.value.trim();

    if (!wid) { toast('请先在上方搜索结果里选择作品', 'bad'); return; }
    if (name.length < 2) { toast('请填写作品名称（至少 2 字）', 'bad'); return; }
    if (!token) { toast('请填写登录态或扫码登录', 'bad'); return; }

    const ok = await dialog(t('确认提交'), '将用你提供的登录态读取 <b>' + esc(wid) + '</b> 的内容并入库。登录态不会保存。', '提交');
    if (!ok) { return; }

    const btn = $('sbSubmit');
    btnLoading(btn, true);
    hint.textContent = t('正在抓取作品并计算评分，可能需要十几秒…');
    try {
      await api('submit', 'submit', { name, work_id: wid, cover, token }, { silent: true });
      hint.textContent = t('已收录，正在跳转到作品页…');
      toast('提交成功');
      setTimeout(() => navigate('#/detail/' + wid), 600);
    } catch (e) {
      hint.textContent = e.message || t('提交失败');
      toast(e.message || t('提交失败'), 'bad');
    } finally {
      btnLoading(btn, false);
    }
  });
}
