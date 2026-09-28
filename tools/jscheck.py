#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""前端源码静态校验（构建前必跑）。

为什么需要：assets/js/src 下所有模块会被合并成**同一个作用域**的单文件 app.js，
任何跨文件的顶层标识符重名都会导致「后定义覆盖前定义」，出现「改了代码却不生效」的
隐蔽故障（历史上已踩过一次：lobby.js 里出现两个 send()）。

检查项：
  1) 跨文件顶层标识符重名（const/let/var/function/class）
  2) 同一文件内重复声明同名函数
用法：python3 tools/jscheck.py   （发现问题退出码 1）
"""
import os
import re
import sys
import collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'assets', 'js', 'src')

TOP_DECL = re.compile(r'^(?:export\s+)?(?:async\s+)?(const|let|var|function|class)\s+([A-Za-z_$][\w$]*)')
# 仅匹配「顶格」函数声明：带缩进的是嵌套函数，属于独立作用域，不构成冲突
FUNC_DECL = re.compile(r'^(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(')
ORDER_RE = re.compile(r"^ORDER = \[(.*?)\]", re.M | re.S)


def build_order():
    """只检查真正参与合并的文件（与 tools/build.py 的 ORDER 保持一致）"""
    txt = open(os.path.join(ROOT, 'tools', 'build.py'), encoding='utf-8').read()
    m = ORDER_RE.search(txt)
    if not m:
        return None
    names = re.findall(r"'([^']+\.js)'", m.group(1))
    return [os.path.join(SRC, n.replace('/', os.sep)) for n in names]


def js_files():
    order = build_order()
    if order:
        return [p for p in order if os.path.isfile(p)]
    out = []
    for root, _, files in os.walk(SRC):
        for f in files:
            if f.endswith('.js'):
                out.append(os.path.join(root, f))
    return sorted(out)


def scan():
    top = collections.defaultdict(list)      # name -> [file...]
    dup_in_file = []                          # (file, name, count)
    for path in js_files():
        rel = os.path.relpath(path, SRC)
        seen = collections.Counter()
        for line in open(path, encoding='utf-8'):
            m = TOP_DECL.match(line)
            if m:
                top[m.group(2)].append(rel)
            fm = FUNC_DECL.match(line)
            if fm:
                seen[fm.group(1)] += 1
        for name, n in seen.items():
            if n > 1:
                dup_in_file.append((rel, name, n))
    dup_cross = {k: v for k, v in top.items() if len(v) > 1}
    return dup_cross, dup_in_file


def main():
    dup_cross, dup_in_file = scan()
    bad = 0
    if dup_cross:
        bad += 1
        print('❌ 跨文件顶层标识符重名（合并后会互相覆盖）：')
        for k, v in sorted(dup_cross.items()):
            print(f'   {k}: {", ".join(v)}')
    if dup_in_file:
        bad += 1
        print('❌ 同一文件内重复声明函数：')
        for f, name, n in dup_in_file:
            print(f'   {f}: {name} × {n}（同文件顶格声明重复）')
    n_files = len(js_files())
    if bad:
        print(f'扫描 {n_files} 个文件：存在冲突，构建已中止')
        return 1
    print(f'扫描 {n_files} 个文件：无重名冲突')
    return 0


if __name__ == '__main__':
    sys.exit(main())
