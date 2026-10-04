/**
 * 榜单页
 */
import { api, state, esc, toast, searchAiOn } from '../core.js';
import { navigate } from '../router.js';
import { setHeroSrc } from '../transitions.js';
import { mdToHtml } from '../md.js';

const CATS = [
  { k: 'game', name: '游戏榜' },
  { k: 'tool', name: '工具榜' },
  { k: 'literature', name: '文学榜' },
  { k: 'fanart', name: '二创榜' },
];
const BOARDS = [
  { k: 'total', name: '总榜' },
  { k: 'vote', name: '投票榜' },
  { k: 'gods', name: '诸神榜' },
  { k: 'cold', name: '冷门榜' },
];
const AI_SUM_FOLD = 240;      // AI 总结折叠阈值（纯文本字数）
export const PAGE_SIZE = 12;  // 小分页：首屏更快，一次别拉太多（服务端按此值返回）

/* 刚在榜单里点开的作品：从详情返回时用它自动定位（见 locateFocus）。
   带时间戳，避免很久之后的一次返回把页面跳走。 */
let pendingFocus = 0;
let pendingFocusAt = 0;

export async function renderRank(container, ctx) {
  const params = (ctx && ctx.params) || {};
  let cat = params.category || 'all';
  let board = params.board || 'total';
  const q = params.q || '';
  let page = 1;
  let loading = false;
  let done = false;
  let totalCount = 0;
  let shownCount = 0;

  /* 搜索页顶部的 AI 总结：仅在有关键词、且用户没关掉时出现 */
  const showAi = q !== '' && searchAiOn();

  container.innerHTML = `
    ${showAi ? `<div class="ai-sum" id="aiSum" hidden>
      <div class="ai-sum-head">
        <span class="ai-sum-tag">AI</span>
        <span class="ai-sum-title">关于「${esc(q)}」的总结</span>
        <span class="tiny muted ai-sum-hint">AI 生成，仅供参考</span>
      </div>
      <div class="ai-sum-body" id="aiSumBody">
        <div class="ai-sum-loading"><i></i><i></i><i></i></div>
      </div>
      <button class="link ai-sum-toggle" id="aiSumToggle" hidden>展开全部</button>
    </div>` : ''}
    ${q ? `<div class="search-note">
      <span>搜索「<b>${esc(q)}</b>」的结果</span>
      <button class="link" id="clearSearch" style="border:0;background:0">清除搜索</button>
    </div>` : ''}
    <div class="seg" id="boardSeg">${BOARDS.map(b => `<button data-b="${b.k}" class="${b.k === board ? 'on' : ''}">${b.name}</button>`).join('')}</div>
    <div class="cat-tabs" id="catTabs">
      <button class="cat-tab ${cat === 'all' ? 'on' : ''}" data-c="all"><span class="n" id="cntAll">·</span><span>全部</span></button>
      ${CATS.map(c => `<button class="cat-tab ${cat === c.k ? 'on' : ''}" data-c="${c.k}"><span class="n" data-cnt="${c.k}">·</span><span>${c.name}</span></button>`).join('')}
    </div>
    <div class="rank-list" id="rankList"></div>
    <div class="rank-more" id="rankMoreBox">
      <button class="btn btn-sm" id="rankMore" hidden>加载更多</button>
      <div class="tiny" id="rankFoot"></div>
    </div>
  `;

  if (showAi) { loadSearchAi(container, q); }   // 与榜单并行，不阻塞首屏

  const list = container.querySelector('#rankList');
  const foot = container.querySelector('#rankFoot');
  const moreBox = container.querySelector('#rankMoreBox');
  const moreBtn = container.querySelector('#rankMore');

  function paintFoot() {
    /* 手动加载按钮：还有下一页时出现，加载中置灰，到底后收起 */
    if (moreBtn) {
      moreBtn.hidden = done || totalCount <= 0;
      moreBtn.disabled = loading;
      moreBtn.textContent = loading ? '加载中…' : '加载更多';
    }
    if (totalCount <= 0) { foot.textContent = ''; return; }
    if (loading) { foot.textContent = '加载中…'; return; }
    foot.textContent = done
      ? ('已显示全部 ' + shownCount + ' 件')
      : ('已显示 ' + shownCount + ' / 共 ' + totalCount + ' 件 · 下滑自动加载，也可点上方按钮');
  }

  async function load(reset) {
    if (loading) return;
    if (reset) { page = 1; done = false; list.innerHTML = ''; }
    if (done) return;
    loading = true;
    if (reset) list.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
    try {
      let d = null;
      /* 首屏：启动载荷已把「同参数的第一页」带回来了，直接用，省掉一次往返 */
      if (reset && q === '' && state.firstPage
          && state.firstPage.cat === cat && state.firstPage.board === board) {
        d = state.firstPage;
        state.firstPage = null;
      }
      if (!d) {
        d = await api('works.php', 'list', { category: cat, board: board, q: q, page: page, size: PAGE_SIZE });
      }
      if (reset) { list.innerHTML = ''; shownCount = 0; }
      const items = d.items || [];
      totalCount = Number(d.total || 0);
      if (!items.length && page === 1) {
        list.innerHTML = '<div class="empty"><p>这里还没有作品</p></div>';
      }
      items.forEach((w, idx) => list.appendChild(rankRow(w, (page - 1) * PAGE_SIZE + idx + 1)));
      shownCount += items.length;
      done = !d.has_more;
      page++;
    } catch (e) {
      if (reset) list.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`;
      foot.textContent = '加载失败：' + e.message;
    } finally {
      loading = false;
      paintFoot();
    }
  }

  /* 分类计数：启动载荷已经带回（服务端 60 秒缓存），直接落屏，不再为首屏多打一个请求。
     万一落到旧后端（没带计数），再补一次查询。 */
  function paintCounts(cats) {
    let total = 0;
    Object.keys(cats || {}).forEach(k => {
      total += Number(cats[k]) || 0;
      const el = container.querySelector(`[data-cnt="${k}"]`);
      if (el) { el.textContent = cats[k]; }
    });
    const all = container.querySelector('#cntAll');
    if (all) { all.textContent = total; }
  }
  paintCounts(state.categories);
  if (!state.categories || !Object.keys(state.categories).length) {
    api('site.php', 'bootstrap', null, { silent: true }).then(d => paintCounts(d && d.categories)).catch(() => {});
  }

  container.querySelector('#boardSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-b]'); if (!b) return;
    board = b.dataset.b;
    container.querySelectorAll('#boardSeg button').forEach(x => x.classList.toggle('on', x === b));
    load(true);
  });
  const clearBtn = container.querySelector('#clearSearch');
  if (clearBtn) clearBtn.addEventListener('click', () => navigate('#/rank'));

  container.querySelector('#catTabs').addEventListener('click', e => {
    const b = e.target.closest('[data-c]'); if (!b) return;
    cat = b.dataset.c;
    container.querySelectorAll('#catTabs .cat-tab').forEach(x => x.classList.toggle('on', x === b));
    load(true);
  });

  /* 手动加载：点按钮取下一页 */
  if (moreBtn) { moreBtn.addEventListener('click', () => load(false)); }

  /* 自动加载：用观察器盯着列表末尾，提前 700px 触发 —— 比「滚动事件 + 高度比对」
     灵敏得多，首屏不满一屏时也能立刻续拉；不支持观察器的环境退回滚动兜底。 */
  const scroller = document.getElementById('view');
  function onScroll() {
    if (!list.isConnected) { scroller.removeEventListener('scroll', onScroll); return; }
    if (done || loading) { return; }
    if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 800) { load(false); }
  }
  let io = null;
  if (typeof IntersectionObserver === 'function' && moreBox) {
    io = new IntersectionObserver(() => {
      if (!list.isConnected) { io.disconnect(); return; }
      if (done || loading) { return; }
      load(false);
    }, { rootMargin: '700px 0px' });          // 默认以视口为根：无论哪个祖先在滚动，进入提前量都能命中
    io.observe(moreBox);
  } else {
    scroller.addEventListener('scroll', onScroll, { passive: true });
  }

  /* 从作品详情返回时自动定位：滚到那件作品并短暂高亮；若它还没被加载出来，
     按需继续取下一页直到找到（最多 12 页），省掉手动翻找。 */
  let focusTries = 0;
  container.__onResume = function () { locateFocus(); };

  function locateFocus() {
    if (!pendingFocus) { return; }
    if (Date.now() - pendingFocusAt > 600000) { pendingFocus = 0; return; }
    const row = list.querySelector('.rank-item[data-cid="' + pendingFocus + '"]');
    if (row) { focusRow(row); return; }
    if (done || loading || focusTries > 12) { pendingFocus = 0; return; }
    focusTries++;
    load(false).then(function () { if (list.isConnected) { locateFocus(); } });
  }
  function focusRow(row) {
    pendingFocus = 0; focusTries = 0;
    try { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    catch (e) { row.scrollIntoView(); }
    row.classList.remove('rank-flash');
    void row.offsetWidth;                        // 重置动画：连点多次也能再次高亮
    row.classList.add('rank-flash');
    setTimeout(function () { row.classList.remove('rank-flash'); }, 1800);
  }

  await load(true);
}

function rankRow(w, rank) {
  const el = document.createElement('button');
  el.className = 'rank-item';
  el.type = 'button';
  el.dataset.cid = w.id;                           // 返回定位靠它反查
  const medal = rank <= 3 ? `m${rank}` : '';
  el.innerHTML = `
    <span class="medal ${medal}">${rank}</span>
    ${w.cover
      ? `<span class="thumb"><img src="${esc(w.cover)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false"></span>`
      : '<span class="thumb ph" aria-hidden="true"></span>'}
    <span class="rank-main">
      <span class="rank-title">${esc(w.title)}</span>
      <span class="rank-meta">
        <span class="chip">${esc(w.category_name)}</span>
        <span>${esc(w.author)}</span>
        <span>热度 ${w.heat}</span>
      </span>
    </span>
    <span class="rank-score">
      <span class="rating r-${esc(w.rating)}">${esc(w.rating)}</span>
      <span class="total">${w.total}</span>
    </span>`;
  el.addEventListener('click', () => {
    pendingFocus = w.id;                           // 返回榜单时自动定位到这一条
    pendingFocusAt = Date.now();
    setHeroSrc(el.querySelector('.thumb'));        // 共享元素：卡片封面
    navigate('#/detail/' + w.id);
  });
  return el;
}

/* ============================================================
 * 搜索页顶部的 AI 总结卡片
 * ------------------------------------------------------------
 * 单轮生成（模型只看一次站内搜索结果，无工具、无追问），
 * 服务端会缓存同样的关键词，命中缓存秒回且不消耗任何额度。
 * 生成失败 / 站点当日额度用尽 / 无命中条目 → 直接撤掉卡片，
 * 不留空壳、不弹错误，搜索本身不受影响。
 * ============================================================ */
async function loadSearchAi(container, q) {
  const card = container.querySelector('#aiSum');
  const body = container.querySelector('#aiSumBody');
  const toggle = container.querySelector('#aiSumToggle');
  if (!card || !body || !toggle) { return; }

  let d;
  try {
    d = await api('ai.php', 'search_ai', { q: q }, { silent: true, timeout: 40000 });
  } catch (e) { card.remove(); return; }

  const answer = String((d && d.answer) || '').trim();
  if (answer === '') { card.remove(); return; }

  body.innerHTML = mdToHtml(answer);
  card.hidden = false;

  /* 折叠判定按纯文本字数，与字体加载、屏幕宽度无关，结果稳定 */
  const plain = body.textContent.replace(/\s+/g, '');
  if (plain.length <= AI_SUM_FOLD) { return; }

  card.classList.add('collapsed');
  toggle.hidden = false;
  toggle.textContent = '展开全部';
  toggle.addEventListener('click', () => {
    const folded = card.classList.toggle('collapsed');
    toggle.textContent = folded ? '展开全部' : '收起';
    if (!folded) { card.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
  });
}
