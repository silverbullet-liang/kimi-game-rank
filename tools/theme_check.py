#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""主题 JSON 校验器（theme/1）
用法：python3 tools/theme_check.py <主题.json> [更多.json ...]
按《主题JSON规范 v1.0》检查结构层规则；资源层（URL 可达、CORS）需联网，默认不做。
"""
import json
import re
import sys

ICON_SLOTS = {
    'nav': ['total', 'vote', 'gods', 'cold', 'chat', 'submit', 'mine', 'help', 'feedback', 'admin', 'monitor', 'today'],
    'action': ['back', 'close', 'more', 'vote', 'star', 'comment', 'reply', 'copy', 'share', 'external',
               'edit', 'delete', 'report', 'send', 'stop', 'retry', 'download', 'expand'],
    'tab': ['rank', 'chat', 'mine'],
    'ui': ['menu', 'theme'],
    'field': ['search', 'password', 'emoji', 'image', 'video', 'upload', 'mention', 'model', 'think',
              'filter', 'sort', 'refresh'],
    'media': ['play', 'pause', 'fullscreen', 'mute'],
    'state': ['loading', 'empty', 'error', 'success', 'warn', 'offline'],
    'user': ['login', 'logout', 'avatar', 'admin', 'verified'],
    'theme': ['light', 'dark', 'auto', 'palette', 'lang'],
    'doc': ['community', 'terms', 'privacy', 'features', 'criteria', 'ranking', 'changelog', 'ai'],
}
SLOTS = {'%s.%s' % (g, k) for g, ks in ICON_SLOTS.items() for k in ks}
BORDER_KEYS = {'card', 'cardTitle', 'button', 'ghost', 'iconButton', 'input',
               'drawerItem', 'tabbar', 'tab', 'avatar', 'bubble', 'announce',
               'topbar', 'seg', 'switch', 'chip', 'toast', 'drawer',
               'monCard', 'panelPlain', 'toolCard', 'empty'}
COLOR_RE = [
    re.compile(r'^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$'),
    re.compile(r'^rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+%?\s*)?\)$'),
    re.compile(r'^hsla?\(\s*[\d.]+\s*,\s*[\d.]+%\s*,\s*[\d.]+%\s*(,\s*[\d.]+%?\s*)?\)$'),
]
BORDER_PROPS = re.compile(r'^(border(-[a-z-]+)?|border-radius|box-shadow|outline|outline-offset|background(-color)?|background-image)$')
FX_PROPS = re.compile(r'^(width|height|margin(-[a-z]+)?|border(-[a-z-]+)?|border-radius|box-shadow|background(-color)?|background-image|transform|opacity|filter|backdrop-filter|animation(-[a-z-]+)?)$')
DANGER_VAL = re.compile(r'url\s*\(|expression|@import|javascript:|[<>{}]', re.I)

TOKENS = {'--glass-blur', '--glass-sat', '--glass-alpha', '--shadow', '--shadow-sm',
          '--border', '--border-soft', '--surface'}
LANGS = ('zh', 'en', 'ja')
MAX_BG_TOTAL = 2 * 1024 * 1024
MAX_JSON = 1024 * 1024


def err(msg):
    return '✗ ' + msg


def check_i18n(v, field, warn):
    """name / colourDesc：字符串或三语对象，必须至少有一个非空值"""
    if isinstance(v, str):
        return None if v.strip() else err('%s 为空' % field)
    if not isinstance(v, dict):
        return err('%s 必须是字符串或对象' % field)
    unknown = set(v) - set(LANGS)
    if unknown:
        warn.append('%s 含未知语种 %s（会被忽略）' % (field, ','.join(sorted(unknown))))
    if not any(isinstance(v.get(k), str) and v.get(k).strip() for k in LANGS):
        return err('%s 至少要有一个语种非空' % field)
    for k in LANGS:
        if k in v and isinstance(v[k], str) and len(v[k]) > 32:
            return err('%s.%s 超过 32 字符' % (field, k))
    return None


def check_colour(v):
    if isinstance(v, str):
        v = {'accent': v}
    if not isinstance(v, dict) or not isinstance(v.get('accent'), str):
        return err('colour 缺少 accent')
    if not re.fullmatch(r'#[0-9A-Fa-f]{6}', v['accent']):
        return err('colour.accent 必须是 #RRGGBB')
    for k in ('accent2', 'ink'):
        if k in v and not re.fullmatch(r'#[0-9A-Fa-f]{6}', str(v[k])):
            return err('colour.%s 必须是 #RRGGBB' % k)
    return None


def check_radius(v):
    if isinstance(v, (int, float)):
        v = {'lg': v}
    if not isinstance(v, dict):
        return err('radius 必须是数字或对象')
    for k in ('lg', 'md', 'sm'):
        if k in v:
            if not isinstance(v[k], (int, float)) or not (0 <= v[k] <= 40):
                return err('radius.%s 必须在 0~40' % k)
    return None


def check_bg(v, warn):
    if not isinstance(v, list):
        return err('background 必须是数组')
    total = 0
    for i, layer in enumerate(v):
        if not isinstance(layer, dict):
            return err('background[%d] 必须是对象' % i)
        t = layer.get('type')
        if t not in ('css', 'image', 'svg'):
            return err('background[%d].type 非法：%r' % (i, t))
        if t == 'css' and not isinstance(layer.get('css'), str):
            return err('background[%d] 缺少 css' % i)
        if t in ('image', 'svg'):
            src = layer.get('src')
            if not isinstance(src, str) or not src:
                return err('background[%d] 缺少 src' % i)
            if src.startswith('data:'):
                if not re.match(r'data:image/(svg\+xml|png|jpeg|webp)[;,]', src):
                    return err('background[%d] data URL 类型不支持' % i)
                if re.search(r'%3Cscript|<script|javascript:|foreignObject', src, re.I):
                    return err('background[%d] 的 SVG 含脚本或事件' % i)
                total += len(src)
                if len(src) > 512 * 1024:
                    return err('background[%d] 单层 data URL 超过 512KB' % i)
            elif not src.startswith('https://'):
                return err('background[%d].src 必须是 https 或 data' % i)
        if 'opacity' in layer and not (0 <= float(layer['opacity']) <= 1):
            return err('background[%d].opacity 必须在 0~1' % i)
        if 'animate' in layer and layer['animate'] not in ('drift', 'float', 'pulse') \
                and not isinstance(layer['animate'], str):
            return err('background[%d].animate 非法' % i)
        css = layer.get('css') or ''
        if re.search(r'@import|expression\(|javascript:', css, re.I):
            return err('background[%d].css 含危险语句' % i)
    if total > MAX_BG_TOTAL:
        return err('背景 data URL 合计超过 2MB')
    return None


def check_icons(v, warn):
    if not isinstance(v, dict):
        return err('icons 必须是对象')
    for k, val in v.items():
        if k not in SLOTS:
            return err('图标位置 %r 不在命名表内（见规范 §8.1）' % k)
        if val is None:
            continue
        if isinstance(val, str):
            if not val.strip():
                return err('图标 %s 是空字符串' % k)
            continue
        if not isinstance(val, dict):
            return err('图标 %s 必须是字符串、对象或 null' % k)
        t = val.get('type')
        if t == 'css':
            if not isinstance(val.get('css'), str):
                return err('图标 %s 缺少 css' % k)
            if re.search(r'@import|expression\(|javascript:', val['css'], re.I):
                return err('图标 %s 的 css 含危险语句' % k)
        elif t in ('url', 'data'):
            src = val.get('src')
            if not isinstance(src, str) or not src:
                return err('图标 %s 缺少 src' % k)
            if t == 'url' and not src.startswith('https://'):
                return err('图标 %s 的 url 必须是 https' % k)
            if t == 'data' and not src.startswith('data:image/'):
                return err('图标 %s 的 data URL 类型不支持' % k)
        else:
            return err('图标 %s 的 type 非法：%r' % (k, t))
    return None


def _decls(css, prop_re):
    if not isinstance(css, str):
        return None
    keep = []
    for part in css.split(';'):
        d = part.strip()
        if not d:
            continue
        if ':' not in d:
            continue
        prop, val = d.split(':', 1)
        prop = prop.strip().lower()
        val = val.strip()
        if not prop_re.match(prop) or not val or len(val) > 160:
            continue
        if DANGER_VAL.search(val):
            continue
        keep.append(prop + ':' + val)
    return ';'.join(keep)


def check_dialog(v):
    if not isinstance(v, dict):
        return err('dialog 必须是对象')
    for k, val in v.items():
        if k == 'dark':
            if not isinstance(val, dict):
                return err('dialog.dark 必须是对象')
            for k2, v2 in val.items():
                if k2 not in ('bg', 'fg', 'border', 'backdrop'):
                    return err('dialog.dark 未知键 %r' % k2)
                if not isinstance(v2, str) or not any(r.match(v2.strip()) for r in COLOR_RE):
                    return err('dialog.dark.%s 必须是 hex / rgb() / hsl() 颜色' % k2)
            continue
        if k not in ('bg', 'fg', 'border', 'backdrop'):
            return err('dialog 未知键 %r（可用 bg/fg/border/backdrop）' % k)
        if not isinstance(val, str) or not any(r.match(val.strip()) for r in COLOR_RE):
            return err('dialog.%s 必须是 hex / rgb() / hsl() 颜色' % k)
    return None


def check_borders(v):
    if not isinstance(v, dict):
        return err('borders 必须是对象')
    for k, decl in v.items():
        if k not in BORDER_KEYS:
            return err('borders 位置 %r 不在白名单内（可用：%s）' % (k, ','.join(sorted(BORDER_KEYS))))
        if not _decls(decl, BORDER_PROPS):
            return err('borders.%s 没有可用声明（只允许边框/圆角/阴影/描边/背景）' % k)
    return None


def check_effects(v):
    if not isinstance(v, dict):
        return err('effects 必须是对象')
    click = v.get('click')
    if click is None:
        return None
    if isinstance(click, str):
        click = {'css': click}
    if not isinstance(click, dict):
        return err('effects.click 必须是字符串或对象')
    if not _decls(click.get('css'), FX_PROPS) and not click.get('keyframes'):
        return err('effects.click 没有可用样式')
    kf = click.get('keyframes')
    if kf:
        if not isinstance(kf, str) or len(kf) > 1400:
            return err('effects.click.keyframes 非法')
        if not re.match(r'^@keyframes\s+tj-[a-z0-9-]+\s*\{', kf):
            return err('keyframes 名字必须以 tj- 开头')
        if re.search(r'url\s*\(|expression|javascript:|@import|position\s*:|pointer-events', kf, re.I):
            return err('keyframes 含禁用属性')
        if kf.count('{') != kf.count('}'):
            return err('keyframes 括号不配平')
    return None


def check_font(v):
    if not isinstance(v, dict):
        return err('font 必须是对象')
    body = v.get('body')
    if body is not None:
        if not isinstance(body, dict):
            return err('font.body 必须是对象')
        if body.get('url'):
            if not str(body['url']).startswith('https://'):
                return err('font.body.url 必须是 https')
            if not body.get('family'):
                return err('font.body 给了 url 却没有 family')
        if body.get('kind') not in (None, 'stylesheet', 'font'):
            return err('font.body.kind 非法')
    if 'fallback' in v and not isinstance(v['fallback'], list):
        return err('font.fallback 必须是数组')
    return None


def check(data, raw_len):
    problems, warns = [], []
    if raw_len > MAX_JSON:
        problems.append(err('文件超过 1MB'))
    if data.get('schema') != 'theme/1':
        problems.append(err('schema 必须是 "theme/1"'))
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{1,31}', str(data.get('id', ''))):
        problems.append(err('id 非法（小写字母数字与连字符，2~32 位）'))
    if 'name' not in data:
        problems.append(err('缺少 name'))
    else:
        e = check_i18n(data['name'], 'name', warns)
        if e:
            problems.append(e)
    if 'colourDesc' in data:
        e = check_i18n(data['colourDesc'], 'colourDesc', warns)
        if e:
            problems.append(e)
    if 'colour' not in data:
        problems.append(err('缺少 colour'))
    else:
        e = check_colour(data['colour'])
        if e:
            problems.append(e)
    if 'radius' in data:
        e = check_radius(data['radius'])
        if e:
            problems.append(e)
    if 'background' in data:
        e = check_bg(data['background'], warns)
        if e:
            problems.append(e)
    if 'icons' in data:
        e = check_icons(data['icons'], warns)
        if e:
            problems.append(e)
    if 'dialog' in data:
        e = check_dialog(data['dialog'])
        if e:
            problems.append(e)
    if 'borders' in data:
        e = check_borders(data['borders'])
        if e:
            problems.append(e)
    if 'effects' in data:
        e = check_effects(data['effects'])
        if e:
            problems.append(e)
    if 'font' in data:
        e = check_font(data['font'])
        if e:
            problems.append(e)
    if 'force' in data and data['force'] not in ('auto', 'light', 'dark'):
        problems.append(err('force 只能是 auto / light / dark'))
    if 'tokens' in data:
        if not isinstance(data['tokens'], dict):
            problems.append(err('tokens 必须是对象'))
        else:
            bad = set(data['tokens']) - TOKENS
            if bad:
                problems.append(err('tokens 含白名单外的键：%s' % ','.join(sorted(bad))))
            for k, val in data['tokens'].items():
                if 'important' in str(val) or 'var(' in str(val):
                    problems.append(err('tokens.%s 不允许 !important 或 var()' % k))
    unknown = set(data) - {'schema', 'id', 'name', 'colour', 'colourDesc', 'radius',
                           'background', 'icons', 'font', 'force', 'tokens', 'borders',
                           'effects', 'dialog', 'meta'}
    if unknown:
        warns.append('忽略未知顶层字段：%s' % ','.join(sorted(unknown)))
    return problems, warns


def main(paths):
    if not paths:
        print('用法：python3 tools/theme_check.py <主题.json>')
        return 2
    bad = 0
    for p in paths:
        try:
            raw = open(p, 'rb').read()
            data = json.loads(raw.decode('utf-8'))
        except Exception as e:
            print('%s\n  %s' % (p, err('无法解析 JSON：%s' % e)))
            bad += 1
            continue
        problems, warns = check(data, len(raw))
        name = data.get('name')
        label = name.get('zh') if isinstance(name, dict) else name
        if problems:
            bad += 1
            print('%s  「%s」' % (p, label))
            for x in problems:
                print('  ' + x)
        else:
            icons = data.get('icons') or {}
            bg = data.get('background') or []
            bd = data.get('borders') or {}
            fx = '点击' if (data.get('effects') or {}).get('click') else '无'
            dlg = '自定义' if data.get('dialog') else '默认'
            print('✓ %s  「%s」  图标 %d · 背景 %d 层 · 边框 %d 处 · 点击 %s · 对话框 %s · 强制 %s'
                  % (p, label, len(icons), len(bg), len(bd), fx, dlg, data.get('force', 'auto')))
        for w in warns:
            print('  ! ' + w)
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
