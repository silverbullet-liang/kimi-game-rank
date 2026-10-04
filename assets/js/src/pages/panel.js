/**
 * 控制面板：二次认证 + 统计 + 作品/用户管理 + 副管理员
 * 视觉：普通后台风格（不使用液态玻璃）
 * 权限：主管理员=全部；副管理员=作品搜索/上传/同步 + 只读数据（登录即入场，无需二次密钥），
 *       社区凭证自备（含 Token 获取脚本下载），各存各的
 */
import { api, state, esc, toast, dialog, prompt_, btnLoading, isAdminish, userName } from '../core.js';
import { navigate } from '../router.js';

const PCATS = [['game', '游戏'], ['tool', '工具'], ['literature', '文学'], ['fanart', '二创']];
const PDIMS = [
  { k: 'creativity', n: '创意' }, { k: 'experience', n: '体验' }, { k: 'depth', n: '深度' },
  { k: 'cost', n: '成本' }, { k: 'attitude', n: '态度' }, { k: 'heat', n: '热度' },
];
const catName = c => (PCATS.find(x => x[0] === c) || [, c])[1];
const sleep = ms => new Promise(r => setTimeout(r, ms));

export async function renderPanel(container) {
  if (!isAdminish()) {
    container.innerHTML = '<div class="empty"><p>无权限访问</p></div>';
    return;
  }
  const isSub = state.role === 'subadmin';

  // 主管理员需二次密钥验证；副管理员登录时已用密钥，直接入场
  if (!isSub) {
    container.innerHTML = '<div class="skeleton" style="height:120px"></div>';
    let ov;
    try { ov = await api('dashboard.php', 'overview'); }
    catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }
    if (!ov.panel_verified) {
      const key = await prompt_('控制面板验证', '请输入管理员密钥（64 位十六进制，由密钥原文经 sha256 计算得出）。', '验证');
      if (!key) { container.innerHTML = '<div class="empty"><p>已取消验证</p></div>'; return; }
      try { await api('admin.php', 'panel_auth', { key: key }); }
      catch (e) { container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`; return; }
      ov = await api('dashboard.php', 'overview');
    }
    container.innerHTML = adminLayout();
    renderOverview(container.querySelector('#ovGrid'), ov, container);
    loadStats('7', container);
    loadUsers(container, '');
    loadSubs(container);
    loadVisitsTop(container);
    bindCommentBin(container);
    bindAnnounce(container);
    bindRefresh(container);
    bindReclassify(container);
    bindDb(container);
    bindBackup(container);
    loadDisc(container);
    loadPeer(container);
    bindAiRank(container);
    startPanelPolling(container);
  } else {
    container.innerHTML = subLayout();
    /* 副管理员的只读数据：总览、趋势、用户列表（不含 IP 与归属地明细）、访问排行。
       任一接口失败都不影响作品上传这条主线。 */
    try {
      const ov = await api('dashboard.php', 'overview');
      const ovGrid = container.querySelector('#ovGrid');
      if (ovGrid) { renderOverview(ovGrid, ov, container); }
    } catch (e) { /* 静默 */ }
    loadStats('7', container);
    loadUsers(container, '', true);
    loadVisitsTop(container);
  }

  const uSearchBtn = container.querySelector('#uSearch');
  if (uSearchBtn && !uSearchBtn.dataset.bound) {
    uSearchBtn.dataset.bound = '1';
    uSearchBtn.addEventListener('click', () =>
      loadUsers(container, container.querySelector('#uq').value.trim(), isSub));
  }

  bindCredential(container);
  bindAddWork(container);
  /* 收录/更新相关的开关与只读排行：副管理员同样可用 */
  bindScore(container);
  bindLink(container);
  bindAiRank(container);
  loadWorks(container, '', isSub);
  container.querySelector('#wSearch').addEventListener('click', () => loadWorks(container, container.querySelector('#wq').value.trim(), isSub, 1));
  const sizeSeg = container.querySelector('#wSizeSeg');
  if (sizeSeg) {
    sizeSeg.addEventListener('click', e => {
      const b = e.target.closest('[data-sz]'); if (!b) return;
      sizeSeg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      loadWorks(container, container.querySelector('#wq').value.trim(), isSub, 1);
    });
  }
}

/* ============================================================
 * 布局
 * ============================================================ */
function adminLayout() {
  return `
    <div class="panel-plain">
      <h3>数据总览</h3>
      <div class="stat-grid" id="ovGrid"></div>
    </div>

    <div class="panel-plain">
      <h3>趋势统计
        <span class="seg" style="float:right;max-width:220px" id="rangeSeg">
          <button data-r="7" class="on">近7天</button>
          <button data-r="30">近30天</button>
          <button data-r="all">全部</button>
        </span>
      </h3>
      <div id="chartArea"><div class="skeleton" style="height:150px"></div></div>
      <div class="legend" id="legend"></div>
    </div>

    <div class="panel-plain">
      <h3>分类分布 / 评级分布</h3>
      <div id="distArea"><div class="skeleton" style="height:120px"></div></div>
    </div>

    ${aiRankBlock()}

    ${credentialBlock()}
    ${addWorkBlock()}
    ${scoreBlock()}
    ${linkBlock()}
    ${refreshBlock()}
    ${reclassifyBlock()}
    ${dbBlock()}
    ${backupBlock()}
    ${workListBlock(true)}

    <div class="panel-plain">
      <h3>用户管理</h3>
      <div class="prow">
        <input class="input" id="uq" placeholder="搜索用户名 / 8 位 UID">
        <button class="btn btn-sm" id="uSearch">搜索</button>
      </div>
      <div id="userList"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="userPager"></div>
    </div>

    ${discBlock()}

    ${peerBlock()}

    ${commentBinBlock()}
    ${visitsBlock()}

    <div class="panel-plain">
      <h3>副管理员</h3>
      <p class="tiny muted">副管理员拥有普通用户全部权限，可搜索与上传作品、回复反馈。<b>升级现有用户时不需要更改密码</b>——副管理员与普通用户同库同通道登录，只有总管理员凭证独立存放。</p>
      <div class="prow">
        <input class="input" id="subName" placeholder="用户名（可填已存在的用户）" autocomplete="off">
        <input class="input" id="subKey" type="password" placeholder="初始密码（仅新建账号时需要，≥8 位）" autocomplete="new-password">
        <button class="btn btn-sm" id="subAdd">添加 / 升级</button>
      </div>
      <div class="tiny" id="subHint" style="margin:-2px 0 10px"></div>
      <div id="subList"><div class="skeleton" style="height:60px"></div></div>
    </div>

    <div class="panel-plain">
      <h3>公告设置</h3>
      <div class="field"><textarea class="input" id="annInput" placeholder="首页横幅公告内容"></textarea></div>
      <button class="btn btn-sm" id="annSave">保存公告</button>
    </div>`;
}

/** 访问排行板块（管理员与副管理员共用） */
function visitsBlock() {
  return `
    <div class="panel-plain">
      <h3>访问排行</h3>
      <p class="tiny muted">按已记录的访问归属地统计，各维度前十五名。</p>
      <div id="visitsTop"><div class="skeleton" style="height:120px"></div></div>
    </div>`;
}

/** 评论回收站（仅主管理员）：删除 = 入回收站，这里可查看、恢复或彻底清空 */
function commentBinBlock() {
  return `
    <div class="panel-plain">
      <h3>评论回收站</h3>
      <p class="tiny muted">
        被删除的评论会进入这里：<b>页面不再显示</b>，但内容仍保留在库中。
        可以恢复到原处，也可以彻底删除；<b>「清空回收站」不可撤销</b>。
      </p>
      <div class="tiny" id="cmBinHead" style="margin-bottom:8px"></div>
      <div id="cmBinList"><div class="skeleton" style="height:80px"></div></div>
      <div class="row-gap" style="margin-top:10px">
        <button class="btn-ghost btn-sm" id="cmBinRefresh">刷新</button>
        <button class="btn-sm" id="cmBinPurge" style="color:#f87171">清空回收站</button>
      </div>
    </div>`;
}

async function loadCommentBin(container) {
  const box = container.querySelector('#cmBinList');
  if (!box) { return; }
  const head = container.querySelector('#cmBinHead');
  try {
    const d = await api('admin.php', 'comments_deleted', { page: 1 });
    if (head) { head.innerHTML = '共 <b>' + Number(d.total) + '</b> 条已删除评论'; }
    if (!d.items.length) { box.innerHTML = '<div class="tiny muted">回收站是空的。</div>'; return; }
    box.innerHTML = '<table class="table"><thead><tr><th>作者</th><th>内容</th><th>所属作品</th><th>删除时间</th><th>操作</th></tr></thead><tbody>'
      + d.items.map(c => `<tr data-cid="${Number(c.id)}">
        <td>${esc(c.username)}<br><span class="tiny muted">${c.by_admin ? '管理员删除' : '本人删除'}</span></td>
        <td style="max-width:340px;word-break:break-word">${esc(c.content).slice(0, 160)}</td>
        <td class="tiny">${esc(c.title)}</td>
        <td class="tiny">${esc(c.time)}</td>
        <td><button class="btn-ghost btn-sm" data-act="restore">恢复</button></td></tr>`).join('')
      + '</tbody></table>';
    box.querySelectorAll('tr[data-cid]').forEach(tr => {
      const b = tr.querySelector('[data-act="restore"]');
      if (!b) { return; }
      b.addEventListener('click', async () => {
        btnLoading(b, true);
        try { await api('admin.php', 'comment_restore', { id: tr.dataset.cid }); toast('已恢复'); loadCommentBin(container); }
        catch (e) { toast(e.message, 'err'); btnLoading(b, false); }
      });
    });
  } catch (e) { box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`; }
}

function bindCommentBin(container) {
  const refresh = container.querySelector('#cmBinRefresh');
  if (refresh) { refresh.addEventListener('click', () => loadCommentBin(container)); }
  const purge = container.querySelector('#cmBinPurge');
  if (purge) {
    purge.addEventListener('click', async () => {
      if (!(await dialog('清空回收站',
          '将<b>彻底删除</b>回收站中的全部评论（含其点赞），<b>此操作不可撤销</b>。确认继续？', '清空', { danger: true }))) { return; }
      btnLoading(purge, true);
      try {
        const r = await api('admin.php', 'comment_purge', {});
        toast('已彻底删除 ' + Number(r.purged) + ' 条');
        loadCommentBin(container);
      } catch (e) { toast(e.message, 'err'); }
      finally { btnLoading(purge, false); }
    });
  }
  loadCommentBin(container);
}

function subLayout() {
  return `
    <div class="panel-plain">
      <h3>副管理员工作台</h3>
      <p class="tiny muted">
        你已以副管理员身份登录：可使用作品搜索与上传、回复反馈，并查看<b>全站只读数据</b>。
        用户 IP 与归属地明细仅总管理员可见；改库、账号处置与公告等操作不可用。
      </p>
    </div>

    <div class="panel-plain">
      <h3>数据总览</h3>
      <div class="stat-grid" id="ovGrid"><div class="skeleton" style="height:60px"></div></div>
    </div>

    <div class="panel-plain">
      <h3>趋势统计
        <span class="seg" style="float:right;max-width:220px" id="rangeSeg">
          <button data-r="7" class="on">近7天</button>
          <button data-r="30">近30天</button>
          <button data-r="all">全部</button>
        </span>
      </h3>
      <div id="chartArea"><div class="skeleton" style="height:150px"></div></div>
      <div class="legend" id="legend"></div>
    </div>

    ${credentialBlock()}
    ${addWorkBlock()}
    ${scoreBlock()}
    ${linkBlock()}
    ${workListBlock(false)}
    ${visitsBlock()}
    ${aiRankBlock()}

    <div class="panel-plain">
      <h3>用户列表（只读）</h3>
      <div class="prow">
        <input class="input" id="uq" placeholder="搜索用户名 / 8 位 UID">
        <button class="btn btn-sm" id="uSearch">搜索</button>
      </div>
      <div id="userList"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="userPager"></div>
    </div>`;
}

function dbBlock() {
  return `
    <div class="panel-plain">
      <h3>数据库更新</h3>
      <p class="tiny muted">
        新增功能若用到新的数据列，需要这些列真的建好才算生效。升级后通常会自动完成，
        万一没赶上（或数据库改表被拒），这里可以手动补上。更新是幂等的，可反复执行。
      </p>
      <div id="dbStatus" class="tiny" style="line-height:1.9;margin-bottom:10px"></div>
      <div class="row-gap">
        <button class="btn-sm btn-ghost" id="dbCheckBtn">检查状态</button>
        <button class="btn btn-sm" id="dbRunBtn">一键更新</button>
      </div>
      <div id="dbResult" hidden style="margin-top:12px"></div>
    </div>`;
}

function bindDb(container) {
  const checkBtn = container.querySelector('#dbCheckBtn');
  const runBtn   = container.querySelector('#dbRunBtn');
  const status   = container.querySelector('#dbStatus');
  const result   = container.querySelector('#dbResult');
  if (!checkBtn) { return; }

  const paint = (d) => {
    const parts = [
      '当前结构版本 <b>' + esc(d.current) + '</b>',
      '程序期望 <b>' + esc(d.expected) + '</b>'
    ];
    status.innerHTML = '<div>' + parts.join(' · ') + '</div>'
      + (d.healthy
          ? '<div style="color:var(--ok,#0a0)">结构完整，' + d.checked + ' 项检查全部通过。</div>'
          : '<div style="color:var(--danger)">缺少 ' + d.missing.length + ' 项：'
            + esc(d.missing.join('、')) + '</div>');
    if (d.last_run) {
      status.innerHTML += '<div class="muted">上次自动检查：' + esc(d.last_run) + '</div>';
    }
  };

  checkBtn.addEventListener('click', async () => {
    checkBtn.disabled = true;
    status.innerHTML = '<div class="skeleton" style="height:40px"></div>';
    try { paint(await api('admin.php', 'db_status', {})); }
    catch (e) { status.innerHTML = '<div style="color:var(--danger)">' + esc(e.message) + '</div>'; }
    checkBtn.disabled = false;
  });

  runBtn.addEventListener('click', async () => {
    if (!(await dialog('更新数据库', '将按当前程序需要补齐缺失的数据表与列。此操作幂等，不会删除已有数据。确定继续？', '开始更新'))) { return; }
    runBtn.disabled = true; checkBtn.disabled = true;
    result.hidden = false;
    result.innerHTML = '<div class="skeleton" style="height:48px"></div>';
    try {
      const d = await api('admin.php', 'db_update', {}, { timeout: 120000 });
      paint(d.after);

      let html = '';
      if (d.error) {
        html += '<div class="tiny" style="color:var(--danger)">执行中断：' + esc(d.error) + '</div>';
      } else if (d.fixed.length) {
        html += '<div class="tiny" style="line-height:1.9">本次补齐 <b>' + d.fixed.length + '</b> 项：'
              + esc(d.fixed.join('、')) + '</div>';
      } else {
        html += '<div class="tiny muted">没有缺失项，结构本来就是完整的。</div>';
      }
      if (d.left.length) {
        html += '<div class="tiny" style="color:var(--danger);margin-top:6px;line-height:1.9">'
              + '仍有 ' + d.left.length + ' 项未能补齐：' + esc(d.left.join('、'))
              + '<br>多半是数据库不允许改表。请联系主机商，或把本页结果发给开发者。</div>';
      } else if (!d.error) {
        html += '<div class="tiny" style="color:var(--ok,#0a0);margin-top:6px">现在结构是完整的。</div>';
      }
      html += '<div class="tiny muted" style="margin-top:6px">耗时 ' + d.elapsed + ' 秒</div>';
      result.innerHTML = html;
      toast(d.left.length ? '更新完成，仍有项目未补齐' : '数据库已是最新');
    } catch (e) {
      result.innerHTML = '<div class="tiny" style="color:var(--danger)">' + esc(e.message) + '</div>';
    }
    runBtn.disabled = false; checkBtn.disabled = false;
  });
}

const fmtBytes = (n) => {
  n = Number(n) || 0;
  if (n < 1024) { return n + ' B'; }
  if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
  return (n / 1048576).toFixed(1) + ' MB';
};

/* 数据库备份：导出全站数据到服务器，再按需下载。仅主管理员可见（副管理员版布局不含本区块） */
function backupBlock() {
  return `
    <div class="panel-plain">
      <h3>数据库备份</h3>
      <p class="tiny muted">
        把全站数据（作品、用户、评论、对话、配置）导出成一个 SQL 文件，存在服务器上
        （该目录已禁止直接用网址访问，下载必须经过管理员鉴权）。备份含账号与评论数据，
        <b>请妥善保管，用完及时删除</b>。
      </p>
      <div id="bkMeta" class="tiny muted" style="margin-bottom:10px"></div>
      <div class="row-gap">
        <button class="btn btn-sm" id="bkRunBtn">立即备份</button>
        <button class="btn-sm btn-ghost" id="bkListBtn">刷新列表</button>
      </div>
      <div id="bkList" style="margin-top:12px"><div class="skeleton" style="height:60px"></div></div>
    </div>`;
}

function bindBackup(container) {
  const runBtn  = container.querySelector('#bkRunBtn');
  const listBtn = container.querySelector('#bkListBtn');
  const meta    = container.querySelector('#bkMeta');
  const box     = container.querySelector('#bkList');
  if (!runBtn) { return; }

  const paint = (d) => {
    meta.innerHTML = d.writable
      ? '共 <b>' + d.items.length + '</b> 份备份 · 单份上限 ' + d.limit_text
      : '<span style="color:var(--danger)">服务器上的备份目录不可写，请检查该目录权限</span>';
    if (!d.items.length) {
      box.innerHTML = '<div class="tiny muted">还没有备份。点「立即备份」生成第一份。</div>';
      return;
    }
    let h = '<table class="table"><thead><tr><th>文件</th><th>大小</th><th>时间</th><th>操作</th></tr></thead><tbody>';
    d.items.forEach(function (it) {
      h += '<tr><td class="tiny">' + esc(it.name) + '</td>'
        + '<td class="tiny">' + fmtBytes(it.bytes) + '</td>'
        + '<td class="tiny">' + esc(it.time) + '</td>'
        + '<td><button class="btn-ghost btn-sm" style="padding:4px 10px" data-dl="' + esc(it.name) + '">下载</button> '
        + '<button class="btn-ghost btn-sm" style="padding:4px 10px" data-del="' + esc(it.name) + '">删除</button></td></tr>';
    });
    box.innerHTML = h + '</tbody></table>';

    box.querySelectorAll('[data-dl]').forEach(function (b) {
      b.addEventListener('click', function () { download(b.getAttribute('data-dl'), b); });
    });
    box.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', async function () {
        const name = b.getAttribute('data-del');
        if (!(await dialog('删除备份', '将永久删除 ' + name + '，无法恢复。确定继续？', '删除'))) { return; }
        b.disabled = true;
        try { await api('admin.php', 'backup_delete', { name: name }); toast('已删除'); load(); }
        catch (e) { toast(e.message, 'err'); b.disabled = false; }
      });
    });
  };

  const load = async () => {
    box.innerHTML = '<div class="skeleton" style="height:60px"></div>';
    try { paint(await api('admin.php', 'backup_list')); }
    catch (e) { box.innerHTML = '<div class="tiny" style="color:var(--danger)">' + esc(e.message) + '</div>'; }
  };

  /* 与「下载 Token 脚本」同一套做法：带鉴权头取回，再交给浏览器落盘 */
  const download = async (name, btn) => {
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = '准备中…';
    try {
      const headers = { 'Accept': 'application/octet-stream' };
      if (state.token) {
        headers['Authorization'] = 'Bearer ' + state.token;
        headers['X-Token'] = state.token;
      }
      const resp = await fetch('api/admin.php?action=backup_download&name=' + encodeURIComponent(name),
        { headers: headers, credentials: 'same-origin' });
      const type = resp.headers.get('Content-Type') || '';
      if (!resp.ok || type.indexOf('json') >= 0) {   // 被拦或鉴权失败时返回的是 JSON
        let msg = '下载失败（HTTP ' + resp.status + '）';
        try { const j = await resp.json(); if (j && j.msg) { msg = j.msg; } } catch (err) {}
        throw new Error(msg);
      }
      const url = window.URL.createObjectURL(await resp.blob());
      const a = document.createElement('a');
      a.href = url;
      a.download = name;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => window.URL.revokeObjectURL(url), 5000);
      toast('已开始下载 ' + name);
    } catch (e) { toast(e.message, 'err'); }
    btn.disabled = false;
    btn.textContent = old;
  };

  runBtn.addEventListener('click', async () => {
    runBtn.disabled = true; listBtn.disabled = true;
    btnLoading(runBtn, true);
    meta.innerHTML = '<span class="muted">正在导出，请勿关闭页面…</span>';
    try {
      const d = await api('admin.php', 'backup', {}, { timeout: 300000, silent: true });
      toast('备份完成');
      meta.innerHTML = '刚生成 <b>' + esc(d.name) + '</b> · ' + fmtBytes(d.bytes)
        + ' · ' + d.tables + ' 张表 / ' + d.rows + ' 行 · 耗时 ' + d.seconds + ' 秒'
        + (d.consistent ? '' : ' · 未启用一致性快照');
    } catch (e) {
      meta.innerHTML = '<span style="color:var(--danger)">' + esc(e.message) + '</span>';
    }
    btnLoading(runBtn, false);
    runBtn.disabled = false; listBtn.disabled = false;
    load();
  });

  listBtn.addEventListener('click', load);
  load();
}

/* 社区凭证：粘贴与「取得」放在同一区块，主管理员与副管理员通用。
   副管理员各存各的（服务端按身份分库），互不共用。 */
function credentialBlock() {
  return `
    <div class="panel-plain">
      <h3>社区凭证</h3>
      <div class="field"><label>Kimi 社区 cookie / token（加密存储，独立不共用）</label>
        <div class="tok-row">
          <input class="input" id="tokInput" placeholder="粘贴社区登录态 token">
          <button class="btn-ghost btn-sm" id="tokScanBtn" type="button">扫码填写</button>
        </div>
      </div>
      <div id="tokQR" hidden>
        <div class="qr-wrap">
          <div class="qr-box" id="tokQRBox"><span class="muted tiny">正在获取二维码…</span></div>
          <div class="qr-hint" id="tokQRHint"></div>
          <button class="btn-ghost btn-sm" id="tokQRRefresh" type="button">刷新二维码</button>
        </div>
        <p class="tiny muted">用 <b>微信</b> 或 <b>Kimi App</b> 扫码并在手机上确认，登录态会自动填入上方输入框，
        再点「仅保存凭证」完成。二维码有效期较短，过期后点「刷新二维码」。</p>
      </div>
      <button class="btn-ghost btn-sm" id="saveTokBtn">仅保存凭证</button>
      <button class="btn-ghost btn-sm" id="tokenScriptBtn">下载 Token 脚本</button>
      <p class="tiny muted" style="margin-top:8px">
        Token 脚本是运行在浏览器里的<b>第三方用户脚本</b>（Tampermonkey），
        用于在社区页面获取你自己的 Token。本站仅提供下载中转，<b>不校验其内容</b>，安装前请自行核对。
      </p>
    </div>`;
}

function addWorkBlock() {
  return `
    <div class="panel-plain">
      <h3>添加作品</h3>
      <div class="seg" style="margin-bottom:10px" id="addModeSeg">
        <button data-m="manual" class="on">手动（按作品 ID）</button>
        <button data-m="auto">自动（同步信息流）</button>
      </div>
      <div class="field" id="workIdField">
        <label>作品 ID</label>
        <input class="input" id="workIdInput" placeholder="输入 Kimi 社区作品 ID" autocomplete="off">
        <div class="sug" id="workSug" hidden></div>
      </div>
      <button class="btn" id="addBtn">开始</button>
      <p class="tiny muted" data-link-state style="margin-top:8px"></p>
    </div>`;
}

/* 评分方式：收录与「全部更新」是否重算评分。
   默认「只更新信息」——站长常按实际情况手动调分，自动重算会把这些改动抹掉，
   所以默认不覆盖评分；且只有总管理员能切换。 */
function scoreBlock() {
  return `
    <div class="panel-plain">
      <h3>评分方式</h3>
      <p class="tiny muted">
        默认「只更新信息」：<b>已收录作品</b>在更新时只改信息（标题、简介、图片、互动数等），
        <b>不覆盖评分</b>，方便按实际情况调分；<b>新收录的作品一律照常评分</b>，不受影响。
        需要把老作品的分数按算法重算时，由总管理员切到「自动评分」再点一次「全部更新」。
        （<b>副管理员只能查看，不能切换</b>。）
      </p>
      <div class="seg" id="scoreSeg" style="margin:10px 0 8px">
        <button data-a="1">自动评分</button>
        <button data-a="0">只更新信息</button>
      </div>
      <p class="tiny" id="scoreNote"></p>
    </div>`;
}

function bindScore(container) {
  const seg = container.querySelector('#scoreSeg');
  const note = container.querySelector('#scoreNote');
  if (!seg) { return; }
  const readonly = (state.role === 'subadmin');   // 副管理员只能看，不能切

  const paint = (auto) => {
    seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.a === (auto ? '1' : '0')));
    note.innerHTML = (auto
      ? '<span style="color:var(--ok,#0a0)">当前：自动评分</span> · 收录与更新都会按算法重算'
      : '<span style="color:var(--accent)">当前：只更新信息</span> · 已收录作品<b>不重算评分</b>（新收录的仍会自动评分）')
      + (readonly ? ' · <b>仅总管理员可切换</b>' : '');
  };
  const lock = (on) => seg.querySelectorAll('button').forEach(b => { b.disabled = on; });
  if (readonly) { lock(true); }

  seg.addEventListener('click', async (e) => {
    if (readonly) { return; }
    const b = e.target.closest('[data-a]');
    if (!b || b.classList.contains('on') || b.disabled) { return; }
    const auto = b.dataset.a === '1';
    lock(true);
    try {
      const d = await api('admin.php', 'score_mode_set', { auto: auto ? 1 : 0 });
      paint(d.auto === true);
      toast(d.auto === true ? '已恢复自动评分' : '已关闭自动评分，之后不再改动评分');
    } catch (e2) {
      toast(e2.message, 'err');
      try { paint((await api('admin.php', 'score_mode', {}, { silent: true })).auto === true); } catch (e3) {}
    }
    lock(false);
  });

  api('admin.php', 'score_mode', {}, { silent: true })
    .then(d => paint(d.auto === true))
    .catch(() => { note.textContent = '读取评分方式失败，刷新页面再试'; });
}

/* 智能链接识别：原页面只是个跳转页时，改用其中的真实地址。默认开启。
   一个开关、三处可见——开关区块本身，以及收录区与批量更新区的状态行。 */
const linkStateText = (on) => '智能链接识别：<b>' + (on ? '已开启' : '已关闭') + '</b>';

function paintLinkState(container, on) {
  container.querySelectorAll('[data-link-state]').forEach(function (el) { el.innerHTML = linkStateText(on); });
  const seg = container.querySelector('#linkSeg');
  if (seg) { seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === (on ? '1' : '0'))); }
  const note = container.querySelector('#linkNote');
  if (note) {
    note.innerHTML = on
      ? '<span style="color:var(--accent)">当前：已开启</span> · 收录与更新时会尝试把跳转页换成真实地址'
      : '<span class="muted">当前：已关闭</span> · 作品页一律按原始地址收录';
  }
}

async function refreshLinkState(container, force) {
  if (container.__linkOn !== undefined && force !== true) { paintLinkState(container, container.__linkOn); return; }
  try {
    const d = await api('admin.php', 'link_mode', {}, { silent: true });
    container.__linkOn = d.on === true;
    paintLinkState(container, container.__linkOn);
  } catch (e) { /* 读不到就不显示，不打扰操作 */ }
}

function linkBlock() {
  return `
    <div class="panel-plain">
      <h3>智能链接识别</h3>
      <p class="tiny muted">
        有些作品的「页面地址」只是个跳转页（一句「正在前往…」，真正的作品在别处）。
        本功能默认开启：收录与批量更新会尝试从这类页面里找出<b>真实地址</b>并替换，
        标题与评分也改按真实页面来算。
      </p>
      <p class="tiny muted">
        判定刻意保守：先确认这页确实像跳转页，再排除常见大站与站内地址，
        <b>只认唯一一个候选链接</b>；识别不出就保持原样，不会乱改。
      </p>
      <div class="seg" id="linkSeg" style="margin:10px 0 8px">
        <button data-v="0">关闭</button>
        <button data-v="1">开启</button>
      </div>
      <p class="tiny" id="linkNote"></p>
    </div>`;
}

function bindLink(container) {
  const seg = container.querySelector('#linkSeg');
  if (!seg) { return; }
  const lock = (on) => seg.querySelectorAll('button').forEach(b => { b.disabled = on; });

  seg.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-v]');
    if (!b || b.classList.contains('on') || b.disabled) { return; }
    const on = b.dataset.v === '1';
    lock(true);
    try {
      const d = await api('admin.php', 'link_mode_set', { on: on ? 1 : 0 });
      container.__linkOn = d.on === true;
      paintLinkState(container, container.__linkOn);
      toast(d.on === true ? '已开启智能链接识别' : '已关闭智能链接识别');
    } catch (e2) {
      toast(e2.message, 'err');
      await refreshLinkState(container, true);
    }
    lock(false);
  });

  refreshLinkState(container);
}

function refreshBlock() {
  return `
    <div class="panel-plain">
      <h3>一键更新全部作品</h3>
      <p class="tiny muted">按社区最新数据更新全部作品（含 CDN 直链）。按排名从高到低分批抓取，可随时停止。</p>
      <p class="tiny muted" data-link-state style="margin-top:6px"></p>
      <div class="prog" id="refreshProg" hidden><div class="prog-bar"><i style="width:0%"></i></div><span class="tiny" id="refreshText"></span></div>
      <button class="btn btn-sm" id="refreshBtn">开始更新</button>
      <button class="btn-ghost btn-sm" id="refreshStop" hidden>停止</button>
    </div>`;
}

function reclassifyBlock() {
  return `
    <div class="panel-plain">
      <h3>一键更换分区</h3>
      <p class="tiny muted">
        按标题与简介自动判定分区，优先级为 <b>二创 ＞ 文学 ＞ 工具 ＞ 其余归游戏</b>。
        游戏不单独判定，是兜底分区——以上三类都不命中就归游戏。
      </p>
      <div class="prog" id="rcProg" hidden><div class="prog-bar"><i style="width:0%"></i></div><span class="tiny" id="rcText"></span></div>
      <div class="row-gap">
        <button class="btn btn-sm" id="rcPreviewBtn">预览变更</button>
        <button class="btn-sm btn-ghost" id="rcApplyBtn" disabled>执行变更</button>
      </div>
      <div id="rcPreview" hidden style="margin-top:12px"></div>

      <hr style="border:0;border-top:1px solid var(--border-soft);margin:18px 0 14px">

      <h3>手工批量迁移</h3>
      <p class="tiny muted">把某个分区的作品整体并入另一个分区，用于「这个分区不想要了，全部并过去」。</p>
      <div class="setting-row" style="border:0;padding:4px 0">
        <span class="muted">从</span>
        <div class="seg seg-mini" id="mvFrom">
          <button data-c="all" class="on">全部</button>
          <button data-c="game">游戏</button>
          <button data-c="tool">工具</button>
          <button data-c="literature">文学</button>
          <button data-c="fanart">二创</button>
        </div>
      </div>
      <div class="setting-row" style="border:0;padding:4px 0">
        <span class="muted">到</span>
        <div class="seg seg-mini" id="mvTo">
          <button data-c="game" class="on">游戏</button>
          <button data-c="tool">工具</button>
          <button data-c="literature">文学</button>
          <button data-c="fanart">二创</button>
        </div>
      </div>
      <button class="btn btn-sm" id="mvBtn" style="margin-top:8px">执行迁移</button>
    </div>`;
}

function workListBlock(editable) {
  return `
    <div class="panel-plain">
      <h3>作品管理</h3>
      <div class="prow">
        <input class="input" id="wq" placeholder="搜索标题 / 作者 / 作品 ID">
        <div class="seg seg-mini" id="wSizeSeg" role="group" aria-label="每页条数">
          <button data-sz="10">10</button>
          <button data-sz="20" class="on">20</button>
          <button data-sz="50">50</button>
        </div>
        <button class="btn btn-sm" id="wSearch">搜索</button>
      </div>
      <div id="workList"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="workPager"></div>
    </div>
    ${editable ? '<div id="editorHost"></div>' : ''}`;
}

/* ============================================================
 * 概览 / 图表
 * ============================================================ */
function renderOverview(box, ov, root) {
  const items = [
    ['用户总量', ov.users_total], ['今日新增', ov.users_today],
    ['在榜作品', ov.works_total], ['总点赞', ov.votes_total],
    ['评论数', ov.comments_total], ['消息数', ov.messages_total],
    ['反馈数', ov.feedback_total], ['累计登录', ov.logins_total],
    ['作品更新', ov.updates_total], ['AI 调用', ov.ai_calls_total],
    ['AI tokens', ov.ai_tokens_total],
    ['本站模型 tokens', ov.ai_tokens_glm], ['模型网关 tokens', ov.ai_tokens_gateway],
  ];
  if (Number(ov.ai_tokens_legacy || 0) > 0) { items.push(['早期未区分 tokens', ov.ai_tokens_legacy]); }
  box.innerHTML = items.map(x => `<div class="stat-box"><div class="k">${esc(x[0])}</div><div class="v">${Number(x[1] || 0)}</div></div>`).join('');
  const seg = (root || document).querySelector('#rangeSeg');
  if (seg && !seg.dataset.bound) {
    seg.dataset.bound = '1';
    seg.addEventListener('click', e => {
      const b = e.target.closest('[data-r]'); if (!b) return;
      seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      loadStats(b.dataset.r, root || document);
    });
  }
}

/* ============================================================
 * AI 用量排行（按通道分账）
 * 两种通道分开看：glm = 本站模型，gateway = 模型网关（免费模型）。
 * 「选了免费模型但实际回退到本站模型」的账记在本站模型，如实反映真实消耗。
 * ============================================================ */
/* AI 用量排行（按通道分账、可翻页）：主管理员与副管理员都能看 */
function aiRankBlock() {
  return `
    <div class="panel-plain">
      <h3>AI 用量排行</h3>
      <div class="seg" id="aiChSeg" style="margin:10px 0 8px">
        <button data-ch="" class="on">全部</button>
        <button data-ch="glm">本站模型</button>
        <button data-ch="gateway">模型网关</button>
      </div>
      <p class="tiny muted" id="aiChNote" style="margin-bottom:8px"></p>
      <div id="aiRank"><div class="skeleton" style="height:80px"></div></div>
      <div class="pager" id="aiRankPager"></div>
    </div>`;
}

function bindAiRank(container) {
  const seg = container.querySelector('#aiChSeg');
  const box = container.querySelector('#aiRank');
  const note = container.querySelector('#aiChNote');
  const pager = container.querySelector('#aiRankPager');
  if (!seg || !box) { return; }

  let ch = '';

  const paintNote = (s) => {
    if (!note || !s) { return; }
    const parts = ['本站模型 ' + Number(s.glm.tokens).toLocaleString() + ' tokens（' + Number(s.glm.calls) + ' 次）',
                   '模型网关 ' + Number(s.gateway.tokens).toLocaleString() + ' tokens（' + Number(s.gateway.calls) + ' 次）'];
    if (Number(s.legacy.tokens) > 0) { parts.push('分账前的旧记录 ' + Number(s.legacy.tokens).toLocaleString() + ' tokens'); }
    note.textContent = parts.join(' · ');
  };

  const load = async (page) => {
    const pg = Math.max(1, Number(page || 1));
    box.innerHTML = '<div class="skeleton" style="height:80px"></div>';
    if (pager) { pager.innerHTML = ''; }
    let d;
    try { d = await api('dashboard.php', 'ai_rank', { ch: ch, page: pg }, { silent: true }); }
    catch (e) { box.innerHTML = '<div class="tiny muted">读取失败，稍后再试</div>'; return; }
    paintNote(d.split);
    const items = (d && d.items) || [];
    if (!items.length) {
      box.innerHTML = '<div class="tiny muted">' + (pg > 1 ? '这一页没有记录了。' : '这条通道还没有用量记录。') + '</div>';
      if (pager && pg > 1) {
        pager.innerHTML = '<button class="btn-ghost btn-sm" data-pg="' + (pg - 1) + '">上一页</button>';
        pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => load(Number(b.dataset.pg))));
      }
      return;
    }
    const max = Math.max.apply(null, items.map(x => Number(x.tokens) || 0)) || 1;
    const from = (pg - 1) * (Number(d.size) || items.length);   // 序号跨页连续
    box.innerHTML = '<table class="table"><thead><tr><th>#</th><th>账号</th><th>tokens</th><th>调用</th></tr></thead><tbody>'
      + items.map((x, i) => `<tr>
        <td class="tiny">${from + i + 1}</td>
        <td>${userName(x.name, 'user')}</td>
        <td>${Number(x.tokens).toLocaleString()}
          <div style="height:4px;margin-top:4px;border-radius:2px;background:var(--border-soft)">
            <i style="display:block;height:100%;width:${Math.max(4, Math.round(Number(x.tokens) / max * 100))}%;border-radius:2px;background:var(--accent)"></i>
          </div>
        </td>
        <td class="tiny">${Number(x.calls)} 次</td>
      </tr>`).join('') + '</tbody></table>';

    if (pager) {
      const pages = Math.max(1, Number(d.total_pages || 1));
      pager.dataset.page = String(pg);
      pager.innerHTML = pages <= 1 ? ''
        : `<button class="btn-ghost btn-sm" data-pg="${pg - 1}" ${pg <= 1 ? 'disabled' : ''}>上一页</button>
           <span class="tiny">第 ${pg} / ${pages} 页 · 共 ${Number(d.total) || 0} 个账号</span>
           <button class="btn-ghost btn-sm" data-pg="${pg + 1}" ${pg >= pages ? 'disabled' : ''}>下一页</button>
           <button class="btn-ghost btn-sm" data-pg="${pages}" ${pg >= pages ? 'disabled' : ''}>末页</button>`;
      pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => load(Number(b.dataset.pg))));
    }
  };

  seg.addEventListener('click', e => {
    const b = e.target.closest('[data-ch]'); if (!b) return;
    seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    ch = b.dataset.ch;
    load(1);          // 换通道回到第一页
  });
  load(1);
}

async function loadStats(range, container) {
  const area = container.querySelector('#chartArea');
  if (!area) { return; }
  /* 图例与分布图的容器只有管理员面板才有（副管理员面板不给分布）——
     必须逐个判空。此前只挡了 chartArea，副管理员一进来就因 distArea 为 null
     抛「Cannot set properties of null」，再被 catch 吞成「加载失败」。 */
  const legend = container.querySelector('#legend');
  const dist   = container.querySelector('#distArea');
  try {
    const d = await api('dashboard.php', 'stats', { range: range });
    const series = d.series || [];
    const metrics = [
      { k: 'signups', n: '注册', c: '#7C3AED' }, { k: 'logins', n: '登录', c: '#007AFF' },
      { k: 'votes', n: '点赞', c: '#f59e0b' }, { k: 'work_updates', n: '作品更新', c: '#10b981' },
      { k: 'ai_calls', n: 'AI 调用', c: '#ef4444' }, { k: 'comments', n: '评论', c: '#8b5cf6' },
      { k: 'messages', n: '消息', c: '#06b6d4' },
    ];
    if (legend) { legend.innerHTML = metrics.map(m => `<span><i style="background:${m.c}"></i>${esc(m.n)}</span>`).join(''); }
    area.innerHTML = lineChart(d.labels || [], series, metrics);
    if (dist) {
      dist.innerHTML = `
        <div class="tiny" style="margin-bottom:6px">作品分类</div>
        ${barChart((d.category_dist || []).map(c => ({ n: catName(c.category), v: Number(c.n) })))}
        <div class="tiny" style="margin:10px 0 6px">评级分布</div>
        ${barChart((d.rating_dist || []).map(r => ({ n: r.rating, v: Number(r.n) })))}`;
    }
  } catch (e) {
    area.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`;
  }
}

function barChart(items) {
  const max = Math.max(1, ...items.map(i => i.v));
  return `<div class="chart-row">${items.map(i =>
    `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px" title="${esc(i.n)}：${i.v}">
       <span class="tiny" style="font-weight:700">${i.v}</span>
       <div class="bar" style="width:100%;height:${Math.max(3, Math.round(i.v / max * 104))}px"></div>
       <span class="tiny">${esc(i.n)}</span>
     </div>`).join('')}</div>`;
}

function lineChart(labels, series, metrics) {
  const W = 700, H = 160, pad = 24;
  const max = Math.max(1, ...series.flatMap(s => metrics.map(m => Number(s[m.k]) || 0)));
  const stepX = series.length > 1 ? (W - pad * 2) / (series.length - 1) : 0;
  let out = `<svg viewBox="0 0 ${W} ${H}" class="line-svg" preserveAspectRatio="none">`;
  for (let g = 0; g <= 4; g++) {
    const y = H - pad - (g / 4) * (H - pad * 2);
    out += `<line x1="${pad}" y1="${y.toFixed(1)}" x2="${W - pad}" y2="${y.toFixed(1)}" stroke="#eceef1"/>`;
    out += `<text x="4" y="${(y + 3).toFixed(1)}" font-size="9" fill="#a0a4ad">${Math.round(max * g / 4)}</text>`;
  }
  metrics.forEach(m => {
    const pts = series.map((s, i) => {
      const x = pad + i * stepX;
      const y = H - pad - ((Number(s[m.k]) || 0) / max) * (H - pad * 2);
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');
    out += `<polyline points="${pts}" fill="none" stroke="${m.c}" stroke-width="2" stroke-linejoin="round"/>`;
  });
  const every = Math.max(1, Math.ceil(series.length / 8));
  series.forEach((s, i) => {
    if (i % every !== 0) return;
    out += `<text x="${(pad + i * stepX).toFixed(0)}" y="${H - 6}" font-size="9" fill="#8a8f99" text-anchor="middle">${esc(labels[i] || '')}</text>`;
  });
  return out + '</svg>';
}

/* ============================================================
 * 社区凭证 · 扫码填写
 * 仿 Kimi 官方 web 端登录：向 auth.kimi.com 申请登录码 → 渲染官网同款二维码
 *   https://www.kimi.com/wechat/mp/auth?id=<code>&device_id=<webId>
 * 微信与 Kimi App 扫的都是这一张 → 轮询 GetLoginQRCodeStatus，SUCCESS 时取回登录态。
 * 轮询由 Web Worker 心跳驱动：手机确认往往发生在管理员切走标签页之后，而后台标签页
 * 会节流甚至冻结主线程定时器，用 Worker 才不会漏掉那次「已确认」。
 * ============================================================ */
const TOKQR_AUTH = 'https://auth.kimi.com/api/account.gateway.v1.AuthService/';
const TOKQR_LIBS = [
  'https://gcore.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js',
  'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js',
];
let tokqrLibP = null;
let tokqrBeat = null, tokqrBeatUrl = '', tokqrTimer = 0, tokqrActive = false;
let tokqrBusy = false, tokqrLast = 0, tokqrCode = '', tokqrUntil = 0;
let tokqrTick = null;   /* 单独声明：构建闸门只认「let 后第一个标识符」 */
let tokqrOnVis = null;

function tokqrRpc(method, payload) {
  return fetch(TOKQR_AUTH + method, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload || {}),
    credentials: 'omit',
    cache: 'no-store',
  }).then(r => {
    if (!r.ok) { throw new Error('HTTP ' + r.status); }
    return r.json();
  });
}

function tokqrLib() {
  if (typeof window.qrcode === 'function') { return Promise.resolve(true); }
  if (tokqrLibP) { return tokqrLibP; }
  tokqrLibP = new Promise(resolve => {
    let i = 0;
    const next = () => {
      if (i >= TOKQR_LIBS.length) { resolve(false); return; }
      const s = document.createElement('script');
      s.src = TOKQR_LIBS[i++];
      s.onload = () => resolve(typeof window.qrcode === 'function');
      s.onerror = next;
      document.head.appendChild(s);
    };
    next();
  });
  return tokqrLibP;
}

function tokqrPaint(box, text) {
  box.innerHTML = '';
  if (typeof window.qrcode !== 'function') { box.textContent = text; return false; }
  try {
    const qr = window.qrcode(0, 'M');
    qr.addData(text); qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    const svg = box.querySelector('svg');
    if (svg) { svg.setAttribute('width', '100%'); svg.setAttribute('height', '100%'); }
    return true;
  } catch (e) { box.textContent = text; return false; }
}

/* 设备标识：官网取火山引擎的 webId；本站拿不到，用一个持久化的等价标识 */
function tokqrWid() {
  let id = '';
  try { id = localStorage.getItem('kimi_qr_wid') || ''; } catch (e) {}
  if (id) { return id; }
  const a = new Uint8Array(8);
  try { crypto.getRandomValues(a); } catch (e) { for (let i = 0; i < 8; i++) { a[i] = Math.floor(Math.random() * 256); } }
  id = 'w' + Date.now().toString(36) + Array.from(a).map(x => x.toString(16).padStart(2, '0')).join('');
  try { localStorage.setItem('kimi_qr_wid', id); } catch (e) {}
  return id;
}

function tokqrHint(kind, text) {
  const el = document.getElementById('tokQRHint');
  if (!el) { return; }
  el.className = 'qr-hint' + (kind ? ' ' + kind : '');
  el.textContent = text;
}

function tokqrStop() {
  tokqrActive = false;
  if (tokqrTimer) { clearTimeout(tokqrTimer); tokqrTimer = 0; }
  if (tokqrBeat) { try { tokqrBeat.terminate(); } catch (e) {} tokqrBeat = null; }
  if (tokqrBeatUrl) { try { URL.revokeObjectURL(tokqrBeatUrl); } catch (e) {} tokqrBeatUrl = ''; }
  if (tokqrOnVis) { try { document.removeEventListener('visibilitychange', tokqrOnVis); } catch (e) {} tokqrOnVis = null; }
  tokqrBusy = false;
}

/* Web Worker 心跳；环境禁用 Blob Worker（如严格 CSP）时返回 false，调用方回退主线程 setTimeout */
function tokqrHeartbeat(ms, fn) {
  try {
    const src = 'var t=null;self.onmessage=function(e){if(e.data==="start"){if(t)clearInterval(t);t=setInterval(function(){self.postMessage(1)},' + ms + ');}else if(e.data==="stop"){if(t)clearInterval(t);t=null;}};';
    tokqrBeatUrl = URL.createObjectURL(new Blob([src], { type: 'text/javascript' }));
    tokqrBeat = new Worker(tokqrBeatUrl);
    tokqrBeat.onmessage = () => { try { fn(); } catch (e) {} };
    tokqrBeat.postMessage('start');
    return true;
  } catch (e) {
    if (tokqrBeat) { try { tokqrBeat.terminate(); } catch (e2) {} tokqrBeat = null; }
    if (tokqrBeatUrl) { try { URL.revokeObjectURL(tokqrBeatUrl); } catch (e2) {} tokqrBeatUrl = ''; }
    return false;
  }
}

/* 登录响应里挑 accessToken（字段名不确定，逐层兜底） */
function tokqrToken(d) {
  if (!d || typeof d !== 'object') { return ''; }
  for (const k of Object.keys(d)) {
    const v = d[k];
    if (v && typeof v === 'object') {
      for (const kk of ['accessToken', 'access_token', 'token']) {
        if (typeof v[kk] === 'string' && v[kk]) { return v[kk]; }
      }
    }
  }
  return '';
}

async function tokqrStart() {
  tokqrStop();
  if (!document.getElementById('tokQRBox')) { return; }
  document.getElementById('tokQRBox').innerHTML = '<span class="muted tiny">正在获取二维码…</span>';
  tokqrHint('', '正在获取二维码…');
  if (!(await tokqrLib()) || !document.getElementById('tokQRBox')) {
    tokqrHint('bad', '二维码组件加载失败，可改用「下载 Token 脚本」');
    return;
  }
  let code = '';
  try {
    const d = await tokqrRpc('CreateLoginQRCode', {});
    code = (d && d.code) ? String(d.code) : '';
  } catch (e) {
    tokqrHint('bad', '获取二维码失败：' + (e.message || '网络错误'));
    return;
  }
  if (!code || !document.getElementById('tokQRBox')) {
    tokqrHint('bad', '服务端未返回登录码，可改用「下载 Token 脚本」');
    return;
  }
  tokqrCode = code;
  const url = 'https://www.kimi.com/wechat/mp/auth?id=' + encodeURIComponent(code)
            + '&device_id=' + encodeURIComponent(tokqrWid());
  if (!tokqrPaint(document.getElementById('tokQRBox'), url)) {
    tokqrHint('bad', '二维码渲染失败，可改用「下载 Token 脚本」');
    return;
  }
  tokqrHint('', '请用 微信 或 Kimi App 扫码');

  tokqrActive = true;
  tokqrUntil = Date.now() + 5 * 60 * 1000;
  tokqrLast = 0; tokqrBusy = false;
  tokqrTick = async () => {
    if (!tokqrActive) { return; }
    const now = Date.now();
    if (tokqrBusy || now - tokqrLast < 900) { return; }        // 防重入 + 去抖
    if (!document.getElementById('tokQRBox') || !tokqrCode) { tokqrStop(); return; }
    if (now > tokqrUntil) { tokqrHint('bad', '二维码已过期，点「刷新二维码」重试'); tokqrStop(); return; }
    tokqrLast = now; tokqrBusy = true;
    try {
      const d = await tokqrRpc('GetLoginQRCodeStatus', { code: tokqrCode });
      const st = String((d && d.status) || '');
      if (st.indexOf('SUCCESS') > -1) {
        const tok = (d && (d.accessToken || d.access_token || d.token)) || tokqrToken(d);
        if (tok) {
          const inp = document.getElementById('tokInput');
          if (inp) { inp.value = tok; }
          tokqrHint('ok', '已填入输入框，点「仅保存凭证」完成');
          tokqrStop();
          toast('已获取登录态，点「仅保存凭证」保存');
        } else {
          tokqrHint('bad', '登录成功但未取到令牌，可改用「下载 Token 脚本」');
          tokqrStop();
        }
        return;
      }
      if (st.indexOf('EXPIRED') > -1) {
        tokqrHint('bad', '二维码已过期，点「刷新二维码」重试'); tokqrStop(); return;
      }
      const scanned = st.indexOf('SCANNED') > -1;
      tokqrHint(scanned ? 'wait' : '', scanned ? '已扫码，请在手机上确认' : '请用 微信 或 Kimi App 扫码');
    } catch (e) {
      tokqrLast = 0;                                           // 网络抖动：保留轮询，下一拍重试
    } finally { tokqrBusy = false; }
  };
  if (!tokqrHeartbeat(1200, tokqrTick)) {                       // 主线程定时器兜底
    const loop = () => {
      if (!tokqrActive) { return; }
      Promise.resolve(tokqrTick()).finally(() => { if (tokqrActive) { tokqrTimer = setTimeout(loop, 1200); } });
    };
    tokqrTimer = setTimeout(loop, 1200);
  }
  tokqrOnVis = () => { if (!document.hidden) { tokqrTick(); } };   // 回到前台立即补一次
  document.addEventListener('visibilitychange', tokqrOnVis);
}

function bindTokenScan(container) {
  const scanBtn = container.querySelector('#tokScanBtn');
  const wrap = container.querySelector('#tokQR');
  if (!scanBtn || !wrap) { return; }
  tokqrStop();                                                 // 重新渲染时先收掉上一次的等待
  scanBtn.addEventListener('click', () => {
    const open = wrap.hidden;
    wrap.hidden = !open;
    scanBtn.classList.toggle('is-open', open);
    if (open) { tokqrStart(); } else { tokqrStop(); }
  });
  const rf = container.querySelector('#tokQRRefresh');
  if (rf) { rf.addEventListener('click', tokqrStart); }
}

/* ============================================================
 * 凭证 / 添加作品（含快捷搜索）
 * ============================================================ */
function bindCredential(container) {
  bindTokenScan(container);
  const btn = container.querySelector('#saveTokBtn');
  if (btn) {
    btn.addEventListener('click', async () => {
      const t = container.querySelector('#tokInput').value.trim();
      if (!t) { toast('请填写 cookie/token', 'err'); return; }
      try { await api('admin.php', 'token_set', { kimi_token: t }); container.querySelector('#tokInput').value = ''; toast('已加密保存'); }
      catch (e) { toast(e.message, 'err'); }
    });
  }

  /* Token 脚本下载：源站无 CORS 头，跨域下 download 属性会被忽略，故由本站中转取回。
     主管理员与副管理员均可用（各自取得自己的 Token）。 */
  const scriptBtn = container.querySelector('#tokenScriptBtn');
  if (!scriptBtn) return;
  const SCRIPT_SRC = 'https://harbor-ljmr.upma.site/Token_acquisition.js';
  scriptBtn.addEventListener('click', async () => {
    btnLoading(scriptBtn, true);
    try {
      const headers = { 'Accept': 'text/javascript' };
      if (state.token) {
        headers['Authorization'] = 'Bearer ' + state.token;
        headers['X-Token'] = state.token;
      }
      const resp = await fetch('api/admin.php?action=userscript', { headers, credentials: 'same-origin' });
      if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
      const blob = await resp.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'Token_acquisition.js';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => window.URL.revokeObjectURL(url), 5000);
      toast('脚本已开始下载');
    } catch (e) {
      window.open(SCRIPT_SRC, '_blank', 'noopener');   // 中转不通就直接给出源地址
      toast('中转下载失败，已打开源地址', 'err');
    } finally { btnLoading(scriptBtn, false); }
  });
}

function bindAddWork(container) {
  let addMode = 'manual';
  refreshLinkState(container);      // 收录区显示当前开关状态
  const seg = container.querySelector('#addModeSeg');
  seg.addEventListener('click', e => {
    const b = e.target.closest('[data-m]'); if (!b) return;
    addMode = b.dataset.m;
    seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    container.querySelector('#workIdField').hidden = addMode !== 'manual';
  });

  // 快捷搜索作品（按标题/作者/ID）
  const idInput = container.querySelector('#workIdInput');
  const sug = container.querySelector('#workSug');
  let timer = null;
  idInput.addEventListener('input', () => {
    clearTimeout(timer);
    const q = idInput.value.trim();
    if (q.length < 1) { sug.hidden = true; sug.innerHTML = ''; return; }
    timer = setTimeout(async () => {
      try {
        const d = await api('admin.php', 'search_works', { q: q }, { silent: true });
        if (!d.items.length) { sug.hidden = true; sug.innerHTML = ''; return; }
        sug.innerHTML = d.items.map(w =>
          `<button type="button" data-cid="${esc(w.community_id)}"><b>${esc(w.title)}</b>
            <span class="tiny">${esc(w.author)} · ${esc(w.community_id)} · ${w.total}分</span></button>`).join('');
        sug.hidden = false;
        sug.querySelectorAll('[data-cid]').forEach(b => b.addEventListener('click', () => {
          idInput.value = b.dataset.cid; sug.hidden = true;
        }));
      } catch (e) { sug.hidden = true; }
    }, 320);
  });
  idInput.addEventListener('blur', () => setTimeout(() => { sug.hidden = true; }, 160));

  container.querySelector('#addBtn').addEventListener('click', async () => {
    const t = container.querySelector('#tokInput').value.trim();
    const body = { mode: addMode };
    if (t) body.kimi_token = t;
    if (addMode === 'manual') {
      body.work_id = idInput.value.trim();
      if (!body.work_id) { toast('请填写作品 ID', 'err'); return; }
    }
    const btn = container.querySelector('#addBtn');
    btnLoading(btn, true); btn.textContent = '处理中…';
    try {
      const r = await api('admin.php', 'add_work', body, { timeout: 60000 });
      const noScore = r.scored === false ? '（未改动评分）' : '';
      const syncNote = r.scored === false ? '（已有作品未重算评分）' : '';
      const linkNote = r.link_real ? '（已改用真实地址）' : '';
      toast(addMode === 'auto'
        ? ('同步完成：新增 ' + r.inserted + '，更新 ' + r.updated + syncNote)
        : ('收录' + (r.status === 'updated' ? '并更新' : '') + '成功' + noScore + linkNote));
      loadWorks(container, container.querySelector('#wq').value.trim(), state.role === 'subadmin');
    } catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(btn, false); btn.textContent = '开始'; }
  });
}

/* ============================================================
 * 分区：自动判定与批量迁移
 * ------------------------------------------------------------
 * 预览与执行是同一套判定（服务端同一函数），所以「预览看到的」
 * 就是「执行会做的」，不存在预览一次、执行又是另一回事的情况。
 * ============================================================ */
function bindReclassify(container) {
  const pBtn = container.querySelector('#rcPreviewBtn');
  const aBtn = container.querySelector('#rcApplyBtn');
  const prog = container.querySelector('#rcProg');
  const bar  = prog ? prog.querySelector('i') : null;
  const text = container.querySelector('#rcText');
  const box  = container.querySelector('#rcPreview');
  if (!pBtn) { return; }

  const catName = c => ({ game: '游戏', tool: '工具', literature: '文学', fanart: '二创' }[c] || c);

  /* ---------- 预览 ---------- */
  pBtn.addEventListener('click', async () => {
    pBtn.disabled = true;
    box.hidden = false;
    box.innerHTML = '<div class="skeleton" style="height:64px"></div>';
    try {
      const d = await api('admin.php', 'reclassify_preview', {});
      const c = d.counts;
      let html = '<div class="tiny" style="line-height:1.9">'
        + '共扫描 <b>' + c.total + '</b> 件 · 需要变更 <b>' + c.changed + '</b> 件 · '
        + '判定一致 ' + c.keep + ' 件'
        + (c.default_game ? ' · 其中靠默认归入游戏 ' + c.default_game + ' 件' : '')
        + '</div>';

      if (d.sample && d.sample.length) {
        html += '<div style="margin-top:10px;max-height:260px;overflow:auto">'
          + '<table class="table"><thead><tr><th>作品</th><th>现在</th><th>将改为</th></tr></thead><tbody>'
          + d.sample.map(x => '<tr><td>' + esc(x.title) + '</td><td>' + catName(x.from) + '</td><td><b>' + catName(x.to) + '</b></td></tr>').join('')
          + '</tbody></table></div>';
        if (c.changed > d.sample.length) {
          html += '<div class="tiny muted" style="margin-top:6px">仅列出前 ' + d.sample.length + ' 条，执行时会处理全部。</div>';
        }
      } else {
        html += '<div class="tiny muted" style="margin-top:8px">没有需要变更的作品。</div>';
      }

      html += '<details style="margin-top:12px"><summary class="tiny" style="cursor:pointer">判定规则</summary>'
        + '<div class="tiny muted" style="line-height:1.9;margin-top:6px">'
        + Object.keys(d.rules).map(k => '<div><b>' + esc(k) + '</b>：' + esc(d.rules[k]) + '</div>').join('')
        + '</div></details>';

      box.innerHTML = html;
      aBtn.disabled = c.changed === 0;
    } catch (e) {
      box.innerHTML = '<div class="tiny" style="color:var(--danger)">' + esc(e.message) + '</div>';
    }
    pBtn.disabled = false;
  });

  /* ---------- 执行（分批） ---------- */
  let running = false;
  aBtn.addEventListener('click', async () => {
    if (running) { return; }
    if (!(await dialog('执行分区变更', '将按预览结果修改作品分区，确定继续？', '执行'))) { return; }
    running = true; aBtn.disabled = true; pBtn.disabled = true;
    prog.hidden = false;
    let offset = 0, applied = 0;

    while (true) {
      let r;
      try { r = await api('admin.php', 'reclassify_apply', { offset: offset }, { timeout: 90000, silent: true }); }
      catch (e) { text.textContent = '中断：' + e.message; break; }

      applied += r.applied;
      const pct = r.total > 0 ? Math.round(r.done / r.total * 100) : 100;
      bar.style.width = pct + '%';
      text.textContent = pct + '%（' + r.done + '/' + r.total + '）· 已变更 ' + applied + ' 件';

      if (r.finished || r.done <= offset) {
        if (r.finished) { text.textContent += ' · 完成'; toast('分区变更完成，共 ' + applied + ' 件'); }
        break;
      }
      offset = r.done;
      await sleep(200);
    }
    running = false; aBtn.disabled = false; pBtn.disabled = false;
    loadWorks(container, container.querySelector('#wq').value.trim(), false);
  });

  /* ---------- 手工批量迁移 ---------- */
  let mvFrom = 'all', mvTo = 'game';
  const fSeg = container.querySelector('#mvFrom');
  const tSeg = container.querySelector('#mvTo');
  if (fSeg) {
    fSeg.addEventListener('click', e => {
      const b = e.target.closest('[data-c]'); if (!b) { return; }
      mvFrom = b.dataset.c;
      fSeg.querySelectorAll('[data-c]').forEach(x => x.classList.toggle('on', x === b));
    });
  }
  if (tSeg) {
    tSeg.addEventListener('click', e => {
      const b = e.target.closest('[data-c]'); if (!b) { return; }
      mvTo = b.dataset.c;
      tSeg.querySelectorAll('[data-c]').forEach(x => x.classList.toggle('on', x === b));
    });
  }
  const mvBtn = container.querySelector('#mvBtn');
  if (mvBtn) {
    mvBtn.addEventListener('click', async () => {
      if (mvFrom === mvTo) { toast('来源与目标相同', 'err'); return; }
      const fromLabel = mvFrom === 'all' ? '全部作品' : catName(mvFrom) + '类全部作品';
      if (!(await dialog('批量迁移分区', '将把「' + fromLabel + '」迁移到「' + catName(mvTo) + '」，确定？', '迁移'))) { return; }
      mvBtn.disabled = true;
      try {
        const r = await api('admin.php', 'move_category', { from: mvFrom, to: mvTo });
        toast('已迁移 ' + r.moved + ' 件作品');
        loadWorks(container, container.querySelector('#wq').value.trim(), false);
      } catch (e) { toast(e.message, 'err'); }
      mvBtn.disabled = false;
    });
  }
}

/* ============================================================
 * 一键更新（分批 + 进度条）
 * ============================================================ */
function bindRefresh(container) {
  const btn = container.querySelector('#refreshBtn');
  const stopBtn = container.querySelector('#refreshStop');
  const prog = container.querySelector('#refreshProg');
  const bar = prog ? prog.querySelector('i') : null;
  const text = container.querySelector('#refreshText');

  if (!btn) return;
  refreshLinkState(container);      // 更新区显示当前开关状态

  let running = false, stopped = false;

  btn.addEventListener('click', async () => {
    if (running) return;
    running = true; stopped = false;
    btn.disabled = true; stopBtn.hidden = false; prog.hidden = false;
    let plan = [], total = 0, done = 0, okN = 0, failN = 0, links = 0, scoreOn = true;
    const paint = () => {
      const pct = total > 0 ? Math.min(100, Math.round(done / total * 100)) : 100;
      bar.style.width = pct + '%';
      text.textContent = pct + '%（' + done + '/' + total + '）· 成功 ' + okN + ' · 失败 ' + failN
        + (links ? ' · 识别真实链接 ' + links : '')
        + (scoreOn ? '' : ' · 未重算评分');
    };

    /* 第一步：取回按排名（总分从高到低）排好的名单，整轮顺序就此固定 */
    try {
      const p = await api('admin.php', 'refresh_plan', {}, { timeout: 90000, silent: true });
      plan = p.ids || [];
      total = Number(p.total) || plan.length;
      scoreOn = p.scored !== false;
      if (p.capped) { text.textContent = '作品数超过单次上限，本轮先更新前 ' + plan.length + ' 件'; }
    } catch (e) {
      text.textContent = '中断：' + e.message;
      running = false; btn.disabled = false; stopBtn.hidden = true;
      return;
    }
    paint();

    /* 第二步：按名单分批更新，每批 5 件 */
    for (let i = 0; i < plan.length && !stopped; i += 5) {
      let r;
      try { r = await api('admin.php', 'refresh_batch', { ids: plan.slice(i, i + 5) }, { timeout: 90000, silent: true }); }
      catch (e) { text.textContent = '中断：' + e.message; stopped = true; break; }

      okN += r.ok; failN += r.fail; done += Number(r.processed) || 0;
      links += Number(r.links) || 0;
      paint();
      await sleep(260);   // 降低共享主机并发压力
    }
    if (stopped) {
      text.textContent += ' · 已停止';
      toast('已停止更新');
    } else {
      done = Math.max(done, total); paint();
      text.textContent += ' · 完成';
      toast(scoreOn ? '全部作品已更新' : '全部作品已更新（未改动评分）');
    }
    running = false; btn.disabled = false; stopBtn.hidden = true;
    loadWorks(container, container.querySelector('#wq').value.trim(), false);
  });

  stopBtn.addEventListener('click', () => { stopped = true; });
}

/* ============================================================
 * 作品列表 / 编辑
 * ============================================================ */
/** 当前选择的每页条数 */
function workPageSize(container) {
  const on = container.querySelector('#wSizeSeg button.on');
  return on ? Number(on.dataset.sz) : 20;
}

/**
 * 作品编辑器（仅主管理员）：改标题 / 作者 / 分类 / 简介 / 链接 / 封面图集 /
 * 上下架与六维明细，保存走 admin.php?action=work_save。
 *
 * 弹层复用站点通用的 #dialogScrim + #dialog（配 .dialog.wide 宽度），
 * 分类与上下架用站内的分段控件，不用原生 select。
 */
function openEditor(container, id, onSaved) {
  const scrim = document.getElementById('dialogScrim');
  const box = document.getElementById('dialog');
  if (!scrim || !box) { return; }

  api('admin.php', 'work_get', { id: id }).then(w => {
    const sc = w.score || {};
    let cat = String(w.category || 'game');
    let hidden = !!w.hidden;

    box.className = 'dialog glass wide';
    box.innerHTML = `
      <h3>编辑作品 #${Number(w.id)}</h3>
      <p class="tiny muted">社区 ID ${esc(w.community_id)} · 改动六维会重算总分与评级（每维 0–200）</p>

      <div class="field"><label>标题</label>
        <input class="input" id="edTitle" type="text" value="${esc(w.title)}" autocomplete="off"></div>

      <div class="field"><label>作者</label>
        <input class="input" id="edAuthor" type="text" value="${esc(w.author)}" autocomplete="off"></div>

      <div class="field"><label>分类</label>
        <div class="seg" id="edCat">
          ${PCATS.map(c => `<button data-c="${c[0]}" class="${c[0] === cat ? 'on' : ''}">${c[1]}</button>`).join('')}
        </div></div>

      <div class="field"><label>简介</label>
        <textarea class="input" id="edIntro" rows="5">${esc(w.intro)}</textarea></div>

      <div class="field"><label>分享链接</label>
        <input class="input" id="edShare" type="text" value="${esc(w.share_link)}" autocomplete="off"></div>

      <div class="field"><label>社区链接</label>
        <input class="input" id="edHtml" type="text" value="${esc(w.html_url)}" autocomplete="off"></div>

      <div class="field"><label>封面图地址</label>
        <input class="input" id="edCover" type="text" value="${esc(w.cover)}" autocomplete="off"></div>

      <div class="field"><label>图集（每行一个地址，最多 9 张）</label>
        <textarea class="input" id="edGallery" rows="3">${esc(w.gallery)}</textarea></div>

      <div class="field"><label>上架状态</label>
        <div class="seg" id="edHidden">
          <button data-h="0" class="${hidden ? '' : 'on'}">在榜</button>
          <button data-h="1" class="${hidden ? 'on' : ''}">已下架</button>
        </div></div>

      <div class="field"><label>六维明细</label></div>
      <div class="ed-grid" id="edDims">
        ${PDIMS.map(d => `<div>
          <label class="tiny">${d.n}</label>
          <input class="input" data-dim="${d.k}" type="text" inputmode="numeric"
                 value="${Number(sc[d.k] || 0)}" autocomplete="off"></div>`).join('')}
      </div>

      <div class="dialog-actions">
        <button class="btn-ghost" id="edCancel">取消</button>
        <button class="btn" id="edSave">保存</button>
      </div>`;

    scrim.hidden = false;

    const close = () => { scrim.hidden = true; box.className = 'dialog glass'; box.innerHTML = ''; };
    scrim.onclick = e => { if (e.target === scrim) { close(); } };

    box.querySelector('#edCat').addEventListener('click', e => {
      const b = e.target.closest('[data-c]');
      if (!b) { return; }
      cat = b.dataset.c;
      box.querySelectorAll('#edCat button').forEach(x => x.classList.toggle('on', x === b));
    });
    box.querySelector('#edHidden').addEventListener('click', e => {
      const b = e.target.closest('[data-h]');
      if (!b) { return; }
      hidden = b.dataset.h === '1';
      box.querySelectorAll('#edHidden button').forEach(x => x.classList.toggle('on', x === b));
    });
    box.querySelector('#edCancel').addEventListener('click', close);

    box.querySelector('#edSave').addEventListener('click', async () => {
      const btn = box.querySelector('#edSave');
      const val = sel => { const el = box.querySelector(sel); return el ? el.value.trim() : ''; };
      const payload = {
        id: id,
        title: val('#edTitle'), author: val('#edAuthor'), intro: val('#edIntro'),
        category: cat, share_link: val('#edShare'), html_url: val('#edHtml'),
        cover: val('#edCover'), gallery: val('#edGallery'), hidden: hidden ? 1 : 0,
      };
      box.querySelectorAll('[data-dim]').forEach(inp => {
        const n = parseInt(String(inp.value).replace(/[^0-9]/g, ''), 10);
        payload['dim_' + inp.dataset.dim] = isNaN(n) ? 0 : Math.max(0, Math.min(200, n));
      });
      btnLoading(btn, true);
      try {
        await api('admin.php', 'work_save', payload);
        toast('已保存');
        close();
        if (typeof onSaved === 'function') { onSaved(); }
      } catch (e) { toast(e.message, 'err'); btnLoading(btn, false); }
    });
  }).catch(e => toast(e.message, 'err'));
}

async function loadWorks(container, q, readOnly, page) {
  const box = container.querySelector('#workList');
  const pager = container.querySelector('#workPager');
  const pg = Math.max(1, Number(page || 1));
  const size = workPageSize(container);
  try {
    const d = await api('admin.php', 'works', { q: q || '', page: pg, size: size });
    const total = Number(d.total || 0);
    const pages = Math.max(1, Number(d.total_pages || 1));

    if (!d.items.length) {
      box.innerHTML = '<div class="tiny">没有匹配的作品</div>';
    } else {
      box.innerHTML = `<table class="table"><thead><tr><th>标题</th><th>分类</th><th>总分</th><th>状态</th><th>操作</th></tr></thead><tbody>` +
        d.items.map(w => `<tr data-id="${w.id}">
          <td>${esc(w.title)}<div class="tiny">${esc(w.community_id)}</div></td>
          <td>${esc(catName(w.category))}</td>
          <td>${Number(w.total_score)}</td>
          <td>${Number(w.is_hidden) === 1 ? '已下架' : '在榜'}</td>
          <td>${readOnly ? '<span class="tiny">—</span>' : `
            <button class="btn-ghost btn-sm" data-act="edit">编辑</button>
            <button class="btn-ghost btn-sm" data-act="toggle">${Number(w.is_hidden) === 1 ? '恢复' : '下架'}</button>
            <button class="btn-ghost btn-sm" data-act="del">删除</button>`}
          </td></tr>`).join('') + '</tbody></table>';
    }

    if (pager) {
      pager.dataset.page = String(pg);           // 供自动轮询沿用当前页
      pager.innerHTML = total === 0 ? ''
        : `<button class="btn-ghost btn-sm" data-pg="1" ${pg <= 1 ? 'disabled' : ''}>首页</button>
           <button class="btn-ghost btn-sm" data-pg="${pg - 1}" ${pg <= 1 ? 'disabled' : ''}>上一页</button>
           <span class="tiny">第 ${pg} / ${pages} 页 · 共 ${total} 件</span>
           <button class="btn-ghost btn-sm" data-pg="${pg + 1}" ${pg >= pages ? 'disabled' : ''}>下一页</button>
           <button class="btn-ghost btn-sm" data-pg="${pages}" ${pg >= pages ? 'disabled' : ''}>末页</button>`;
      pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => {
        const card = box.closest('.panel-plain');
        loadWorks(container, q, readOnly, Number(b.dataset.pg)).then(() => {
          /* 换页不回到页面头部：仅当列表标题已滚出视口时，回到「作品管理」顶部 */
          if (card) {
            const top = card.getBoundingClientRect().top;
            if (top < 0) { card.scrollIntoView({ block: 'start' }); }
          }
        });
      }));
    }

    if (readOnly || !d.items.length) { return; }
    box.querySelectorAll('tr[data-id]').forEach(tr => {
      const id = tr.dataset.id;
      const hidden = tr.children[3].textContent === '已下架';
      tr.querySelector('[data-act="edit"]').addEventListener('click', () => openEditor(container, id, () => loadWorks(container, q, false, pg)));
      tr.querySelector('[data-act="toggle"]').addEventListener('click', async () => {
        try { await api('admin.php', 'work_save', { id: id, hidden: hidden ? 0 : 1 }); toast('已更新'); loadWorks(container, q, false, pg); }
        catch (e) { toast(e.message, 'err'); }
      });
      tr.querySelector('[data-act="del"]').addEventListener('click', async () => {
        if (await dialog('删除作品', '删除后不可恢复，确认？', '删除', { danger: true })) {
          try { await api('admin.php', 'work_delete', { id: id }); toast('已删除'); loadWorks(container, q, false, pg); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
    });
  } catch (e) {
    box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`;
    if (pager) { pager.innerHTML = ''; }
  }
}

/** 访问排行：国家 / 省份 / 城市 三个维度各前十五 */
async function loadVisitsTop(container) {
  const box = container.querySelector('#visitsTop');
  if (!box || !box.isConnected) { return; }
  try {
    const d = await api('admin.php', 'visits_top');
    const col = (title, arr) => {
      if (!arr || !arr.length) { return `<div class="tp-col"><div class="tp-h">${title}</div><div class="tiny muted">暂无数据</div></div>`; }
      const max = arr.reduce((m, x) => Math.max(m, Number(x.count) || 0), 1);
      const rows = arr.map((x, i) =>
        `<div class="tp-row"><span class="tp-i">${i + 1}</span><span class="tp-n" title="${esc(x.name)}">${esc(x.name)}</span>
         <span class="tp-bar"><i style="width:${Math.max(3, Math.round((Number(x.count) || 0) / max * 100))}%"></i></span>
         <span class="tp-c">${Number(x.count) || 0}</span></div>`).join('');
      return `<div class="tp-col"><div class="tp-h">${title}</div>${rows}</div>`;
    };
    const total = Number(d.total) || 0;
    const located = Number(d.located) || 0;
    let note;
    if (total === 0) {
      note = '暂无访问记录。只有登录用户在「我的 → 记录访问信息」开启后，登录时才会写入一条记录。';
    } else if (located === 0) {
      note = `共 ${total} 条记录，但归属地服务没有返回地区信息（第三方接口不可用或未配置），因此只显示「未知」。`;
    } else {
      note = `累计 ${total} 条记录，其中 ${located} 条已解析归属地${total - located > 0 ? `，${total - located} 条无归属地` : ''}。`;
    }
    const empty = located === 0;
    box.innerHTML = `<div class="tiny muted" style="margin-bottom:10px;line-height:1.6">${note}</div>
      ${empty ? '' : `<div class="tp-grid">
        ${col('国家 / 地区', d.country)}${col('省份', d.province)}${col('城市', d.city)}
      </div>`}`;
  } catch (e) {
    box.innerHTML = `<div class="tiny muted">${esc(e.message || '加载失败')}</div>`;
  }
}

/* ============================================================
 * 违纪通报管理
 * ============================================================
 * 列出全部通报，可「设置」二次调整：理由、说明、封禁天数（重算解封时间）、
 * 立即解封、IP 封禁开关。累计被通报（单次封禁 ≥7 天）达 10 次的账号会被永久删除。
 */

/* ============================================================
 * 站点互通（多站互为镜像）
 * ============================================================
 * 站点列表 + 一键互通。数据传输先压缩再用非对称加密（每站一把 64 位私钥），
 * 两边密钥对不上就同步不了；世界对话 / AI 对话等实时数据不参与。
 */
function peerBlock() {
  return `
    <div class="panel-plain">
      <h3>站点互通</h3>
      <p class="tiny muted">
        把几个站点配成一组、互为镜像：作品、评分、评论、账号双向同步，最终内容一致。
        站间数据先压缩再加密，两边密钥对不上就同步不了；世界对话、AI 对话等实时数据不参与。
      </p>
      <div class="prow" style="flex-wrap:wrap;gap:8px">
        <span class="tiny">本站公钥：<code id="peerMyKey" style="user-select:all">读取中…</code></span>
      </div>
      <div class="prow" style="margin-top:8px;gap:8px">
        <button class="btn btn-sm" id="peerSyncAll">一键互通</button>
        <button class="btn btn-sm" id="peerAdd">添加站点</button>
      </div>
      <div id="peerForm"></div>
      <div id="peerList" style="margin-top:10px"></div>
    </div>`;
}

async function loadPeer(container) {
  const keyEl   = container.querySelector('#peerMyKey');
  const listEl  = container.querySelector('#peerList');
  const formEl  = container.querySelector('#peerForm');
  const syncBtn = container.querySelector('#peerSyncAll');
  const addBtn  = container.querySelector('#peerAdd');
  if (!listEl) { return; }

  const st = await api('admin.php', 'peers_state', {}, { silent: true }).catch(() => null);
  if (!st) { listEl.innerHTML = '<div class="tiny muted">读取失败</div>'; return; }
  keyEl.textContent = !st.sodium
    ? '主机未启用 sodium 扩展，互通不可用'
    : (st.my_key_set ? (st.my_pubkey || '推导失败') : '未配置私钥（请在 config 的 peers.private_key 填 64 位十六进制）');

  let rows = st.peers || [];
  const drawList = () => {
    if (!rows.length) { listEl.innerHTML = '<div class="tiny muted">还没有配置任何站点。</div>'; return; }
    listEl.innerHTML = rows.map(p => `
      <div class="prow" style="padding:8px 0;align-items:center;flex-wrap:wrap;gap:6px">
        <span class="tiny" style="flex:1;min-width:180px">
          <b>${esc(p.name || p.base_url)}</b> · ${esc(p.base_url)}<br>
          <span class="muted">${p.enabled ? '' : '[已停用] '}${p.last_sync_at ? '上次同步 ' + esc(p.last_sync_at) + ' UTC' : '尚未同步'}${p.last_status ? ' · ' + esc(p.last_status) : ''}</span>
        </span>
        <button class="btn btn-sm" data-a="test" data-id="${p.id}">测试</button>
        <button class="btn btn-sm" data-a="edit" data-id="${p.id}">编辑</button>
        <button class="btn btn-sm" data-a="del" data-id="${p.id}">删除</button>
      </div>`).join('');
  };
  drawList();

  const refresh = async () => {
    const s = await api('admin.php', 'peers_state', {}, { silent: true }).catch(() => null);
    if (s && s.peers) { rows = s.peers; drawList(); }
  };

  const openForm = p => {
    p = p || { id: 0, name: '', base_url: '', pubkey: '', enabled: 1 };
    formEl.innerHTML = `
      <div class="panel-plain" style="margin-top:10px">
        <div class="prow"><input class="inp" id="pName" placeholder="站点名称" value="${esc(p.name)}"></div>
        <div class="prow"><input class="inp" id="pUrl" placeholder="https://对端域名（不带结尾斜杠）" value="${esc(p.base_url)}"></div>
        <div class="prow"><input class="inp" id="pKey" placeholder="对端公钥（64 位十六进制）" value="${esc(p.pubkey)}"></div>
        <div class="prow" style="gap:8px">
          <label class="tiny"><input type="checkbox" id="pEn" ${p.enabled ? 'checked' : ''}> 启用</label>
          <button class="btn btn-sm" id="pSave">保存</button>
          <button class="btn btn-sm" id="pCancel">取消</button>
        </div>
      </div>`;
    formEl.querySelector('#pCancel').addEventListener('click', () => { formEl.innerHTML = ''; });
    formEl.querySelector('#pSave').addEventListener('click', async () => {
      const payload = {
        id: p.id,
        name: formEl.querySelector('#pName').value.trim(),
        base_url: formEl.querySelector('#pUrl').value.trim(),
        pubkey: formEl.querySelector('#pKey').value.trim(),
        enabled: formEl.querySelector('#pEn').checked ? 1 : 0,
      };
      try { await api('admin.php', 'peer_save', payload); toast('已保存'); formEl.innerHTML = ''; await refresh(); }
      catch (e) { toast(e.message || '保存失败', 'err'); }
    });
  };

  listEl.addEventListener('click', async e => {
    const b = e.target.closest('button[data-a]');
    if (!b) { return; }
    const id = Number(b.dataset.id);
    if (b.dataset.a === 'edit') { openForm(rows.find(r => Number(r.id) === id)); return; }
    if (b.dataset.a === 'del') {
      if (!(await dialog('删除站点', '将从列表中移除该站点，不影响已同步的数据。确定？', '删除'))) { return; }
      try { await api('admin.php', 'peer_del', { id }); toast('已删除'); await refresh(); }
      catch (e) { toast(e.message || '删除失败', 'err'); }
      return;
    }
    if (b.dataset.a === 'test') {
      b.disabled = true; b.textContent = '测试中…';
      try { await api('admin.php', 'peer_test', { id }, { timeout: 30000 }); toast('连接正常，密钥校验通过'); }
      catch (e) { toast(e.message || '连接失败', 'err'); }
      finally { b.disabled = false; b.textContent = '测试'; }
    }
  });

  addBtn.addEventListener('click', () => openForm(null));

  syncBtn.addEventListener('click', async () => {
    if (!(await dialog('一键互通', '将与列表中所有启用站点双向同步数据，期间不要关闭页面。确定继续？', '开始'))) { return; }
    syncBtn.disabled = true; syncBtn.textContent = '互通中…';
    try {
      const r = await api('admin.php', 'peer_sync', {}, { timeout: 300000 });
      const list = (r.results || []).map(x => x.ok
        ? x.name + '：拉取 ' + x.pulled + ' / 推送 ' + x.pushed + '（' + x.tables + ' 张表）'
        : x.name + '：失败' + (x.msg ? '（' + x.msg + '）' : ''));
      toast(list.length ? list.join('；') : '没有启用中的站点');
      await refresh();
    } catch (e) { toast(e.message || '互通失败', 'err'); }
    finally { syncBtn.disabled = false; syncBtn.textContent = '一键互通'; }
  });
}

function discBlock() {
  return `
    <div class="panel-plain">
      <h3>违纪通报</h3>
      <p class="tiny muted">
        被通报过的用户都在这里。可以随时「设置」调整理由、封禁天数或直接解封。
        累计被通报 <b>10 次</b>（单次封禁 ≥ 7 天才计入）的账号会被<b>永久删除</b>。
      </p>
      <div class="prow"><button class="btn btn-sm" id="discRefresh">刷新列表</button></div>
      <div id="discListBox" style="margin-top:12px"><div class="skeleton" style="height:80px"></div></div>
    </div>`;
}

async function discEditDialog(row) {
  let preset = [];
  try { preset = ((await api('admin.php', 'disc_reasons', {}, { silent: true })).items) || []; } catch (e) { }
  const cur = row.reasons || [];
  const opts = [];
  preset.concat(cur).forEach(r => { if (r && opts.indexOf(r) < 0) { opts.push(r); } });

  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';
    const banState = row.banned ? (row.ban_until ? '封停至 ' + esc(row.ban_until) : '永久封停') : '未封停';

    box.innerHTML = `
      <h3>设置通报 · ${esc(row.username)}</h3>
      <p class="tiny muted">累计 ${Number(row.user_count || 0)} 次 · 当前 ${banState}</p>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">通报理由（可多选）</div>
        <div class="disc-pick" id="edPick">
          ${opts.map(r => `<button type="button" class="disc-opt${cur.indexOf(r) >= 0 ? ' on' : ''}" data-r="${esc(r)}">${esc(r)}</button>`).join('')}
        </div>
        <input class="input" id="edCustom" type="text" maxlength="60" autocomplete="off"
               placeholder="补充一条理由（可留空）" style="margin-top:8px">
      </div>

      <div class="field">
        <textarea class="input" id="edNote" maxlength="500" placeholder="补充说明（可留空）">${esc(row.note || '')}</textarea>
      </div>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">封禁时长（改动会重新计算解封时间）</div>
        <div class="seg" id="edDays">
          <button type="button" data-d="1">1 天</button>
          <button type="button" data-d="7">7 天</button>
          <button type="button" data-d="30">30 天</button>
          <button type="button" data-d="0">永久</button>
          <button type="button" data-d="-1" class="on">不改动</button>
        </div>
        <input class="input" id="edDaysCustom" type="text" inputmode="decimal" autocomplete="off"
               placeholder="或直接填天数，支持小数（如 0.5 = 12 小时、1.5 = 36 小时）" style="margin-top:8px">
      </div>

      <div class="disc-pick">
        <button type="button" class="disc-opt${row.ip_banned ? ' on' : ''}" id="edIp">封禁访问 IP</button>
        <button type="button" class="disc-opt" id="edUnban">立即解封账号</button>
      </div>

      <div class="dialog-actions">
        <button class="btn-ghost" data-r="0">取消</button>
        <button class="btn" data-r="1">保存</button>
      </div>`;

    scrim.hidden = false;
    box.querySelectorAll('#edPick .disc-opt').forEach(b => b.addEventListener('click', () => b.classList.toggle('on')));
    const ipB = box.querySelector('#edIp');
    const unB = box.querySelector('#edUnban');
    ipB.addEventListener('click', () => ipB.classList.toggle('on'));
    unB.addEventListener('click', () => {
      const on = !unB.classList.contains('on');
      unB.classList.toggle('on', on);
      if (on) { box.querySelector('#edDays').querySelectorAll('button').forEach(x => x.classList.toggle('on', x.dataset.d === '-1')); }
    });
    const seg = box.querySelector('#edDays');
    seg.addEventListener('click', e => {
      const b = e.target.closest('[data-d]');
      if (!b) { return; }
      seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      if (b.dataset.d !== '-1') { unB.classList.remove('on'); }
    });

    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.r === '0') { close(null); return; }
      const picked = [];
      box.querySelectorAll('#edPick .disc-opt.on').forEach(x => picked.push(x.dataset.r));
      const custom = String(box.querySelector('#edCustom').value || '').trim();
      if (custom) { picked.push(custom); }
      if (!picked.length) { toast('请至少保留一条理由', 'err'); return; }

      const patch = {
        id: row.id,
        set_reasons: '1', reasons: JSON.stringify(picked),
        set_note: '1', note: String(box.querySelector('#edNote').value || '').trim(),
        set_ip: '1', ip_banned: ipB.classList.contains('on') ? 1 : 0,
      };
      if (unB.classList.contains('on')) {
        patch.set_unban = '1'; patch.unban = 1;
      } else {
        const typed = parseFloat(String(box.querySelector('#edDaysCustom').value || '').trim());
        const cur2 = seg.querySelector('button.on');
        const d = Number.isFinite(typed) && typed >= 0 ? typed : Number(cur2 ? cur2.dataset.d : -1);
        if (d >= 0) { patch.set_days = '1'; patch.ban_days = d; }
      }
      close(patch);
    }));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}

async function loadDisc(container) {
  const box = container.querySelector('#discListBox');
  if (!box) { return; }
  try {
    const d = await api('admin.php', 'disc_list', { page: 1 });
    const items = d.items || [];
    if (!items.length) { box.innerHTML = '<div class="tiny muted">还没有通报记录。</div>'; return; }

    box.innerHTML = '<table class="table"><thead><tr><th>用户</th><th>理由</th><th>处理</th><th>累计</th><th>时间</th><th>操作</th></tr></thead><tbody>'
      + items.map(it => `<tr data-rid="${it.id}">
        <td>${userName(it.username, 'user', it.user_count)}${it.user_alive ? '' : ' <span class="tiny muted">（账号已删）</span>'}</td>
        <td class="tiny">${esc((it.reasons || []).join('；'))}</td>
        <td class="tiny">${it.banned ? (it.ban_until ? '至 ' + esc(it.ban_until) : '永久') : '未封停'}${it.ip_banned ? ' · IP' : ''}</td>
        <td class="tiny">${Number(it.user_count || 0)} 次</td>
        <td class="tiny">${esc(it.created)}</td>
        <td><button class="btn-ghost btn-sm" data-act="edit">设置</button>
            <button class="btn-ghost btn-sm" data-act="revoke">撤销</button></td></tr>`).join('')
      + '</tbody></table>';

    box.querySelectorAll('tr[data-rid]').forEach(tr => {
      const row = items.filter(x => String(x.id) === tr.dataset.rid)[0];
      if (!row) { return; }
      tr.querySelector('[data-act="edit"]').addEventListener('click', async () => {
        const patch = await discEditDialog(row);
        if (!patch) { return; }
        try { await api('admin.php', 'disc_update', patch); toast('已更新该通报'); loadDisc(container); }
        catch (e) { toast(e.message, 'err'); }
      });
      tr.querySelector('[data-act="revoke"]').addEventListener('click', async () => {
        if (!(await dialog('撤销通报', '将删除该通报并解除其账号与 IP 封禁，确认？', '撤销', { danger: true }))) { return; }
        try { await api('admin.php', 'disc_delete', { id: row.id }); toast('已撤销'); loadDisc(container); }
        catch (e) { toast(e.message, 'err'); }
      });

    });
  } catch (e) {
    box.innerHTML = '<div class="tiny">加载失败：' + esc(e.message) + '</div>';
  }
}

/* ============================================================
 * 违纪通报（一键封禁）
 * ============================================================
 * 表单全自绘：理由多选、补充理由、说明、封禁时长（可永久）、封 IP、清理内容。
 * 提交后由后端落实封禁与清理；被通报者再访问站点会被 302 到违纪界面。
 */
async function discDialog(ids, names) {
  let preset = [];
  try { preset = ((await api('admin.php', 'disc_reasons', {}, { silent: true })).items) || []; } catch (e) { }

  return new Promise(resolve => {
    const scrim = document.getElementById('dialogScrim');
    const box = document.getElementById('dialog');
    box.className = 'dialog glass';
    const who = names.map(n => '<b>' + esc(n) + '</b>').join('、');

    box.innerHTML = `
      <h3>违纪通报 · 封禁</h3>
      <p class="tiny muted">对象：${who}</p>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">通报理由（可多选，至少一条）</div>
        <div class="disc-pick" id="discPick">
          ${preset.map((r, i) => `<button type="button" class="disc-opt${i === 0 ? ' on' : ''}" data-r="${esc(r)}">${esc(r)}</button>`).join('')}
        </div>
      </div>

      <div class="field">
        <input class="input" id="discCustom" type="text" maxlength="60" autocomplete="off" placeholder="补充理由（可留空）">
      </div>

      <div class="field">
        <textarea class="input" id="discNote" maxlength="500" placeholder="补充说明（可留空，会显示在通报页上）"></textarea>
      </div>

      <div class="field">
        <div class="tiny muted" style="margin-bottom:6px">封禁时长</div>
        <div class="seg" id="discDays">
          <button type="button" data-d="1">1 天</button>
          <button type="button" data-d="7" class="on">7 天</button>
          <button type="button" data-d="30">30 天</button>
          <button type="button" data-d="0">永久</button>
        </div>
        <input class="input" id="discDaysCustom" type="text" inputmode="decimal" autocomplete="off"
               placeholder="或直接填写天数，支持小数（如 0.5 = 12 小时、1.5 = 36 小时）" style="margin-top:8px">
      </div>

      <div class="disc-pick">
        <button type="button" class="disc-opt on" id="discIp">封禁访问 IP</button>
        <button type="button" class="disc-opt" id="discPurge">删除其全部评论 / 对话 / 图片</button>
      </div>

      <div class="dialog-actions">
        <button class="btn-ghost" data-r="0">取消</button>
        <button class="btn btn-danger" data-r="1">确认通报并封禁</button>
      </div>`;

    scrim.hidden = false;

    /* 多选：理由可多选，开关型按钮独立切换 */
    box.querySelectorAll('#discPick .disc-opt').forEach(b => {
      b.addEventListener('click', () => b.classList.toggle('on'));
    });
    const tog = (id) => box.querySelector('#' + id);
    tog('discIp').addEventListener('click', () => tog('discIp').classList.toggle('on'));
    tog('discPurge').addEventListener('click', () => tog('discPurge').classList.toggle('on'));

    /* 时长：分段按钮单选；自定义输入优先 */
    const seg = box.querySelector('#discDays');
    seg.addEventListener('click', e => {
      const b = e.target.closest('[data-d]');
      if (!b) { return; }
      seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    });

    const close = v => { scrim.hidden = true; box.innerHTML = ''; resolve(v); };
    box.querySelectorAll('[data-r]').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.r === '0') { close(null); return; }
      const picked = [];
      box.querySelectorAll('#discPick .disc-opt.on').forEach(x => picked.push(x.dataset.r));
      const custom = String(box.querySelector('#discCustom').value || '').trim();
      if (custom) { picked.push(custom); }
      if (!picked.length) { toast('请至少选择或填写一条理由', 'err'); return; }
      const cur = seg.querySelector('button.on');
      const typed = parseFloat(String(box.querySelector('#discDaysCustom').value || '').trim());
      const days = Number.isFinite(typed) && typed >= 0 ? typed : Number(cur ? cur.dataset.d : 7);
      close({
        reasons: picked,
        note: String(box.querySelector('#discNote').value || '').trim(),
        days: days,
        ban_ip: tog('discIp').classList.contains('on') ? 1 : 0,
        purge: tog('discPurge').classList.contains('on') ? 1 : 0,
      });
    }));
    scrim.onclick = e => { if (e.target === scrim) close(null); };
  });
}

/** 一键通报：提交并回显结果（含清理统计） */
async function submitDiscipline(container, ids, names, q, readOnly, pg) {
  const form = await discDialog(ids, names);
  if (!form) { return; }
  try {
    const r = await api('admin.php', 'disc_create', {
      user_ids: JSON.stringify(ids),
      reasons: JSON.stringify(form.reasons),
      note: form.note,
      ban_days: form.days,
      ban_account: 1,
      ban_ip: form.ban_ip,
      purge: form.purge,
    });
    let extra = '';
    if (form.purge) {
      extra = '<br><br>已清理：评论 <b>' + Number(r.purged_comments || 0) + '</b> 条 · 对话 <b>'
            + Number(r.purged_messages || 0) + '</b> 条 · 图片 <b>' + Number(r.purged_images || 0) + '</b> 张';
    }
    await dialog('通报完成',
      '已通报 <b>' + Number(r.created || 0) + '</b> 个用户。'
      + (r.ips_banned ? '<br>封禁 IP <b>' + Number(r.ips_banned) + '</b> 个。' : '')
      + (r.no_ip ? '<br><span class="tiny">其中 ' + Number(r.no_ip) + ' 个暂无可封的 IP，等其下次访问后再操作即可。</span>' : '')
      + extra, '知道了');
    loadUsers(container, q, readOnly, pg);
  } catch (e) {
    toast(e.message, 'err');
  }
}

async function loadUsers(container, q, readOnly, page) {
  const box = container.querySelector('#userList');
  if (!box) return;
  const pager = container.querySelector('#userPager');
  const pg = Math.max(1, Number(page || 1));
  try {
    const d = await api('admin.php', 'users', { q: q, page: pg });
    if (!d.items.length) {
      box.innerHTML = '<div class="tiny">' + (pg > 1 ? '这一页没有用户了。' : '暂无用户') + '</div>';
      if (pager) {
        pager.dataset.page = String(pg);
        pager.innerHTML = pg > 1 ? '<button class="btn-ghost btn-sm" data-pg="' + (pg - 1) + '">上一页</button>' : '';
        pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click',
          () => loadUsers(container, q, readOnly, Number(b.dataset.pg))));
      }
      return;
    }
    const showIp = d.ip_visible === true;
    const opsHead = readOnly ? '' : '<th>操作</th>';
    box.innerHTML = `<div class="tiny" style="margin-bottom:6px">可按用户名或 8 位 UID 搜索${readOnly ? ' · 只读' : ' · 点「通报」可写理由并封禁账号与 IP'}</div>
      <table class="table"><thead><tr><th>用户名</th><th>UID</th><th>注册</th><th>最近登录</th>${showIp ? '<th>最近 IP / 归属地</th>' : ''}<th>AI 用量</th>${opsHead}</tr></thead><tbody>` +
      d.items.map(u => `<tr data-id="${u.id}" data-name="${esc(u.username)}">
        <td>${userName(u.username, u.role, u.reports)}</td>
        <td class="uid-cell">${esc(u.uid8 || '—')}</td>
        <td>${esc(u.created)}</td>
        <td>${esc(u.last_login)}</td>
        ${showIp ? `<td class="tiny">${esc(u.ip_masked || '—')}<br><span class="muted">${esc(u.location || '')}</span></td>` : ''}
        <td>${Number(u.ai_tokens)} tokens / ${Number(u.ai_calls)} 次</td>
        ${readOnly ? '' : `<td>${u.role === 'user'
              ? '<button class="btn-ghost btn-sm" data-act="disc">通报</button>' : ''}
          ${u.role === 'user'
              ? '<button class="btn-ghost btn-sm" data-act="promote">设为副管理员</button>' : ''}
          ${u.role === 'user' ? '<button class="btn-ghost btn-sm" data-act="del">删除</button>' : ''}</td>`}</tr>`).join('') + '</tbody></table>';

    box.querySelectorAll('tr[data-id]').forEach(tr => {
      const uname = tr.dataset.name || '';
      const delBtn = tr.querySelector('[data-act="del"]');
      if (delBtn) delBtn.addEventListener('click', async () => {
        if (await dialog('删除用户', '将删除「' + esc(uname) + '」及其全部数据，不可恢复。确认？', '删除', { danger: true })) {
          try { await api('admin.php', 'user_delete', { id: tr.dataset.id }); toast('已删除'); loadUsers(container, q, readOnly, pg); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
      const discBtn = tr.querySelector('[data-act="disc"]');
      if (discBtn) discBtn.addEventListener('click', () => {
        submitDiscipline(container, [Number(tr.dataset.id)], [uname], q, readOnly, pg);
      });
      const proBtn = tr.querySelector('[data-act="promote"]');
      if (proBtn) proBtn.addEventListener('click', async () => {
        if (!(await dialog('设为副管理员',
            '将「<b>' + esc(uname) + '</b>」升级为副管理员？<br><br>'
            + '· <b>不需要更改密码</b>，其原密码继续可用<br>'
            + '· 评论、对话等数据全部保留<br>'
            + '· UID 换为专属豹子号<br>'
            + '· 获得作品搜索/上传与回复反馈权限', '确认设立'))) { return; }
        btnLoading(proBtn, true);
        try {
          const r = await api('admin.php', 'sub_add', { username: uname, secret: '' });
          toast('已升级为副管理员');
          await dialog('升级完成',
            '「<b>' + esc(r.username) + '</b>」现为副管理员：<b>密码保持不变</b>，数据全部保留。<br><br>'
            + '专属 UID（豹子号）：<b>' + esc(r.uid8 || '—') + '</b>', '知道了');
          loadSubs(container);
          loadUsers(container, q, readOnly, pg);
        } catch (e) { toast(e.message, 'err'); }
        finally { btnLoading(proBtn, false); }
      });
    });

    /* 分页：与作品列表同一套控件 */
    if (pager) {
      const pages = Math.max(1, Number(d.total_pages || 1));
      pager.dataset.page = String(pg);
      pager.innerHTML = pages <= 1 ? ''
        : `<button class="btn-ghost btn-sm" data-pg="${pg - 1}" ${pg <= 1 ? 'disabled' : ''}>上一页</button>
           <span class="tiny">第 ${pg} / ${pages} 页 · 共 ${Number(d.total) || 0} 位</span>
           <button class="btn-ghost btn-sm" data-pg="${pg + 1}" ${pg >= pages ? 'disabled' : ''}>下一页</button>
           <button class="btn-ghost btn-sm" data-pg="${pages}" ${pg >= pages ? 'disabled' : ''}>末页</button>`;
      pager.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click',
        () => loadUsers(container, q, readOnly, Number(b.dataset.pg))));
    }
  } catch (e) { box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`; }
}

/* ============================================================
 * 副管理员
 * ============================================================ */
async function loadSubs(container) {
  const box = container.querySelector('#subList');
  if (!box) return;
  try {
    const d = await api('admin.php', 'subs');
    /* 豹子号余量：池用尽后新副管理员自动改用普通 8 位 UID，身份与权限不受影响，
       这里把余量摆在眼前，免得加人时对着 UID 发懵。 */
    const bTotal = Number(d.babao_total || 0), bLeft = Number(d.babao_left || 0);
    const poolNote = bTotal <= 0 ? '' : `<div class="tiny" style="margin-bottom:8px;line-height:1.6;${bLeft > 0 ? 'opacity:.75' : 'color:var(--danger)'}">
        豹子号已用 ${bTotal - bLeft}/${bTotal}${bLeft > 0
          ? `，还剩 ${bLeft} 个`
          : '，池已用尽：新加的副管理员会自动改用普通 8 位 UID，身份与权限完全不受影响'}
      </div>`;
    box.innerHTML = poolNote + (d.items.length
      ? `<table class="table"><thead><tr><th>用户名</th><th>UID（豹子号）</th><th>设立时间</th><th>操作</th></tr></thead><tbody>` +
        d.items.map(s => `<tr data-id="${s.id}">
          <td>${userName(s.username, 'subadmin')}</td><td class="uid-cell">${esc(s.uid8 || '—')}</td><td>${esc(s.created)}</td>
          <td>
            <button class="btn-ghost btn-sm" data-act="demote">取消身份</button>
            <button class="btn-ghost btn-sm" data-act="del">删除</button>
          </td></tr>`).join('') + '</tbody></table>'
      : '<div class="tiny">尚未添加副管理员</div>');
    box.querySelectorAll('tr[data-id]').forEach(tr => {
      tr.querySelector('[data-act="demote"]').addEventListener('click', async () => {
        if (await dialog('取消副管理员身份',
            '该账号将变回普通用户：<br>· 评论、对话、反馈等数据<b>全部保留</b><br>· UID 由豹子号换回普通编号<br>· 若该账号是升级而来，仍可用其原密码登录',
            '取消身份')) {
          try { await api('admin.php', 'sub_demote', { id: tr.dataset.id }); toast('已取消副管理员身份'); loadSubs(container); loadUsers(container, container.querySelector('#uq').value.trim()); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
      tr.querySelector('[data-act="del"]').addEventListener('click', async () => {
        if (await dialog('删除副管理员', '将同时删除其账号与全部数据，不可恢复。若只是收回权限，请用「取消身份」。确认？', '删除', { danger: true })) {
          try { await api('admin.php', 'sub_del', { id: tr.dataset.id }); toast('已删除'); loadSubs(container); }
          catch (e) { toast(e.message, 'err'); }
        }
      });
    });
  } catch (e) { box.innerHTML = `<div class="tiny">加载失败：${esc(e.message)}</div>`; }

  const nameEl = container.querySelector('#subName');
  const keyEl = container.querySelector('#subKey');
  const hintEl = container.querySelector('#subHint');
  if (!nameEl || nameEl.dataset.bound) return;
  nameEl.dataset.bound = '1';

  /* 输入即预检：判断是升级现有用户还是新建账号 */
  let subTimer = null;
  nameEl.addEventListener('input', () => {
    clearTimeout(subTimer);
    const v = nameEl.value.trim();
    if (hintEl) { hintEl.innerHTML = ''; }
    if (!v) { return; }
    subTimer = setTimeout(async () => {
      try {
        const d = await api('admin.php', 'users', { q: v, page: 1 }, { silent: true });
        const hit = (d.items || []).find(u => String(u.username) === v);
        if (!hintEl) { return; }
        if (!hit) {
          hintEl.textContent = '用户名未被占用 → 将创建新账号（需在右侧填写初始密码）';
        } else if (hit.role === 'subadmin') {
          hintEl.innerHTML = '<span style="color:var(--danger)">该账号已是副管理员</span>';
        } else if (hit.role === 'admin') {
          hintEl.innerHTML = '<span style="color:var(--danger)">管理员账号不可转为副管理员</span>';
        } else {
          hintEl.innerHTML = '将<b>升级</b>现有用户「' + esc(hit.username) + '」（UID ' + esc(hit.uid8 || '—')
            + '）→ <b>密码保持不变</b>，数据全部保留，UID 换为豹子号';
        }
      } catch (e) { /* 预检失败静默 */ }
    }, 320);
  });

  container.querySelector('#subAdd').addEventListener('click', async () => {
    const n = nameEl.value.trim(), k = keyEl.value.trim();
    if (!n || !k) { toast('请填写用户名与密钥', 'err'); return; }
    const btn = container.querySelector('#subAdd');
    btnLoading(btn, true);
    try {
      const r = await api('admin.php', 'sub_add', { username: n, secret: k });
      nameEl.value = ''; keyEl.value = ''; if (hintEl) { hintEl.innerHTML = ''; }
      const modeTxt = {
        promoted:          ['现有用户已升级为副管理员', '账号「<b>' + esc(r.username) + '</b>」已升级：<b>密码保持不变</b>（继续用原密码登录），评论、对话等数据全部保留，UID 已换为豹子号。'],
        promoted_resetpwd: ['已升级并重置密码', '账号「<b>' + esc(r.username) + '</b>」已升级，登录密码已重置为你刚填写的密码，数据全部保留。'],
        created:           ['副管理员账号已创建', '账号「<b>' + esc(r.username) + '</b>」已创建，用「用户名 + 初始密码」登录即可。'],
        repaired:          ['已修复该副管理员', '该账号的凭证此前未同步，已自动修复为副管理员身份。'],
      }[r.mode] || ['副管理员已设立', '账号「<b>' + esc(r.username) + '</b>」已设立。'];
      toast(modeTxt[0]);
      await dialog(modeTxt[0],
        modeTxt[1] + '<br><br>专属 UID（豹子号）：<b>' + esc(r.uid8 || '—') + '</b>'
        + '<br><span class="tiny">权限：普通用户全部功能 + 作品搜索/上传 + 回复反馈</span>',
        '知道了');
      loadSubs(container);
      loadUsers(container, container.querySelector('#uq').value.trim());
    } catch (e) { toast(e.message, 'err'); }
    finally { btnLoading(btn, false); }
  });
}

/* ============================================================
 * 公告
 * ============================================================ */
function bindAnnounce(container) {
  const inp = container.querySelector('#annInput');
  if (!inp) return;
  api('admin.php', 'announce_get').then(a => { inp.value = a.content || ''; }).catch(() => {});
  container.querySelector('#annSave').addEventListener('click', async () => {
    try { await api('admin.php', 'announce_set', { content: inp.value }); toast('公告已更新'); }
    catch (e) { toast(e.message, 'err'); }
  });
}

/* ------------------------------------------------------------
 * 控制面板数据自动刷新（默认 30s）
 * 用户正在输入、弹窗打开、标签页不可见时一律跳过，避免打断操作
 * ------------------------------------------------------------ */
function panelBusy(container) {
  const ae = document.activeElement;
  if (ae && container.contains(ae) && /INPUT|TEXTAREA/.test(ae.tagName)) { return true; }
  const scrim = document.getElementById('dialogScrim');
  if (scrim && !scrim.hidden) { return true; }
  return false;
}

function startPanelPolling(container) {
  const tick = async () => {
    if (document.hidden || !container.isConnected || panelBusy(container)) { return; }
    try {
      const qEl  = container.querySelector('#wq');
      const uqEl = container.querySelector('#uq');
      const pgEl = container.querySelector('#workPager');
      const uPgEl = container.querySelector('#userPager');
      const rangeEl = container.querySelector('#rangeSeg button.on');

      const ov = await api('dashboard.php', 'overview', null, { silent: true });
      const ovGrid = container.querySelector('#ovGrid');
      if (ovGrid) { renderOverview(ovGrid, ov, container); }

      loadStats(rangeEl ? rangeEl.dataset.r : '7', container);
      loadWorks(container, qEl ? qEl.value.trim() : '', false,
                pgEl ? Number(pgEl.dataset.page || '1') : 1);
      /* 轮询时保持当前页；副管理员走只读渲染（此前漏传，会短暂显示不可用的操作按钮） */
      loadUsers(container, uqEl ? uqEl.value.trim() : '', state.role === 'subadmin',
                uPgEl ? Number(uPgEl.dataset.page || '1') : 1);
      loadSubs(container);
      loadVisitsTop(container);
    } catch (e) { /* 静默：下一轮再试 */ }
  };
  window.__addPageTimer(setInterval(tick, 30000));
}
