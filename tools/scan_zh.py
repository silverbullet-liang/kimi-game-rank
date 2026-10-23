#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""扫描 src 中未走 t() 的中文界面文案。

排除：注释、已在 t('…') 内的、属性值/类名/路由名/动作名、console 输出、正则。
输出：文件:行 片段，供人工确认后再补译。
"""
import os, re, sys

SRC = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'assets', 'js', 'src')
CJK = re.compile(r'[\u4e00-\u9fff]')
SKIP_FILE = {'i18n.js'}

# 这些上下文里的中文不是界面文案
SKIP_CTX = re.compile(
    r"(?:class(Name)?\s*=|data-[\w-]+\s*=|setAttribute\(|querySelector\(|getElementById\(|"
    r"===?\s*'[^']*'|!==?\s*'[^']*'|case\s+'|indexOf\(|includes\(|startsWith\(|"
    r"action\s*:|console\.|location\.hash|#/)"
)


def strip_comments(s):
    s = re.sub(r'/\*[\s\S]*?\*/', '', s)
    return re.sub(r'(?m)^\s*//.*$', '', s)


def literals(line):
    """返回 (内容, 起始列) 的字符串字面量，跳过 t('…') 里的。"""
    out = []
    i, n = 0, len(line)
    while i < n:
        c = line[i]
        if c in '\'"`':
            q, j, buf = c, i + 1, []
            while j < n:
                if line[j] == '\\':
                    j += 2; continue
                if line[j] == q:
                    break
                buf.append(line[j]); j += 1
            body = ''.join(buf)
            if CJK.search(body):
                prev = line[:i].rstrip()
                if not (prev.endswith('t(') or prev.endswith('t((')):
                    out.append(body)
            i = j + 1
        else:
            i += 1
    return out


shown = 0
for root, _, files in os.walk(SRC):
    for fn in sorted(files):
        if not fn.endswith('.js') or fn in SKIP_FILE:
            continue
        path = os.path.join(root, fn)
        for no, raw in enumerate(strip_comments(open(path, encoding='utf-8').read()).split('\n'), 1):
            if not CJK.search(raw):
                continue
            if SKIP_CTX.search(raw):
                # 整行像是属性/比较，但可能仍含界面文案，放宽：仍检查字面量
                pass
            for body in literals(raw):
                body = body.strip()
                if len(body) < 2:
                    continue
                print(f'{os.path.relpath(path, SRC)}:{no}\t{body[:90]}')
                shown += 1
print(f'--- 共 {shown} 处', file=sys.stderr)
