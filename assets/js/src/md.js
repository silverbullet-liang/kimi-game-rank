/**
 * Markdown 渲染（文档页与 AI 回复共用）
 * ------------------------------------------------------------
 * 行式解析：标题 / 列表 / 引用 / 代码块 / 表格 / 行内语法。
 * 全部内容先转义再拼装，插入的标签固定，用户内容无法逃逸。
 * 第三个环节的 inlineExtra 钩子用于在「已转义的单个行内片段」上追加替换
 * （站内表情、图片链接等），避免调用方重复实现解析。
 */
import { esc } from './core.js';

export function mdToHtml(md, inlineExtra) {
  const lines = String(md).split(/\r?\n/);
  let html = '';
  let i = 0;
  let inCode = false, codeBuf = [], inList = '', inTable = false, tableBuf = [];

  /* 先转义 → 再行内语法 → 最后交给调用方的挂载钩子（表情 / 图片等）。
     顺序保证：用户内容永远在转义之后被处理，无法注入标签。 */
  const inline = s => {
    let h = esc(s)
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    return (typeof inlineExtra === 'function') ? inlineExtra(h) : h;
  };

  const flushTable = () => {
    if (!tableBuf.length) return;
    const rows = tableBuf.filter(r => !/^\s*\|?[\s:\-|]+\|?\s*$/.test(r));
    let t = '<table>';
    rows.forEach((r, idx) => {
      const cells = r.replace(/^\||\|$/g, '').split('|').map(c => c.trim());
      const tag = idx === 0 ? 'th' : 'td';
      t += '<tr>' + cells.map(c => `<${tag}>${inline(c)}</${tag}>`).join('') + '</tr>';
    });
    t += '</table>';
    html += t;
    tableBuf = []; inTable = false;
  };

  const closeList = () => { if (inList) { html += '</' + inList + '>'; inList = ''; } };

  for (i = 0; i < lines.length; i++) {
    let line = lines[i];

    if (/^```/.test(line)) {
      if (inCode) { html += '<pre><code>' + esc(codeBuf.join('\n')) + '</code></pre>'; codeBuf = []; inCode = false; }
      else { closeList(); inCode = true; }
      continue;
    }
    if (inCode) { codeBuf.push(line); continue; }

    if (/^\s*\|.*\|\s*$/.test(line)) { inTable = true; tableBuf.push(line); continue; }
    else if (inTable) { flushTable(); }

    /* 分隔线：--- / *** / ___（独占一行）。须在列表规则之前判定，
       否则会被误当成普通段落而原样显示。 */
    if (/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) { closeList(); html += '<hr>'; continue; }

    if (/^####\s+/.test(line)) { closeList(); html += '<h4>' + inline(line.replace(/^####\s+/, '')) + '</h4>'; continue; }
    if (/^###\s+/.test(line)) { closeList(); html += '<h3>' + inline(line.replace(/^###\s+/, '')) + '</h3>'; continue; }
    if (/^##\s+/.test(line)) { closeList(); html += '<h2>' + inline(line.replace(/^##\s+/, '')) + '</h2>'; continue; }
    if (/^#\s+/.test(line)) { closeList(); html += '<h1>' + inline(line.replace(/^#\s+/, '')) + '</h1>'; continue; }
    if (/^\s*>\s?/.test(line)) { closeList(); html += '<blockquote>' + inline(line.replace(/^\s*>\s?/, '')) + '</blockquote>'; continue; }
    if (/^\s*[-*]\s+/.test(line)) {
      if (inList !== 'ul') { closeList(); html += '<ul>'; inList = 'ul'; }
      html += '<li>' + inline(line.replace(/^\s*[-*]\s+/, '')) + '</li>'; continue;
    }
    if (/^\s*\d+[.)]\s+/.test(line)) {
      if (inList !== 'ol') { closeList(); html += '<ol>'; inList = 'ol'; }
      html += '<li>' + inline(line.replace(/^\s*\d+[.)]\s+/, '')) + '</li>'; continue;
    }
    if (/^\s*$/.test(line)) { closeList(); continue; }

    closeList();
    html += '<p>' + inline(line) + '</p>';
  }
  if (inCode) html += '<pre><code>' + esc(codeBuf.join('\n')) + '</code></pre>';
  flushTable();
  closeList();
  return html;
}
