#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""公开文档中英一致性闸门（离线，出包/发版前必跑）。

检查三件事：
1. 《更新日志》英文版必须逐版覆盖中文版自 EN_FROM 起的所有版本；
2. 七组成对文档的二级/三级节数必须一致（差 >0 即失败）；
3. 中文文档里出现的「关键新增点」必须在对应英文文档出现同义标记。

退出码非 0 表示未通过。
"""
import io
import os
import re
import sys

DOCS = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'assets', 'docs')
EN_FROM = (3, 40, 0)   # 英文更新日志承诺覆盖的起点，见页面开头说明

# (中文, 英文)；None 表示只查更新日志
PAIRS = [
    ('功能说明.md', 'en/features.md'),
    ('AI 使用说明.md', 'en/ai.md'),
    ('社区公约.md', 'en/community.md'),
    ('评分标准.md', 'en/scoring.md'),
    ('入榜规则.md', 'en/admission.md'),
    ('用户协议.md', 'en/terms.md'),
    ('隐私政策.md', 'en/privacy.md'),
]

# 关键新增点：(中文文档, 英文文档, 中文标记, 英文标记) —— 少一个就算漏译
SPOTS = [
    ('功能说明.md', 'en/features.md', '48', '48'),
    ('功能说明.md', 'en/features.md', 'Turnstile', 'Turnstile'),
    ('隐私政策.md', 'en/privacy.md', 'Turnstile', 'Turnstile'),
    ('隐私政策.md', 'en/privacy.md', 'trycap.dev', 'trycap.dev'),
    ('用户协议.md', 'en/terms.md', 'Turnstile', 'Turnstile'),
    ('用户协议.md', 'en/terms.md', 'trycap.dev', 'trycap.dev'),
]

fails = []


def read(rel):
    p = os.path.join(DOCS, rel)
    if not os.path.isfile(p):
        fails.append('缺少文件 ' + rel)
        return ''
    return io.open(p, encoding='utf-8').read()


def ver_key(v):
    return tuple(int(x) for x in v.lstrip('v').split('.'))


def heads(text):
    return [m.group(1) for m in re.finditer(r'^## (v[\d.]+)', text, re.M)]


# 1. 更新日志逐版对齐
cn_logs, en_logs = heads(read('更新日志.md')), heads(read('en/changelog.md'))
need = [v for v in cn_logs if ver_key(v) >= EN_FROM]
missing = [v for v in need if v not in en_logs]
if missing:
    fails.append('英文更新日志缺少 %d 个版本：%s' % (len(missing), '、'.join(missing[:8]) + (' …' if len(missing) > 8 else '')))
print('[更新日志] 中文 %d 版 / 英文 %d 版；英文需覆盖 ≥v%d.%d.%d，缺 %d 个'
      % (len(cn_logs), len(en_logs), EN_FROM[0], EN_FROM[1], EN_FROM[2], len(missing)))

# 2. 七组成对文档节数
for cn, en in PAIRS:
    a, b = read(cn), read(en)
    ha = len(re.findall(r'^#{2,3} ', a, re.M))
    hb = len(re.findall(r'^#{2,3} ', b, re.M))
    bad = ha != hb
    if bad:
        fails.append('%s 节数不一致：中文 %d / 英文 %d' % (cn, ha, hb))
    print('[节数] %-14s %2d : % -2d %s' % (cn, ha, hb, 'OK' if not bad else 'FAIL'))

# 3. 关键新增点
for cn, en, ma, mb in SPOTS:
    a, b = read(cn), read(en)
    if ma in a and mb not in b:
        fails.append('%s 有「%s」而 %s 没有对应标记「%s」' % (cn, ma, en, mb))
print('[关键点] 抽查 %d 项' % len(SPOTS))

print('-' * 52)
if fails:
    for f in fails:
        print('FAIL ' + f)
    print('未通过：%d 项' % len(fails))
    sys.exit(1)
print('全部通过')
