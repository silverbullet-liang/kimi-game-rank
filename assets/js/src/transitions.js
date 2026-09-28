/**
 * 页面转场、页面缓存与预见式返回
 * ------------------------------------------------------------
 * 三件事：
 * 1) 页面缓存：已渲染的页面连同滚动位置一起留住。返回时直接恢复，
 *    不重新请求接口、不重建 DOM，因此不会「自动刷新」、不会丢滚动位置。
 * 2) 方向转场：用浏览器原生 View Transitions 实现前进/返回两套动画，
 *    返回时上一页从左侧滑回（「从哪来回哪去」）。
 * 3) 预见式返回：从左边缘右滑时页面实时跟手位移，同时把上一页从缓存
 *    取出铺在身后一起位移 —— 手指拖到哪儿就能看到上一页。
 *
 * 全部能力都做了降级：浏览器不支持时退化为无动画的普通切换，不影响功能。
 */

/* ============================================================
 * 页面缓存（LRU）
 * ============================================================ */
const cache = new Map();
const CACHE_MAX = 8;

export function cacheGet(key) { return cache.get(key) || null; }

export function cacheSet(key, node, scrollTop) {
  cache.delete(key);                                   // 重新插入，维持 LRU 顺序
  cache.set(key, { node: node, scrollTop: scrollTop || 0 });
  while (cache.size > CACHE_MAX) {
    const oldest = cache.keys().next().value;
    cache.delete(oldest);
  }
}

export function cacheTouch(key) {
  const v = cache.get(key);
  if (v) { cache.delete(key); cache.set(key, v); }
}

export function cacheDrop(key) { cache.delete(key); }

/** 找最近一次访问过的其它页面（用于返回预览） */
export function cachePrev(key) {
  const keys = Array.from(cache.keys());
  for (let i = keys.length - 1; i >= 0; i--) {
    if (keys[i] !== key) { return cache.get(keys[i]); }
  }
  return null;
}

/* ============================================================
 * 转场
 * ============================================================ */
let animOn = true;
export function setNavAnim(on) { animOn = !!on; }
export function navAnimOn() { return animOn; }

function reduceMotion() {
  return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}

/**
 * 带方向地执行一次页面切换。
 * dir: 'fwd'（前进） | 'back'（返回）
 * update: 返回 Promise 或同步完成 DOM 更新的函数
 */
export async function runTransition(dir, update) {
  /* 文档隐藏时开转场必然被跳过，浏览器会以 InvalidStateError 拒绝 ready —— 直接走无动画切换 */
  if (!animOn || document.hidden || reduceMotion() || typeof document.startViewTransition !== 'function') {
    await update();
    return;
  }
  const root = document.documentElement;
  root.dataset.navDir = dir;
  const done = function () { try { delete root.dataset.navDir; } catch (e) {} };

  /* 新签名支持 types（Chrome 125+）；老实现只接受回调 */
  let t = null;
  try {
    t = document.startViewTransition({ update: update, types: [dir] });
  } catch (e1) {
    try { t = document.startViewTransition(update); } catch (e2) { t = null; }
  }

  if (t) {
    /* ready 在「转场被跳过」时会 reject（InvalidStateError: Document hidden 等）。
       我们从不 await 它 —— 不接住就会冒成「未处理的 Promise 错误」；
       而转场被跳过根本不是错误，页面照样完成切换。 */
    if (t.ready && typeof t.ready.catch === 'function') { t.ready.catch(function () {}); }
    if (t.finished && typeof t.finished.then === 'function') {
      await t.finished.catch(function () {});      // 中途被中止同样不该冒泡
    } else {
      await update();
    }
  } else {
    await update();                                 // 原生 API 不可用：退化为无动画切换
  }
  done();
}

/* ============================================================
 * 预见式返回：左边缘右滑跟手 + 上一页预览
 * ============================================================ */
/**
 * @param view       主内容容器（被拖动的元素）
 * @param opts.canBack   () => boolean，当前是否允许返回（主标签页不允许）
 * @param opts.onPreview () => node|null，取出上一页节点用于预览
 * @param opts.onCommit  () => void，手势达到阈值、真正执行返回
 */
export function enablePredictiveBack(view, opts) {
  const EDGE = 36;      // 触发边缘宽度
  const THRESH = 78;    // 提交阈值
  let tracking = false, startX = 0, startY = 0, dx = 0, decided = false;
  let preview = null, holder = null;

  function cleanup() {
    view.style.transition = '';
    view.style.transform = '';
    view.style.willChange = '';
    if (holder && holder.parentNode) { holder.parentNode.removeChild(holder); }
    holder = null; preview = null;
    tracking = false; decided = false; dx = 0;
  }

  view.addEventListener('touchstart', function (e) {
    if (!opts.canBack || !opts.canBack()) { return; }
    if (e.touches.length !== 1) { return; }
    const t = e.touches[0];
    if (t.clientX > EDGE) { return; }
    tracking = true; decided = false; dx = 0;
    startX = t.clientX; startY = t.clientY;
    view.style.transition = 'none';
    view.style.willChange = 'transform';
  }, { passive: true });

  view.addEventListener('touchmove', function (e) {
    if (!tracking) { return; }
    const t = e.touches[0];
    const mx = t.clientX - startX;
    const my = t.clientY - startY;

    /* 先判断意图：纵向滑动为主则放弃（避免与页面滚动打架） */
    if (!decided) {
      if (Math.abs(my) > Math.abs(mx) && Math.abs(my) > 8) { cleanup(); return; }
      if (Math.abs(mx) > 6) { decided = true; } else { return; }
    }

    dx = Math.max(0, mx);
    view.style.transform = dx > 0 ? 'translateX(' + dx + 'px)' : '';

    /* 首次拖动时把上一页铺到身后 */
    if (!holder && dx > 4 && opts.onPreview) {
      preview = opts.onPreview();
      if (preview && preview.node) {
        holder = document.createElement('div');
        holder.className = 'back-preview';
        const clone = preview.node.cloneNode(true);
        /* 预览是「影子副本」：必须清掉 id 与交互标记，否则与当前页 DOM 冲突 */
        clone.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        clone.removeAttribute('id');
        clone.setAttribute('aria-hidden', 'true');
        clone.style.pointerEvents = 'none';
        holder.appendChild(clone);
        document.body.appendChild(holder);
        /* 用负边距把预览放在当前页左侧，形成「从哪来回哪去」的层次 */
        holder.style.transform = 'translateX(-30%) scale(.94)';
      }
    }
    if (holder) {
      const p = Math.min(dx / 260, 1);
      holder.style.transform = 'translateX(' + (-30 + p * 30) + '%) scale(' + (0.94 + p * 0.06) + ')';
      holder.style.opacity = String(0.55 + p * 0.45);
    }
  }, { passive: true });

  view.addEventListener('touchend', function () {
    if (!tracking) { return; }
    const commit = dx >= THRESH;
    if (commit) {
      /* 手势达成：动画退出后真正返回 */
      view.style.transition = 'transform .22s cubic-bezier(.2,.8,.2,1)';
      view.style.transform = 'translateX(100%)';
      if (holder) {
        holder.style.transition = 'transform .22s cubic-bezier(.2,.8,.2,1), opacity .22s';
        holder.style.transform = 'translateX(0) scale(1)';
        holder.style.opacity = '1';
      }
      setTimeout(function () {
        cleanup();
        if (opts.onCommit) { opts.onCommit(); }
      }, 200);
    } else {
      view.style.transition = 'transform .24s cubic-bezier(.2,.8,.2,1)';
      view.style.transform = '';
      if (holder) {
        holder.style.transition = 'transform .24s cubic-bezier(.2,.8,.2,1), opacity .24s';
        holder.style.transform = 'translateX(-30%) scale(.94)';
        holder.style.opacity = '0';
      }
      setTimeout(cleanup, 240);
    }
  }, { passive: true });

  view.addEventListener('touchcancel', cleanup, { passive: true });
}

/* ============================================================
 * 共享元素（「从哪来回哪去」的空间连续）
 * ------------------------------------------------------------
 * 列表项封面与详情页首图共用同一个 view-transition-name，
 * 于是进入时封面「长大」成详情图，返回时沿原路径缩回原处。
 * 两个槽位分别记录列表侧与详情侧的元素：同一时刻各页只有一个同名元素，
 * 符合 View Transitions 的唯一性要求；换一个作品时旧元素自动摘名。
 * ============================================================ */
let heroSrc = null, heroDst = null;

function namer(slot) {
  return function (el) {
    if (slot.prev && slot.prev !== el) {
      try { slot.prev.style.viewTransitionName = ''; } catch (e) {}
    }
    if (el) { try { el.style.viewTransitionName = 'hero'; } catch (e) {} }
    slot.prev = el || null;
  };
}
const setSrc = namer({ prev: null });
const setDst = namer({ prev: null });

export function setHeroSrc(el) { heroSrc = el; setSrc(el); }
export function setHeroDst(el) { heroDst = el; setDst(el); }
export function clearHero() {
  if (heroSrc) { try { heroSrc.style.viewTransitionName = ''; } catch (e) {} }
  if (heroDst) { try { heroDst.style.viewTransitionName = ''; } catch (e) {} }
  heroSrc = null; heroDst = null;
}
