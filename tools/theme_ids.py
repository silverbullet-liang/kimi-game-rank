#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
节日主题 JSON 的 id 规范化：统一为「中文名拼音 + 版本号」。

用法：
    python3 tools/theme_ids.py --check     # 只检查、不写文件（CI 闸门）
    python3 tools/theme_ids.py             # 批量写回 themes/*.theme.json

命名规则（两端校验器的硬约束，见 theme-json.js / app/theme_json.php）：
    ^[a-z0-9][a-z0-9_-]{1,31}$   —— 只允许小写字母、数字、下划线、连字符，2–32 位。
因此版本号里的点号必须去掉：3.76.0 → v3760。

例：重阳节 → chongyang-v3760；1024 程序员节 → 1024chengxuyuanjie-v3760。

幂等：结果只由 name.zh 与 APP_VERSION 决定，重复运行不会再改。
"""
import argparse
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
THEMES = ROOT / 'themes'
BOOTSTRAP = ROOT / 'app' / 'bootstrap.php'
ID_RE = re.compile(r'^[a-z0-9][a-z0-9_-]{1,31}$')


def app_version() -> str:
    text = BOOTSTRAP.read_text(encoding='utf-8')
    m = re.search(r"define\('APP_VERSION'\s*,\s*'([0-9.]+)'\s*\)", text)
    if not m:
        raise SystemExit('读取 APP_VERSION 失败')
    return m.group(1)


def version_tag(ver: str) -> str:
    return 'v' + ver.replace('.', '')


def pinyin_id(name: str, fallback: str) -> str:
    try:
        from pypinyin import lazy_pinyin
    except ImportError:
        raise SystemExit('缺少 pypinyin：pip install pypinyin')
    s = ''.join(lazy_pinyin(name)).lower()
    s = re.sub(r'[^a-z0-9]+', '', s)
    if not s:
        s = re.sub(r'[^a-z0-9]+', '', fallback.lower())
    return s


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--check', action='store_true', help='只检查，不写文件')
    args = ap.parse_args()

    tag = version_tag(app_version())
    files = sorted(THEMES.glob('*.theme.json'))
    if not files:
        raise SystemExit('themes/ 下没有主题 JSON')

    changes = []
    seen = {}
    for path in files:
        data = json.loads(path.read_text(encoding='utf-8'))
        name = data.get('name', {})
        zh = name.get('zh') if isinstance(name, dict) else str(name or '')
        base = pinyin_id(str(zh or ''), path.stem)
        new_id = base + '-' + tag
        if len(new_id) > 32:
            raise SystemExit('%s → %s 超过 32 位' % (path.name, new_id))
        if not ID_RE.match(new_id):
            raise SystemExit('%s → %s 不符合 ID 规则' % (path.name, new_id))
        if new_id in seen:
            raise SystemExit('ID 冲突：%s 与 %s 都要用 %s' % (seen[new_id], path.name, new_id))
        seen[new_id] = path.name
        old = data.get('id', '')
        if old != new_id:
            changes.append((path, old, new_id, data))

    print('版本 %s → 后缀 %s；主题 %d 个；需改 %d 个' % (app_version(), tag, len(files), len(changes)))
    for path, old, new, _ in changes:
        print('  %-34s %-22s → %s' % (path.name, old, new))

    if args.check:
        return 1 if changes else 0

    for path, old, new, data in changes:
        data['id'] = new
        path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('已写回 %d 个文件' % len(changes))
    return 0


if __name__ == '__main__':
    sys.exit(main())
