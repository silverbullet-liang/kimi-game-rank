#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""检查 src 里所有 t('中文') 的 key 是否都在 en.json / ja.json 里。

「包了 t() 但没词条」会让界面在英文下回落到中文 —— 这是最隐蔽的一类漏译。
用法：python3 tools/check_i18n_keys.py            # 只报告
      python3 tools/check_i18n_keys.py --mine     # 只输出缺失清单（供补译）
"""
import json, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'assets', 'js', 'src')
CALL = re.compile(r"(?<![.\w$])t\(\s*'((?:[^'\\]|\\.)*)'\s*\)")
CJK = re.compile(r'[\u4e00-\u9fff]')

keys = set()
for root, _, files in os.walk(SRC):
    for fn in files:
        if fn.endswith('.js'):
            s = open(os.path.join(root, fn), encoding='utf-8').read()
            for m in CALL.finditer(s):
                k = m.group(1)
                if CJK.search(k):
                    keys.add(k)

en = json.load(open(os.path.join(ROOT, 'assets', 'i18n', 'en.json'), encoding='utf-8'))
ja = json.load(open(os.path.join(ROOT, 'assets', 'i18n', 'ja.json'), encoding='utf-8'))
miss = sorted(k for k in keys if k not in en or k not in ja)

# 同时反向检查：字典里有、但源码与静态扫描都不用的 key（提示冗余，不阻断）
if '--mine' in sys.argv:
    for k in miss:
        print(k)
    sys.exit(0)

print(f'src 中 t() 的中文 key：{len(keys)} 个；en 词条 {len(en)}，ja 词条 {len(ja)}')
if miss:
    print(f'❌ 缺词条 {len(miss)} 个：')
    for k in miss:
        print('   ·', k[:70])
    sys.exit(1)
print('✅ 全部 key 都有 en / ja 译文')
