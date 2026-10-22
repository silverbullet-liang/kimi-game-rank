/**
 * 界面多语言：中 / 英 / 日。
 *
 * 设计要点（决定了后续维护成本）：
 *  · key 直接用中文原文，例如 t('登录')。译文表里没有的 key 原样返回中文，
 *    所以「漏译」只会退回中文，不会白屏或出现 key。新增文案不必先登记。
 *  · 语言优先级：用户手动选择（localStorage） > 浏览器首选语言 > 中文。
 *  · 文档语言跟随界面：中文看中文文档，英文与日文都看英文文档（日文文档暂不提供）。
 */
export const LANGS = [
  { k: 'zh', name: '中文' },
  { k: 'en', name: 'English' },
  { k: 'ja', name: '日本語' }
];

export const I18N = { lang: 'zh', dict: {}, ready: false };

const KEY = 'lang';

function valid(k) {
  for (let i = 0; i < LANGS.length; i++) { if (LANGS[i].k === k) { return true; } }
  return false;
}

export function i18nDetect() {
  try {
    const saved = localStorage.getItem(KEY);
    if (saved && valid(saved)) { return saved; }
  } catch (e) {}
  if (window.__LANG && valid(window.__LANG)) { return window.__LANG; }
  const list = navigator.languages || [navigator.language || ''];
  for (let i = 0; i < list.length; i++) {
    const s = String(list[i] || '').toLowerCase();
    if (s.indexOf('ja') === 0) { return 'ja'; }
    if (s.indexOf('en') === 0) { return 'en'; }
    if (s.indexOf('zh') === 0) { return 'zh'; }
  }
  return 'zh';
}

export async function i18nInit() {
  I18N.lang = i18nDetect();
  document.documentElement.lang = (I18N.lang === 'zh' ? 'zh-CN' : I18N.lang);
  if (I18N.lang === 'zh') { I18N.ready = true; return; }
  try {
    const r = await fetch('assets/i18n/' + I18N.lang + '.json', { cache: 'no-cache' });
    if (r.ok) { I18N.dict = await r.json(); }
  } catch (e) { /* 取不到就退回中文 */ }
  I18N.ready = true;
}

/** 取译文；缺译原样返回，保证界面永远有内容 */
export function t(s) {
  if (I18N.lang === 'zh') { return s; }
  const v = I18N.dict[s];
  return (v == null || v === '') ? s : v;
}

/** 文档页应加载哪种语言的文档 */
export function docLangOf(lang) {
  return (lang || I18N.lang) === 'zh' ? 'zh' : 'en';
}

export function setLang(k) {
  if (!valid(k) || k === I18N.lang) { return; }
  try {
    localStorage.setItem(KEY, k);
    localStorage.setItem('doclang', docLangOf(k));
    document.cookie = 'kimgr_lang=' + k + ';path=/;max-age=31536000;samesite=lax';
  } catch (e) {}
  location.reload();
}

/** 把带 data-i18n="中文key" 的静态节点翻译成当前语言（服务端渲染的文案也能换） */
export function applyI18nDom(root) {
  const box = root || document;
  const nodes = box.querySelectorAll('[data-i18n]');
  for (let i = 0; i < nodes.length; i++) {
    const k = nodes[i].getAttribute('data-i18n');
    const v = t(k);
    if (v !== k) { nodes[i].textContent = v; }
  }
}

/* 运行时文本翻译：源码里大量 HTML 是模板串（`<span>世界对话</span>`），
   逐处改造成 ${t()} 会踩到「单引号串里插值」的语法坑，改为渲染后按字典替换文本节点。
   只做「整段精确匹配」，且跳过用户内容容器，避免把作品标题、昵称、消息内容译掉。 */
const SKIP_SEL = '.uname,.bubble,.mv-title,.rank-title,.w-title,.doc,.md-body,textarea,input,select,script,style,[data-notrans]';

export function applyI18nText(root) {
  if (I18N.lang === 'zh') { return; }
  const box = root || document.body;
  if (!box || !box.querySelectorAll) { return; }
  const walker = document.createTreeWalker(box, NodeFilter.SHOW_TEXT, null);
  const jobs = [];
  let node;
  while ((node = walker.nextNode())) {
    const raw = node.nodeValue;
    if (!raw) { continue; }
    const core = raw.trim();
    if (core.length < 2 || core.length > 40) { continue; }
    const hit = I18N.dict[core];
    if (hit == null || hit === '') { continue; }
    const el = node.parentElement;
    if (el && el.closest && el.closest(SKIP_SEL)) { continue; }
    jobs.push([node, raw.replace(core, hit)]);
  }
  for (let i = 0; i < jobs.length; i++) { jobs[i][0].nodeValue = jobs[i][1]; }
}
