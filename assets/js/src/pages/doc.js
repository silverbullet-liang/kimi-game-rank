/**
 * 文档页：直接 fetch assets/docs/*.md 并渲染（不在前端硬编码文案）
 */
import { esc, toast } from '../core.js';

const ALLOWED = ['功能说明', 'AI 使用说明', '社区公约', '评分标准', '入榜规则',
  '用户协议', '隐私政策', '更新日志'];

export async function renderDoc(container, ctx) {
  const name = decodeURIComponent((ctx.sub || '').trim()) || '评分标准';
  if (ALLOWED.indexOf(name) === -1) {
    container.innerHTML = '<div class="empty"><p>文档不存在</p></div>';
    return;
  }
  container.innerHTML = '<div class="skeleton" style="height:200px"></div>';
  try {
    const resp = await fetch('assets/docs/' + encodeURIComponent(name) + '.md', { cache: 'no-cache' });
    if (!resp.ok) throw new Error('文档加载失败');
    const md = await resp.text();
    const box = document.createElement('div');
    box.className = 'card doc';
    let html = mdToHtml(md);

    /* 更新日志页：顶部标出当前站点版本，便于对照是否已部署到最新 */
    if (name === '更新日志') {
      const v = (window.__SITE__ && window.__SITE__.ver) ? String(window.__SITE__.ver) : '';
      const latest = (md.match(/^##\s*v([0-9]+\.[0-9]+\.[0-9]+)/m) || [])[1] || '';
      const same = v !== '' && latest.indexOf(v) >= 0;
      html = `<div class="doc-ver${same ? ' ok' : ''}">当前站点版本 v${esc(v || '未知')}`
        + (latest && !same ? ` · 日志最新为 v${esc(latest)}（本站可能尚未更新到该版本）` : '')
        + `</div>` + html;
    }

    box.innerHTML = html;
    appendDownload(box, name, md);
    container.innerHTML = '';
    container.appendChild(box);
  } catch (e) {
    container.innerHTML = `<div class="empty"><p>${esc(e.message)}</p></div>`;
  }
}

/* 下载（托盘 + 下箭头） */
const ICON_DL = '<svg viewBox="0 0 24 24" class="ic" style="width:16px;height:16px;fill:none;'
  + 'stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round" aria-hidden="true">'
  + '<path d="M12 3.5v10.5M7.8 10.2 12 14.4l4.2-4.2M5 18.5h14"/></svg>';

/**
 * 文档末尾的下载条。原文已在内存中，直接落盘，不产生第二次请求。
 * 文件名取自文档名，内容与页面所见完全一致（未经渲染改写）。
 */
function appendDownload(box, name, md) {
  const bar = document.createElement('div');
  bar.className = 'doc-dl';

  const hint = document.createElement('span');
  hint.className = 'doc-dl-hint';
  hint.textContent = name + '.md · 与页面内容一致';

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-ghost btn-sm';
  btn.setAttribute('aria-label', '下载 ' + name + ' 的原始 md 文件');
  btn.innerHTML = ICON_DL + '下载原始 md 文件';
  btn.addEventListener('click', () => {
    const url = URL.createObjectURL(new Blob([md], { type: 'text/markdown;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name + '.md';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
    toast('已开始下载 ' + name + '.md');
  });

  bar.appendChild(hint);
  bar.appendChild(btn);
  box.appendChild(bar);
}

/* ---------- 轻量 Markdown 渲染（覆盖本文档用到的语法） ---------- */
import { mdToHtml } from '../md.js';
export { mdToHtml };
