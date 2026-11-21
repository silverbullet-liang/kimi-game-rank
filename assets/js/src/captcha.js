/**
 * 人机验证：只用 Cloudflare Turnstile，没有备用通道。
 * ------------------------------------------------------------
 * 为什么不留备通道：备通道都是更弱的验证（纯计算题，脚本几十毫秒可解），
 * 一旦存在「主通道不通就自动退到弱通道」的路径，攻击者只要想办法让主通道
 * 加载失败，就等于关掉了整道门。宁可登不进去，也不退到弱验证。
 *
 * 因此这里没有任何自动切换、没有超时探测：加载不出来就提示刷新，
 * 校验一律由服务端向 Turnstile 复核，前端说了不算。
 */
import { I18N } from './i18n.js';

const CH = { token: '', channel: '' };
let cfWidget = null;
let cfLoading = null;
const LANG_MAP = { zh: 'zh-cn', en: 'en', ja: 'ja' };

export function captchaConfig() {
  return (typeof window !== 'undefined' && window.__CAPTCHA) || { on: false, sitekey: '', script: '' };
}
export function captchaToken() { return CH.token; }
export function captchaChannel() { return CH.channel; }

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

/** 重置：提交失败后 token 已失效，必须重新取一个 */
export function resetCaptcha(host) {
  if (window.turnstile && cfWidget !== null) {
    try { window.turnstile.remove(cfWidget); } catch (e) { /* 已销毁则忽略 */ }
  }
  cfWidget = null;
  CH.token = '';
  CH.channel = '';
  if (host) {
    host.dataset.cfMounted = '';
    host.innerHTML = '';
    mountCaptcha(host, host.__onCfToken);
  }
}

/**
 * 把验证组件挂到 host 里。onChange(token, channel) 在验证通过后被调用
 * （token 为空串表示尚未通过 / 已过期）。
 */
export function mountCaptcha(host, onChange) {
  if (!host) { return; }
  host.__onCfToken = onChange || host.__onCfToken;
  const cb = (t, c) => { CH.token = t; CH.channel = c; if (host.__onCfToken) { host.__onCfToken(t, c); } };

  const cfg = captchaConfig();
  if (!cfg.on) { host.innerHTML = ''; return; }
  if (host.dataset.cfMounted === '1') { return; }
  host.dataset.cfMounted = '1';
  CH.token = '';
  CH.channel = '';
  cb('', '');

  if (!cfg.sitekey || !cfg.script) {
    host.innerHTML = '<div class="tiny muted">' + t('人机验证未配置，请联系管理员') + '</div>';
    return;
  }

  host.innerHTML = '<div class="cap-box">'
    + '<div id="cfSlot"></div>'
    + '<div class="tiny muted" id="capHint" style="margin-top:6px">' + t('正在加载人机验证…') + '</div>'
    + '</div>';
  const hint = host.querySelector('#capHint');
  const slot = host.querySelector('#cfSlot');

  loadTurnstile(cfg.script).then(() => {
    if (!window.turnstile) { hint.textContent = t('人机验证加载失败，请刷新重试'); return; }
    const dark = document.documentElement.dataset.theme === 'dark';
    try {
      cfWidget = window.turnstile.render(slot, {
        sitekey: cfg.sitekey,
        theme: dark ? 'dark' : 'light',
        language: LANG_MAP[I18N.lang] || 'zh-cn',
        size: 'flexible',
        callback: (token) => { hint.textContent = t('验证通过'); cb(token, 'turnstile'); },
        'expired-callback': () => { hint.textContent = t('验证已过期，请重新验证'); cb('', ''); },
        'timeout-callback': () => { hint.textContent = t('验证超时，请重新验证'); cb('', ''); },
        'error-callback': () => { hint.textContent = t('验证出错，请刷新重试'); cb('', ''); }
      });
      hint.textContent = '';
    } catch (e) {
      hint.textContent = t('人机验证加载失败，请刷新重试');
    }
  }).catch(() => {
    hint.textContent = t('人机验证加载失败，请刷新重试');
  });
}
