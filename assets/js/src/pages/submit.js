/**
 * 提交作品页（登录用户）
 * 流程：填登录态 → 搜 Kimi 社区 → 选中作品自动填表 → 确认提交
 * 作品 ID 只能由搜索结果带入，不允许手填
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
        <textarea class="input" id="sbToken" rows="3" placeholder="粘贴 Kimi accessToken"></textarea>
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
        <input class="input" id="sbSearch" placeholder="作品名称 / 关键词" autocomplete="off">
      </div>
      <div id="sbHits"></div>
    </div>

    <div class="card">
      <div class="card-title">第三步 · 确认信息</div>
      <div class="field">
        <label>作品名称 <span class="req">*</span></label>
        <input class="input" id="sbName" maxlength="120" placeholder="选中作品后自动填入，可修改">
      </div>
      <div class="field">
        <label>作品 ID <span class="req">*</span></label>
        <input class="input" id="sbId" readonly placeholder="由上方搜索结果自动填入">
      </div>
      <div class="field">
        <label>封面链接</label>
        <input class="input" id="sbCover" maxlength="500" placeholder="自动带入作品封面，可修改" autocomplete="off">
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
            tokenEl.value = r.token;
            $('sbQrTip').textContent = '已获取登录态';
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

  /* ---------- Kimi 社区搜索 ---------- */
  let timer = 0, seq = 0;
  $('sbSearch').addEventListener('input', () => {
    const q = $('sbSearch').value.trim();
    clearTimeout(timer);
    if (!q) { hits.innerHTML = ''; return; }
    timer = setTimeout(async () => {
      const token = tokenEl.value.trim();
      if (!token) { hits.innerHTML = '<p class="tiny muted">请先在上方填写登录态，然后才能搜索社区。</p>'; return; }
      const my = ++seq;
      hits.innerHTML = '<p class="tiny muted">搜索中…</p>';
      try {
        const d = await api('submit.php', 'community_search', { q, token }, { silent: true });
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
            <span class="sb-tag">${it.exists ? '已在榜' : '选择'}</span>
          </div>`).join('');
      } catch (e) {
        if (my === seq) { hits.innerHTML = '<p class="tiny muted">' + esc(e.message || '搜索失败') + '</p>'; }
      }
    }, 380);
  });

  hits.addEventListener('click', (e) => {
    const row = e.target.closest('.sb-hit');
    if (!row) { return; }
    $('sbId').value = row.dataset.id;
    $('sbName').value = row.dataset.title || '';
    $('sbCover').value = row.dataset.cover || '';
    hint.textContent = '已选择：' + row.dataset.title + '。确认后提交。';
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

    const ok = await dialog('确认提交', '将用你提供的登录态读取 <b>' + esc(wid) + '</b> 的内容并入库。登录态不会保存。', '提交');
    if (!ok) { return; }

    const btn = $('sbSubmit');
    btnLoading(btn, true);
    hint.textContent = '正在抓取作品并计算评分，可能需要十几秒…';
    try {
      await api('submit.php', 'submit', { name, work_id: wid, cover, token }, { silent: true });
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
