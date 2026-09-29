/**
 * 我的页：个人信息 / AI 用量 / IP 与访问记录 / 设置 / 控制面板入口
 */
import { api, state, esc, toast, dialog, setToken, askNotifyPermission, userName, isAdminish, getPrefs, setPrefs } from '../core.js';
import { navigate } from '../router.js';
import { saveTheme, ACCENTS, SKINS, hexToHsl, festivalInWindow } from '../theme.js';
import { setNavAnim } from '../transitions.js';

/** 配额上限展示（数据缺失时返回空串，不显示占位符） */
function limit(key, d) {
  const n = d && d.quota && d.quota.limits ? Number(d.quota.limits[key]) : 0;
  return n > 0 ? ' / ' + n : '';
}

export async function renderMine(container) {
  if (state.role === 'guest') {
    container.innerHTML = `
      <div class="card">
        <div class="profile-head">
          <span class="av">${avatarImg('guest')}</span>
          <div><div class="name">游客</div><div class="tiny">当前为游客模式</div></div>
        </div>
        <p class="muted tiny">游客可浏览榜单与世界对话；登录后可评论、点赞、使用 AI 对话。</p>
        <button class="btn" id="goLogin" style="width:100%;margin-top:10px">登录 / 注册</button>
      </div>
      <div class="card">
        <div class="card-title">外观设置</div>
        <div id="guestAppearance"></div>
      </div>
      <div class="card">
        <div class="card-title">内容屏蔽</div>
        <div id="guestBlock"></div>
      </div>
      <div class="card">
        <div class="card-title">搜索</div>
        <div id="guestSearchAi"></div>
      </div>`;
    container.querySelector('#goLogin').addEventListener('click', () => navigate('#/login'));
    mountAppearance(container.querySelector('#guestAppearance'));
    mountBlockList(container.querySelector('#guestBlock'), true);
    mountSearchAi(container.querySelector('#guestSearchAi'), true);
    return;
  }

  let d;
  try { d = await api('profile.php', 'info'); }
  catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }

  const isAdmin = isAdminish();
  const s = d.settings || {};

  container.innerHTML = `
    <div class="card">
      <div class="profile-head">
        <span class="av">${avatarImg(d.username, d.avatar)}</span>
        <div>
          <div class="name">${userName(d.username, d.role)}</div>
          <div class="tiny">${isAdmin ? (d.role === 'subadmin' ? '副管理员账号' : '管理员账号') : ('注册于 ' + esc(d.registered_at || ''))}</div>
        </div>
      </div>
      <div class="uid-row">
        <div class="uid-left">
          <div class="tiny">我的 UID</div>
          <div class="uid8${d.uid8_babao ? ' babao' : ''}">${esc(d.uid8 || '—')}</div>
        </div>
        <button class="btn-ghost btn-sm" id="copyUid">复制</button>
      </div>
      <div class="tiny" style="margin-top:6px">由账号经可逆算法唯一生成${d.uid8_babao ? ' · 管理身份专用豹子号' : ''}，可据此反查账号</div>
      ${isAdmin ? '<button class="btn" id="panelBtn" style="width:100%;margin-top:10px">进入控制面板</button>' : ''}
    </div>

    <div class="card">
      <div class="card-title">AI 用量</div>
      <div class="stat-grid">
        <div class="stat-box"><div class="k">累计 tokens</div><div class="v">${d.usage ? d.usage.tokens : 0}</div></div>
        <div class="stat-box"><div class="k">调用次数</div><div class="v">${d.usage ? d.usage.calls : 0}</div></div>
        <div class="stat-box"><div class="k">今日 tokens</div><div class="v">${d.usage ? d.usage.today : 0}<i class="cap">${limit('daily_tokens', d)}</i></div></div>
        <div class="stat-box"><div class="k">本月 tokens</div><div class="v">${d.usage ? d.usage.month : 0}<i class="cap">${limit('monthly_tokens', d)}</i></div></div>
      </div>
      ${d.checkin ? `
      <div class="checkin-row">
        <div>
          <div class="ck-big">连续签到 ${d.checkin.streak} 天</div>
          <div class="tiny">${d.checkin.unlimited ? '管理员额度无上限，签到仅记录连续天数' : '每日签到：当日 AI 额度 +1000 · 每连续满 7 天：本月额度 +100000'}</div>
        </div>
        <button class="btn" id="checkinBtn"${d.checkin.today ? ' disabled' : ''}>${d.checkin.today ? '已签到' : '签到'}</button>
      </div>` : ''}
      ${isAdmin ? '<div id="top3" style="margin-top:12px"></div>' : ''}
    </div>

    <div class="card">
      <div class="card-title">AI 免费模型</div>
      <div class="stat-grid">
        <div class="stat-box"><div class="k">可选模型</div><div class="v" id="aiModelCount">—</div></div>
        <div class="stat-box"><div class="k">我的今日次数</div><div class="v" id="aiUserQuota">—</div></div>
        <div class="stat-box"><div class="k">全站剩余</div><div class="v" id="aiSiteQuota">—</div></div>
        <div class="stat-box"><div class="k">列表更新于</div><div class="v" id="aiRefreshed">—</div></div>
      </div>
      <div class="tiny muted" style="margin-top:8px">免费模型按次数计：每人每天 2 次、全站每天 50 次；其余情况按 token 额度。</div>
    </div>

    ${isAdmin ? '' : `
    <div class="card">
      <div class="card-title">我的 IP 信息</div>
      <div class="visit-row"><span>脱敏 IP</span><span>${esc(d.ip ? d.ip.masked : '')}</span></div>
      <div class="visit-row"><span>归属地</span><span>${esc(d.ip ? d.ip.location : '')}</span></div>
      <div class="visit-row"><span>运营商</span><span>${esc(d.ip ? d.ip.isp : '')}</span></div>
      <div class="setting-row">
        <span>记录访问信息</span>
        <button class="switch" id="swIp" role="switch" aria-checked="${Number(s.ip_record) === 1}"></button>
      </div>
    </div>

    <div class="card">
      <div class="card-title">访问记录</div>
      <div id="visitList"><div class="skeleton" style="height:40px"></div></div>
    </div>`}

    <div class="card">
      <div class="card-title">外观设置</div>
      <div id="appearance"></div>
    </div>

    <div class="card">
      <div class="card-title">内容屏蔽</div>
      <div id="blockWrap"></div>
    </div>

    <div class="card">
      <div class="card-title">搜索</div>
      <div id="searchAiSet"></div>
    </div>

    <div class="card">
      <div class="setting-row">
        <span>消息网页通知</span>
        <button class="switch" id="swNotify" role="switch" aria-checked="${Number(s.notify) === 1}"></button>
      </div>
    </div>

    <button class="btn-ghost" id="logoutBtn" style="width:100%;margin-bottom:16px">退出登录</button>
  `;

  /* AI 免费模型额度卡片：独立拉取，失败静默（不影响页面其它部分） */
  (async () => {
    try {
      const q = await api('ai.php', 'models', null, { silent: true, timeout: 40000 });
      const set = (sel, v) => { const el = container.querySelector(sel); if (el) { el.textContent = v; } };
      set('#aiModelCount', String((q.models || []).length));
      set('#aiSiteQuota', q.site.remaining + ' / ' + q.site.limit);
      if (q.unlimited) { set('#aiUserQuota', '无限制'); }
      else { set('#aiUserQuota', q.user.used + ' / ' + q.user.limit); }
      set('#aiRefreshed', q.refreshed_at || '—');
    } catch (e) { /* 静默 */ }
  })();

  if (isAdmin) {
    container.querySelector('#panelBtn').addEventListener('click', () => navigate('#/panel'));
    const copyBtn = container.querySelector('#copyUid');
    if (copyBtn) copyBtn.addEventListener('click', async () => {
      const v = String(d.uid8 || '');
      if (!v) { toast('暂无 UID', 'err'); return; }
      try {
        await navigator.clipboard.writeText(v);
        toast('已复制 UID：' + v);
      } catch (e) {
        // 兜底：剪贴板不可用时显示可选中文本
        toast('UID：' + v);
      }
    });
    loadTop3(container.querySelector('#top3'));
  }

  const swIp = container.querySelector('#swIp');
  if (swIp) swIp.addEventListener('click', async () => {
    const on = swIp.getAttribute('aria-checked') === 'true' ? 0 : 1;
    swIp.setAttribute('aria-checked', on ? 'true' : 'false');
    try { await api('profile.php', 'settings', { ip_record: on }); state.settings.ip_record = on; toast('已保存'); }
    catch (e) { toast(e.message, 'err'); }
  });

  const swNotify = container.querySelector('#swNotify');
  if (swNotify) swNotify.addEventListener('click', async () => {
    const on = swNotify.getAttribute('aria-checked') === 'true' ? 0 : 1;
    swNotify.setAttribute('aria-checked', on ? 'true' : 'false');
    try {
      await api('profile.php', 'settings', { notify: on }, { silent: true });
      state.settings.notify = on;
      toast('已保存');
      if (on) { const g = await askNotifyPermission(); if (!g) toast('浏览器未授予通知权限', 'err'); }
    } catch (e) { toast(e.message, 'err'); }
  });

  mountAppearance(container.querySelector('#appearance'));
  mountBlockList(container.querySelector('#blockWrap'), false);
  mountSearchAi(container.querySelector('#searchAiSet'), false);
  loadVisits(container.querySelector('#visitList'));

  const ck = container.querySelector('#checkinBtn');   // 必须在本页容器内查找：渲染时尚未挂载到 document
  if (ck) {
    ck.addEventListener('click', async () => {
      ck.disabled = true;
      try {
        const r = await api('ai.php', 'checkin', {});
        const wb = Number(r.week_bonus) > 0 ? `！连续满 7 天，本月额度 +${r.week_bonus}` : '';
        toast('签到成功：当日额度 +' + r.daily_bonus + wb);
        renderMine(container);   // 刷新连续天数与配额分母
      } catch (e) {
        ck.disabled = false;
        toast(e.message, 'err');
      }
    });
  }

  container.querySelector('#logoutBtn').addEventListener('click', async () => {
    if (await dialog('退出登录', '确认退出当前账号吗？', '退出')) {
      try { await api('auth.php', 'logout'); } catch (e) {}
      setToken('');
      toast('已退出');
      location.reload();
    }
  });
}

/* ---------- 外观设置（深浅色 + 主题色） ---------- */
/**
 * 内容屏蔽设置：每行一条规则，命中则收起该评论。
 * 游客写本机 localStorage，登录用户写账号设置。
 */
/** 写入皮肤 cookie（服务端据此选择加载哪份皮肤文件） */
function setSkinCookie(skin) {
  try {
    document.cookie = 'kimgr_skin=' + encodeURIComponent(skin) + '; path=/; max-age=31536000; SameSite=Lax';
  } catch (e) {}
}

/** 搜索结果页 AI 总结开关：登录用户写账号设置，游客写本机偏好 */
function mountSearchAi(box, isGuest) {
  if (!box) { return; }
  const cur = isGuest ? getPrefs().search_ai : (state.settings || {}).search_ai;
  box.innerHTML = `
    <div class="setting-row">
      <span>搜索结果顶部的 AI 总结</span>
      <button class="switch" id="swSearchAi" role="switch" aria-checked="${Number(cur) !== 0}"></button>
    </div>
    <div class="tiny muted" style="margin-top:6px;line-height:1.6">
      开启后，搜索关键词时顶部会多出一张 AI 总结卡片；关闭则不再显示。
    </div>`;
  const sw = box.querySelector('#swSearchAi');
  sw.addEventListener('click', async () => {
    const next = sw.getAttribute('aria-checked') === 'true' ? 0 : 1;
    sw.setAttribute('aria-checked', next ? 'true' : 'false');
    if (isGuest) {
      setPrefs(Object.assign({}, getPrefs(), { search_ai: next }));
      toast('已保存到本机浏览器');
      return;
    }
    try {
      const st = await api('profile.php', 'settings', { search_ai: next });
      state.settings = Object.assign({}, state.settings, st || {});
      toast('已保存');
    } catch (e) {
      sw.setAttribute('aria-checked', next ? 'false' : 'true');   // 回滚，避免界面与库不一致
      toast(e.message, 'err');
    }
  });
}

function mountBlockList(box, isGuest) {
  if (!box) { return; }
  const raw = isGuest ? String(getPrefs().block_words || '') : String((state.settings || {}).block_words || '');
  box.innerHTML = `
    <div class="tiny" style="margin-bottom:8px;line-height:1.6">
      每行一条规则，命中的评论会自动收起（仅对你生效，不影响他人）。<br>
      普通词语：忽略大小写包含即命中；正则表达式：用斜杠包裹，例如 <b>/广告|引流|私聊/i</b>。<br>
      最多 50 条，每条不超过 100 个字符。
    </div>
    <textarea class="input" id="bwText" rows="4" maxlength="2000" placeholder="加微信&#10;/免费|引流|私聊/i"></textarea>
    <button class="btn" id="bwSave" style="margin-top:10px">保存屏蔽规则</button>
    <button class="btn-ghost" id="bwClear" style="margin-top:10px;margin-left:8px">清空</button>`;
  const ta = box.querySelector('#bwText');
  ta.value = raw;

  const save = async v => {
    if (isGuest) {
      setPrefs(Object.assign({}, getPrefs(), { block_words: v }));
      toast('已保存到本机浏览器');
    } else {
      try {
        const st = await api('profile.php', 'settings', { block_words: v });
        state.settings = Object.assign({}, state.settings, st || {});
        toast('已保存');
      } catch (e) { toast(e.message, 'err'); }
    }
  };

  box.querySelector('#bwSave').addEventListener('click', () => save(ta.value));
  box.querySelector('#bwClear').addEventListener('click', () => { ta.value = ''; save(''); });
}

/** 外观设置里展示的皮肤：国庆专版仅在窗口期内出现，且排在最前 */
function skinKeys() {
  const keys = Object.keys(SKINS).filter(k => k !== 'festival');
  return festivalInWindow() ? ['festival'].concat(keys) : keys;
}

function mountAppearance(box) {
  if (!box) return;
  const theme = state.settings.theme || 'light';
  const accent = state.settings.accent || 'blue-purple';
  const skin = document.documentElement.dataset.skin || state.settings.skin || 'glass';
  const navOn = getPrefs().nav_anim !== 0;
  box.innerHTML = `
    <div class="setting-row">
      <span>深浅色</span>
      <div class="seg" style="max-width:200px" id="themeSeg">
        <button data-t="light" class="${theme === 'light' ? 'on' : ''}">浅色</button>
        <button data-t="dark" class="${theme === 'dark' ? 'on' : ''}">深色</button>
        <button data-t="system" class="${theme === 'system' ? 'on' : ''}">跟随</button>
      </div>
    </div>
    <div class="setting-row" style="flex-direction:column;align-items:flex-start;gap:8px">
      <span>设计风格</span>
      <div class="skin-row" id="skinRow">
        ${skinKeys().map(k => `<button class="skin-card ${skin === k ? 'on' : ''}" data-s="${k}">
          <span class="skin-prev p-${k}" aria-hidden="true"></span>
          <b>${esc(SKINS[k].name)}</b>
          <span class="tiny">${esc(SKINS[k].desc)}</span>
        </button>`).join('')}
      </div>
    </div>
    <div class="setting-row" style="flex-direction:column;align-items:flex-start;gap:8px">
      <span>主题色（蓝紫色 / 苹果色 / 自定义）</span>
      <div class="swatch-row" id="accentRow">
        <button class="swatch" data-a="blue-purple" title="蓝紫" style="background:#7C3AED"></button>
        <button class="swatch" data-a="ios-colorful" title="iOS 彩色" style="background:#007AFF"></button>
        <button class="swatch" data-a="custom" title="自定义" style="background:conic-gradient(#f43f5e,#f59e0b,#22c55e,#3b82f6,#a855f7,#f43f5e)"></button>
      </div>
      <div id="customBox" hidden>
        <div class="tiny" style="margin:6px 0">自定义颜色（H/S/L 微调）</div>
        <div class="swatch-row" style="gap:12px">
          <div class="slider" data-hsl="h" style="width:150px"><div class="rail"><div class="fill"></div><div class="knob"></div></div></div>
          <div class="slider" data-hsl="s" style="width:150px"><div class="rail"><div class="fill"></div><div class="knob"></div></div></div>
          <div class="slider" data-hsl="l" style="width:150px"><div class="rail"><div class="fill"></div><div class="knob"></div></div></div>
        </div>
        <div class="tiny" id="customHex"></div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
          <input class="input" id="hexInput" placeholder="#6D3BF5" style="max-width:130px;padding:8px 10px;font-size:13px">
          <button class="btn-ghost btn-sm" id="hexApply">应用</button>
        </div>
      </div>
    </div>
    <div class="setting-row">
      <span>返回动画</span>
      <div class="seg" style="max-width:200px" id="navAnimSeg">
        <button data-n="1" class="${navOn ? 'on' : ''}">开启</button>
        <button data-n="0" class="${navOn ? '' : 'on'}">关闭</button>
      </div>
    </div>
    <div class="tiny" style="margin-top:-4px;line-height:1.7">
      开启后：界面切换有方向感，返回时上一页沿原路径滑回（从哪来回哪去），
      返回后恢复原来的位置与内容，不重新加载；从左边缘右滑还能跟手预览上一页。
    </div>`;

  const skinRow = box.querySelector('#skinRow');
  if (skinRow) {
    skinRow.addEventListener('click', async e => {
      const b = e.target.closest('[data-s]'); if (!b) return;
      const sk = b.dataset.s;
      if (!SKINS[sk]) { return; }

      /* 国庆专版是限时皮肤，不写 kimgr_skin（服务端白名单里没有它）：
         选中它 = 清除「退出标记」；选其它皮肤 = 记为主动退出，窗口期内不再自动启用。 */
      if (sk === 'festival') {
        try { document.cookie = 'kimgr_skin_optout=; path=/; max-age=0'; } catch (e) {}
        toast('正在切换到「' + SKINS[sk].name + '」…');
        setTimeout(() => location.reload(), 260);
        return;
      }
      try { document.cookie = 'kimgr_skin_optout=1; path=/; max-age=31536000; SameSite=Lax'; } catch (e) {}

      /* 每种风格是一份独立 CSS 文件，由内联脚本按 cookie 选择加载，
         因此切换动作 = 写 cookie（+ 同步账号偏好）→ 刷新页面。 */
      setSkinCookie(sk);
      setPrefs(Object.assign({}, getPrefs(), { skin: sk }));
      if (state.role !== 'guest') {
        try { await api('profile.php', 'settings', { skin: sk }); state.settings.skin = sk; } catch (err) {}
      }
      skinRow.querySelectorAll('.skin-card').forEach(x => x.classList.toggle('on', x === b));
      toast('正在切换到「' + SKINS[sk].name + '」…');
      setTimeout(() => location.reload(), 260);
    });
  }

  box.querySelector('#navAnimSeg').addEventListener('click', e => {
    const b = e.target.closest('[data-n]'); if (!b) return;
    const on = b.dataset.n === '1';
    setPrefs(Object.assign({}, getPrefs(), { nav_anim: on ? 1 : 0 }));
    setNavAnim(on);
    box.querySelectorAll('#navAnimSeg button').forEach(x => x.classList.toggle('on', x === b));
    toast(on ? '已开启返回动画' : '已关闭返回动画');
  });

  box.querySelector('#themeSeg').addEventListener('click', async e => {
    const b = e.target.closest('[data-t]'); if (!b) return;
    box.querySelectorAll('#themeSeg button').forEach(x => x.classList.toggle('on', x === b));
    await saveTheme({ theme: b.dataset.t });
  });

  const row = box.querySelector('#accentRow');
  markSwatch(row, accent);
  const customBox = box.querySelector('#customBox');
  if (accent === 'custom') { customBox.hidden = false; initHsl(box); }

  row.addEventListener('click', async e => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    markSwatch(row, b.dataset.a);
    customBox.hidden = b.dataset.a !== 'custom';
    if (b.dataset.a === 'custom') initHsl(box);
    await saveTheme({ accent: b.dataset.a });
  });
}

function markSwatch(row, accent) {
  row.querySelectorAll('.swatch').forEach(s => s.classList.toggle('on', s.dataset.a === accent));
}

function initHsl(box) {
  if (box.dataset.hslInit === '1') return;
  box.dataset.hslInit = '1';
  const cur = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
  const scr = [box.querySelector('[data-hsl="h"]'), box.querySelector('[data-hsl="s"]'), box.querySelector('[data-hsl="l"]')];

  // 简易 HSL：以滑块位置直接映射并实时写入
  const vals = { h: 265, s: 70, l: 55 };
  const render = () => {
    const hex = `hsl(${vals.h} ${vals.s}% ${vals.l}%)`;
    document.documentElement.style.setProperty('--accent', hex);
    document.documentElement.style.setProperty('--accent-2', `hsl(${(vals.h + 18) % 360} ${Math.min(100, vals.s + 5)}% ${Math.min(80, vals.l + 12)}%)`);
    box.querySelector('#customHex').textContent = hex;
  };
  scr.forEach((el, i) => {
    const key = ['h', 's', 'l'][i];
    const set = (ratio) => {
      const r = Math.max(0, Math.min(1, ratio));
      vals[key] = key === 'h' ? Math.round(r * 359) : Math.round(r * 100);
      el.querySelector('.fill').style.width = (r * 100) + '%';
      el.querySelector('.knob').style.left = (r * 100) + '%';
      render();
    };
    set(key === 'h' ? vals.h / 359 : vals[key] / 100);
    const drag = ev => {
      const rect = el.getBoundingClientRect();
      const x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - rect.left;
      set(x / rect.width);
    };
    el.addEventListener('pointerdown', ev => {
      drag(ev);
      const move = e2 => drag(e2);
      const up = async () => {
        window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up);
        await saveTheme({ accent: 'custom', accent_custom: `hsl(${vals.h} ${vals.s}% ${vals.l}%)` });
      };
      window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    });
  });
  render();

  // 十六进制输入
  const hexInput = box.querySelector('#hexInput');
  const hexApply = box.querySelector('#hexApply');
  const hexEl = box.querySelector('#customHex');
  if (hexInput) { hexInput.value = (hexEl.textContent || '').trim() || '#6D3BF5'; }
  if (hexApply) hexApply.addEventListener('click', async () => {
    let v = (hexInput.value || '').trim();
    if (v.charAt(0) !== '#') { v = '#' + v; }
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) { toast('请输入合法色值，如 #6D3BF5', 'err'); return; }
    // 反推 HSL 滑块位置
    const { h, s: ss, l } = hexToHsl(v);
    vals.h = h; vals.s = ss; vals.l = l;
    [['h', h / 359], ['s', ss / 100], ['l', l / 100]].forEach(([k, ratio], i) => {
      const el = scr[i];
      el.querySelector('.fill').style.width = (ratio * 100) + '%';
      el.querySelector('.knob').style.left = (ratio * 100) + '%';
    });
    render();
    await saveTheme({ accent: 'custom', accent_custom: v });
    toast('已应用自定义颜色');
  });
}

/* ---------- 访问记录 ---------- */
async function loadVisits(box) {
  if (!box) return;
  try {
    const d = await api('profile.php', 'visits');
    box.innerHTML = '';
    if (!d.items.length) { box.innerHTML = '<div class="tiny" style="padding:8px 0">暂无访问记录</div>'; return; }
    d.items.forEach(v => {
      const el = document.createElement('div');
      el.className = 'visit-row';
      el.innerHTML = `<span>${esc(v.location || '未知地区')} · ${esc(v.ip)}</span><span class="tiny">${esc(v.time)}</span>`;
      box.appendChild(el);
    });
  } catch (e) { box.innerHTML = '<div class="tiny">加载失败</div>'; }
}

/* ---------- 管理员：用量 Top3 ---------- */
async function loadTop3(box) {
  if (!box) return;
  try {
    const d = await api('profile.php', 'usage');
    const t3 = d.top3 || [];
    if (!t3.length) { box.innerHTML = '<div class="tiny">暂无用量排行</div>'; return; }
    box.innerHTML = '<div class="tiny" style="margin-bottom:6px">全站用量 Top3</div>' + t3.map((u, i) =>
      `<div class="visit-row"><span>${i + 1}. ${esc(u.username)}</span><span class="tiny">${u.tokens} tokens · ${u.calls} 次</span></div>`).join('');
  } catch (e) {}
}

function avatarImg(name, url) {
  if (url) return `<img src="${url}" alt="" draggable="false" style="user-select:none">`;
  return `<img src="data:image/svg+xml;utf8,${encodeURIComponent(simpleAvatar(name || 'guest'))}" alt="" draggable="false" style="user-select:none">`;
}

/* 前端兜底头像（后端未返回时）；正常由接口下发 SVG */
function simpleAvatar(seed) {
  let h = 0; for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) % 360;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#eceef2"/><rect x="10" y="10" width="20" height="20" fill="hsl(${h},68%,52%)"/><rect x="30" y="30" width="20" height="20" fill="hsl(${h},68%,52%)"/><rect x="50" y="10" width="20" height="20" fill="hsl(${(h+40)%360},72%,62%)"/><rect x="10" y="50" width="20" height="20" fill="hsl(${(h+40)%360},72%,62%)"/><rect x="50" y="50" width="20" height="20" fill="hsl(${h},68%,52%)"/></svg>`;
}
