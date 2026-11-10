/**
 * 主题 JSON 解析器（schema: theme/1）
 * ------------------------------------------------------------
 * 职责：把一份 JSON 变成「看得见的主题」——CSS 变量、背景图层、图标覆盖、字体、强制明暗。
 * 原则：
 *   1. 任何字段缺失或非法都回退，绝不白屏、绝不抛错打断业务；
 *   2. 主题来自他人，按不可信输入处理：体积、URL、CSS、SVG 全部过滤；
 *   3. 不拼 innerHTML 承载主题内容，一律 createElement + 属性赋值。
 */
import { applyThemeIcons } from './icons.js';

const MAX_JSON = 1024 * 1024;      /* 1 MB */
const MAX_LAYERS = 12;
const MAX_LAYER_BYTES = 512 * 1024;
const MAX_BG_BYTES = 2 * 1024 * 1024;
const MAX_PATH = 4000;

/* 66 个合法位置（与规范 §8.1 一致） */
const SLOTS = {
  'nav.total': 1, 'nav.vote': 1, 'nav.gods': 1, 'nav.cold': 1, 'nav.chat': 1, 'nav.submit': 1,
  'nav.mine': 1, 'nav.help': 1, 'nav.feedback': 1, 'nav.admin': 1, 'nav.monitor': 1, 'nav.today': 1,
  'action.back': 1, 'action.close': 1, 'action.more': 1, 'action.vote': 1, 'action.star': 1,
  'action.comment': 1, 'action.reply': 1, 'action.copy': 1, 'action.share': 1, 'action.external': 1,
  'action.edit': 1, 'action.delete': 1, 'action.report': 1, 'action.send': 1, 'action.stop': 1,
  'action.retry': 1, 'action.download': 1, 'action.expand': 1,
  'field.search': 1, 'field.password': 1, 'field.emoji': 1, 'field.image': 1, 'field.video': 1,
  'field.upload': 1, 'field.mention': 1, 'field.model': 1, 'field.think': 1, 'field.filter': 1,
  'field.sort': 1, 'field.refresh': 1,
  'media.play': 1, 'media.pause': 1, 'media.fullscreen': 1, 'media.mute': 1,
  'state.loading': 1, 'state.empty': 1, 'state.error': 1, 'state.success': 1, 'state.warn': 1,
  'state.offline': 1,
  'user.login': 1, 'user.logout': 1, 'user.avatar': 1, 'user.admin': 1, 'user.verified': 1,
  'theme.light': 1, 'theme.dark': 1, 'theme.auto': 1, 'theme.palette': 1, 'theme.lang': 1,
  'doc.community': 1, 'doc.terms': 1, 'doc.privacy': 1, 'doc.features': 1, 'doc.criteria': 1,
  'doc.ranking': 1, 'doc.changelog': 1, 'doc.ai': 1,
};

/* 8 个可覆写的变量：只碰观感，不碰布局尺寸 */
const TOKENS = ['--glass-blur', '--glass-sat', '--glass-alpha', '--shadow', '--shadow-sm',
  '--border', '--border-soft', '--surface'];

/* 边框作用位置：语义键 → 站内真实选择器。主题只能给声明，不能给选择器。 */
const BORDER_KEYS = {
  card: '.card',
  cardTitle: '.card-title',
  button: '.btn',
  ghost: '.btn-ghost',
  iconButton: '.icon-btn',
  input: 'input:not([type="checkbox"]):not([type="radio"]), textarea, select',
  drawerItem: '.drawer-item',
  tabbar: '.tabbar',
  tab: '.tab',
  avatar: '.avatar',
  bubble: '.bubble',
  announce: '.announce',
};

/* 边框允许的属性：形状与描边，不允许影响布局与定位 */
const BORDER_PROPS = /^(border(-[a-z-]+)?|border-radius|box-shadow|outline|outline-offset|background(-color)?|background-image)$/;

/* 点击特效允许的属性：默认已 fixed + pointer-events:none，主题不可覆盖这两项 */
const FX_PROPS = /^(width|height|margin(-[a-z]+)?|border(-[a-z-]+)?|border-radius|box-shadow|background(-color)?|background-image|transform|opacity|filter|backdrop-filter|animation(-[a-z-]+)?)$/;

const CLICK_BASE = 'position:fixed;z-index:2147483000;pointer-events:none;'
  + 'width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:50%;'
  + 'background:var(--accent);opacity:.45;animation:tj-click-fade .55s ease-out forwards';

const STYLE_ID = 'tjson-style';const BG_ID = 'tjson-bg';
const FONT_LINK_ID = 'tjson-font';

const TJ = { theme: null, force: 'auto', errors: [], warns: [] };
let writtenVars = [];

/* ---------- 基础工具 ---------- */

function utf8Len(s) {
  var n = 0;
  for (var i = 0; i < s.length; i++) {
    var c = s.charCodeAt(i);
    n += c < 0x80 ? 1 : (c < 0x800 ? 2 : (c >= 0xd800 && c <= 0xdbff ? 4 : 3));
  }
  return n;
}

function isHex(c) { return typeof c === 'string' && /^#[0-9A-Fa-f]{6}$/.test(c); }

function escAttr(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
    .replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/'/g, '&#39;');
}

/** 只接受 https 与图片类 data URL；拒绝 http、协议相对、内网地址 */
function safeUrl(u, allowData) {
  if (typeof u !== 'string' || !u) { return ''; }
  if (u.slice(0, 5) === 'data:') {
    if (!allowData) { return ''; }
    return /^data:image\/(svg\+xml|png|jpeg|webp|gif)[;,]/i.test(u) ? u : '';
  }
  if (u.slice(0, 8) !== 'https://') { return ''; }
  var host = u.slice(8).split('/')[0].split(':')[0].toLowerCase();
  if (!host || host === 'localhost' || /\.local$/.test(host)) { return ''; }
  if (/^(10|127)\./.test(host) || /^192\.168\./.test(host) || /^172\.(1[6-9]|2\d|3[01])\./.test(host)) { return ''; }
  if (host === '0.0.0.0' || host === '[::1]' || host === '::1') { return ''; }
  return u;
}

/** SVG 过滤：去掉脚本、事件、外链与 foreignObject，保留图形本身 */
function sanitizeSvg(svg) {
  var s = String(svg);
  s = s.replace(/<\s*script[\s\S]*?<\s*\/\s*script\s*>/gi, '');
  s = s.replace(/<\s*script[^>]*\/?>/gi, '');
  s = s.replace(/<\s*foreignObject[\s\S]*?<\s*\/\s*foreignObject\s*>/gi, '');
  s = s.replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
  s = s.replace(/(xlink:href|href)\s*=\s*("(?:[^"]*)"|'(?:[^']*)')/gi, function (m, a, v) {
    var val = v.slice(1, -1);
    return val.charAt(0) === '#' ? m : '';
  });
  s = s.replace(/javascript\s*:/gi, '');
  s = s.replace(/<!\s*\[CDATA\[[\s\S]*?\]\]\s*>/g, '');
  s = s.replace(/<\s*style[\s\S]*?<\s*\/\s*style\s*>/gi, '');
  return s;
}

/** 解码 data URL 文本（只处理 utf8 / base64 的 svg） */
function dataUrlText(u) {
  var comma = u.indexOf(',');
  if (comma < 0) { return null; }
  var head = u.slice(0, comma), body = u.slice(comma + 1);
  try {
    if (/;base64/i.test(head)) {
      if (typeof atob !== 'function') { return null; }
      var bin = atob(body);
      try {
        if (typeof TextDecoder === 'function') {
          var bytes = new Uint8Array(bin.length);
          for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
          return new TextDecoder('utf-8').decode(bytes);
        }
      } catch (e) {}
      return bin;
    }
    return decodeURIComponent(body);
  } catch (e) { return null; }
}

/** CSS 声明过滤：禁导入、表达式、脚本协议与定位劫持 */
function sanitizeCss(css) {
  var s = String(css || '');
  if (s.length > 1200) { return ''; }
  if (/@import|expression\s*\(|javascript\s*:|-moz-binding|behavior\s*:/i.test(s)) { return ''; }
  if (/position\s*:\s*(fixed|absolute|sticky)/i.test(s)) { return ''; }
  if (/z-index|pointer-events|display\s*:\s*none/i.test(s)) { return ''; }
  if (/[<>{}]/.test(s)) { return ''; }
  return s;
}

/**
 * 按属性白名单筛声明：主题只能给「形状/描边/动效」这类属性，
 * 定位与层级由站点控制，避免盖住按钮或撑破布局。
 */
function sanitizeDecls(css, propRe) {
  if (typeof css !== 'string') { return ''; }
  var out = [], parts = css.split(';');
  for (var i = 0; i < parts.length; i++) {
    var d = parts[i].trim();
    if (!d) { continue; }
    var idx = d.indexOf(':');
    if (idx <= 0) { continue; }
    var prop = d.slice(0, idx).trim().toLowerCase();
    var val = d.slice(idx + 1).trim();
    if (!val || val.length > 160) { continue; }
    if (!propRe.test(prop)) { continue; }
    if (/url\s*\(|expression|@import|javascript:|\/\*|[<>{}]/i.test(val)) { continue; }
    out.push(prop + ':' + val);
  }
  return out.join(';');
}

/** 自定义关键帧：名字必须 tj- 开头，括号配平，禁定位与外链 */
function sanitizeKeyframes(css) {
  var s = String(css || '').trim();
  if (!s || s.length > 1400) { return ''; }
  if (!/^@keyframes\s+tj-[a-z0-9-]+\s*\{/.test(s)) { return ''; }
  if (/url\s*\(|expression|javascript:|@import|position\s*:|pointer-events/i.test(s)) { return ''; }
  var o = (s.match(/\{/g) || []).length, c2 = (s.match(/\}/g) || []).length;
  return o === c2 ? s : '';
}

/* ---------- 校验 ---------- */

function parseI18n(v, field, warns) {
  if (typeof v === 'string') { return v.trim() ? { zh: v.trim() } : null; }
  if (!v || typeof v !== 'object') { return null; }
  var out = {}, ok = false;
  ['zh', 'en', 'ja'].forEach(function (k) {
    if (typeof v[k] === 'string' && v[k].trim()) {
      out[k] = v[k].trim().slice(0, 32);
      ok = true;
    }
  });
  if (!ok) { return null; }
  Object.keys(v).forEach(function (k) {
    if (['zh', 'en', 'ja'].indexOf(k) < 0) { warns.push(field + ' 含未知语种 ' + k + '（忽略）'); }
  });
  return out;
}

function validate(data, rawLen) {
  var errors = [], warns = [];
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    return { errors: ['JSON 顶层必须是对象'], warns: warns };
  }
  if (rawLen > MAX_JSON) { errors.push('文件超过 1 MB'); }
  if (data.schema !== 'theme/1') { errors.push('schema 必须是 "theme/1"'); }
  if (typeof data.id !== 'string' || !/^[a-z0-9][a-z0-9_-]{1,31}$/.test(data.id)) {
    errors.push('id 非法（小写字母数字与连字符，2~32 位）');
  }

  var name = parseI18n(data.name, 'name', warns);
  if (!name) { errors.push('缺少或非法的 name'); }

  var colour = data.colour;
  if (typeof colour === 'string') { colour = { accent: colour }; }
  if (!colour || typeof colour !== 'object' || !isHex(colour.accent)) {
    errors.push('colour.accent 必须是 #RRGGBB');
  } else {
    if (colour.accent2 != null && !isHex(colour.accent2)) { errors.push('colour.accent2 必须是 #RRGGBB'); }
    if (colour.ink != null && !isHex(colour.ink)) { errors.push('colour.ink 必须是 #RRGGBB'); }
  }

  var desc = null;
  if (data.colourDesc != null) {
    desc = parseI18n(data.colourDesc, 'colourDesc', warns);
    if (!desc) { errors.push('colourDesc 非法'); }
  }

  var radius = null;
  var r = data.radius;
  if (r != null) {
    if (typeof r === 'number') { r = { lg: r }; }
    if (!r || typeof r !== 'object') {
      errors.push('radius 必须是数字或对象');
    } else {
      radius = {};
      ['lg', 'md', 'sm'].forEach(function (k) {
        if (r[k] != null) {
          if (typeof r[k] !== 'number' || r[k] < 0 || r[k] > 40) { errors.push('radius.' + k + ' 必须在 0~40'); }
          else { radius[k] = Math.round(r[k]); }
        }
      });
      if (radius.lg != null && radius.md == null) { radius.md = Math.round(radius.lg * 0.73); }
      if (radius.lg != null && radius.sm == null) { radius.sm = Math.round(radius.lg * 0.5); }
    }
  }

  /* 背景 */
  var bg = [], bgBytes = 0;
  if (data.background != null) {
    if (!Array.isArray(data.background)) {
      errors.push('background 必须是数组');
    } else if (data.background.length > MAX_LAYERS) {
      errors.push('background 最多 ' + MAX_LAYERS + ' 层');
    } else {
      data.background.forEach(function (layer, i) {
        if (!layer || typeof layer !== 'object') { errors.push('background[' + i + '] 必须是对象'); return; }
        var type = layer.type;
        if (['css', 'image', 'svg'].indexOf(type) < 0) { errors.push('background[' + i + '].type 非法'); return; }
        var one = { type: type };
        if (type === 'css') {
          one.css = sanitizeCss(layer.css);
          if (!one.css) { errors.push('background[' + i + '].css 为空或含危险语句'); return; }
        } else {
          var src = safeUrl(layer.src, true);
          if (!src) { errors.push('background[' + i + '].src 必须是 https 或图片 data URL'); return; }
          if (src.slice(0, 5) === 'data:') {
            if (utf8Len(src) > MAX_LAYER_BYTES) { errors.push('background[' + i + '] 单层超过 512 KB'); return; }
            bgBytes += utf8Len(src);
            if (/^data:image\/svg\+xml/i.test(src)) {
              var txt = dataUrlText(src);
              if (txt) {
                /* 含脚本或内联事件的 SVG 不做修补，直接拒绝：
                   与云端的准入口径保持一致，也免得用户以为自己用的是「净化过」的图。 */
                if (/(<\s*script|\son[a-z]+\s*=|foreignObject|javascript\s*:)/i.test(txt)) {
                  errors.push('background[' + i + '] 的 SVG 含脚本或事件，已拒绝');
                  return;
                }
                var clean = sanitizeSvg(txt);
                if (!clean || clean.indexOf('<svg') < 0) { errors.push('background[' + i + '] SVG 非法'); return; }
                one.svgText = clean;
              }
            }
          }
          one.src = src;
        }
        one.position = cssToken(layer.position) || 'center';
        one.size = cssToken(layer.size) || (type === 'css' ? 'auto' : 'cover');
        one.repeat = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'].indexOf(layer.repeat) >= 0 ? layer.repeat : 'no-repeat';
        one.opacity = (typeof layer.opacity === 'number' && layer.opacity >= 0 && layer.opacity <= 1) ? layer.opacity : 1;
        one.blend = cssToken(layer.blend) || 'normal';
        one.mask = layer.mask ? sanitizeCss(layer.mask) : '';
        one.animate = layer.animate === 'drift' || layer.animate === 'float' || layer.animate === 'pulse' ? layer.animate : '';
        one.fixed = layer.fixed === true;
        one.rect = parseRect(layer.rect);
        bg.push(one);
      });
      if (bgBytes > MAX_BG_BYTES) { errors.push('背景 data URL 合计超过 2 MB'); }
    }
  }

  /* 图标 */
  var icons = {}, iconCss = {};
  if (data.icons != null) {
    if (!data.icons || typeof data.icons !== 'object' || Array.isArray(data.icons)) {
      errors.push('icons 必须是对象');
    } else {
      Object.keys(data.icons).forEach(function (slot) {
        if (!SLOTS[slot]) { errors.push('图标位置 ' + slot + ' 不在命名表内'); return; }
        var v = data.icons[slot];
        if (v === null) { return; }
        if (typeof v === 'string') {
          var path = v.trim();
          if (!path || path.length > MAX_PATH || !/^[MmZzLlHhVvCcSsQqTtAa0-9eE,.\-\s]+$/.test(path)) {
            errors.push('图标 ' + slot + ' 的 path 非法'); return;
          }
          icons[slot] = path;
          return;
        }
        if (!v || typeof v !== 'object') { errors.push('图标 ' + slot + ' 必须是字符串、对象或 null'); return; }
        if (v.type === 'css') {
          var css = sanitizeCss(v.css);
          if (!css) { errors.push('图标 ' + slot + ' 的 css 非法'); return; }
          icons[slot] = 'css|' + slot;
          iconCss[slot] = css;
        } else if (v.type === 'url' || v.type === 'data') {
          var u = safeUrl(v.src, v.type === 'data');
          if (!u) { errors.push('图标 ' + slot + ' 的地址非法'); return; }
          if (u.slice(0, 5) === 'data:' && /^data:image\/svg\+xml/i.test(u)) {
            var t2 = dataUrlText(u);
            if (t2 && /(<\s*script|\son[a-z]+\s*=|foreignObject|javascript\s*:)/i.test(t2)) {
              errors.push('图标 ' + slot + ' 的 SVG 含脚本或事件，已拒绝'); return;
            }
            var c2 = t2 ? sanitizeSvg(t2) : '';
            if (!c2 || c2.indexOf('<svg') < 0) { errors.push('图标 ' + slot + ' 的 SVG 非法'); return; }
          }
          icons[slot] = (v.type === 'url') ? 'img:' + u : u;
        } else {
          errors.push('图标 ' + slot + ' 的 type 非法');
        }
      });
    }
  }

  /* 字体 */
  var font = null;
  if (data.font != null) {
    if (!data.font || typeof data.font !== 'object') {
      errors.push('font 必须是对象');
    } else {
      var body = data.font.body;
      font = { family: '', url: '', kind: 'stylesheet', weight: '', fallback: [] };
      if (body && typeof body === 'object') {
        if (body.url) {
          var fu = safeUrl(body.url, false);
          if (!fu) { errors.push('font.body.url 必须是 https'); }
          else { font.url = fu; }
        }
        if (typeof body.family === 'string' && /^[A-Za-z0-9 _\-.]{1,64}$/.test(body.family)) {
          font.family = body.family;
        }
        if (body.kind === 'font' || body.kind === 'stylesheet') { font.kind = body.kind; }
        if (typeof body.weight === 'string' && /^[0-9 ]{1,20}$/.test(body.weight)) { font.weight = body.weight; }
        if (font.url && !font.family) { errors.push('font.body 给了 url 却没有合法的 family'); }
      }
      if (Array.isArray(data.font.fallback)) {
        font.fallback = data.font.fallback.filter(function (x) {
          return typeof x === 'string' && /^[A-Za-z0-9 _\-,.]{1,48}$/.test(x);
        }).slice(0, 6);
      }
    }
  }

  /* 边框：语义键 → 声明串，只作用于站内既有元素 */
  var borders = {};
  if (data.borders != null) {
    if (!data.borders || typeof data.borders !== 'object' || Array.isArray(data.borders)) {
      errors.push('borders 必须是对象');
    } else {
      Object.keys(data.borders).forEach(function (k) {
        if (!BORDER_KEYS[k]) { warns.push('borders 忽略未知位置 ' + k); return; }
        var decl = sanitizeDecls(data.borders[k], BORDER_PROPS);
        if (decl) { borders[k] = decl; }
        else { warns.push('borders.' + k + ' 没有可用声明，已忽略'); }
      });
    }
  }

  /* 点击特效：作用于站点在点击处生成的 .tj-click 元素 */
  var effects = null;
  if (data.effects != null) {
    if (!data.effects || typeof data.effects !== 'object' || Array.isArray(data.effects)) {
      errors.push('effects 必须是对象');
    } else {
      var click = data.effects.click;
      if (click != null) {
        if (typeof click === 'string') { click = { css: click }; }
        if (!click || typeof click !== 'object') {
          errors.push('effects.click 必须是字符串或对象');
        } else {
          var fx = sanitizeDecls(click.css, FX_PROPS);
          var kf = click.keyframes ? sanitizeKeyframes(click.keyframes) : '';
          if (fx || kf) { effects = { click: { css: fx, keyframes: kf } }; }
          else { errors.push('effects.click 没有可用的样式'); }
        }
      }
    }
  }

  /* force / tokens */
  var force = 'auto';
  if (data.force != null) {
    if (['auto', 'light', 'dark'].indexOf(data.force) < 0) { errors.push('force 只能是 auto / light / dark'); }
    else { force = data.force; }
  }

  var tokens = {};
  if (data.tokens != null) {
    if (!data.tokens || typeof data.tokens !== 'object') {
      errors.push('tokens 必须是对象');
    } else {
      Object.keys(data.tokens).forEach(function (k) {
        if (TOKENS.indexOf(k) < 0) {
          warns.push('tokens 忽略白名单外的键 ' + k);
          return;
        }
        var val = String(data.tokens[k] == null ? '' : data.tokens[k]);
        if (!val || val.length > 120 || /important|var\(|url\(|[{}<>]/i.test(val)) {
          warns.push('tokens.' + k + ' 值不合法，已忽略');
          return;
        }
        tokens[k] = val;
      });
    }
  }

  var known = ['schema', 'id', 'name', 'colour', 'colourDesc', 'radius', 'background',
    'icons', 'font', 'force', 'tokens', 'borders', 'effects', 'meta'];
  Object.keys(data).forEach(function (k) {
    if (known.indexOf(k) < 0) { warns.push('忽略未知字段 ' + k); }
  });

  if (errors.length) { return { errors: errors, warns: warns }; }

  return {
    errors: [], warns: warns,
    theme: {
      id: data.id, name: name, desc: desc, colour: colour, radius: radius,
      background: bg, icons: icons, iconCss: iconCss, font: font, force: force,
      tokens: tokens, borders: borders, effects: effects, meta: data.meta || null,
    },
  };
}

/** CSS 值令牌：只允许字母数字、空格、百分号、点、斜杠与常见单位 */
function cssToken(v) {
  if (typeof v !== 'string' || !v.trim() || v.length > 64) { return ''; }
  var s = v.trim();
  return /^[A-Za-z0-9 %.,\/\-()#]+$/.test(s) ? s : '';
}

/** 图层矩形：用 CSS 长度定位，拒绝任何函数与表达式 */
function parseRect(r) {
  if (!r || typeof r !== 'object') { return null; }
  var out = {}, hit = false;
  ['top', 'right', 'bottom', 'left', 'width', 'height'].forEach(function (k) {
    var v = r[k];
    if (v == null) { return; }
    var s = String(v).trim();
    if (s === 'auto' || /^-?\d+(\.\d+)?(px|%|vh|vw|rem|em)$/.test(s)) { out[k] = s; hit = true; }
  });
  return hit ? out : null;
}

/* ---------- 应用 ---------- */

function lighten(hex, n) {
  var r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
  r = Math.min(255, r + n * 2); g = Math.min(255, g + n * 2); b = Math.min(255, b + n * 2);
  return '#' + [r, g, b].map(function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
}

/** 主色上的文字色：按相对亮度选黑或白 */
function contrastInk(hex) {
  var r = parseInt(hex.slice(1, 3), 16) / 255, g = parseInt(hex.slice(3, 5), 16) / 255, b = parseInt(hex.slice(5, 7), 16) / 255;
  var f = function (c) { return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); };
  var L = .2126 * f(r) + .7152 * f(g) + .0722 * f(b);
  return (L + .05) / .05 > 4.5 ? '#000000' : '#FFFFFF';
}

function setVar(k, v) {
  /* 节日皮肤用 !important 锁了 --accent 等变量，只有 inline 的 !important 能压过去 */
  document.documentElement.style.setProperty(k, v, 'important');
  if (writtenVars.indexOf(k) < 0) { writtenVars.push(k); }
}

function buildLayers(list) {
  var box = document.createElement('div');
  box.id = BG_ID;
  box.setAttribute('aria-hidden', 'true');
  list.forEach(function (l) {
    var d = document.createElement('div');
    d.className = 'tj-layer';
    if (l.rect) {
      ['top', 'right', 'bottom', 'left', 'width', 'height'].forEach(function (k) {
        if (l.rect[k]) { d.style[k] = l.rect[k]; }
      });
    }
    if (l.type === 'css') {
      d.style.backgroundImage = l.css;
    } else {
      var src = l.src;
      /* SVG 走过滤后的结果，重新编码为 data URL */
      if (l.svgText) {
        src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(l.svgText);
      }
      d.style.backgroundImage = 'url("' + src.replace(/"/g, '%22') + '")';
    }
    d.style.backgroundPosition = l.position;
    d.style.backgroundSize = l.size;
    d.style.backgroundRepeat = l.repeat;
    d.style.opacity = String(l.opacity);
    d.style.mixBlendMode = l.blend;
    if (l.mask) { d.style.webkitMaskImage = l.mask; d.style.maskImage = l.mask; }
    if (l.animate) { d.className += ' tj-anim-' + l.animate; }
    if (l.fixed) { d.style.backgroundAttachment = 'fixed'; }
    box.appendChild(d);
  });
  document.body.appendChild(box);
}

function injectStyle(theme) {
  var css = [];
  /* 图标 CSS 型：按 slot 生成独立规则，不用 style 属性 */
  Object.keys(theme.iconCss).forEach(function (slot) {
    css.push('html[data-tjson] .ti-' + slot.replace(/\./g, '-') + '{' + theme.iconCss[slot] + '}');
  });
  /* 边框：按语义键映射到站内既有元素，一律 !important 才压得过皮肤 */
  Object.keys(theme.borders || {}).forEach(function (k) {
    css.push('html[data-tjson] ' + BORDER_KEYS[k] + '{' + theme.borders[k] + ' !important}');
  });
  /* 点击特效：基础层由站点固定（fixed + 穿透），主题只往上叠形状与动效 */
  if (theme.effects && theme.effects.click) {
    var fx = theme.effects.click;
    if (fx.keyframes) { css.push(fx.keyframes); }
    css.push('@keyframes tj-click-fade{to{opacity:0;transform:scale(1.9)}}');
    css.push('html[data-tjson] .tj-click{' + CLICK_BASE + (fx.css ? ';' + fx.css : '') + '}');
  }

  /* 字体：只改字族，不动字号与行高 */
  if (theme.font && theme.font.family) {
    var fam = '"' + theme.font.family + '"';
    var fb = theme.font.fallback.length ? ', ' + theme.font.fallback.join(', ') : '';
    css.push('html[data-tjson] body,html[data-tjson] button,html[data-tjson] input,'
      + 'html[data-tjson] textarea,html[data-tjson] select,html[data-tjson] .doc-body{'
      + 'font-family:' + fam + fb + ' !important}');
  }
  if (!css.length) { return; }
  var el = document.createElement('style');
  el.id = STYLE_ID;
  el.textContent = css.join('\n');
  document.head.appendChild(el);
}

let clickBound = false;

function onClickFx(e) {
  if (e && typeof e.button === 'number' && e.button !== 0) { return; }
  var d = document.createElement('i');
  d.className = 'tj-click';
  d.setAttribute('aria-hidden', 'true');
  d.style.left = (e.clientX | 0) + 'px';
  d.style.top = (e.clientY | 0) + 'px';
  document.body.appendChild(d);
  setTimeout(function () { if (d.parentNode) { d.parentNode.removeChild(d); } }, 1500);
}

function bindClickFx(on) {
  if (on && !clickBound) {
    document.addEventListener('pointerdown', onClickFx, true);
    clickBound = true;
  } else if (!on && clickBound) {
    document.removeEventListener('pointerdown', onClickFx, true);
    clickBound = false;
    var old = document.querySelectorAll('.tj-click');
    for (var i = 0; i < old.length; i++) { if (old[i].parentNode) { old[i].parentNode.removeChild(old[i]); } }
  }
}

function injectFont(theme) {
  var f = theme.font;
  if (!f || !f.url || !f.family) { return; }
  if (f.kind === 'stylesheet') {
    var link = document.createElement('link');
    link.id = FONT_LINK_ID;
    link.rel = 'stylesheet';
    link.href = f.url;
    link.crossOrigin = 'anonymous';
    document.head.appendChild(link);
    return;
  }
  var css = '@font-face{font-family:"' + f.family + '";src:url("' + f.url + '")'
    + (f.weight ? ';font-weight:' + f.weight : '')
    + ';font-display:swap}';
  var el = document.createElement('style');
  el.id = FONT_LINK_ID;
  el.textContent = css;
  document.head.appendChild(el);
}

/** 只重写变量（明暗切换后 applyTheme 会覆盖掉主题色，需要补一次） */
function applyVars(theme) {
  var c = theme.colour;
  setVar('--accent', c.accent);
  setVar('--accent-2', c.accent2 || lighten(c.accent, 12));
  setVar('--accent-ink', c.ink || contrastInk(c.accent));
  setVar('--toggle-on', c.accent);
  setVar('--slider-fill', c.accent);
  if (theme.radius) {
    if (theme.radius.lg != null) { setVar('--radius-lg', theme.radius.lg + 'px'); }
    if (theme.radius.md != null) { setVar('--radius-md', theme.radius.md + 'px'); }
    if (theme.radius.sm != null) { setVar('--radius-sm', theme.radius.sm + 'px'); }
  }
  Object.keys(theme.tokens).forEach(function (k) { setVar(k, theme.tokens[k]); });
}

function apply(theme) {
  clear();
  document.documentElement.setAttribute('data-tjson', theme.id);
  applyVars(theme);
  if (theme.background && theme.background.length) {
    /* 站点 body 自带三层渐变背景，会盖住 z-index:-1 的图层。
       主题自带背景时把 body 背景让位（由主题的第一层充当底色）。 */
    document.documentElement.setAttribute('data-tjbg', '1');
    buildLayers(theme.background);
  }
  applyThemeIcons(theme.icons);
  injectStyle(theme);
  injectFont(theme);
  bindClickFx(!!(theme.effects && theme.effects.click));
  TJ.theme = theme;
  TJ.force = theme.force || 'auto';
  window.__TJSON_FORCE = TJ.force;
  if (TJ.force !== 'auto') {
    document.documentElement.dataset.theme = TJ.force;
  }
  /* 常驻 UI（抽屉）不会自己重绘：图标换了要让它立刻反映出来 */
  try { if (typeof window.__rerenderDrawer === 'function') { window.__rerenderDrawer(); } } catch (e) {}
  try { window.dispatchEvent(new CustomEvent('tjson:applied', { detail: { id: theme.id, force: TJ.force } })); } catch (e) {}
  return true;
}

function clear() {
  writtenVars.forEach(function (k) { document.documentElement.style.removeProperty(k); });
  writtenVars = [];
  delete document.documentElement.dataset.tjson;
  delete document.documentElement.dataset.tjbg;
  delete window.__TJSON_FORCE;
  var bg = document.getElementById(BG_ID);
  if (bg) { bg.remove(); }
  ['tjson-style', 'tjson-font'].forEach(function (id) {
    var n = document.getElementById(id);
    if (n) { n.remove(); }
  });
  bindClickFx(false);
  applyThemeIcons({});
  try { if (typeof window.__rerenderDrawer === 'function') { window.__rerenderDrawer(); } } catch (e) {}
  TJ.theme = null;
  TJ.force = 'auto';
}

/* ---------- 对外 ---------- */

/** 解析并校验；返回 { ok, theme, errors, warns } */
function parse(raw) {
  var text = (typeof raw === 'string') ? raw : JSON.stringify(raw || {});
  var len = utf8Len(text);
  var data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    return { ok: false, errors: ['JSON 解析失败：' + e.message], warns: [] };
  }
  var r = validate(data, len);
  if (r.errors.length) { return { ok: false, theme: null, errors: r.errors, warns: r.warns }; }
  return { ok: true, theme: r.theme, errors: [], warns: r.warns };
}

/** 解析 + 应用；失败时不改动现有主题 */
function applyRaw(raw) {
  var r = parse(raw);
  if (!r.ok) { TJ.errors = r.errors; TJ.warns = r.warns; return r; }
  apply(r.theme);
  TJ.errors = [];
  TJ.warns = r.warns;
  return r;
}

/** 读取当前主题的摘要，供 UI 展示 */
function current() {
  if (!TJ.theme) { return null; }
  return {
    id: TJ.theme.id,
    name: TJ.theme.name,
    desc: TJ.theme.desc,
    accent: TJ.theme.colour.accent,
    force: TJ.theme.force,
    layers: TJ.theme.background.length,
    icons: Object.keys(TJ.theme.icons).length,
    font: TJ.theme.font ? TJ.theme.font.family : '',
    borders: Object.keys(TJ.theme.borders || {}).length,
    click: !!(TJ.theme.effects && TJ.theme.effects.click),
  };
}

/** 启动时恢复本机保存的主题；数据来自 localStorage，同样要过校验（可被篡改） */
function restoreThemeJson() {
  var text = '';
  try { text = localStorage.getItem('kimgr_tjson') || ''; } catch (e) { return; }
  if (!text) { return; }
  var r = applyRaw(text);
  if (!r.ok) {
    try { localStorage.removeItem('kimgr_tjson'); } catch (e2) {}
  }
}

const ThemeJSON = {
  parse: parse,
  apply: apply,
  applyRaw: applyRaw,
  clear: clear,
  current: current,
  reapply: function () { if (TJ.theme) { applyVars(TJ.theme); } },
  restore: restoreThemeJson,
  slots: SLOTS,
  limits: { json: MAX_JSON, layers: MAX_LAYERS, layerBytes: MAX_LAYER_BYTES, bgBytes: MAX_BG_BYTES },
};
window.ThemeJSON = ThemeJSON;
window.__TJSON_REAPPLY = ThemeJSON.reapply;
window.restoreThemeJson = restoreThemeJson;
