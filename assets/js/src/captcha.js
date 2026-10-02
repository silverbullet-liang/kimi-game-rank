/**
 * 人机验证（Cap PoW 双实例）
 * ------------------------------------------------------------
 * 两个实例都提供配套前端脚本，直接用它们的组件，不自己实现 PoW 求解器：
 *   主通道 captcha.gurl.eu.org —— 官方 cap-widget（Web Component，事件驱动）
 *   备通道 cap-pow.wuw.li     —— 实例自带 cap-pow.js（需同时加载它的 CSS）
 *
 * 主通道 3 秒内没渲染出内容（脚本被墙、实例挂了、shadowRoot 为空）就切备通道。
 * 两个通道的 token 不通用，所以要连 channel 一起交回服务端。
 *
 * 最终 token 是一次性的：一次提交失败后必须重新验证，取新 token。
 */
const CH = { token: '', channel: '' };
let activeChannel = '';

export function captchaConfig() {
  return (typeof window !== 'undefined' && window.__CAPTCHA) || { on: false, channels: {} };
}
export function captchaToken() { return CH.token; }
export function captchaChannel() { return CH.channel; }

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.onload = () => resolve(true);
    s.onerror = () => reject(new Error('脚本加载失败'));
    document.head.appendChild(s);
  });
}

/** 重置：提交失败后调用，token 已失效，必须重新获取 */
export function resetCaptcha(host) {
  CH.token = '';
  CH.channel = '';
  activeChannel = '';
  if (host) { mountCaptcha(host, host.__onCapToken); }
}

/**
 * 把验证组件挂到 host 里。onChange(token, channel) 在验证通过后被调用
 * （token 为空串表示已重置 / 尚未通过）。
 */
export function mountCaptcha(host, onChange) {
  if (!host) { return; }
  host.__onCapToken = onChange || host.__onCapToken;
  const cb = (t, c) => { CH.token = t; CH.channel = c; if (host.__onCapToken) { host.__onCapToken(t, c); } };

  const cfg = captchaConfig();
  if (!cfg.on) { host.innerHTML = ''; return; }
  /* 同一个宿主已挂过就不重复挂；宿主被重建（切登录/注册）时会自然重来 */
  if (host.querySelector('#cap-widget') || host.querySelector('.cap-wrap')) { return; }
  CH.token = '';
  CH.channel = '';
  cb('', '');

  const std = (cfg.channels || {}).standard || {};
  const php = (cfg.channels || {}).php || {};
  host.innerHTML = `
    <div class="cap-box">
      <div id="capStd"></div>
      <div id="capPhp" style="display:none"></div>
      <div class="tiny muted" id="capHint" style="margin-top:6px">正在加载人机验证…</div>
    </div>`;

  const hint = host.querySelector('#capHint');

  function switchToPhp(why) {
    if (activeChannel === 'php') { return; }
    activeChannel = 'php';
    CH.token = '';
    cb('', '');
    host.querySelector('#capStd').style.display = 'none';
    const box = host.querySelector('#capPhp');
    box.style.display = 'block';
    if (!php.script) { hint.textContent = '人机验证暂不可用，请稍后再试'; return; }
    hint.textContent = '主通道不可用（' + why + '），已切换到备用通道';
    if (php.css && !document.querySelector('link[data-cap-php]')) {
      const l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = php.css;
      l.setAttribute('data-cap-php', '1');
      document.head.appendChild(l);
    }
    /* PHP 实例的组件结构固定，class 名不能改 */
    box.innerHTML = `
      <div class="cap-wrap">
        <div class="captcha">
          <div class="cap-ct" id="cap-ct" role="button" tabindex="0" aria-label="点击进行人机验证">
            <div class="cap-cb">
              <div class="cap-check">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="4,12 9,17 20,6"></polyline></svg>
              </div>
              <svg class="cap-ring" viewBox="0 0 32 32" aria-hidden="true">
                <circle class="cap-ring-bg" cx="16" cy="16" r="14"></circle>
                <circle class="cap-ring-fg" cx="16" cy="16" r="14"></circle>
              </svg>
            </div>
            <div class="cap-lw"><span class="cap-label active">验证你是人类</span></div>
          </div>
        </div>
      </div>`;
    loadScript(php.script).then(() => {
      const wait = (n) => {
        if (window.CapPow) {
          window.CapPow.onDone = (token) => { hint.textContent = '验证通过'; cb(token, 'php'); };
          window.CapPow.onFail = (msg) => { hint.textContent = '验证失败：' + (msg || '未知'); cb('', ''); };
        } else if (n < 30) { setTimeout(() => wait(n + 1), 300); }
        else { hint.textContent = '备用通道加载超时，请刷新重试'; }
      };
      wait(0);
    }).catch(() => { hint.textContent = '备用通道加载失败，请刷新重试'; });
  }

  /* 主通道：官方 cap-widget，脚本加载后自动挂载 */
  if (!std.script) { switchToPhp('未配置'); return; }
  const w = document.createElement('cap-widget');
  w.id = 'cap-widget';
  if (std.api) { w.setAttribute('data-cap-api-endpoint', std.api); }
  w.addEventListener('solve', (e) => { hint.textContent = '验证通过'; cb(e.detail.token, 'standard'); });
  w.addEventListener('progress', (e) => { hint.textContent = '计算中… ' + Math.round(e.detail.progress) + '%'; });
  w.addEventListener('error', (e) => { hint.textContent = '验证出错：' + ((e.detail && e.detail.message) || '未知'); });
  host.querySelector('#capStd').appendChild(w);

  activeChannel = 'standard';
  loadScript(std.script).catch(() => switchToPhp('脚本加载失败'));
  /* 3 秒健康检查：没渲染出来就切备通道 */
  setTimeout(() => {
    if (activeChannel !== 'standard') { return; }
    const inst = host.querySelector('#cap-widget');
    const ok = inst && (inst.shadowRoot || inst.children.length > 0);
    if (!ok) { switchToPhp('未渲染'); }
    else if (hint.textContent === '正在加载人机验证…') { hint.textContent = '点击下方按钮完成验证'; }
  }, 3000);
}
