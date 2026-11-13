#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""GitHub Pages 源目录自检
------------------------------------------------------------
site/ 是 GitHub Pages 的发布源（Settings → Pages → /site），
里面只放活动页副本与 .nojekyll。主站用的是仓库根的 themes.html。
两份内容必须完全一致，否则会出现「主站改了、Pages 还是旧的」。

用法：python3 tools/verify_pages.py
"""
import os
import sys

PROJ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(PROJ, 'themes.html')
DST = os.path.join(PROJ, 'site', 'themes.html')
NOJEKYLL = os.path.join(PROJ, 'site', '.nojekyll')

fails = []


def chk(cond, ok_msg, bad_msg):
    if cond:
        print('  OK   ' + ok_msg)
    else:
        fails.append(bad_msg)
        print('  FAIL ' + bad_msg)


print('== GitHub Pages 源目录 ==')
chk(os.path.isfile(SRC), '仓库根 themes.html 存在（主站 /themes.html）', '缺少仓库根 themes.html')
chk(os.path.isfile(DST), 'site/themes.html 存在（Pages 发布用）',
    '缺少 site/themes.html，先执行：cp themes.html site/themes.html')
chk(os.path.isfile(NOJEKYLL), 'site/.nojekyll 存在（免 Jekyll 处理）', '缺少 site/.nojekyll')

if os.path.isfile(SRC) and os.path.isfile(DST):
    a = open(SRC, 'rb').read()
    b = open(DST, 'rb').read()
    chk(a == b, '两份内容完全一致（%d 字节）' % len(a),
        '两份不一致：改完 themes.html 后请重新复制到 site/themes.html')
    text = a.decode('utf-8', 'replace')
    chk('Kimi 游戏榜' in text and 'themes' in text, '页面含活动页与主站链接', '页面缺少关键链接')
    for bad in ('<script src', 'cdn.', 'fonts.googleapis'):
        chk(bad not in text, '无外部依赖：%s' % bad, '引入了外部依赖：%s' % bad)
    chk('暂未' not in text and '3.61' not in text, '页面不含版本号（暂未部署新版本时的口径）',
        '页面写死了版本号')

print('-' * 60)
if fails:
    print('失败 %d 项' % len(fails))
    sys.exit(1)
print('全部通过')
