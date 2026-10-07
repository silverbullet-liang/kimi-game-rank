/**
 * 登录页：密码登录 / 注册 / 游客模式（无验证码）
 */
import { api, state, esc, toast, setToken, askNotifyPermission, btnLoading } from '../core.js';
import { navigate } from '../router.js';
import { applyTheme } from '../theme.js';
import { mountCaptcha, resetCaptcha, captchaToken, captchaChannel, captchaConfig } from '../captcha.js';

export async function renderLogin(container) {
  let tab = 'password';   // password | guest
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
      try { const d = await api('auth.php', 'guest'); await finishLogin(d, true); }
      catch (e) { toast(e.message, 'err'); }
    });
    const pane = container.querySelector('#pane');
    if (tab === 'password') paintPassword(pane);
    else paintGuest(pane);
  }

  function paintPassword(pane) {
    pane.innerHTML = `
      <div class="seg" style="margin-bottom:16px">
        <button data-sub="login" class="${sub === 'login' ? 'on' : ''}">登录</button>
        <button data-sub="register" class="${sub === 'register' ? 'on' : ''}">注册</button>
      </div>
      <div class="field">
        <label>用户名${sub === 'register' ? '（即昵称，全站展示，注册后不可更改）' : ''}</label>
        <input class="input" id="uName" type="text" autocomplete="username"
               placeholder="${sub === 'register' ? '2-64 位，中文、字母、数字或符号均可' : '请输入用户名'}">
      </div>
      <div class="field">
        <label>密码${sub === 'register' ? '（8-64 位，建议同时包含数字和字母）' : ''}</label>
        <div class="input-wrap">
          <input class="input" id="uPwd" type="password" autocomplete="${sub === 'register' ? 'new-password' : 'current-password'}" placeholder="${sub === 'register' ? '设置 8-64 位密码' : '请输入密码'}">
          <button class="icon-btn eye" id="eyeBtn" type="button" aria-label="显示密码">
            <svg viewBox="0 0 24 24" class="ic"><path d="M12 5c5 0 9 4.5 9 7s-4 7-9 7-9-4.5-9-7 4-7 9-7zm0 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0 2a2 2 0 1 1 0 4 2 2 0 0 1 0-4z"/></svg>
          </button>
        </div>
      </div>
      <div class="agree">
        <span class="box ${agreed ? 'on' : ''}" id="agreeBox"><svg viewBox="0 0 24 24" class="ic"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg></span>
        <span>我已阅读并同意<span class="link" data-doc="用户协议">《用户协议》</span>与<span class="link" data-doc="隐私政策">《隐私政策》</span>（本站为个人兴趣分享，请文明互动）</span>
      </div>
      <div id="capBox" style="margin-bottom:12px"></div>
      <button class="btn" id="submitBtn" style="width:100%">${sub === 'register' ? '注册并登录' : '登录'}</button>
      <div class="tiny" style="text-align:center;margin-top:10px">${sub === 'register' ? '密码登录的账号可浏览榜单并参与投票' : '忘记密码请联系管理员'}</div>`;

    pane.querySelectorAll('[data-sub]').forEach(b => b.addEventListener('click', () => { sub = b.dataset.sub; paintPassword(pane); }));
    pane.querySelector('#agreeBox').addEventListener('click', () => { agreed = !agreed; pane.querySelector('#agreeBox').classList.toggle('on', agreed); });
    pane.querySelectorAll('[data-doc]').forEach(l => l.addEventListener('click', () => navigate('#/doc/' + encodeURIComponent(l.dataset.doc))));
    pane.querySelector('#eyeBtn').addEventListener('click', () => {
      const p = pane.querySelector('#uPwd');
      p.type = p.type === 'password' ? 'text' : 'password';
    });
    const capBox = pane.querySelector('#capBox');
    mountCaptcha(capBox, () => { /* token 变化时无需额外动作，提交时统一读取 */ });

    pane.querySelector('#submitBtn').addEventListener('click', async () => {
      const btn = pane.querySelector('#submitBtn');
      if (btn.disabled) { return; }          // 防重复提交
      const name = pane.querySelector('#uName').value;
      const pwd = pane.querySelector('#uPwd').value;
      if (!name || !pwd) { toast('请填写用户名与密码', 'err'); return; }
      if (!agreed) { toast('请先勾选同意协议', 'err'); return; }
      if (captchaConfig().on && !captchaToken()) { toast('请先完成人机验证', 'err'); return; }
      btnLoading(btn, true);
      try {
        const d = await api('auth.php', sub === 'register' ? 'register' : 'login', {
          username: name, password: pwd,
          cap_token: captchaToken(), cap_channel: captchaChannel(),
        });
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
      try { const d = await api('auth.php', 'guest'); await finishLogin(d, true); }
      catch (e) { toast(e.message, 'err'); btnLoading(btn, false); }
    });
  }

  async function finishLogin(d, guest) {
    setToken(d.token);
    state.role = d.role || (guest ? 'guest' : 'user');
    state.uid = d.uid || 0;
    state.username = d.username || '游客';
    // 重新校验以拉取设置与头像
    try {
      const v = await api('auth.php', 'verify');
      state.settings = v.settings || {};
      state.avatar = v.avatar || '';
      state.username = v.username || state.username;
      applyTheme();
    } catch (e) {}
    toast(guest ? '已进入游客模式' : ('欢迎，' + state.username));
    if (Number(state.settings.notify) === 1) askNotifyPermission();
    // 登录后回「我的」；游客进入回榜单（路由会重绘页面与抽屉，无需整页刷新）
    navigate(guest ? '#/rank' : '#/mine');
  }

  paint();
}
