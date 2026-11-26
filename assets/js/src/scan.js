/**
 * 站内扫一扫：调用摄像头解码二维码，只认本站的登录码。
 *
 * 为什么要有它：扫码登录的流程是「电脑出码 → 已登录的手机扫 → 手机上确认」。
 * 手机端如果只能靠系统相机扫，等于把用户请出浏览器；这里把「扫」也放进站内。
 *
 * 安全边界：
 *  - 解码结果只提取 16–32 位十六进制的 qr id，绝不按原样跳转（防任意链接跳转）；
 *  - 摄像头流在关闭时立刻停止，不后台常驻；
 *  - 不支持摄像头（http、旧 WebView、权限被拒）时给手输与相册两条退路。
 */
import { esc } from './core.js';
import { ASSET_BASE } from './base.js';
import { navigate } from './router.js';

/** jsQR 按需加载：只有点了「扫一扫」才拉 256KB，首屏不受影响 */
function loadJsQr() {
  return new Promise(function (resolve, reject) {
    if (window.jsQR) { resolve(); return; }
    const s = document.createElement('script');
    s.src = ASSET_BASE + '/js/vendor/jsqr.js';
    s.async = true;
    s.onload = function () { if (window.jsQR) { resolve(); } else { reject(new Error('jsQR 未就绪')); } };
    s.onerror = function () { reject(new Error('解码组件加载失败')); };
    document.head.appendChild(s);
  });
}

/** 只接受「本站登录码」：16–32 位十六进制，前面可带 ?qr= / #/login?qr= */
export function qrIdOf(text) {
  const s = String(text == null ? '' : text).trim();
  if (!s || s.length > 512) { return ''; }
  /* 服务端签发的是 16 位；32 位一并接受。长度必须精确，
     且后面不能再接字母数字（qr=xxxzz 这种不是合法 id，不能截前 16 位凑合）。 */
  const ID = '((?:[a-f0-9]{32}|[a-f0-9]{16}))';
  const m = s.match(new RegExp('[?&#]qr=' + ID + '(?![0-9a-zA-Z])', 'i'))
         || s.match(new RegExp('^' + ID + '$', 'i'));
  return m ? m[1].toLowerCase() : '';
}

export function scanQrLogin() {
  const media = navigator.mediaDevices;
  if (!media || typeof media.getUserMedia !== 'function') {
    manualOnly(t('这台设备不支持调用摄像头（或页面不是 https），请用系统相机扫码，或粘贴链接。'));
    return;
  }

  const mask = document.createElement('div');
  mask.className = 'scan-mask';
  mask.innerHTML = `
    <div class="scan-bar">
      <span class="scan-title">${t('扫一扫')}</span>
      <button class="scan-close" id="scanClose" aria-label="${t('关闭')}">${t('关闭')}</button>
    </div>
    <video class="scan-video" id="scanVideo" playsinline muted autoplay></video>
    <div class="scan-frame"><i></i><i></i><i></i><i></i></div>
    <div class="scan-tip" id="scanTip">${t('把登录二维码放进框内')}</div>
    <div class="scan-foot">
      <label class="scan-pill" for="scanFile">${t('从相册选择')}</label>
      <input type="file" id="scanFile" accept="image/*" hidden>
      <div class="scan-manual">
        <input id="scanPaste" type="url" inputmode="url" placeholder="${t('或粘贴登录链接 / 编号')}" autocomplete="off">
        <button class="scan-pill" id="scanGo">${t('打开')}</button>
      </div>
    </div>`;
  document.body.appendChild(mask);
  document.body.style.overflow = 'hidden';

  const video = mask.querySelector('#scanVideo');
  const tip = mask.querySelector('#scanTip');
  let stream = null, raf = 0, dead = false, loading = false;

  const close = function () {
    dead = true;
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    if (stream) {
      try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) { /* 已停止 */ }
      stream = null;
    }
    if (video) { try { video.srcObject = null; } catch (e) { /* ignore */ } }
    mask.remove();
    document.body.style.overflow = '';
  };

  const fail = function (msg) {
    close();
    manualOnly(msg);
  };

  const hit = function (text) {
    const id = qrIdOf(text);
    if (!id) {
      tip.textContent = t('这不是本站的登录码');
      return;
    }
    close();
    navigate('#/login?qr=' + id);   // 只带 id，绝不整串跳转
  };

  mask.querySelector('#scanClose').addEventListener('click', close);
  mask.addEventListener('click', function (e) { if (e.target === mask) { close(); } });

  /* 手输 / 相册：摄像头不可用时的退路 */
  const goManual = function (raw) {
    const id = qrIdOf(raw);
    if (!id) { fail(t('这不是本站的登录码')); return; }
    close();
    navigate('#/login?qr=' + id);
  };
  mask.querySelector('#scanGo').addEventListener('click', function () {
    const el = mask.querySelector('#scanPaste');
    goManual(el ? el.value : '');
  });
  mask.querySelector('#scanPaste').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); goManual(e.target.value); }
  });
  mask.querySelector('#scanFile').addEventListener('change', function (e) {
    const f = e.target.files && e.target.files[0];
    if (!f) { return; }
    const img = new Image();
    const url = URL.createObjectURL(f);
    img.onload = function () {
      URL.revokeObjectURL(url);
      try {
        const w = Math.min(1280, img.naturalWidth || 1280);
        const h = Math.round(w * (img.naturalHeight || 1) / (img.naturalWidth || 1)) || w;
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        const cx = c.getContext('2d');
        cx.drawImage(img, 0, 0, w, h);
        const d = cx.getImageData(0, 0, w, h);
        const code = window.jsQR ? window.jsQR(d.data, w, h, { inversionAttempts: 'attemptBoth' }) : null;
        if (code && code.data) { hit(code.data); } else { tip.textContent = t('图片里没读出二维码'); }
      } catch (err) {
        tip.textContent = t('图片里没读出二维码');
      }
    };
    img.onerror = function () { URL.revokeObjectURL(url); tip.textContent = t('图片读取失败'); };
    img.src = url;
  });

  loadJsQr().then(function () {
    if (dead) { return; }
    return media.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
  }).then(function (s) {
    if (dead) { if (s) { s.getTracks().forEach(function (t) { t.stop(); }); } return; }
    if (!s) { return; }
    stream = s;
    video.srcObject = s;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const tick = function () {
      if (dead) { return; }
      if (video.readyState >= 2 && video.videoWidth) {
        const w = video.videoWidth, h = video.videoHeight;
        if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
        try {
          ctx.drawImage(video, 0, 0, w, h);
          const data = ctx.getImageData(0, 0, w, h);
          const code = window.jsQR ? window.jsQR(data.data, w, h, { inversionAttempts: 'dontInvert' }) : null;
          if (code && code.data) { hit(code.data); return; }
        } catch (e) { /* 帧读取偶发失败，下一帧继续 */ }
      }
      raf = requestAnimationFrame(tick);
    };
    tick();
  }).catch(function (e) {
    if (dead) { return; }
    const name = (e && e.name) || '';
    if (name === 'NotAllowedError') { fail(t('摄像头权限被拒绝，可在浏览器设置里允许后重试。')); }
    else if (name === 'NotFoundError') { fail(t('没有可用的摄像头。')); }
    else { fail(t('摄像头打不开，请用系统相机扫码。')); }
  });
}

/** 摄像头不可用时的兜底：只留手输 + 相册，不再请求权限 */
function manualOnly(msg) {
  const wrap = document.createElement('div');
  wrap.className = 'scan-mask';
  wrap.innerHTML = `
    <div class="scan-bar">
      <span class="scan-title">${t('扫一扫')}</span>
      <button class="scan-close" id="scanClose">${t('关闭')}</button>
    </div>
    <div class="scan-alt">
      <div class="tiny">${esc(msg)}</div>
      <label class="scan-pill" for="scanFile2" style="margin-top:14px">${t('从相册选择')}</label>
      <input type="file" id="scanFile2" accept="image/*" hidden>
      <div class="scan-manual" style="margin-top:12px">
        <input id="scanPaste2" type="url" inputmode="url" placeholder="${t('或粘贴登录链接 / 编号')}" autocomplete="off">
        <button class="scan-pill" id="scanGo2">${t('打开')}</button>
      </div>
    </div>`;
  document.body.appendChild(wrap);
  document.body.style.overflow = 'hidden';
  const done = function () { wrap.remove(); document.body.style.overflow = ''; };
  const go = function (raw) {
    const id = qrIdOf(raw);
    if (!id) { wrap.querySelector('.tiny').textContent = t('这不是本站的登录码'); return; }
    done();
    navigate('#/login?qr=' + id);
  };
  wrap.querySelector('#scanClose').addEventListener('click', done);
  wrap.querySelector('#scanGo2').addEventListener('click', function () { go(wrap.querySelector('#scanPaste2').value); });
  wrap.querySelector('#scanPaste2').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); go(e.target.value); }
  });
  wrap.querySelector('#scanFile2').addEventListener('change', function (e) {
    const f = e.target.files && e.target.files[0];
    if (!f) { return; }
    const img = new Image();
    const u = URL.createObjectURL(f);
    img.onload = function () {
      URL.revokeObjectURL(u);
      const w = Math.min(1280, img.naturalWidth || 1280);
      const h = Math.round(w * (img.naturalHeight || 1) / (img.naturalWidth || 1)) || w;
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      const cx = c.getContext('2d');
      cx.drawImage(img, 0, 0, w, h);
      const d = cx.getImageData(0, 0, w, h);
      const code = window.jsQR ? window.jsQR(d.data, w, h, { inversionAttempts: 'attemptBoth' }) : null;
      if (code && code.data) { go(code.data); } else { wrap.querySelector('.tiny').textContent = t('图片里没读出二维码'); }
    };
    img.src = u;
  });
}
