#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""GitHub Pages 源目录自检
------------------------------------------------------------
site/ 是 GitHub Pages 的发布源（Settings → Pages → gh-pages 分支根目录，
主站另有仓库根的同名文件）。多页并存时，仓库根与 site/ 的副本必须完全一致，
否则会出现「主站改了、Pages 还是旧的」。

用法：python3 tools/verify_pages.py
"""
import os
import sys

PROJ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PAGES = ['themes.html']
NOJEKYLL = os.path.join(PROJ, 'site', '.nojekyll')

fails = []


def chk(cond, ok_msg, bad_msg):
    if cond:
        print('  OK   ' + ok_msg)
    else:
        fails.append(bad_msg)
        print('  FAIL ' + bad_msg)


print('== GitHub Pages 源目录 ==')
chk(os.path.isfile(NOJEKYLL), 'site/.nojekyll 存在（免 Jekyll 处理）', '缺少 site/.nojekyll')

for name in PAGES:
    src = os.path.join(PROJ, name)
    dst = os.path.join(PROJ, 'site', name)
    chk(os.path.isfile(src), '%s 存在（主站 /%s）' % (name, name), '缺少仓库根 ' + name)
    chk(os.path.isfile(dst), 'site/%s 存在（Pages 发布用）' % name,
        '缺少 site/%s，先执行：cp %s site/%s' % (name, name, name))
    if not (os.path.isfile(src) and os.path.isfile(dst)):
        continue
    a = open(src, 'rb').read()
    b = open(dst, 'rb').read()
    chk(a == b, '%s 两份内容一致（%d 字节）' % (name, len(a)),
        '%s 两份不一致：改完请重新复制到 site/' % name)
    text = a.decode('utf-8', 'replace')
    chk('<script src' not in text and 'cdn.' not in text and 'fonts.googleapis' not in text,
        '%s 无外部依赖' % name, '%s 引入了外部依赖' % name)
    chk('暂未' not in text and '3.61' not in text, '%s 不含版本号' % name, '%s 写死了版本号' % name)

print('-' * 60)
if fails:
    print('失败 %d 项' % len(fails))
    sys.exit(1)
print('全部通过')
