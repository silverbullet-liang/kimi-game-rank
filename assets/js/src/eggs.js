/**
 * 彩蛋（v3.54.0）
 * ------------------------------------------------------------
 * 五个不打扰的小东西，全部满足三条约束：
 *   · 不影响任何功能，随时可关闭；
 *   · 移动端与桌面端都能遇到（键盘类彩蛋另有触屏等价操作）；
 *   · 每个彩蛋只触发一次（本机记住），不反复骚扰。
 *
 *   1. 开发者工具：打开 F12 时提示本站开源与仓库地址（不阻止、不对抗）
 *   2. 控制台：打印版本与仓库（打开控制台才看得到）
 *   3. 方向键密码：↑↑↓↓←→←→BA 撒一次星
 *   4. 连点站名七次：报出版本号与一句手记
 *   5. 凌晨访问：一句问候（0–5 点，每天一次）
 */
import { toast, esc } from './core.js';
import { t } from './i18n.js';

const REPO = 'https://github.com/silverbullet-liang/kimi-game-rank';
const VER = (typeof window !== 'undefined' && window.__SITE__ && window.__SITE__.ver) ? String(window.__SITE__.ver) : '';

/* ============================================================
 * 通用：一次性弹窗（自带关闭，点遮罩关闭）
 * ============================================================ */
function eggDialog(title, html, actions) {
  const scrim = document.getElementById('dialogScrim');
  const box = document.getElementById('dialog');
  if (!scrim || !box) { return; }
  box.className = 'dialog glass';
  box.innerHTML = '<h3>' + title + '</h3>' + html
    + '<div class="dialog-actions">' + (actions || []).map(function (a, i) {
      return '<button class="' + (i === 0 ? 'btn-ghost' : 'btn') + '" data-e="' + i + '">' + a.label + '</button>';
    }).join('') + '</div>';
  scrim.hidden = false;
  const close = function () {
    scrim.hidden = true;
    box.innerHTML = '';
    scrim.onclick = null;
  };
  box.querySelectorAll('[data-e]').forEach(function (b) {
    b.addEventListener('click', function () {
      const act = (actions || [])[Number(b.dataset.e)] || {};
      if (typeof act.onClick === 'function') { act.onClick(); }
      close();
    });
  });
  scrim.onclick = function (e) { if (e.target === scrim) { close(); } };
}

function once(key) {
  try {
    if (localStorage.getItem(key) === '1') { return false; }
    localStorage.setItem(key, '1');
  } catch (e) { /* 存储不可用就当没记过 */ }
  return true;
}

/* ============================================================
 * 一、开发者工具 → 提示开源
 * 只在视口被明显压缩时判定（双侧合计 > 160px），移动端不会误触发。
 * 不对抗：不写 debugger、不阻断、不隐藏内容，只是把仓库地址告诉你。
 * ============================================================ */
function watchDevTools() {
  let done = false;
  const check = function () {
    if (done || document.visibilityState !== 'visible') { return; }
    const dw = Math.abs((window.outerWidth || 0) - (window.innerWidth || 0));
    const dh = Math.abs((window.outerHeight || 0) - (window.innerHeight || 0));
    if (dw <= 160 && dh <= 160) { return; }
    done = true;
    if (!once('kimgr_egg_devtools')) { return; }
    eggDialog(
      esc(t('你打开了开发者工具')),
      '<p class="tiny muted">' + esc(t('本站全部源码以 AGPL-3.0 公开。想看清怎么算的分、主题怎么做，仓库里都有。')) + '</p>'
        + '<p class="tiny" style="user-select:all;word-break:break-all">' + esc(REPO) + '</p>',
      [
        { label: esc(t('继续浏览')) },
        { label: esc(t('去仓库看看')), onClick: function () { window.open(REPO, '_blank', 'noopener'); } },
      ]
    );
  };
  check();
  setInterval(check, 1200);
}

/* ============================================================
 * 二、控制台留言
 * ============================================================ */
function consoleNote() {
  try {
    const css = 'color:#7C3AED;font-weight:700';
    console.log('%c Kimi 游戏榜 ' + (VER ? 'v' + VER : ''), 'color:#fff;background:#7C3AED;padding:2px 6px;border-radius:4px');
    console.log('%c六维评分 · 全部源码 AGPL-3.0 公开', css);
    console.log('%c' + REPO, 'color:#666');
    console.log('%c如果你在做类似的站，欢迎对照实现或提 issue。', 'color:#888');
  } catch (e) { /* 控制台不可用则跳过 */ }
}

/* ============================================================
 * 三、方向键密码 → 撒一次星
 * ============================================================ */
const KONAMI = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a'];

function watchKonami() {
  let idx = 0;
  document.addEventListener('keydown', function (e) {
    const k = e.key && e.key.length === 1 ? e.key.toLowerCase() : e.key;
    if (k === KONAMI[idx]) {
      idx++;
      if (idx === KONAMI.length) {
        idx = 0;
        starBurst();
      }
    } else {
      idx = (k === KONAMI[0]) ? 1 : 0;
    }
  }, true);
}

/** 满屏撒一次星：纯 CSS 动画，3 秒后自动清理，不残留节点 */
function starBurst() {
  if (!once('kimgr_egg_stars')) { return; }
  const box = document.createElement('div');
  box.setAttribute('aria-hidden', 'true');
  box.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:90;overflow:hidden';
  let html = '';
  for (let i = 0; i < 60; i++) {
    const left = Math.round(Math.random() * 1000) / 10;
    const delay = Math.round(Math.random() * 900);
    const dur = 1600 + Math.round(Math.random() * 1200);
    const size = 2 + Math.round(Math.random() * 4);
    html += '<i style="position:absolute;top:-10px;left:' + left + '%;width:' + size + 'px;height:' + size + 'px;'
      + 'border-radius:50%;background:#FFD873;box-shadow:0 0 8px #FFD873;'
      + 'animation:eggStar ' + dur + 'ms linear ' + delay + 'ms forwards"></i>';
  }
  box.innerHTML = html;
  const style = document.createElement('style');
  style.textContent = '@keyframes eggStar{to{transform:translateY(105vh) rotate(220deg);opacity:0}}';
  box.appendChild(style);
  document.body.appendChild(box);
  setTimeout(function () { box.remove(); }, 3600);
  toast(t('找到密码了'));
}

/* ============================================================
 * 四、连点站名七次
 * ============================================================ */
function watchBrandTaps() {
  let n = 0;
  let last = 0;
  document.addEventListener('click', function (e) {
    const el = e.target && e.target.closest ? e.target.closest('.brand-name, .topbar .logo') : null;
    if (!el) { return; }
    const now = Date.now();
    if (now - last > 1600) { n = 0; }      // 间隔太久重新计
    last = now;
    n++;
    if (n < 7) { return; }
    n = 0;
    if (!once('kimgr_egg_taps')) { return; }
    eggDialog(
      esc(t('第七下')),
      '<p class="tiny muted">' + esc(t('你连点了七次站名。当前版本 v') + (VER || '?') + t('，站点仍在迭代。')) + '</p>'
        + '<p class="tiny muted">' + esc(t('一句话手记：把地址写成普通路径那天，最难的是让每一处相对引用都别断。')) + '</p>'
        + '<p class="tiny" style="user-select:all;word-break:break-all">' + esc(REPO) + '</p>',
      [{ label: esc(t('知道了')) }, { label: esc(t('看看更新日志')), onClick: function () {
        document.dispatchEvent(new CustomEvent('kimgr:navigate', { detail: '#/doc/更新日志' }));
      } }]
    );
  }, true);
}

/* ============================================================
 * 五、凌晨访问
 * ============================================================ */
function lateNight() {
  const h = new Date().getHours();
  if (h < 0 || h >= 5) { return; }
  const day = new Date().toISOString().slice(0, 10);
  try {
    if (localStorage.getItem('kimgr_egg_late') === day) { return; }
    localStorage.setItem('kimgr_egg_late', day);
  } catch (e) { return; }
  setTimeout(function () {
    toast(t('凌晨了，早点休息。') + (h > 0 ? t('（现在 ') + h + t(' 点）') : ''));
  }, 2600);
}

/* ============================================================
 * 启动（延迟到首屏渲染之后，不抢主线程）
 * ============================================================ */
export function startEggs() {
  setTimeout(function () {
    consoleNote();
    watchDevTools();
    watchKonami();
    watchBrandTaps();
    lateNight();
  }, 1800);
}
