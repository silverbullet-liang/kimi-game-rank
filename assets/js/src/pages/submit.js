/**
 * 提交作品页：所有人可用（不要求登录本站）
 * 字段：名称*、作品 ID*、封面链接（可选）、登录态*（可粘贴或扫码）
 * 附：快捷搜索已收录作品，避免重复提交
 */
import { api, state, toast, esc, btnLoading, dialog } from '../core.js';
import { navigate } from '../router.js';

const QR_LIB = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';

function sbLoadQR(cb) {
  if (window.QRCode) { cb(true); return; }
  const s = document.createElement('script');
  s.src = QR_LIB;
  s.onload = () => cb(!!window.QRCode);
  s.onerror = () => cb(false);
  document.head.appendChild(s);
}

export async function renderSubmit(container) {
  /* 游客只能搜索与浏览；提交需要登录 */
  if (state.role === 'guest') {
    container.innerHTML = `
      <div class="card">
        <div class="card-title">提交作品</div>
        <p class="muted">登录后才能提交作品。游客可以先搜索榜单，看看想找的作品在不在。</p>
        <button class="btn" id="sbGoLogin" type="button" style="margin-top:12px">去登录 / 注册</button>
      </div>
      <div class="card">
        <div class="card-title">快捷搜索</div>
        <div class="field">
          <input class="input" id="sbSearchGuest" placeholder="作品名称 / 作品 ID / 作者" autocomplete="off">
        </div>
        <div id="sbHitsGuest"></div>
      </div>`;
    container.querySelector('#sbGoLogin').addEventListener('click', () => navigate('#/login'));
    const box = container.querySelector('#sbHitsGuest');
    let t = 0, seq = 0;
    container.querySelector('#sbSearchGuest').addEventListener('input', (e) => {
      const q = e.target.value.trim();
      clearTimeout(t);
      if (!q) { box.innerHTML = ''; return; }
      t = setTimeout(async () => {
        const my = ++seq;
        try {
          const d = await api('submit.php', 'search', { q }, { silent: true });
          if (my !== seq) { return; }
          const list = (d && d.list) || [];
          box.innerHTML = list.length
            ? list.map(it => `<div class="sb-hit" data-id="${esc(it.community_id)}">
                 ${it.cover ? `<img src="${esc(it.cover)}" alt="" loading="lazy" draggable="false">` : '<span class="sb-noimg"></span>'}
                 <div class="sb-hit-main"><b>${esc(it.title)}</b>
                   <span class="tiny muted">${esc(it.author || '')} · ${it.score || 0} 分 ${esc(it.rating || '')}</span></div>
               </div>`).join('')
            : '<p class="tiny muted">没有匹配的作品。</p>';
        } catch (err) { /* 静默 */ }
      }, 320);
    });
    box.addEventListener('click', (e) => {
      const row = e.target.closest('.sb-hit');
      if (row) { navigate('#/detail/' + row.dataset.id); }
    });
    return;
  }

  container.innerHTML = `
    <div class="card">
      <div class="card-title">提交作品</div>
      <p class="muted">贴上作品 ID 就能收录。先搜一下，也许它已经在榜上了。</p>
      <div class="field">
        <label>快捷搜索</label>
        <input class="input" id="sbSearch" placeholder="作品名称 / 作品 ID / 作者" autocomplete="off">
      </div>
      <div id="sbHits"></div>
    </div>

    <div class="card">
      <div class="card-title">作品信息</div>
      <div class="field">
        <label>作品名称 <span class="req">*</span></label>
        <input class="input" id="sbName" maxlength="120" placeholder="2-120 字">
      </div>
      <div class="field">
        <label>作品 ID <span class="req">*</span></label>
        <input class="input" id="sbId" maxlength="80" placeholder="分享链接里的那串 ID" autocomplete="off">
      </div>
      <div class="field">
        <label>封面链接</label>
        <input class="input" id="sbCover" maxlength="500" placeholder="https://...（留空则用作品自带封面）" autocomplete="off">
      </div>
      <div class="field">
        <label>登录态 <span class="req">*</span></label>
        <textarea class="input" id="sbToken" rows="3" placeholder="粘贴 Kimi accessToken；只用于这次读取，不保存"></textarea>
        <div class="sb-row">
          <button class="btn btn-sm btn-ghost" id="sbQr" type="button">扫码登录</button>
          <span class="tiny muted">扫码或粘贴，二选一</span>
        </div>
        <div id="sbQrBox" class="sb-qr" hidden>
          <div id="sbQrImg" class="sb-qr-img"></div>
          <div class="tiny muted" id="sbQrTip">用 Kimi App 扫码授权</div>
        </div>
      </div>
      <button class="btn" id="sbSubmit" type="button">提交并收录</button>
      <div class="tiny muted" id="sbHint" style="margin-top:10px">每个账号每天可提交 1 次。提交后立即抓取并评分，重复作品会被拦下。</div>
    </div>`;

  const $ = (id) => container.querySelector('#' + id);
  const hits = $('sbHits');
  const hint = $('sbHint');

  /* ---------- 快捷搜索 ---------- */
  let timer = 0, seq = 0;
  $('sbSearch').addEventListener('input', () => {
    const q = $('sbSearch').value.trim();
    clearTimeout(timer);
    if (!q) { hits.innerHTML = ''; return; }
    timer = setTimeout(async () => {
      const my = ++seq;
      try {
        const d = await api('submit.php', 'search', { q }, { silent: true });
        if (my !== seq) { return; }
        const list = (d && d.list) || [];
        if (!list.length) { hits.innerHTML = '<p class="tiny muted">榜上还没有匹配的作品，可以直接提交。</p>'; return; }
        hits.innerHTML = list.map(it => `
          <div class="sb-hit" data-id="${esc(it.community_id)}" data-title="${esc(it.title)}" data-cover="${esc(it.cover || '')}">
            ${it.cover ? `<img src="${esc(it.cover)}" alt="" loading="lazy" draggable="false">` : '<span class="sb-noimg"></span>'}
            <div class="sb-hit-main">
              <b>${esc(it.title)}</b>
              <span class="tiny muted">${esc(it.author || '')} · ${esc(it.community_id)} · ${it.score || 0} 分 ${esc(it.rating || '')}</span>
            </div>
            <span class="sb-tag">已在榜</span>
          </div>`).join('');
      } catch (e) { /* 静默：搜索失败不打断填写 */ }
    }, 320);
  });

  hits.addEventListener('click', (e) => {
    const row = e.target.closest('.sb-hit');
    if (!row) { return; }
    navigate('#/detail/' + row.dataset.id);
  });

  /* ---------- 扫码登录 ---------- */
  let qrTimer = 0;
  function stopQR() { if (qrTimer) { clearInterval(qrTimer); qrTimer = 0; } }

  $('sbQr').addEventListener('click', async () => {
    const box = $('sbQrBox');
    if (!box.hidden) { box.hidden = true; stopQR(); return; }
    btnLoading($('sbQr'), true);
    try {
      const d = await api('submit.php', 'qr_new', null, { silent: true });
      btnLoading($('sbQr'), false);
      if (!d || !d.code) { throw new Error('扫码会话创建失败'); }
      box.hidden = false;
      $('sbQrImg').innerHTML = '';
      $('sbQrTip').textContent = '用 Kimi App 扫码授权';
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
        if (left <= 0) { stopQR(); $('sbQrTip').textContent = '二维码已过期，请重新生成'; return; }
        try {
          const r = await api('submit.php', 'qr_poll', { code: d.code }, { silent: true });
          if (r && r.status === 'SUCCESS' && r.token) {
            stopQR();
            $('sbToken').value = r.token;
            $('sbQrTip').textContent = '登录态已获取，可以提交了';
            box.hidden = true;
            toast('登录成功');
          } else if (r && r.status === 'SCANNED') {
            $('sbQrTip').textContent = '已扫码，请在手机上确认';
          } else if (r && r.status === 'EXPIRED') {
            stopQR();
            $('sbQrTip').textContent = '二维码已过期，请重新生成';
          }
        } catch (e) { /* 继续轮询 */ }
      }, 2500);
    } catch (e) {
      btnLoading($('sbQr'), false);
      toast(e.message || '扫码服务不可用，请粘贴登录态', 'bad');
    }
  });

  /* ---------- 提交 ---------- */
  $('sbSubmit').addEventListener('click', async () => {
    const name = $('sbName').value.trim();
    const wid = $('sbId').value.trim();
    const cover = $('sbCover').value.trim();
    const token = $('sbToken').value.trim();

    if (name.length < 2) { toast('请填写作品名称（至少 2 字）', 'bad'); return; }
    if (!wid) { toast('请填写作品 ID', 'bad'); return; }
    if (!token) { toast('请填写登录态或扫码登录', 'bad'); return; }

    const ok = await dialog('确认提交', '将用你提供的登录态读取 <b>' + esc(wid) + '</b> 的内容并入库。登录态不会保存。', '提交');
    if (!ok) { return; }

    const btn = $('sbSubmit');
    btnLoading(btn, true);
    hint.textContent = '正在抓取作品并计算评分，可能需要十几秒…';
    try {
      const r = await api('submit.php', 'submit', { name, work_id: wid, cover, token }, { silent: true });
      hint.textContent = '已收录，正在跳转到作品页…';
      toast('提交成功');
      setTimeout(() => navigate('#/detail/' + wid), 600);
    } catch (e) {
      hint.textContent = e.message || '提交失败';
      toast(e.message || '提交失败', 'bad');
    } finally {
      btnLoading(btn, false);
    }
  });
}
