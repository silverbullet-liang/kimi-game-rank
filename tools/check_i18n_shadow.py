#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""检测 i18n 函数 t() 被局部变量遮蔽的地方（t is not a function 的根因）。

做法：找出 src 中所有 `const|let|var t =` 声明，用简易 tokenizer 定位其作用域块，
若块内存在 t( 调用且该调用不在更内层同名声明之后，即报告冲突。
"""
import os, re, sys

SRC = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'assets', 'js', 'src')
DECL = re.compile(r'\b(?:const|let|var)\s+t\s*=')
CALL = re.compile(r'(?<![.\w$])t\s*\(')


def strip_mask(s):
    """把注释/字符串内容替换成空格（保留长度与换行），便于做括号配对。"""
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
            for k in (i, min(i + 1, n - 1)):
                if k < n: out[k] = ' '
            i += 2
        elif c in '"\'`':
            q = c; out[i] = ' '; i += 1
            while i < n:
                if s[i] == '\\':
                    out[i] = ' '; i += 1
                    if i < n: out[i] = ' '; i += 1
                    continue
                if s[i] == q:
                    out[i] = ' '; i += 1; break
                if s[i] != '\n': out[i] = ' '
                i += 1
        else:
            i += 1
    return ''.join(out)


def block_end(mask, pos):
    """从 pos 起向后找所在块的结束位置：先碰到 } 且深度归 -1 时结束。"""
    depth, i, n = 0, pos, len(mask)
    while i < n:
        ch = mask[i]
        if ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth < 0:
                return i
        i += 1
    return n


def block_start(mask, pos):
    depth, i = 0, pos
    while i >= 0:
        ch = mask[i]
        if ch == '}':
            depth += 1
        elif ch == '{':
            if depth == 0:
                return i
            depth -= 1
        i -= 1
    return 0


def line_of(text, pos):
    return text.count('\n', 0, pos) + 1


bad = 0
for root, _, files in os.walk(SRC):
    for fn in sorted(files):
        if not fn.endswith('.js'):
            continue
        path = os.path.join(root, fn)
        src = open(path, encoding='utf-8').read()
        mask = strip_mask(src)
        for m in DECL.finditer(mask):
            bs = block_start(mask, m.start())
            be = block_end(mask, m.start())
            scope = mask[be + 1:] if False else mask[m.end():be]
            # 只看声明之后的同一块：从声明处到所在块结束
            tail_start = m.end()
            depth = 0
            i = tail_start
            stop = len(mask)
            while i < len(mask):
                if mask[i] == '{':
                    depth += 1
                elif mask[i] == '}':
                    if depth == 0:
                        stop = i; break
                    depth -= 1
                i += 1
            seg = mask[tail_start:stop]
            hits = [c.start() for c in CALL.finditer(seg)]
            if hits:
                ln = line_of(src, m.start())
                where = [line_of(src, tail_start + h) for h in hits[:4]]
                print(f'{os.path.relpath(path, SRC)}:{ln}  局部 t 遮蔽，其后在 {where} 仍调用 t()')
                bad += 1
print(('冲突 %d 处' % bad) if bad else '✅ 无遮蔽冲突')
sys.exit(1 if bad else 0)
