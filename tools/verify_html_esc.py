#!/usr/bin/env python3
"""检查前端 HTML 属性拼接是否都把用户可控值转义了。

背景：世界对话的图片地址曾被人塞进 `" onerror="alert(`，拼到 <img src="..."> 里
把内联脚本截断，整页报 Unexpected end of input。这类洞靠 code review 记不住，
每次出包前跑一遍。

判据：属性值里出现 `+ 变量 +` 或 `${变量}`，且变量不是常量、没被 esc()/encodeURIComponent()
包裹 → 报问题。
"""
import os
import re
import sys

SRC = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "assets", "js", "src")
ATTR = r'(?:src|href|style)'   # 只查真正高危的属性：data-*/title 里的内部常量不会带引号

# 'attr="' + EXPR + '"'   或   attr="${EXPR}"
P_PLUS = re.compile(ATTR + r'="\'\s*\+\s*([^+]+?)\s*\+\s*\'"')
P_TPL = re.compile(ATTR + r'="\$\{([^}]+)\}"')

SAFE = re.compile(r'^(?:esc|encodeURIComponent|json|String)\s*\(|^\d+$|^\'[^\']*\'$|^"[^"]*"$|^`[^`]*`$')


def scan():
    problems = []
    for root, _dirs, files in os.walk(SRC):
        for fn in files:
            if not fn.endswith(".js"):
                continue
            p = os.path.join(root, fn)
            for i, line in enumerate(open(p, encoding="utf-8"), 1):
                for pat in (P_PLUS, P_TPL):
                    for m in pat.finditer(line):
                        expr = m.group(1).strip()
                        if SAFE.search(expr):
                            continue
                        # 常量拼接（含运算符但无标识符）放过
                        if not re.search(r'[A-Za-z_$]', expr):
                            continue
                        problems.append((os.path.relpath(p, SRC), i, expr, line.strip()[:110]))
    return problems


def main():
    probs = scan()
    for f, ln, expr, ctx in probs:
        print("  ! %s:%d  %s" % (f, ln, expr))
        print("      %s" % ctx)
    print("检查完成：问题 %d 个" % len(probs))
    return 1 if probs else 0


if __name__ == "__main__":
    sys.exit(main())
