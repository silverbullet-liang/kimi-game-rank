#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""产物完整性闸门：src 模块必须全部登记进 ORDER，导出常量必须落进产物 app.js。

背景：引入 assets/js/src/config.js（导出 UI_PAGE / MON_DEFAULT）时漏登记
tools/build.py 的 ORDER，该模块从未被合并 —— pages/detail.js 在渲染评论区时抛
「UI_PAGE is not defined」，表现为**未捕获的 Promise 错误**（游客浏览作品即触发）。
jscheck 只查未声明函数/重名，拦不住"导出常量没合并"，故单列此闸门。

用法：python3 tools/verify_bundle.py
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'assets', 'js', 'src')
BUNDLE = os.path.join(ROOT, 'assets', 'js', 'app.js')
BUILD = os.path.join(ROOT, 'tools', 'build.py')

total = 0
fails = []


def check(cond, label):
    global total
    total += 1
    if not cond:
        fails.append(label)


def _slice(text, start_marker, end_marker):
    i = text.index(start_marker)
    return text[i:text.index(end_marker, i)]


build_txt = open(BUILD, encoding='utf-8').read()
order_txt = _slice(build_txt, 'ORDER = [', ']')
exempt_txt = _slice(build_txt, 'ORDER_EXEMPT = ', 'ORDER = [')
order = re.findall(r"'([^']+\.js)'", order_txt)
exempt = set(re.findall(r"'([^']+\.js)'", exempt_txt))
listed = set(order)

check(len(order) > 0, 'build.py 的 ORDER 为空或解析失败')
check('router.js' in exempt, 'router.js 应在 ORDER_EXEMPT（与 app.js 的 navigate 重名，刻意不合并）')

# 1) src 下每个模块都必须登记（或明确豁免）
src_files = []
for dirpath, dirnames, filenames in os.walk(SRC):
    dirnames[:] = [d for d in dirnames if d not in ('.git', 'node_modules')]
    for fn in filenames:
        if fn.endswith('.js'):
            rel = os.path.relpath(os.path.join(dirpath, fn), SRC).replace(os.sep, '/')
            src_files.append(rel)

check(len(src_files) > 0, 'src 目录下未找到任何 .js 模块')
for rel in sorted(src_files):
    if rel == 'app.js':
        continue
    check(rel in listed or rel in exempt, '模块未登记进 ORDER：%s' % rel)

# 2) 每个被合并模块的导出常量，必须能在产物里找到定义
bundle = open(BUNDLE, encoding='utf-8').read() if os.path.exists(BUNDLE) else ''
check(bool(bundle), '产物 assets/js/app.js 不存在或为空')

exported = []
for rel in src_files:
    if rel not in listed:
        continue
    mod = open(os.path.join(SRC, rel), encoding='utf-8').read()
    for name in re.findall(r'export\s+const\s+([A-Za-z_$][\w$]*)', mod):
        exported.append((rel, name))

check(len(exported) > 0, '未从任何模块解析出 export const（解析规则可能失效）')
for rel, name in exported:
    check(re.search(r'\bconst\s+%s\b' % re.escape(name), bundle) is not None,
          '导出常量未落进产物：%s（来自 %s）' % (name, rel))

# 3) 具体的已知坑位（回归锚点）
check('const UI_PAGE' in bundle, '产物缺少 const UI_PAGE（详情页评论区会抛 UI_PAGE is not defined）')
check('const MON_DEFAULT' in bundle, '产物缺少 const MON_DEFAULT（监测页会抛 MON_DEFAULT is not defined）')
# 引用侧也必须在（防止"常量定义着、页面却退回硬编码"的假安全）
for rel, ref in [('pages/detail.js', 'UI_PAGE.comments'), ('pages/detail.js', 'UI_PAGE.repliesFold'), ('pages/monitor.js', 'MON_DEFAULT')]:
    mod = open(os.path.join(SRC, rel), encoding='utf-8').read()
    check(ref in mod, '%s 应引用 %s（单一来源原则）' % (rel, ref))

# 4) 首屏图片优先级（LCP 长尾回归锚点）：关键图必须带 fetchpriority="high"
check('fetchpriority="high"' in bundle, '产物缺少 fetchpriority="high"（首屏/详情关键图未提优先级，LCP 会变差）')
check('fetchpriority="low"' in bundle, '产物缺少 fetchpriority="low"（非首屏图未让出带宽）')

print('产物完整性闸门：%d 项，失败 %d 项' % (total, len(fails)))
for f in fails:
    print('  FAIL:', f)
sys.exit(1 if fails else 0)
