#!/usr/bin/env node
/**
 * 生成《更新日志》独立展示版（液态玻璃风格、单文件、零依赖）
 * ------------------------------------------------------------
 * 用法：node tools/standalone.mjs
 * 产物：standalone/changelog.html
 *
 * 说明：
 *  - 正文由 assets/docs/更新日志.md 渲染而来，站点文档更新后重新执行本脚本即可。
 *  - 站内互链（?p=doc&arg=…）在独立版中降级为纯文本，避免产生死链。
 *  - 列表项的「折行续行」在此处合并回同一项（Markdown 语义正确），
 *    因此独立版排版比站点渲染器更规整。
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { mdToHtml } from '../assets/js/src/md.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC  = path.join(ROOT, 'assets/docs/更新日志.md');
const OUT  = path.join(ROOT, 'standalone/changelog.html');
const SHELL = path.join(ROOT, 'standalone/_shell.html');

/* ---------- 1. 读取并预处理 Markdown ---------- */
let md = fs.readFileSync(SRC, 'utf8');

// 站内互链 → 纯文本；移除「相关文档」行（独立版没有那些页面）
md = md.replace(/\[([^\]]+)\]\(\?p=doc&arg=[^)]*\)/g, '**$1**');
md = md.replace(/^\*\*相关文档\*\*：.*$/gm, '');

// 合并列表项的续行（以 2 个以上空格开头、且自身不是新列表项）
{
  const out = [];
  for (const ln of md.split('\n')) {
    const isItem = /^\s*(?:\d+[.)]|[-*])\s+/.test(ln);
    const isCont = !isItem && /^ {2,}\S/.test(ln);
    const prev = out.length > 0 ? out[out.length - 1] : '';
    const prevIsItem = /^\s*(?:\d+[.)]|[-*])\s+/.test(prev);
    if (isCont && prevIsItem) {
      // 中文之间不补空格，英文/数字之间补一个空格
      const sep = /[A-Za-z0-9`)\]]$/.test(prev.replace(/\s+$/, '')) ? ' ' : '';
      out[out.length - 1] = prev.replace(/\s+$/, '') + sep + ln.trim();
    } else {
      out.push(ln);
    }
  }
  md = out.join('\n').replace(/\n{3,}/g, '\n\n');
}

/* ---------- 2. 版本清单 ---------- */
const versions = [...md.matchAll(/^##\s*(v[\d.]+)\s*·\s*(\d{4}-\d{2}-\d{2})/gm)]
  .map(m => ({ v: m[1], d: m[2], slug: m[1].replace(/\./g, '_') }));

/* ---------- 3. 渲染 ---------- */
let html = mdToHtml(md);

html = html.replace(/<h2>(v[\d.]+) · (\d{4}-\d{2}-\d{2})<\/h2>/g,
  (_, v, d) => `<h2><span class="vtag mono">${v}</span><span class="vdate mono">${d}</span></h2>`);

const SEC = { '新增': 'add', '改动': 'chg', '修复': 'fix', '安全': 'safe', '文档': 'doc', '数据库': 'db', '优化': 'chg' };
html = html.replace(/<h3>([^<]+)<\/h3>/g, (_, t) =>
  `<h3 class="sec s-${SEC[t] || 'def'}">${t}</h3>`);

// 按版本切成独立玻璃卡；同时修正「子列表打断编号」导致的重新计数
const parts = html.split(/(?=<h2><span class="vtag)/);
const intro = (parts.shift() || '').replace(/<hr>\s*$/, '');
const cards = parts.map(chunk => {
  const m = chunk.match(/<h2><span class="vtag mono">(v[\d.]+)<\/span>/);
  const slug = m ? m[1].replace(/\./g, '_') : '';
  // 同一个小节内若有多个 <ol>（中间夹了子列表），为后续 <ol> 指定续接起点
  const fixed = chunk.split(/(?=<h3 class="sec)/).map(sec => {
    let seen = 0;
    return sec.replace(/<ol>([\s\S]*?)<\/ol>/g, (_, inner) => {
      const n = (inner.match(/<li>/g) || []).length;
      const out = `<ol style="counter-reset:n ${seen}">${inner}</ol>`;
      seen += n;
      return out;
    });
  }).join('');
  return `<section class="card glass" id="${slug}">${fixed}</section>`;
});

/* ---------- 4. 套壳 ---------- */
const nav = versions.map(o => `<a href="#${o.slug}">${o.v}</a>`).join('');
const shell = fs.readFileSync(SHELL, 'utf8');
const out = shell
  .replace('{{NAV}}', nav)
  .replace('{{HERO}}', intro)
  .replace('{{CARDS}}', cards.join('\n'))
  .replace('{{COUNT}}', String(versions.length))
  .replace('{{LATEST}}', versions.length ? versions[0].v : '—')
  .replace('{{UPDATED}}', versions.length ? versions[0].d : '—')
  .replace('{{GENERATED}}', new Date().toISOString().slice(0, 10));

fs.mkdirSync(path.dirname(OUT), { recursive: true });
fs.writeFileSync(OUT, out, 'utf8');

console.log(`✅ 已生成 ${path.relative(ROOT, OUT)}`);
console.log(`   版本 ${versions.length} 个：${versions.map(o => o.v).join(' / ')}`);
console.log(`   大小 ${(Buffer.byteLength(out) / 1024).toFixed(1)} KB`);
