/**
 * 对话页：世界对话 / AI 对话
 * 文件名曾为 chat.js：主机拦截路径中含 chat 的请求，故改名（页面路由仍为 #/chat）。
 * 布局：固定头部 + 内部滚动消息区 + 固定输入区（不整页滚动）
 * 游客：仅可查看，输入区锁定（后端同样强制校验）
 *
 * 注意：本文件为合并构建的源文件，勿出现重复函数名（tools/jscheck.py 会校验）。
 */
import { api, state, esc, toast, btnLoading, dialog, userName, isAdminish, bindImageViewer } from '../core.js';
import { mdToHtml } from '../md.js';
import { navigate } from '../router.js';

let mode = 'world';        // world | ai
let EMOJI = null;          // { 名称: 图片路径 }
let PACKS = null;          // [ { id, name, items:[{code,file}] } ] 供分组面板使用

/** 加载本地表情索引（表情已本地化，无防盗链问题） */
async function loadPacks() {
  if (PACKS) { return PACKS; }
  try {
    const r = await fetch('assets/emoji/index.json', { cache: 'force-cache' });
    const d = await r.json();
    PACKS = (d.packs || []).filter(function (p) { return (p.items || []).length; });
    EMOJI = {};
    PACKS.forEach(function (p) {
      (p.items || []).forEach(function (it) { EMOJI[it.code] = 'assets/emoji/' + it.file; });
    });
  } catch (e) { PACKS = []; EMOJI = {}; }
  return PACKS;
}
async function loadEmoji() { await loadPacks(); return EMOJI; }

/**
 * 展开表情选择面板：顶部分组（常用 / 小黄脸 / tv 小电视），下方网格。
 * 表情已达三百余个，平铺一屏会失去可用性，因此按包切换；
 * 网格采用事件委托，切换分组不重复绑定。
 */
async function mountEmojiPicker(picker, input) {
  const packs = await loadPacks();
  picker.dataset.kind = 'emoji';
  if (!packs.length) {
    picker.innerHTML = '<div class="picker-head">未找到表情资源</div>';
    picker.hidden = false;
    return;
  }
  picker.innerHTML =
    '<div class="picker-head">表情（点击插入）</div>' +
    '<div class="pk-tabs">' + packs.map(function (p, i) {
      return '<button class="pk-tab' + (i === 0 ? ' on' : '') + '" data-pk="' + i + '">' +
        esc(p.name) + '<span class="pk-n">' + (p.items || []).length + '</span></button>';
    }).join('') + '</div>' +
    '<div class="picker-grid"></div>';
  picker.hidden = false;
  picker.scrollTop = 0;

  const grid = picker.querySelector('.picker-grid');
  const draw = function (i) {
    grid.innerHTML = (packs[i].items || []).map(function (it) {
      return '<button class="pk-emoji" data-name="' + esc(it.code) + '" title="' + esc(it.code) + '">' +
        '<img src="assets/emoji/' + esc(it.file) + '" alt="' + esc(it.code) + '" loading="lazy" draggable="false"></button>';
    }).join('');
    picker.scrollTop = 0;
  };
  draw(0);

  picker.querySelector('.pk-tabs').addEventListener('click', function (e) {
    const b = e.target.closest('[data-pk]'); if (!b) { return; }
    picker.querySelectorAll('[data-pk]').forEach(function (x) { x.classList.toggle('on', x === b); });
    draw(parseInt(b.dataset.pk, 10) || 0);
  });
  grid.addEventListener('click', function (e) {
    const b = e.target.closest('[data-name]'); if (!b) { return; }
    input.value += '[' + b.dataset.name + ']';
    input.dispatchEvent(new Event('input'));
    input.focus();
  });
}

/** 远程图片：默认直连原图（不消耗主机请求数）；直连失败由全局 error 捕获回退到本站代理 */
function imgSrc(u) {
  if (!u) { return ''; }
  if (/^api\/media\.php/i.test(u)) { return u; }
  if (/^https?:\/\//i.test(u)) { return u; }
  return '';
}

/**
 * 行内富化：站内表情 [名称] + 图片链接 → img。
 * 入参必须是「已转义」的 HTML 片段（Markdown 渲染器与纯文本渲染器共用此函数）。
 */
function richInline(html) {
  let h = html;
  if (EMOJI) {
    h = h.replace(/\[([^\[\]\s]{1,12})\]/g, function (m, name) {
      const f = EMOJI[name];
      return f ? ('<img class="emoji" src="' + f + '" alt="' + esc(name) + '" draggable="false">') : m;
    });
  }
  return h.replace(/(https?:\/\/[^\s<>"']+\.(?:png|jpe?g|gif|webp|avif)(?:\?[^\s<>"']*)?)/gi, function (u) {
    return '<img class="msg-img" src="' + esc(u) + '" alt="图片" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false">';
  });
}

/** 世界对话消息：纯文本 + 表情 + 图片（刻意不渲染 Markdown，避免消息被格式刷屏） */
function renderRich(text) {
  /* @ 提及：与评论回复同款灰字（.reply-to） */
  const h = esc(text).replace(/(^|[\s（(【[>])@([\u4e00-\u9fa5A-Za-z0-9_\-]{1,16})/g,
    (m, p, nm) => p + '<span class="reply-to">@' + nm + '</span>');
  return richInline(h);
}

/** 清洗历史里可能残留的工具标签（旧版本数据），避免裸标签展示 */
/** AI 回复最终渲染：剥离工具标签 → Markdown → 表情 / 图片 */
function renderAiRich(text) {
  return mdToHtml(stripToolTags(text), richInline);
}

/** 流式绘制：以「原始文本」为唯一真源整段重绘（节流调用），额外提示追加在末尾 */
function paintAi(target, raw, tail) {
  const p = aiParts(target);
  p.text.classList.add('md-on');   // Markdown 已渲染，交给块级布局
  p.text.innerHTML = renderAiRich(raw) + (tail || '');
}

function stripToolTags(text) {
  let s = String(text == null ? '' : text);

  /* 裸工具调用（无标签）：模型可能直接输出「工具名 + 空行 + JSON」。
     与后端 works_tool_scan_bare 同规则，作为最后一道兜底，确保指令绝不露出。 */
  const NAMES = 'web_open|webopen|search|get|rank|comments|weather|time';
  const fence = '(?:`{3}[a-zA-Z0-9]*[ \\t]*\\r?\\n)?[ \\t]*';
  const gap = '[ \\t]*[:：]?[ \\t]*(?:\\r?\\n[ \\t]*){0,3}';
  const obj = '\\{[^{}]*(?:\\{[^{}]*\\}[^{}]*)*\\}';
  const tail = '[ \\t]*(?:\\r?\\n[ \\t]*`{3})?';
  const bareRe = new RegExp('(?:^|\\r?\\n)[ \\t]*' + fence + '(' + NAMES + ')' + gap + fence + '(' + obj + ')' + tail, 'gi');
  s = s.replace(bareRe, (m, name, body) => {
    try { const j = JSON.parse(body); if (j && j.action) { return '\n'; } } catch (e) {}
    return m;
  });
  /* 单独的裸工具名整行（如只输出 time） */
  s = s.replace(/(^|\r?\n)[ \t]*(web_open|webopen|search|get|rank|comments|weather|time)[ \t]*[:：]?[ \t]*(?=\r?\n|$)/gi, '$1');

  return s
    .replace(/<Works\s*[\-_ ]?\s*check\s*>[\s\S]*?(?:<\/Works\s*[\-_ ]?\s*check\s*>|$)/gi, '')
    .replace(/<\/?(?:web_open|webopen|search|get|rank|comments|weather|time)\b[^>]*\/?>/gi, '')
    .replace(/<Works\s*[\-_ ]?\s*check\s*>/gi, '')
    .trim();
}

export async function renderLobby(container, ctx) {
  container.innerHTML = `
    <div class="seg seg-narrow" id="chatSeg">
      <button data-m="world" class="${mode === 'world' ? 'on' : ''}">世界对话</button>
      <button data-m="ai" class="${mode === 'ai' ? 'on' : ''}">AI 对话</button>
    </div>
    <div id="chatBody" class="chat-body"></div>
  `;
  container.querySelector('#chatSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-m]'); if (!b) return;
    mode = b.dataset.m;
    container.querySelectorAll('#chatSeg button').forEach(x => x.classList.toggle('on', x === b));
    mountBody(container);
  });
  mountBody(container);
}

function mountBody(container) {
  const body = container.querySelector('#chatBody');
  if (mode === 'world') mountWorld(body);
  else mountAi(body);
}

/** 判断消息区是否已接近底部（用户手动上翻时不打扰） */
function atBottom(el) {
  return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}
function toBottom(el) {
  el.scrollTop = el.scrollHeight;
}

/* ============================================================
 * 世界对话（重构版）
 * ------------------------------------------------------------
 * 与后端 api/lobby.php 的契约：
 *   list   { since_id?, limit? }            → { items:[…], degraded? }
 *   send   { type:'text'|'image', content?, media? } → 单条消息
 *   recall { id }                           → 空
 * 消息对象：{ id, uid, username, role, avatar, content,
 *            msg_type, media, recalled, mine, time }
 *
 * 增量推送由全局轮询触发：window.__chatAppend(items) / window.__setPollCursor(id)
 * 设计要点：
 *   - 以 id 去重，杜绝轮询与本地追加造成重复上屏
 *   - 只在「用户本就在底部」时自动贴底，不打扰上翻阅读
 *   - 失败一律就地给出重试入口，绝不静默
 * ============================================================ */
const W_ICON_SMILE = '<svg viewBox="0 0 24 24" class="ic"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM8.5 9.5a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zm7 0a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zM7.6 14.2a1 1 0 0 1 1.4-.1 4.6 4.6 0 0 0 6 0 1 1 0 0 1 1.3 1.5 6.6 6.6 0 0 1-8.6 0 1 1 0 0 1-.1-1.4z"/></svg>';
const W_ICON_IMAGE = '<svg viewBox="0 0 24 24" class="ic"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 12h14l-4.5-6-3.5 4.5-2.5-2.5L5 17zm3.5-7a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2z"/></svg>';
const W_ICON_SEND = '<svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:#fff"><path d="M3 20l18-8L3 4v6l12 2-12 2v6z"/></svg>';

async function mountWorld(body) {
  const canSend = state.role === 'user' || isAdminish();
  window.__chatPollEnabled = true;
  window.__aiBusy = false;

  body.innerHTML = `
    <div class="chat-wrap">
      <div class="chat-stream" id="wStream">
        <div class="skeleton" style="height:54px"></div>
        <div class="skeleton" style="height:54px"></div>
      </div>
      <div class="picker" id="wPicker" hidden></div>
      <div class="chat-input">
        <button class="icon-btn pk-btn" id="wEmoji" aria-label="表情" ${canSend ? '' : 'disabled'}>${W_ICON_SMILE}</button>
        <button class="icon-btn pk-btn" id="wImage" aria-label="图片" ${canSend ? '' : 'disabled'}>${W_ICON_IMAGE}</button>
        <button class="icon-btn pk-btn at-txt" id="wAt" aria-label="@ 官方AI" ${canSend ? '' : 'disabled'}>@</button>
        <div class="cnt" id="wCount">0/500</div>
        <textarea class="input" id="wInput" rows="1" maxlength="500"
          placeholder="${canSend ? '输入消息…' : '游客仅可查看，登录后可发言'}" ${canSend ? '' : 'disabled'}></textarea>
        <button class="btn" id="wSend" aria-label="发送" ${canSend ? '' : 'disabled'}>${W_ICON_SEND}</button>
      </div>
      <div class="identity-tip">
        <span>当前发言身份：${userName(state.username, state.role)}${canSend ? '' : ' · 游客仅可查看'}</span>
        ${canSend ? '' : '<button class="link" id="wLogin" style="border:0;background:0;font-size:12px">登录后可发言</button>'}
      </div>
    </div>`;

  const stream  = body.querySelector('#wStream');
  bindImageViewer(stream);          /* 点图片看大图 */
  const input   = body.querySelector('#wInput');
  const sendBtn = body.querySelector('#wSend');
  const count   = body.querySelector('#wCount');
  const picker  = body.querySelector('#wPicker');

  const seen = new Set();     // 已上屏的 id
  let cursor = 0;             // 已上屏的最大 id

  function stick() { stream.scrollTop = stream.scrollHeight; }

  function render(m) {
    const el = document.createElement('div');
    el.className = 'msg' + (m.mine ? ' mine' : '') + (m.msg_type === 'ai' ? ' ai' : '');
    let inner;
    if (m.recalled) {
      inner = '<span class="recall">该消息已撤回</span>';
    } else if (m.msg_type === 'image' && m.media) {
      inner = '<img class="msg-img" src="' + imgSrc(m.media) + '" alt="图片消息" loading="lazy" decoding="async" referrerpolicy="no-referrer" draggable="false">';
    } else {
      inner = renderRich(m.content);
    }
    const recallBtn = (m.mine && !m.recalled)
      ? ' · <button class="link" data-recall="1" style="border:0;background:0;font-size:12px">撤回</button>' : '';
    /* 单条删除：本人或管理员都能删；官方 AI 的消息只有管理员看得到这个按钮 */
    const delBtn = (!m.recalled && (m.mine || isAdminish()))
      ? ' · <button class="link" data-del="1" style="border:0;background:0;font-size:12px">删除</button>' : '';
    el.innerHTML = '<span class="av"><img src="' + esc(m.avatar) + '" alt="" draggable="false" style="user-select:none"></span>'
      + '<span class="bubble-wrap">'
      +   '<span class="who">' + userName(m.username, m.role) + ' · ' + esc(m.time) + recallBtn + delBtn + '</span>'
      +   '<div class="bubble">' + inner + '</div>'
      +   (!m.recalled && m.flag === 'middle'
            ? '<span class="msg-flag" title="系统认为这条内容可能有恶意，但仍予放行">可能有恶意'
              + (isAdminish() ? ' · <button class="link" data-unflag="1" style="border:0;background:0;font-size:12px;color:inherit;text-decoration:underline">取消标注</button>' : '')
              + '</span>' : '')
      + '</span>';

    const rb = el.querySelector('[data-recall]');
    if (rb) {
      rb.addEventListener('click', async () => {
        rb.disabled = true;
        try {
          await api('lobby.php', 'recall', { id: m.id });
          m.recalled = true;
          const b = el.querySelector('.bubble');
          if (b) { b.innerHTML = '<span class="recall">该消息已撤回</span>'; }
          rb.remove();
        } catch (e) { rb.disabled = false; toast(e.message, 'err'); }
      });
    }

    const db2 = el.querySelector('[data-del]');
    if (db2) {
      db2.addEventListener('click', async () => {
        if (!(await dialog('删除这条消息', '删除后这条消息在对话里不再显示（对所有人）。确认？', '删除', { danger: true }))) { return; }
        db2.disabled = true;
        try {
          await api('lobby.php', 'del', { id: m.id });
          m.recalled = true;
          const b = el.querySelector('.bubble');
          if (b) { b.innerHTML = '<span class="recall">该消息已删除</span>'; }
          const rb2 = el.querySelector('[data-recall]'); if (rb2) { rb2.remove(); }
          db2.remove();
        } catch (e) { db2.disabled = false; toast(e.message, 'err'); }
      });
    }

    /* 管理员：一键取消「可能有恶意」标注（系统误标时用） */
    const ub = el.querySelector('[data-unflag]');
    if (ub) {
      ub.addEventListener('click', async () => {
        ub.disabled = true;
        try {
          await api('lobby.php', 'flag', { id: m.id, on: 0 });
          m.flag = '';
          const f = el.querySelector('.msg-flag'); if (f) { f.remove(); }
          toast('已取消标注');
        } catch (e) { ub.disabled = false; toast(e.message, 'err'); }
      });
    }
    return el;
  }

  function put(m, scroll) {
    if (!m || !(m.id > 0) || seen.has(m.id)) { return; }
    seen.add(m.id);
    if (m.id > cursor) { cursor = m.id; }
    stream.appendChild(render(m));
    if (scroll) { stick(); }
    if (window.__setPollCursor) { window.__setPollCursor(cursor); }
  }

  function emptyNote(text) {
    const d = document.createElement('div');
    d.className = 'empty';
    d.style.padding = '22px';
    d.textContent = text;
    return d;
  }

  function errorNote(msg, onRetry) {
    stream.innerHTML = '';
    const d = document.createElement('div');
    d.className = 'empty';
    const p = document.createElement('p');
    p.textContent = msg;
    const b = document.createElement('button');
    b.className = 'btn-ghost btn-sm';
    b.textContent = '重试';
    b.addEventListener('click', onRetry);
    d.appendChild(p); d.appendChild(b);
    stream.appendChild(d);
  }

  async function load() {
    stream.innerHTML = '<div class="skeleton" style="height:54px"></div><div class="skeleton" style="height:54px"></div>';
    try {
      const d = await api('lobby.php', 'list', { limit: 30 });
      stream.innerHTML = '';
      if (d && d.degraded) { errorNote(d.hint || '对话暂时不可用，请稍后重试', load); return; }
      const items = (d && d.items) || [];
      items.forEach(m => put(m, false));
      if (!items.length) { stream.appendChild(emptyNote('还没有人发言，来发第一条吧')); }
      stick();
    } catch (e) {
      errorNote(e.message || '加载失败', load);
    }
  }

  /**
   * 审核未通过的提示条。
   * 刻意不用 toast —— toast 几秒就没了，而这里要留下一个「AI 重审」按钮，
   * 等用户自己决定是否申诉。点它会让模型单独复核一次，返回 true / middle / false。
   */
  function rejectNote(text, payload) {
    const old = stream.parentNode.querySelector('.reject-note');
    if (old) { old.remove(); }
    const bar = document.createElement('div');
    bar.className = 'reject-note';
    const canReview = !(payload && payload.type === 'image');   // 图片外链没有「重审」可言
    bar.innerHTML = '<span class="rn-text">' + esc(text) + '</span>'
      + (canReview ? '<button class="btn btn-sm" data-review="1">AI 重审</button>' : '');
    stream.after(bar);

    const btn = bar.querySelector('[data-review]');
    if (btn) btn.addEventListener('click', async () => {
      btn.disabled = true;
      btn.textContent = '重审中…';
      try {
        const r = await api('recheck.php', 'run', { content: payload.content }, { timeout: 30000 });
        const v = (r && r.verdict) || 'false';
        if (v === 'false') {
          bar.querySelector('.rn-text').textContent = 'AI 复核后仍判为违规，未发送';
          btn.remove();
          return;
        }
        bar.remove();
        /* true：直接重发；middle：同样重发，消息会带上「可能有恶意」的标注 */
        if (v === 'middle') { toast('AI 复核通过，将标注「可能有恶意」后发送'); }
        submit(payload.type || 'text', payload);
      } catch (e) {
        btn.disabled = false;
        btn.textContent = 'AI 重审';
        bar.querySelector('.rn-text').textContent = (e && e.message) ? e.message : '重审失败，请稍后再试';
      }
    });
  }

  async function submit(type, payload) {
    if (!canSend) { toast('游客仅可查看，登录后可发言', 'err'); return; }
    btnLoading(sendBtn, true);
    try {
      const m = await api('lobby.php', 'send', Object.assign({ type: type }, payload), { timeout: 30000 });
      if (stream.querySelector('.empty')) { stream.innerHTML = ''; seen.clear(); }
      put(m, true);
      if (m && m.ai) { put(m.ai, true); }
      if (m && m.ai_note) { toast(m.ai_note, 'err'); }
      picker.hidden = true;
      const bar = stream.parentNode.querySelector('.reject-note');
      if (bar) { bar.remove(); }
    } catch (e) {
      if (e && e.code === 422) {
        /* 图片同样给一条明确的通知条，而不是静默失败（用户以为发出去了） */
        rejectNote(e.message || '内容未通过审核', { type: type, content: payload.content, media: payload.media });
      } else {
        toast(e.message, 'err');
      }
    }
    finally { btnLoading(sendBtn, false); }
  }

  function sendText() {
    const text = input.value.trim();
    if (!text) { return; }
    input.value = '';
    if (count) { count.textContent = '0/500'; }
    input.style.height = 'auto';
    submit('text', { content: text });
  }

  function pickerSendImage(u) { submit('image', { media: u }); }

  function openImagePicker() {
    if (!picker.hidden && picker.dataset.kind === 'image') { picker.hidden = true; return; }
    picker.dataset.kind = 'image';
    picker.innerHTML = '<div class="picker-head">发送图片</div>'
      + '<div class="picker-body">'
      +   '<input class="input" id="wImgUrl" placeholder="粘贴图片链接（https://…png / jpg）" style="margin-bottom:8px">'
      +   '<div class="row-gap">'
      +     '<button class="btn btn-sm" id="wImgLink">发送链接图片</button>'
      +     '<button class="btn-ghost btn-sm" id="wImgUpBtn">上传本地图片</button>'
      +     '<input type="file" id="wImgFile" accept="image/*" class="sr-only">'
      +   '</div>'
      +   '<div class="tiny" id="wImgHint" style="margin-top:8px">支持 JPG / PNG / GIF / WebP，最大 2MB</div>'
      + '</div>';
    picker.hidden = false;

    const hint = picker.querySelector('#wImgHint');
    const urlIn = picker.querySelector('#wImgUrl');
    picker.querySelector('#wImgLink').addEventListener('click', () => {
      const u = (urlIn.value || '').trim();
      if (!/^https?:\/\//i.test(u)) { hint.textContent = '请输入以 http(s) 开头的图片链接'; return; }
      pickerSendImage(u);
    });
    picker.querySelector('#wImgUpBtn').addEventListener('click', () => picker.querySelector('#wImgFile').click());
    picker.querySelector('#wImgFile').addEventListener('change', async ev => {
      const f = ev.target.files && ev.target.files[0];
      if (!f) { return; }
      if (f.size > 2 * 1024 * 1024) { hint.textContent = '图片不能超过 2MB'; return; }
      hint.textContent = '上传中…';
      try {
        const fd = new FormData();
        fd.append('file', f);
        const resp = await fetch('api/media.php?action=upload', {
          method: 'POST',
          headers: { 'X-Token': state.token, 'X-CSRF-Token': state.csrf },
          body: fd,
          credentials: 'same-origin',
        });
        const j = await resp.json();
        if (!j || j.code !== 0) { throw new Error((j && j.msg) || '上传失败'); }
        pickerSendImage(j.data.url);
      } catch (e) { hint.textContent = '上传失败：' + (e.message || '未知错误'); }
    });
  }

  sendBtn.addEventListener('click', sendText);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendText(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(108, input.scrollHeight) + 'px';
    if (count) { count.textContent = input.value.length + '/500'; }
  });
  input.addEventListener('focus', () => requestAnimationFrame(stick));

  /* ---------- @ 提及（仅世界对话）：输入 @ 弹面板；@ 按钮一键插入 ---------- */
  const AI_MENTION = '@官方AI ';
  function atInsert() {
    const st = input.selectionStart, en = input.selectionEnd, v = input.value;
    input.value = v.slice(0, st) + AI_MENTION + v.slice(en);
    const p = st + AI_MENTION.length;
    input.setSelectionRange(p, p);
    input.focus();
    input.dispatchEvent(new Event('input'));
  }
  function atRange() {
    const pos = input.selectionStart;
    const m = input.value.slice(0, pos).match(/(?:^|[\s（(【[>])@([\u4e00-\u9fa5A-Za-z0-9]{0,7})$/);
    return (m && m[1] !== undefined) ? { start: pos - m[1].length - 1 } : null;
  }
  function atSync() {
    if (!canSend) { return; }
    const r = atRange();
    const q = r ? input.value.slice(r.start + 1, input.selectionStart) : '';
    if (!r || '官方AI'.indexOf(q) !== 0) {
      if (picker.dataset.kind === 'mention') { picker.hidden = true; picker.dataset.kind = ''; }
      return;
    }
    picker.dataset.kind = 'mention';
    picker.innerHTML = '<div class="picker-head">@ 提及（点击插入）</div>'
      + '<div class="picker-body"><button class="btn btn-sm" id="wAtPick">' + esc(AI_MENTION.trim()) + '</button>'
      + '<div class="tiny" style="margin-top:6px">@ 官方 AI，它会回应这条消息</div></div>';
    picker.hidden = false;
    picker.querySelector('#wAtPick').addEventListener('click', () => {
      const st = atRange();
      const pos = input.selectionStart, v = input.value;
      const from = st ? st.start : pos;
      input.value = v.slice(0, from) + AI_MENTION + v.slice(pos);
      const p = from + AI_MENTION.length;
      input.setSelectionRange(p, p);
      picker.hidden = true; picker.dataset.kind = '';
      input.focus();
      input.dispatchEvent(new Event('input'));
    });
  }
  const atBtn = body.querySelector('#wAt');
  if (atBtn) { atBtn.addEventListener('click', atInsert); }
  input.addEventListener('input', atSync);

  const loginBtn = body.querySelector('#wLogin');
  if (loginBtn) { loginBtn.addEventListener('click', () => navigate('#/login')); }

  const emojiBtn = body.querySelector('#wEmoji');
  if (emojiBtn) {
    emojiBtn.addEventListener('click', async () => {
      if (!picker.hidden && picker.dataset.kind === 'emoji') { picker.hidden = true; return; }
      await mountEmojiPicker(picker, input);
    });
  }
  const imageBtn = body.querySelector('#wImage');
  if (imageBtn) { imageBtn.addEventListener('click', openImagePicker); }

  await loadEmoji();
  await load();
}

/* ============================================================
 * AI 对话（流式输出 + 工具调用卡片 + 非流式降级）
 * ============================================================ */
const TOOL_META = {
  search:   { n: '检索站内作品', d: 'M11 4a7 7 0 1 1 0 14 7 7 0 0 1 0-14zm0 2a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.6 11.2 3.2 3.2-1.4 1.4-3.2-3.2z' },
  get:      { n: '读取作品详情', d: 'M4 4h16v16H4V4zm2 2v12h12V6H6zm3 2h6v2H9V8zm0 4h6v2H9v-2z' },
  rank:     { n: '查询榜单排名', d: 'M4 20V10h4v10H4zm6 0V4h4v16h-4zm6 0v-7h4v7h-4z' },
  comments: { n: '读取评论区',   d: 'M4 5h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H9l-4 4V6a1 1 0 0 1 1-1z' },
  web_open: { n: '联网读取网页', d: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm7 9h-3a15 15 0 0 0-1.2-5.4A8 8 0 0 1 19 11zM12 4c1 1.6 1.7 4.2 1.8 7h-3.6C10.3 8.2 11 5.6 12 4zM5 11a8 8 0 0 1 4.2-5.4A15 15 0 0 0 8 11H5zm0 2h3a15 15 0 0 0 1.2 5.4A8 8 0 0 1 5 13zm7 7c-1-1.6-1.7-4.2-1.8-7h3.6c-.1 2.8-.8 5.4-1.8 7zm2.8-1.6A15 15 0 0 0 16 13h3a8 8 0 0 1-4.2 5.4z' },
  weather:  { n: '查询天气',     d: 'M6.5 19a4.5 4.5 0 0 1-.7-8.95A5.6 5.6 0 0 1 16.4 9.2 3.9 3.9 0 0 1 16 17H6.5zm-2 2h13v1.6h-13V21z' },
  time:     { n: '获取当前时间', d: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1.2 4.6v5.1l3.9 2.3-1.1 1.9-5-3V6.6h2.2z' },
};

/** 气泡内容分区：工具卡片区 + 文本区 */
function aiParts(target) {
  if (!target.querySelector('.ai-text')) {
    target.innerHTML = '<div class="tool-cards"></div>'
      + '<div class="ai-text"><span class="typing"><i></i><i></i><i></i></span></div>';
  }
  return { cards: target.querySelector('.tool-cards'), text: target.querySelector('.ai-text') };
}

function addToolCall(cards, action) {
  const meta = TOOL_META[action] || { n: action || '工具调用', d: TOOL_META.search.d };
  const el = document.createElement('div');
  el.className = 'tool-card call';
  el.dataset.action = action || '';
  el.innerHTML = `<svg viewBox="0 0 24 24" class="tc-ic"><path d="${meta.d}"/></svg>
    <span class="tc-body"><b>${esc(meta.n)}</b><span class="tiny">正在调用…</span></span>
    <span class="tc-dot"><i></i><i></i><i></i></span>`;
  cards.appendChild(el);
}

function resolveTool(cards, ev) {
  const list = cards.querySelectorAll('.tool-card.call');
  const el = list[list.length - 1];
  if (!el) { return; }
  el.classList.remove('call');
  el.classList.add(ev.ok ? 'ok' : 'err');
  const sub = el.querySelector('.tiny');
  if (sub) { sub.textContent = (ev.ok ? '已完成 · ' : '失败 · ') + (ev.summary || ''); }
  const dot = el.querySelector('.tc-dot');
  if (dot) { dot.remove(); }
}

const AI_ICON_SEND = '<svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:#fff"><path d="M3 20l18-8L3 4v6l12 2-12 2v6z"/></svg>';
const AI_ICON_STOP = '<svg viewBox="0 0 24 24" class="ic" style="width:16px;height:16px;fill:#fff"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>';

async function mountAi(body) {
  window.__chatPollEnabled = false;         // AI 页不轮询世界对话，避免并发拖累
  if (state.role === 'guest') {
    body.innerHTML = `<div class="block-note">
      <p>AI 对话需要登录后使用</p>
      <button class="btn btn-sm" id="goLogin">去登录</button>
    </div>`;
    body.querySelector('#goLogin').addEventListener('click', () => navigate('#/login'));
    return;
  }

  body.innerHTML = `
    <div class="chat-wrap">
      <div class="chat-stream" id="stream">
        <div class="skeleton" style="height:54px"></div>
      </div>
      <div class="picker" id="pickerAi" hidden></div>
      <div class="model-bar">
        <button type="button" class="model-pick" id="modelBtn" aria-haspopup="listbox" aria-expanded="false">
          <svg viewBox="0 0 24 24" class="mp-ic"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/></svg>
          <span class="mp-name" id="modelBtnName">模型</span>
          <svg viewBox="0 0 24 24" class="mp-chev"><path d="M7 10l5 5 5-5z"/></svg>
        </button>
      </div>
      <div class="model-sheet" id="modelPanel" hidden>
        <div class="ms-head">选择模型</div>
        <div class="ms-list" id="modelList"></div>
      </div>
      <div class="chat-input">
        <button class="icon-btn pk-btn" id="emojiBtnAi" aria-label="表情">
          <svg viewBox="0 0 24 24" class="ic"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16zM8.5 9.5a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zm7 0a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zM7.6 14.2a1 1 0 0 1 1.4-.1 4.6 4.6 0 0 0 6 0 1 1 0 0 1 1.3 1.5 6.6 6.6 0 0 1-8.6 0 1 1 0 0 1-.1-1.4z"/></svg>
        </button>
        <div class="cnt" id="aiCount">0/2000</div>
        <textarea class="input" id="aiInput" rows="1" maxlength="2000" placeholder="向 AI 提问，可问榜单规则与作品信息…"></textarea>
        <button class="btn" id="aiSend" aria-label="发送">
          <svg viewBox="0 0 24 24" class="ic" style="width:18px;height:18px;fill:#fff"><path d="M3 20l18-8L3 4v6l12 2-12 2v6z"/></svg>
        </button>
      </div>
      <div class="identity-tip">
        <span id="quotaText">正在读取用量…</span>
        <span class="tiny" id="usageNow"></span>
        <button class="link" id="clearAi" style="border:0;background:0;font-size:12px">清空对话</button>
      </div>
    </div>`;

  const stream = body.querySelector('#stream');
  bindImageViewer(stream);          /* 点图片看大图 */
  const input = body.querySelector('#aiInput');
  const sendBtn = body.querySelector('#aiSend');
  const quotaTip = body.querySelector('#quotaText');
  const usageNow = body.querySelector('#usageNow');
  const aiCnt = body.querySelector('#aiCount');
  const picker = body.querySelector('#pickerAi');
  const modelBtn = body.querySelector('#modelBtn');
  const modelBtnName = body.querySelector('#modelBtnName');
  const modelPanel = body.querySelector('#modelPanel');
  const modelList = body.querySelector('#modelList');
  let aiModels = [];        // 可选免费模型
  let selModel = '';        // '' = 本站默认模型（按 token 计）
  let defaultModelName = '';// 本站默认模型名（取自配置，如 glm-4-flash）
  let lastState = null;     // 最近一次额度快照，用于切换模型后重算显示
  let controller = null;
  let rawText = '';        // 本轮回答的原始文本（渲染的唯一真源）
  let tailHtml = '';       // 追加在正文之后的系统提示（错误等）
  let lastPaint = 0;

  function setSendState(busy) {
    if (busy) {
      sendBtn.innerHTML = AI_ICON_STOP;
      sendBtn.classList.remove('btn');
      sendBtn.classList.add('btn-stop');
    } else {
      sendBtn.innerHTML = AI_ICON_SEND;
      sendBtn.classList.add('btn');
      sendBtn.classList.remove('btn-stop');
    }
  }

  /** 按历史重建消息（清洗旧数据里残留的工具标签） */
  async function loadHistory() {
    try {
      const d = await api('ai.php', 'history');
      stream.innerHTML = '';
      (d.items || []).forEach(m => {
        const text = m.role === 'user' ? m.content : stripToolTags(m.content);
        if (m.role !== 'user' && !text) { return; }
        stream.appendChild(aiMsg(m.role === 'user' ? 'mine' : 'them', text));
      });
      if (!(d.items || []).length) {
        stream.innerHTML = '<div class="empty" style="padding:22px">开始和 AI 聊聊吧，可问榜单排名、作品详情或让我联网查资料</div>';
      }
      requestAnimationFrame(() => toBottom(stream));
    } catch (e) {
      stream.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`;
    }
  }

  /* ---------- 模型选择与额度（自定义控件，非原生） ----------
     默认使用本站模型（GLM，按 token 计）；选中免费模型才按次数计。 */
  const MODEL_LS = 'kimgr_ai_models';
  function selSave() { try { localStorage.setItem(MODEL_LS, JSON.stringify(selModel ? [selModel] : [])); } catch (e) {} }
  function selLoad() {
    try { const a = JSON.parse(localStorage.getItem(MODEL_LS) || '[]'); return (Array.isArray(a) && a.length) ? String(a[0]) : ''; }
    catch (e) { return ''; }
  }
  function labelOf(id) {
    if (!id) { return defaultModelName || '默认模型'; }
    const m = aiModels.find(x => x.id === id);
    return m ? m.name : (defaultModelName || '默认模型');
  }
  function renderModelBtn() { modelBtnName.textContent = labelOf(selModel); }
  function renderModelPanel() {
    const rows = [{ id: '', name: defaultModelName || '默认模型', sub: '本站默认 · 按 token 计', ctx: 0 }]
      .concat(aiModels.map(m => ({ id: m.id, name: m.name, sub: '免费模型 · 按次数计', ctx: m.ctx })));
    modelList.innerHTML = rows.map(r =>
      '<button type="button" class="ms-row' + (r.id === selModel ? ' on' : '') + '" data-id="' + esc(r.id) + '">'
      + '<span class="ms-check"></span>'
      + '<span class="ms-main"><span class="ms-name">' + esc(r.name) + '</span>'
      + '<span class="ms-sub">' + esc(r.sub) + '</span></span>'
      + '<span class="ms-ctx">' + (r.ctx ? Math.round(r.ctx / 1024) + 'K' : '') + '</span>'
      + '</button>').join('');
  }
  function quotaText(d) {
    const site = ' · 全站余 ' + d.site.remaining + '/' + d.site.limit;
    if (d.unlimited) { return '当前身份：' + (state.role === 'subadmin' ? '副管理员' : '管理员') + ' · 无限制' + site; }
    if (selModel && d.online) { return '今日 你 ' + d.user.used + '/' + d.user.limit + ' 次' + site; }
    const t = d.token || {};
    return '今日 ' + (t.today || 0) + '/' + (t.daily_limit || 0) + ' tokens' + site;
  }
  function paintQuota() { if (quotaTip && lastState) { quotaTip.textContent = quotaText(lastState); } }

  async function loadState() {
    try {
      const d = await api('ai.php', 'models', null, { silent: true, timeout: 40000 });
      aiModels = d.models || [];
      defaultModelName = d.default_name || '';
      const saved = selLoad();
      selModel = (saved && aiModels.some(m => m.id === saved)) ? saved : '';
      lastState = d;
      renderModelBtn();
      paintQuota();
    } catch (e) {
      modelBtnName.textContent = defaultModelName || '默认模型';
      if (quotaTip) { quotaTip.textContent = ''; }
    }
  }

  modelBtn.addEventListener('click', () => {
    const open = modelPanel.hidden;
    if (open) { renderModelPanel(); }
    modelPanel.hidden = !open;
    modelBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  modelList.addEventListener('click', e => {
    const b = e.target.closest('[data-id]'); if (!b) return;
    selModel = b.dataset.id || '';
    selSave(); renderModelBtn(); renderModelPanel(); paintQuota();
    modelPanel.hidden = true;
    modelBtn.setAttribute('aria-expanded', 'false');
  });

  /** 非流式兜底：返回完整回答文本。counted = 流式那次服务端是否已受理（受理过才算「已计入」，否则必须重新检查并占用额度） */
  async function sendSync(text, target, counted) {
    const d = await api('ai.php', 'send_sync', { content: text, fallback: counted ? 1 : 0, model: selModel }, { timeout: 180000, silent: true });
    const p = aiParts(target);
    (d.tools || []).forEach(t => { addToolCall(p.cards, t.action); resolveTool(p.cards, t); });
    return d.text || '';
  }

  async function send() {
    if (controller) { controller.abort(); return; }        // 生成中 → 本次点击为「停止」
    const text = input.value.trim();
    if (!text) { return; }
    input.value = '';
    if (aiCnt) { aiCnt.textContent = '0/2000'; }
    input.style.height = 'auto';
    const mineEl = aiMsg('mine', text);
    stream.appendChild(mineEl);
    toBottom(stream);

    const bubble = aiMsg('them', '');
    stream.appendChild(bubble);
    const target = bubble.querySelector('.bubble');
    aiParts(target);
    let firstChunk = true;
    let gotAny = false;        // 是否已收到可见内容
    let gotDone = false;       // 服务端是否正常收尾
    let stopped = false;       // 用户主动停止
    let gateError = null;      // 被闸门拦下（额度用尽 / 登录态失效）：这一条根本没发出去
    let streamAccepted = false;// 服务端是否受理了本次流式（决定降级重放要不要重算额度）
    let lastUsage = null;
    toBottom(stream);

    window.__aiBusy = true;
    controller = new AbortController();
    setSendState(true);

    // 首字守卫：15s 无任何输出 → 中断并转非流式
    const idleTimer = setTimeout(() => { if (!gotAny && controller) { controller.abort(); } }, 15000);

    try {
      const resp = await fetch('api/ai.php?action=send', {
        signal: controller.signal,
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'text/event-stream',
          'Authorization': 'Bearer ' + state.token,
          'X-Token': state.token,
          'X-CSRF-Token': state.csrf,
        },
        body: JSON.stringify({ content: text, model: selModel }),
        credentials: 'same-origin',
      });
      if (!resp.ok || !resp.body) {
        /* 闸门被拒（额度用尽 / 登录态失效 …）：这是「不许发」，不是「通道坏了」。
           必须原样报出原因，并且绝不转非流式重发 —— 否则超额也能照发出去。 */
        let why = '流式通道不可用';
        try { const j = await resp.json(); if (j && j.msg) { why = j.msg; } } catch (e2) {}
        const err = new Error(why);
        err.gate = true;
        throw err;
      }
      streamAccepted = true;     // 服务端已受理：这次的降级才是「已计入额度」的重放

      rawText = ''; tailHtml = ''; lastPaint = 0;
      const reader = resp.body.getReader();
      const decoder = new TextDecoder();
      let buf = '';
      while (true) {
        const r = await reader.read();
        if (r.done) { break; }
        buf += decoder.decode(r.value, { stream: true });
        let idx;
        while ((idx = buf.indexOf('\n\n')) >= 0) {
          const chunk = buf.slice(0, idx);
          buf = buf.slice(idx + 2);
          const line = chunk.trim();
          if (line.indexOf('data:') !== 0) { continue; }
          let ev;
          try { ev = JSON.parse(line.slice(5).trim()); } catch (e) { continue; }

          if (ev.delta) {
            const p = aiParts(target);
            if (firstChunk) { p.text.textContent = ''; firstChunk = false; }
            rawText += ev.delta;
            gotAny = true;
            const now = Date.now();
            if (now - lastPaint > 80) {              // 节流：约 12fps 重绘
              lastPaint = now;
              paintAi(target, rawText, tailHtml);
              if (atBottom(stream)) { toBottom(stream); }
            }
          }
          if (ev.tool_call) {
            const p = aiParts(target);
            addToolCall(p.cards, ev.tool_call.action || '');
            gotAny = true;
            toBottom(stream);
          }
          if (ev.tool_result) {
            resolveTool(aiParts(target).cards, ev.tool_result);
            toBottom(stream);
          }
          if (ev.error) {
            tailHtml += `<span style="color:var(--danger)"><br>${esc(ev.error)}</span>`;
            gotAny = true;
            paintAi(target, rawText, tailHtml);
            if (atBottom(stream)) { toBottom(stream); }
          }
          if (ev.done) { gotDone = true; paintAi(target, rawText, tailHtml); }
          if (ev.usage && ev.usage.total_tokens) { lastUsage = ev.usage; }
        }
      }
    } catch (e) {
      if (e && e.name === 'AbortError') {
        if (!gotAny) {
          stopped = true;
          aiParts(target).text.innerHTML = '<span class="recall">已停止生成</span>';
        }
        // 已有内容的中断 → 交给下面的非流式补齐
      } else if (e && e.gate) {
        gateError = e;           // 被闸门拦下：不做任何兜底
      }
      // 其它网络异常同样走补齐
    } finally {
      clearTimeout(idleTimer);
      controller = null;
      setSendState(false);
    }

    if (gateError) {
      /* 被闸门拦下：这一条根本没发出去，界面不该留下痕迹，内容还给用户 */
      if (mineEl && mineEl.parentNode) { mineEl.parentNode.removeChild(mineEl); }
      bubble.remove();
      input.value = text;
      if (aiCnt) { aiCnt.textContent = text.length + '/2000'; }
      toast(gateError.message, 'err');
    } else if (!stopped && !gotDone) {
      // 未正常收尾（被主机掐断 / 无输出 / 中途断流）→ 非流式补齐完整回答
      try {
        const t = await sendSync(text, target, streamAccepted);
        const p = aiParts(target);
        if (t) { p.text.innerHTML = renderAiRich(t); }
        else if (!gotAny) { p.text.textContent = '（无内容返回，请稍后重试）'; }
      } catch (e) {
        const p = aiParts(target);
        if (gotAny) {
          p.text.insertAdjacentHTML('beforeend',
            ` <span class="tiny" style="color:var(--danger)">· 回答中断</span> <button class="link" id="aiRetry">重试</button>`);
          const rb = p.text.querySelector('#aiRetry');
          if (rb) rb.addEventListener('click', () => { p.text.textContent = ''; rawText = ''; tailHtml = ''; sendAgain(text, target); });
        } else {
          p.text.innerHTML = `<span style="color:var(--danger)">${esc(e.message || '发送失败')}</span>`
            + ' <button class="link" id="aiRetry2">重试</button>';
          const rb = p.text.querySelector('#aiRetry2');
          if (rb) rb.addEventListener('click', () => { p.text.textContent = ''; rawText = ''; tailHtml = ''; sendAgain(text, target); });
        }
      }
    }

    if (lastUsage && usageNow) { usageNow.textContent = '本次 ' + Number(lastUsage.total_tokens) + ' tokens'; }
    window.__aiBusy = false;
    toBottom(stream);
  }

  /** 重试（复用气泡，仅补一次非流式结果） */
  async function sendAgain(text, target) {
    try {
      const t = await sendSync(text, target);
      aiParts(target).text.innerHTML = t ? renderAiRich(t) : '（无内容返回）';
    } catch (e) {
      aiParts(target).text.innerHTML = `<span style="color:var(--danger)">${esc(e.message || '发送失败')}</span>`;
    }
    toBottom(stream);
  }

  sendBtn.addEventListener('click', send);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(108, input.scrollHeight) + 'px';
    if (aiCnt) { aiCnt.textContent = input.value.length + '/2000'; }
  });
  input.addEventListener('focus', () => requestAnimationFrame(() => toBottom(stream)));

  // 清空对话：清空后从服务端重新拉取，确保界面与库一致
  body.querySelector('#clearAi').addEventListener('click', async () => {
    if (!(await dialog('清空对话', '将删除你与 AI 的全部聊天记录，不可恢复。确认？', '清空', { danger: true }))) { return; }
    try {
      await api('ai.php', 'clear');
      if (usageNow) { usageNow.textContent = ''; }
      await loadHistory();
      toast('已清空对话记录');
    } catch (e) { toast(e.message, 'err'); }
  });

  // AI 输入区表情面板
  const emojiBtnAi = body.querySelector('#emojiBtnAi');
  if (emojiBtnAi) {
    emojiBtnAi.addEventListener('click', async () => {
      if (!picker.hidden && picker.dataset.kind === 'emoji') { picker.hidden = true; return; }
      await mountEmojiPicker(picker, input);
    });
  }

  await loadEmoji();
  await loadHistory();
  await loadState();
}

/** AI 消息气泡 */
function aiMsg(side, text) {
  const el = document.createElement('div');
  el.className = 'msg' + (side === 'mine' ? ' mine' : '');
  const who = side === 'mine' ? esc(state.username) : 'AI 助手';
  const avatar = side === 'mine'
    ? '<img src="' + esc(state.avatar || '') + '" alt="" draggable="false" style="user-select:none">'
    : '<span class="ai-av"><svg viewBox="0 0 24 24"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M18 15l.9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z"/></svg></span>';
  el.innerHTML = `
    <span class="av">${avatar}</span>
    <span class="bubble-wrap">
      <span class="who">${who}</span>
      <div class="bubble">${renderAiRich(text)}</div>
    </span>`;
  return el;
}
