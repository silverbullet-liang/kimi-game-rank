#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""闸门：src 里不得出现局部变量 `t`。

i18n 的 t() 是全站唯一保留的 t。历史事故：
  · detail.js  `const t = document.createElement('button')`  → t is not a function（评论区展开按钮）
  · lobby.js   `const t = d.token || {}` 出现在 t() 调用之后 → Cannot access 't' before initialization（AI 对话页）
两者都只在特定分支触发，静态跑页面很难发现，所以用「一律禁止」这条硬规则兜住。
"""
import os, re, sys

SRC = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'assets', 'js', 'src')
BAD = re.compile(r'\b(?:const|let|var)\s+t\s*=')


def strip_mask(s):
    """把注释与字符串内容替换成空格（Worker 源码字符串里的 var t 不算本站变量）。"""
    out = list(s)
    i, n = 0, len(s)
    while i < n:
        c = s[i]
        if c == '/' and i + 1 < n and s[i + 1] == '/':
            while i < n and s[i] != '\n':
                out[i] = ' '; i += 1
        elif c == '/' and i + 1 < n and s[i + 1] == '*':
            while i < n and not (s[i] == '*' and i + 1 < n and s[i + 1] == '/'):
                if s[i] != '\n': out[i] = ' '
                i += 1
            i += 2
        elif c in '"\'`':
            q = c; i += 1
            while i < n:
                if s[i] == '\\':
                    out[i] = ' '; i += 1
                    if i < n: out[i] = ' '
                    i += 1; continue
                if s[i] == q:
                    out[i] = ' '; i += 1; break
                if s[i] != '\n': out[i] = ' '
                i += 1
        else:
            i += 1
    return ''.join(out)


bad = 0
for root, _, files in os.walk(SRC):
    for fn in sorted(files):
        if not fn.endswith('.js'):
            continue
        p = os.path.join(root, fn)
        raw = open(p, encoding='utf-8').read()
        lines = raw.split('\n')
        masked = strip_mask(raw).split('\n')
        for no, line in enumerate(masked, 1):
            if BAD.search(line):
                print(f'{os.path.relpath(p, SRC)}:{no}  {lines[no-1].strip()[:90]}')
                bad += 1
if bad:
    print(f'❌ 局部变量 t 共 {bad} 处 —— 请改名（t 只留给翻译函数）')
    sys.exit(1)
print('✅ 没有局部变量 t 遮蔽翻译函数')
