/**
 * 登录页：密码登录 / 注册 / 游客模式（无验证码）
 */
import { api, state, esc, toast, setToken, askNotifyPermission, btnLoading } from '../core.js';
import { navigate } from '../router.js';
import { applyTheme } from '../theme.js';
import { mountCaptcha, resetCaptcha, captchaToken, captchaChannel, captchaConfig } from '../captcha.js';

export async function renderLogin(container) {
  /* ?tab=code 可直接落到验证码登录（邮件里给的入口、或用户从地址栏直达） */
  let tab = (/[?&]tab=code\b/.test(location.search)) ? 'code' : 'password';   // password | code | guest
  let sub = 'login';      // login | register
  let agreed = false;

  function paint() {
    container.innerHTML = `
      <div class="login-wrap">
        <div class="login-title">登录 kimi游戏榜</div>
        <div class="login-sub">登录后可投票、评论、参与世界对话并使用 AI 助手；游客仅可浏览榜单</div>

        <div class="card">
          <div class="seg" style="margin-bottom:14px">
            <button data-tab="password" class="${tab === 'password' ? 'on' : ''}">密码登录</button>
            <button data-tab="code" class="${tab === 'code' ? 'on' : ''}">${t('验证码登录')}</button>
            <button data-tab="guest" class="${tab === 'guest' ? 'on' : ''}">游客模式</button>
          </div>
          <div id="pane"></div>
        </div>

        <div style="text-align:center;margin-top:14px">
          <button class="btn-ghost btn-sm" id="skipLogin">暂不登录，以游客身份浏览</button>
          <div class="tiny" style="margin-top:8px">不登录默认为游客模式，可随时在「我的」中登录</div>
          <div class="tiny" style="margin-top:10px">
            <span class="link" data-doc="功能说明">《功能说明》</span>
            <span style="opacity:.45;margin:0 4px">·</span>
            <span class="link" data-doc="AI 使用说明">《AI 使用说明》</span>
          </div>
          <div class="tiny muted" style="margin-top:6px">登录后可投票、评论，并使用 AI 助手（免费模型每人每日 2 次）</div>
        </div>
      </div>`;

    container.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { tab = b.dataset.tab; paint(); }));
    container.querySelectorAll('[data-doc]').forEach(l => l.addEventListener('click', () => navigate('#/doc/' + encodeURIComponent(l.dataset.doc))));
    container.querySelector('#skipLogin').addEventListener('click', async () => {
      try { const d = await api('auth', 'guest'); await finishLogin(d, true); }
      catch (e) { toast(e.message, 'err'); }
    });
    const pane = container.querySelector('#pane');
    if (tab === 'password') paintPassword(pane);
    else if (tab === 'code') paintCode(pane);
    else paintGuest(pane);
  }

  /* 双重验证第二步：密码已通过，等服务端发出的邮件验证码回来换令牌 */
  function paintTwofa(pane, d) {
    pane.innerHTML = `
      <div class="tiny" style="margin-bottom:10px">已向 ${esc(d.masked || '')} 发送验证码，${Math.round((d.ttl || 120) / 60)} 分钟内有效。</div>
      <div class="field">
        <label>${t('验证码')}</label>
        <input class="input" id="tCode" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="${t('6 位数字')}">
      </div>
      <div id="capBox" style="margin-bottom:12px"></div>
      <button class="btn" id="tSubmit" style="width:100%">验证并登录</button>
      <div class="tiny" style="text-align:center;margin-top:10px">收不到？<span class="link" id="tBack">重新登录</span></div>`;
    const capBox = pane.querySelector('#capBox');
    resetCaptcha(capBox);
    pane.querySelector('#tBack').addEventListener('click', () => { sub = 'login'; paintPassword(pane); });
    pane.querySelector('#tSubmit').addEventListener('click', async function () {
      const c = (pane.querySelector('#tCode').value || '').replace(/\D/g, '');
      if (c.length !== 6) { toast(t('请输入 6 位验证码'), 'err'); return; }
      if (captchaConfig().on && !captchaToken()) { toast('请先完成人机验证', 'err'); return; }
      const btn = this;
      btnLoading(btn, true);
      try {
        const r = await api('auth', 'login_2fa', {
          ticket: d.ticket, code: c,
          cap_token: captchaToken(), cap_channel: captchaChannel(),
        });
        await finishLogin(r);
      } catch (e) {
        toast(e.message, 'err');
        btnLoading(btn, false);
        resetCaptcha(capBox);
      }
    });
    const first = pane.querySelector('#tCode');
    if (first) { first.focus(); }
  }

  /* 邮箱验证码登录：先在「我的」里绑定过邮箱，这里才能收到码 */
  function paintCode(pane) {
    pane.innerHTML = `
      <div class="field">
        <label>${t('邮箱')}</label>
        <input class="input" id="cMail" type="email" autocomplete="email" inputmode="email" placeholder="you@example.com">
      </div>
      <div class="field">
        <label>${t('验证码')}</label>
        <div class="input-wrap">
          <input class="input" id="cCode" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="6 位数字">
          <button class="icon-btn" id="sendCode" type="button" style="width:auto;padding:0 12px;font-size:13px;white-space:nowrap">${t('获取验证码')}</button>
        </div>
      </div>
      <div class="tiny" style="margin:-4px 0 12px">两分钟有效，只能用一次。没收到先看垃圾邮件；还没绑邮箱的，请到「我的」里绑定后再用这条通道。</div>
      <div id="capBox" style="margin-bottom:12px"></div>
      <button class="btn" id="submitBtn" style="width:100%">登录</button>`;

    const mail = pane.querySelector('#cMail');
    const code = pane.querySelector('#cCode');
    const sendBtn = pane.querySelector('#sendCode');
    const capBox = pane.querySelector('#capBox');
    let left = 0, timer = null;
    resetCaptcha(capBox);

    function rest(n) {
      left = n;
      clearInterval(timer);
      const tick = function () {
        if (left <= 0) { clearInterval(timer); sendBtn.disabled = false; sendBtn.textContent = t('获取验证码'); return; }
        sendBtn.disabled = true;
        sendBtn.textContent = left + ' 秒';
        left--;
      };
      tick();
      timer = setInterval(tick, 1000);
    }

    sendBtn.addEventListener('click', async function () {
      const v = (mail.value || '').trim();
      if (!v) { toast(t('请填写邮箱'), 'err'); mail.focus(); return; }
      sendBtn.disabled = true;
      try {
        await api('auth', 'login_send', { email: v, lang: (function () {
          try { return localStorage.getItem('kimgr_lang') || 'zh'; } catch (e) { return 'zh'; }
        })() });
        toast(t('验证码已发送'));
        rest(60);
        code.focus();
      } catch (e) {
        toast(e.message, 'err');
        sendBtn.disabled = false;
      }
    });

    pane.querySelector('#submitBtn').addEventListener('click', async function () {
      const v = (mail.value || '').trim();
      const c = (code.value || '').replace(/\D/g, '');
      if (!v || c.length !== 6) { toast(t('请输入 6 位验证码'), 'err'); return; }
      if (captchaConfig().enabled && !captchaToken()) { toast('请先完成人机验证', 'err'); return; }
      const btn = this;
      btnLoading(btn, true);
      try {
        const d = await api('auth', 'login_code', {
          email: v, code: c,
          cap_token: captchaToken(), cap_channel: captchaChannel(),
        });
        await finishLogin(d);
      } catch (e) {
        toast(e.message, 'err');
        btnLoading(btn, false);
        resetCaptcha(capBox);
      }
    });
  }

  function paintPassword(pane) {
    pane.innerHTML = `
      <div class="seg" style="margin-bottom:16px">
        <button data-sub="login" class="${sub === 'login' ? 'on' : ''}">登录</button>
        <button data-sub="register" class="${sub === 'register' ? 'on' : ''}">注册</button>
      </div>
      <div class="field">
        <label>${sub === 'register' ? '用户名（即昵称，全站展示，注册后不可更改）' : 'UID / 用户名 / 邮箱'}</label>
        <input class="input" id="uName" type="text" autocomplete="username"
               placeholder="${sub === 'register' ? t('2-64 位，中文、字母、数字或符号均可') : 'UID、用户名或已绑定的邮箱'}">
      </div>
      <div class="field">
        <label>密码${sub === 'register' ? '（8-64 位，建议同时包含数字和字母）' : ''}</label>
        <div class="input-wrap">
          <input class="input" id="uPwd" type="password" autocomplete="${sub === 'register' ? 'new-password' : 'current-password'}" placeholder="${sub === 'register' ? t('设置 8-64 位密码') : t('请输入密码')}">
          <button class="icon-btn eye" id="eyeBtn" type="button" aria-label="${t('显示密码')}">
            <svg viewBox="0 0 24 24" class="ic"><path d="M12 5c5 0 9 4.5 9 7s-4 7-9 7-9-4.5-9-7 4-7 9-7zm0 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0 2a2 2 0 1 1 0 4 2 2 0 0 1 0-4z"/></svg>
          </button>
        </div>
      </div>
      <div class="agree">
        <span class="box ${agreed ? 'on' : ''}" id="agreeBox"><svg viewBox="0 0 24 24" class="ic"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg></span>
        <span>我已阅读并同意<span class="link" data-doc="用户协议">《用户协议》</span>${t('与')}<span class="link" data-doc="隐私政策">《隐私政策》</span>（本站为个人兴趣分享，请文明互动）</span>
      </div>
      ${sub === 'register' ? `
      <div class="field">
        <label>邮箱（可选，绑定后可用于验证码登录）</label>
        <div class="input-wrap">
          <input class="input" id="rMail" type="email" autocomplete="email" inputmode="email" placeholder="you@example.com">
          <button class="icon-btn" id="rSend" type="button" style="width:auto;padding:0 12px;font-size:13px;white-space:nowrap">${t('获取验证码')}</button>
        </div>
      </div>
      <div class="field" id="rCodeWrap" style="display:none">
        <label>${t('验证码')}</label>
        <input class="input" id="rCode" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="${t('6 位数字')}">
      </div>` : ''}
      <div id="capBox" style="margin-bottom:12px"></div>
      <button class="btn" id="submitBtn" style="width:100%">${sub === 'register' ? '注册并登录' : t('登录')}</button>
      <div class="tiny" style="text-align:center;margin-top:10px">${sub === 'register' ? '密码登录的账号可浏览榜单并参与投票' : t('忘记密码请联系管理员')}</div>
      <div class="reg-video" id="regVideo">
        <button class="reg-video-btn" id="regVideoBtn" type="button">
          <svg viewBox="0 0 24 24" class="ic"><path d="M8 5v14l11-7z"/></svg>
          <span>先看看这是什么 · 8 分钟宣传片</span>
        </button>
        <div class="reg-video-frame" id="regVideoFrame"></div>
      </div>`;

    pane.querySelectorAll('[data-sub]').forEach(b => b.addEventListener('click', () => { sub = b.dataset.sub; paintPassword(pane); }));
    pane.querySelector('#agreeBox').addEventListener('click', () => { agreed = !agreed; pane.querySelector('#agreeBox').classList.toggle('on', agreed); });
    pane.querySelectorAll('[data-doc]').forEach(l => l.addEventListener('click', () => navigate('#/doc/' + encodeURIComponent(l.dataset.doc))));
    const vBtn = pane.querySelector('#regVideoBtn');
    const vBox = pane.querySelector('#regVideoFrame');
    if (vBtn && vBox) {
      vBtn.addEventListener('click', () => {
        vBox.innerHTML = '<iframe src="https://player.bilibili.com/player.html?bvid=BV1AinG6fEUo&page=1&high_quality=1&danmaku=1&autoplay=0" scrolling="no" frameborder="no" allowfullscreen="true" referrerpolicy="no-referrer-when-downgrade" title="' + t('Kimi 游戏榜宣传视频') + '"></iframe>';
        vBtn.remove();
      });
    }
    pane.querySelector('#eyeBtn').addEventListener('click', () => {
      const p = pane.querySelector('#uPwd');
      p.type = p.type === 'password' ? 'text' : 'password';
    });
    const capBox = pane.querySelector('#capBox');
    mountCaptcha(capBox, () => { /* token 变化时无需额外动作，提交时统一读取 */ });

    /* 注册时的可选邮箱：点了「获取验证码」才展开验证码输入框 */
    const rSend = pane.querySelector('#rSend');
    if (rSend) {
      let rtimer = null, rleft = 0;
      pane.querySelector('#rMail').addEventListener('input', () => {
        const v = (pane.querySelector('#rMail').value || '').trim();
        pane.querySelector('#rCodeWrap').style.display = v ? '' : 'none';
      });
      rSend.addEventListener('click', async () => {
        const v = (pane.querySelector('#rMail').value || '').trim();
        if (!v) { toast(t('请填写邮箱'), 'err'); pane.querySelector('#rMail').focus(); return; }
        rSend.disabled = true;
        try {
          const lang = (function () { try { return localStorage.getItem('kimgr_lang') || 'zh'; } catch (e) { return 'zh'; } })();
          await api('auth', 'reg_send', { email: v, lang: lang });
          toast(t('验证码已发送'));
          pane.querySelector('#rCodeWrap').style.display = '';
          rleft = 60;
          clearInterval(rtimer);
          const tick = function () {
            if (rleft <= 0) { clearInterval(rtimer); rSend.disabled = false; rSend.textContent = t('获取验证码'); return; }
            rSend.disabled = true;
            rSend.textContent = rleft + ' 秒';
            rleft--;
          };
          tick();
          rtimer = setInterval(tick, 1000);
          pane.querySelector('#rCode').focus();
        } catch (e) {
          toast(e.message, 'err');
          rSend.disabled = false;
        }
      });
    }

    pane.querySelector('#submitBtn').addEventListener('click', async () => {
      const btn = pane.querySelector('#submitBtn');
      if (btn.disabled) { return; }          // 防重复提交
      const name = pane.querySelector('#uName').value;
      const pwd = pane.querySelector('#uPwd').value;
      if (!name || !pwd) { toast(sub === 'register' ? '请填写用户名与密码' : '请填写账号与密码', 'err'); return; }
      if (!agreed) { toast('请先勾选同意协议', 'err'); return; }
      const payload = {
        username: name, identifier: name, password: pwd,
        cap_token: captchaToken(), cap_channel: captchaChannel(),
      };
      if (sub === 'register') {
        const rm = (pane.querySelector('#rMail').value || '').trim();
        const rc = (pane.querySelector('#rCode').value || '').replace(/\D/g, '');
        if (rm !== '') {
          if (rc.length !== 6) { toast(t('请输入 6 位验证码'), 'err'); return; }
          payload.email = rm;
          payload.code = rc;
        }
      }
      if (captchaConfig().on && !captchaToken()) { toast('请先完成人机验证', 'err'); return; }
      btnLoading(btn, true);
      try {
        const d = await api('auth', sub === 'register' ? 'register' : 'login', payload);
        if (d && d.twofa) { paintTwofa(pane, d); return; }
        await finishLogin(d);
      } catch (e) {
        toast(e.message, 'err');
        btnLoading(btn, false);
        /* 最终 token 是一次性的：失败后必须重新验证拿新 token */
        resetCaptcha(capBox);
      }
    });
  }

  function paintGuest(pane) {
    pane.innerHTML = `
      <div class="guest-box">
        <svg viewBox="0 0 24 24" class="ic" style="width:44px;height:44px;fill:var(--text-3);margin:0 auto 10px"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z"/></svg>
        <div style="font-weight:700;margin-bottom:6px">游客模式</div>
        <p class="muted tiny">无需登录即可浏览全部榜单与作品详情；参与投票、评论与 AI 对话需要登录账号。</p>
        <button class="btn" id="guestBtn" style="width:100%;margin-top:14px">以游客身份进入</button>
      </div>`;
    pane.querySelector('#guestBtn').addEventListener('click', async () => {
      const btn = pane.querySelector('#guestBtn');
      if (btn.disabled) { return; }
      btnLoading(btn, true);
      try { const d = await api('auth', 'guest'); await finishLogin(d, true); }
      catch (e) { toast(e.message, 'err'); btnLoading(btn, false); }
    });
  }

  async function finishLogin(d, guest) {
    setToken(d.token);
    state.role = d.role || (guest ? 'guest' : 'user');
    state.uid = d.uid || 0;
    state.username = d.username || t('游客');
    // 重新校验以拉取设置与头像
    try {
      const v = await api('auth', 'verify');
      state.settings = v.settings || {};
      state.avatar = v.avatar || '';
      state.username = v.username || state.username;
      applyTheme();
    } catch (e) {}
    toast(guest ? '已进入游客模式' : (t('欢迎，') + state.username));
    if (Number(state.settings.notify) === 1) askNotifyPermission();
    // 登录后回「我的」；游客进入回榜单（路由会重绘页面与抽屉，无需整页刷新）
    navigate(guest ? '#/rank' : '#/mine');
    if (!guest && typeof window.__nameFixCheck === 'function') { window.__nameFixCheck(); }
  }

  paint();
}
