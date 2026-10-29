/**
 * 「关于今天」：当天命中的节日的介绍
 * ------------------------------------------------------------
 * 内容来自站内 Markdown（assets/docs/festivals/<语言>/<节日>.md），
 * 中 / 英 / 日 三份各有完整正文（简介、由来、习俗、故事）；
 * 某语言缺文件时回退英文，再缺则提示，不阻断页面。
 * 同一天可能命中两个节日 —— 按目录顺序逐块渲染。
 */
import { esc, toast } from '../core.js';
import { I18N, t } from '../i18n.js';
import { mdToHtml } from '../md.js';
import { ASSET_BASE } from '../base.js';

/** 语言目录：中文用 zh，其余按界面语言；缺失回退 en */
const DOC_DIR = { zh: 'zh', en: 'en', ja: 'ja' };

function docDirs() {
  const first = DOC_DIR[I18N.lang] || 'en';
  return first === 'en' ? ['en'] : [first, 'en'];
}

async function fetchDoc(key) {
  const dirs = docDirs();
  for (let i = 0; i < dirs.length; i++) {
    const url = ASSET_BASE + '/docs/festivals/' + dirs[i] + '/' + encodeURIComponent(key) + '.md';
    try {
      const r = await fetch(url, { cache: 'no-cache' });
      if (r.ok) { return { text: await r.text(), dir: dirs[i] }; }
    } catch (e) { /* 换下一个语言再试 */ }
  }
  return null;
}

export async function renderToday(container) {
  container.innerHTML = '<div class="skeleton" style="height:80px"></div><div class="skeleton"></div>';
  const fest = (typeof window !== 'undefined' && window.__FESTIVAL) ? window.__FESTIVAL : {};
  const list = Array.isArray(fest.today) ? fest.today : [];

  if (!list.length) {
    container.innerHTML = `<div class="card">
      <div class="card-title">${esc(t('今天没有节日主题'))}</div>
      <p class="tiny muted">${esc(t('站点在节日当天会自动换上对应主题，这里会显示那个节日的介绍、由来、习俗与故事。'))}</p>
    </div>`;
    return;
  }

  const dateText = String(fest.date || '');
  let html = `<div class="card">
    <div class="card-title">${esc(t('关于今天'))}</div>
    <p class="tiny muted">${esc(dateText)} · ${esc(list.map(function (x) { return t(x.name); }).join(' · '))}</p>
  </div>`;

  for (let i = 0; i < list.length; i++) {
    const it = list[i];
    const got = await fetchDoc(it.key);
    const head = `<div class="card doc">
      <div class="doc-head"><div class="doc-title">${esc(t(it.name))}</div>
        <div class="doc-lang"><span>${got ? (got.dir === 'zh' ? '中文' : (got.dir === 'ja' ? '日本語' : 'English')) : ''}</span></div>
      </div>`;
    if (!got) {
      html += head + `<p class="tiny muted">${esc(t('这个节日的介绍还没准备好。'))}</p></div>`;
      continue;
    }
    html += head + `<div class="doc-body">${mdToHtml(got.text)}</div></div>`;
  }

  container.innerHTML = html;
}
