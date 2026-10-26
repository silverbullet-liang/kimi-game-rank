/**
 * 人机验证（主：Cloudflare Turnstile；备：Cap PoW 双实例）
 * ------------------------------------------------------------
 * 主通道 Turnstile：官方 explicit 渲染，通常无需操作即通过；
 *   脚本加载失败或 3.5 秒内没渲染出来（被墙、组件异常）就切 Cap 备通道。
 * 备通道沿用原来的双实例：standard（官方 cap-widget）→ php（cap-pow.js），
 *   前者再 3 秒没渲染就切后者 —— 与原双通道行为一致。
 *
 * 三个通道的 token 互不通用，提交时连 channel 一起交回服务端，
 * 服务端按声明通道校验（没声明就依次试，拿错通道的 token 必然失败、无害）。
 *
 * token 都是一次性的：提交失败后必须 reset 重新验证，取新 token。
 */
import { I18N } from './i18n.js';

const CH = { token: '', channel: '' };
let activeChannel = '';
let cfWidget = null;
let cfLoading = null;
const LANG_MAP = { zh: 'zh-cn', en: 'en', ja: 'ja' };

export function captchaConfig() {
  return (typeof window !== 'undefined' && window.__CAPTCHA) || { on: false, sitekey: '', script: '', channels: {} };
}
export function captchaToken() { return CH.token; }
export function captchaChannel() { return CH.channel; }

/** Cap 实例的配套脚本加载（Turnstile 用 loadTurnstile，只加载一次） */
function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.onload = () => resolve(true);
    s.onerror = () => reject(new Error('load-failed'));
    document.head.appendChild(s);
  });
}

function loadTurnstile(src) {
  if (window.turnstile) { return Promise.resolve(true); }
  if (cfLoading) { return cfLoading; }
  cfLoading = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.defer = true;
    s.onload = () => resolve(true);
    s.onerror = () => { cfLoading = null; reject(new Error('load-failed')); };
    document.head.appendChild(s);
  });
  return cfLoading;
}

/** 重置：提交失败后调用，token 已失效，必须重新获取 */
export function resetCaptcha(host) {
  if (window.turnstile && cfWidget !== null) {
    try { window.turnstile.remove(cfWidget); } catch (e) { /* 已销毁则忽略 */ }
  }
  cfWidget = null;
  CH.token = '';
  CH.channel = '';
  activeChannel = '';
  if (host) {
    host.dataset.cfMounted = '';
    host.innerHTML = '';
    mountCaptcha(host, host.__onCfToken);
  }
}

/**
 * 把验证组件挂到 host 里。onChange(token, channel) 在验证通过后被调用
 * （token 为空串表示已重置 / 尚未通过 / 已过期）。
 */
export function mountCaptcha(host, onChange) {
  if (!host) { return; }
  host.__onCfToken = onChange || host.__onCfToken;
  const cb = (t, c) => { CH.token = t; CH.channel = c; if (host.__onCfToken) { host.__onCfToken(t, c); } };

  const cfg = captchaConfig();
  if (!cfg.on) { host.innerHTML = ''; return; }
  /* 同一个宿主已挂过就不重复挂；宿主被重建（切登录/注册）时会自然重来 */
  if (host.dataset.cfMounted === '1') { return; }
  host.dataset.cfMounted = '1';
  CH.token = '';
  CH.channel = '';
  cb('', '');

  const std = (cfg.channels || {}).standard || {};
  const php = (cfg.channels || {}).php || {};
  host.innerHTML = '<div class="cap-box">'
    + '<div id="cfSlot"></div>'
    + '<div id="capStd" style="display:none"></div>'
    + '<div id="capPhp" style="display:none"></div>'
    + '<div class="tiny muted" id="capHint" style="margin-top:6px">' + t('正在加载人机验证…') + '</div>'
    + '</div>';

  const hint = host.querySelector('#capHint');

  /* ---------- 备通道：Cap 双实例（原逻辑原样保留） ---------- */
  function switchToCap(which, why) {
    if (activeChannel === which) { return; }
    /* 切换原因只进控制台，不显示给用户：内部措辞容易被误读成组件坏了 */
    if (why) { try { console.debug('[captcha] switched to', which, '|', why); } catch (e) {} }
    /* 主通道若已渲染出 widget，先销毁，避免残留 iframe */
    if (window.turnstile && cfWidget !== null) {
      try { window.turnstile.remove(cfWidget); } catch (e) { /* 已销毁则忽略 */ }
      cfWidget = null;
    }
    activeChannel = which;
    CH.token = '';
    CH.channel = '';
    cb('', '');
    host.querySelector('#cfSlot').style.display = 'none';

    if (which === 'php') {
      host.querySelector('#capStd').style.display = 'none';
      const box = host.querySelector('#capPhp');
      box.style.display = 'block';
      if (!php.script) { hint.textContent = t('人机验证暂不可用，请稍后再试'); return; }
      hint.textContent = t('备用通道加载中');
      if (php.css && !document.querySelector('link[data-cap-php]')) {
        const l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = php.css;
        l.setAttribute('data-cap-php', '1');
        document.head.appendChild(l);
      }
      /* PHP 实例的组件结构固定，class 名不能改 */
      box.innerHTML = '<div class="cap-wrap"><div class="captcha">'
        + '<div class="cap-ct" id="cap-ct" role="button" tabindex="0" aria-label="' + t('点击进行人机验证') + '">'
        + '<div class="cap-cb"><div class="cap-check">'
        + '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="4,12 9,17 20,6"></polyline></svg>'
        + '</div><svg class="cap-ring" viewBox="0 0 32 32" aria-hidden="true">'
        + '<circle class="cap-ring-bg" cx="16" cy="16" r="14"></circle>'
        + '<circle class="cap-ring-fg" cx="16" cy="16" r="14"></circle></svg></div>'
        + '<div class="cap-lw"><span class="cap-label active">' + t('验证你是人类') + '</span></div>'
        + '</div></div></div>';
      loadScript(php.script).then(() => {
        const wait = (n) => {
          if (window.CapPow) {
            window.CapPow.onDone = (token) => { hint.textContent = t('验证通过'); cb(token, 'php'); };
            window.CapPow.onFail = (msg) => { hint.textContent = t('验证失败：') + (msg || t('未知')); cb('', ''); };
          } else if (n < 30) { setTimeout(() => wait(n + 1), 300); }
          else { hint.textContent = t('人机验证暂不可用，请刷新重试'); }
        };
        wait(0);
      }).catch(() => { hint.textContent = t('人机验证加载失败，请刷新重试'); });
      return;
    }

    /* standard：官方 cap-widget */
    host.querySelector('#capPhp').style.display = 'none';
    const stdBox = host.querySelector('#capStd');
    stdBox.style.display = 'block';
    if (!std.script) { switchToCap('php', t('未配置')); return; }
    hint.textContent = t('主通道不可用，已切换到备用通道');
    const w = document.createElement('cap-widget');
    w.id = 'cap-widget';
    if (std.api) { w.setAttribute('data-cap-api-endpoint', std.api); }
    w.addEventListener('solve', (e) => { hint.textContent = t('验证通过'); cb(e.detail.token, 'standard'); });
    w.addEventListener('progress', (e) => { hint.textContent = t('计算中… ') + Math.round(e.detail.progress) + '%'; });
    w.addEventListener('error', (e) => { hint.textContent = t('验证出错：') + ((e.detail && e.detail.message) || t('未知')); });
    stdBox.appendChild(w);
    activeChannel = 'standard';
    loadScript(std.script).catch(() => switchToCap('php', t('脚本加载失败')));
    /* 只有「组件既没升级出 shadowRoot、也没有任何子节点、且还没拿到 token」才算失败；
       时间放宽到 5 秒，避免慢网络下把正在初始化的组件判死。 */
    setTimeout(() => {
      if (activeChannel !== 'standard' || CH.token) { return; }
      const inst = host.querySelector('#cap-widget');
      const ok = inst && (inst.shadowRoot || inst.children.length > 0);
      if (!ok) { switchToCap('php', t('组件未出现')); }
    }, 5000);
  }

  /* ---------- 主通道：Cloudflare Turnstile ---------- */
  if (!cfg.sitekey || !cfg.script) { switchToCap('standard', t('未配置')); return; }
  activeChannel = 'turnstile';
  const slot = host.querySelector('#cfSlot');
  /* 是否渲染成功只认 render() 的返回与回调，绝不用「DOM 里有没有 iframe」当判据：
     widget 有时已渲染完毕，但采样时机/框架差异让探测落空，按 DOM 判断会把正常
     工作的组件销毁并切走 —— 表现就是「明明渲染了却说没渲染」。 */
  let cfState = 'idle';
  const markRendered = () => { cfState = 'rendered'; hint.textContent = ''; };

  loadTurnstile(cfg.script).then(() => {
    if (activeChannel !== 'turnstile' || !window.turnstile) { return; }
    const dark = document.documentElement.dataset.theme === 'dark';
    try {
      cfWidget = window.turnstile.render(slot, {
        sitekey: cfg.sitekey,
        theme: dark ? 'dark' : 'light',
        language: LANG_MAP[I18N.lang] || 'zh-cn',
        size: 'flexible',
        callback: (token) => { hint.textContent = t('验证通过'); cb(token, 'turnstile'); },
        'expired-callback': () => { if (activeChannel === 'turnstile') { hint.textContent = t('验证已过期，请重新验证'); cb('', ''); } },
        'timeout-callback': () => { if (activeChannel === 'turnstile') { hint.textContent = t('验证超时，请重新验证'); cb('', ''); } },
        'error-callback': () => {
          if (activeChannel !== 'turnstile') { return; }
          cfState = 'failed';
          cb('', '');
          switchToCap('standard', t('验证出错'));
        }
      });
      markRendered();
    } catch (e) {
      cfState = 'failed';
      switchToCap('standard', t('渲染失败'));
    }
  }).catch(() => {
    if (activeChannel !== 'turnstile') { return; }
    cfState = 'failed';
    switchToCap('standard', t('脚本未加载'));
  });

  /* 6 秒兜底：只处理「脚本压根没回来」这一种情况。已渲染的一律不动；
     即便 DOM 探测不到，也只改提示文案，不做任何破坏性动作。 */
  setTimeout(() => {
    if (activeChannel !== 'turnstile') { return; }
    if (cfState === 'rendered') {
      if (!slot.querySelector('iframe') && slot.children.length === 0) {
        hint.textContent = t('验证框若长时间未显示，请刷新重试');
      }
      return;
    }
    switchToCap('standard', t('脚本未加载'));
  }, 6000);
}
