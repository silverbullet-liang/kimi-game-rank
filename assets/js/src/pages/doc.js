import { ASSET_BASE } from '../base.js';
/**
 * 文档页：直接 fetch assets/docs/*.md 并渲染（不在前端硬编码文案）
 */
import { esc, toast } from '../core.js';
import { I18N, docLangOf } from '../i18n.js';

const ALLOWED = [t('功能说明'), t('AI 使用说明'), t('社区公约'), t('评分标准'), t('入榜规则'),
  t('用户协议'), t('隐私政策'), t('更新日志')];

/* 文档里的 B站视频：单独一段的 BV 号或视频链接 → 内嵌播放器。
   mdToHtml 全量转义，所以这里只在渲染结果上做定点替换，替换进 DOM 的只有
   我们自己拼的 iframe（BV 号已按 [0-9A-Za-z]{10} 白名单过滤）。 */
function biliEmbed(html) {
  const player = bv =>
    '<div class="bili-embed"><iframe src="https://player.bilibili.com/player.html?bvid=' +
    bv + '&autoplay=0&danmaku=1" scrolling="no" frameborder="0" allowfullscreen loading="lazy"></iframe></div>';
  return html
    .replace(/<p>\s*(?:https?:\/\/)?(?:www\.|m\.)?bilibili\.com\/video\/(BV[0-9A-Za-z]{10})[^\s<]*\s*<\/p>/g,
      (m, bv) => player(bv))
    .replace(/<p>\s*(BV[0-9A-Za-z]{10})\s*<\/p>/g, (m, bv) => player(bv));
}

/* 中文文档名 → 英文文件名（assets/docs/en/<slug>.md） */
const SLUG = {
  '功能说明': 'features', 'AI 使用说明': 'ai', '社区公约': 'community',
  '评分标准': 'scoring', '入榜规则': 'admission', '用户协议': 'terms',
  '隐私政策': 'privacy', '更新日志': 'changelog'
};
/* 文档语言跟随界面语言：中文看中文文档，英文与日文都看英文文档 */
function docLang() {
  return docLangOf(I18N.lang);
}

function langBar() {
  return '<div class="doc-lang"><span>' + (docLang() === 'en' ? 'English' : t('中文')) + '</span></div>';
}

export async function renderDoc(container, ctx) {
  const name = decodeURIComponent((ctx.sub || '').trim()) || '评分标准';
  if (ALLOWED.indexOf(name) === -1) {
    container.innerHTML = '<div class="empty"><p>文档不存在</p></div>';
    return;
  }
  container.innerHTML = '<div class="skeleton" style="height:200px"></div>';
  try {
    const lang = docLang();
    const path = (lang === 'en')
      ? ASSET_BASE + '/docs/en/' + (SLUG[name] || 'features') + '.md'
      : ASSET_BASE + '/docs/' + encodeURIComponent(name) + '.md';
    const resp = await fetch(path, { cache: 'no-cache' });
    if (!resp.ok) throw new Error(lang === 'en' ? 'English version of this document is not available yet' : '文档加载失败');
    const md = await resp.text();
    const box = document.createElement('div');
    box.className = 'card doc';
    let html = mdToHtml(md);
    html = biliEmbed(html);
    html = '<div class="doc-head">' + langBar() + '</div>' + html;

    /* 更新日志页：顶部标出当前站点版本，便于对照是否已部署到最新 */
    if (name === t('更新日志')) {
      const v = (window.__SITE__ && window.__SITE__.ver) ? String(window.__SITE__.ver) : '';
      const latest = (md.match(/^##\s*v([0-9]+\.[0-9]+\.[0-9]+)/m) || [])[1] || '';
      const same = v !== '' && latest.indexOf(v) >= 0;
      html = `<div class="doc-ver${same ? ' ok' : ''}">当前站点版本 v${esc(v || t('未知'))}`
        + (latest && !same ? ` · 日志最新为 v${esc(latest)}（本站可能尚未更新到该版本）` : '')
        + `</div>` + html;
    }

    box.innerHTML = html;
    box.querySelectorAll('[data-lang]').forEach(function (b) {
      b.addEventListener('click', function () {
        const want = b.getAttribute('data-lang');
        if (want === docLang()) { return; }
        try { localStorage.setItem(LANG_KEY, want); } catch (e) {}
        renderDoc(container, ctx);
      });
    });
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
  const shown = (I18N.lang === 'zh') ? name : (SLUG[name] || 'features');
  hint.textContent = shown + t('.md · 与页面内容一致');

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-ghost btn-sm';
  btn.setAttribute('aria-label', t('下载 ') + shown + t(' 的原始 md 文件'));
  btn.innerHTML = ICON_DL + t('下载原始 md 文件');
  btn.addEventListener('click', () => {
    const url = URL.createObjectURL(new Blob([md], { type: 'text/markdown;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = shown + '.md';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
    toast(t('已开始下载 ') + shown + '.md');
  });

  bar.appendChild(hint);
  bar.appendChild(btn);
  box.appendChild(bar);
}

/* ---------- 轻量 Markdown 渲染（覆盖本文档用到的语法） ---------- */
import { mdToHtml } from '../md.js';
export { mdToHtml };
