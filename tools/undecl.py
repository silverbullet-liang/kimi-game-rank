#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""构建闸门：查「引用了不存在的函数」与「引用了未声明的 container / root」。

为什么需要：
  · 这类写错既不是语法错误（node --check 通过），也不是重名冲突（jscheck 通过），
    只在**运行时**爆「xxx is not defined」，页面直接失效；
  · 历史上已经三次把线上打坏：
      container is not defined（panel.js 概览渲染）
      root is not defined（同一处，参数漏接）
      openEditor is not defined（编辑作品按钮 —— 调用写了，函数从来没写）

检查方式：
  A. 全量：在**打包产物**上找出所有「裸函数调用」（形如 name(...)，排除 .name(...)），
     若该名字既没有在任何地方声明、也不在内置全局白名单里，即判定为漏写函数。
     声明来源覆盖：function / class / const|let|var（含解构）、函数与箭头形参、
     catch 形参、for 头、对象方法简写。
  B. 针对性：顶层函数体内引用 container / root 但既非形参也未声明。

用法：python3 tools/undecl.py   （发现问题退出码 1）
"""
import io
import os
import re
import sys
import glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'assets', 'js', 'src')
BUNDLE = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'assets', 'js', 'app.js')

FUNCS = re.compile(r'^(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(([^)]*)\)')
WATCH = ('container', 'root')

# 内置全局：允许被调用而无需声明
GLOBALS = set("""
window document console Math JSON Object Array String Number Boolean Date RegExp Error TypeError
RangeError Promise Map Set WeakMap WeakSet Symbol BigInt Proxy Reflect parseInt parseFloat isNaN
isFinite encodeURIComponent decodeURIComponent encodeURI decodeURI setTimeout clearTimeout
setInterval clearInterval queueMicrotask requestAnimationFrame cancelAnimationFrame fetch alert
confirm prompt structuredClone atob btoa URL URLSearchParams Blob File FileReader FormData Headers
Request Response AbortController TextEncoder TextDecoder Intl performance navigator location history
localStorage sessionStorage indexedDB caches crypto Image Audio Event CustomEvent MutationObserver
IntersectionObserver ResizeObserver matchMedia getComputedStyle DOMParser XMLHttpRequest WebSocket
Worker Notification screen devicePixelRatio customElements Function Uint8Array Int8Array Uint16Array
Uint32Array Int16Array Int32Array Float32Array Float64Array ArrayBuffer DataView module require
exports globalThis self parent top frames open close print
""".split())

# 语言关键字：后面跟括号但不是函数调用
KEYWORDS = set("""
if for while switch catch return typeof delete void new in of instanceof do else case break continue
throw try finally yield await async function class const let var super this null true false undefined
""".split())

RE_FUNC_DECL = re.compile(r'\bfunction\s+([A-Za-z_$][\w$]*)')
RE_CLASS = re.compile(r'\bclass\s+([A-Za-z_$][\w$]*)')
RE_VAR = re.compile(r'\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)')
RE_DESTR_OBJ = re.compile(r'\b(?:const|let|var)\s*\{([^}]*)\}')
RE_DESTR_ARR = re.compile(r'\b(?:const|let|var)\s*\[([^\]]*)\]')
RE_CATCH = re.compile(r'\bcatch\s*\(([^)]*)\)')
RE_FORHEAD = re.compile(r'\bfor\s*\(([^)]*)\)')
RE_FN_PARAMS = re.compile(r'\bfunction\s*[A-Za-z_$\w]*\s*\(([^)]*)\)')
RE_ARROW_BRACED = re.compile(r'\(([^)]*)\)\s*=>')
RE_ARROW_SINGLE = re.compile(r'(?<![\w$.])([A-Za-z_$][\w$]*)\s*=>')
RE_METHOD = re.compile(r'(?<![\w$.])([A-Za-z_$][\w$]*)\s*\(([^)]*)\)\s*\{')
RE_CALL = re.compile(r'(?<![\w$.])([A-Za-z_$][\w$]*)\s*\(')


def add_params(blob, sink):
    if not blob:
        return
    for part in blob.split(','):
        part = part.split('=')[0].strip()
        if not part:
            continue
        if part[0] in '{[':                       # 解构形参
            for inner in re.split(r'[,:]', part.strip('{}[]')):
                inner = inner.strip()
                if re.match(r'^[A-Za-z_$][\w$]*$', inner):
                    sink.add(inner)
            continue
        m = re.match(r'^(?:\.\.\.)?([A-Za-z_$][\w$]*)$', part)
        if m:
            sink.add(m.group(1))


def strip_strings(src):
    """把字符串/模板串/注释的内容抹成空格，只留真正的代码。

    为什么必须做：CSS 与 HTML 都写在模板串里，
    `translateY(`、`linear-gradient(`、`cubic-bezier(` 会被当成函数调用，
    不掩码就会满屏误报，闸门立刻失去意义。
    模板串里的 ${...} 是代码，保留下来继续参与检查。
    """
    out = []
    stack = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ''
        top = stack[-1] if stack else None
        if top and top[0] == 'line':
            if c == '\n':
                stack.pop(); out.append(c)
            else:
                out.append(' ')
            i += 1; continue
        if top and top[0] == 'block':
            if c == '*' and nxt == '/':
                stack.pop(); out.append('  '); i += 2; continue
            out.append('\n' if c == '\n' else ' '); i += 1; continue
        if top and top[0] == 'regex':
            # 正则字面量：字符类里的 / 不结束正则；反斜杠转义整对跳过
            if c == '\\':
                out.append('  '); i += 2; continue
            if c == '[':
                stack[-1] = ('regex', True)
            elif c == ']' and top[1]:
                stack[-1] = ('regex', False)
            elif c == '/' and not top[1]:
                stack.pop(); out.append('/'); i += 1; continue
            out.append('\n' if c == '\n' else ' '); i += 1; continue
        if top and top[0] in ('str', 'tmpl'):
            if c == '\\':
                out.append('  '); i += 2; continue
            if top[0] == 'str':
                if c == top[1]:
                    stack.pop(); out.append(c)
                else:
                    out.append('\n' if c == '\n' else ' ')
                i += 1; continue
            if c == '`':
                stack.pop(); out.append(c); i += 1; continue
            if c == '$' and nxt == '{':
                stack.append(('interp', 1)); out.append('  '); i += 2; continue
            out.append('\n' if c == '\n' else ' '); i += 1; continue
        # ---- 代码态 ----
        if c == '/' and nxt == '/':
            stack.append(('line',)); out.append('  '); i += 2; continue
        if c == '/' and nxt == '*':
            stack.append(('block',)); out.append('  '); i += 2; continue
        if c == '/':
            # 前一个有效字符若是运算符/分隔符，这里的 / 就是正则字面量的起始；
            # 否则是除法。不做这一步，/[&<>"']/ 里的引号会把整个词法状态带偏。
            prev = None
            for ch in reversed(out):
                if ch not in ' \t\n':
                    prev = ch
                    break
            if prev is None or prev in '(,=:[!&|?{};+-*%~^<>':
                stack.append(('regex', False)); out.append('/'); i += 1; continue
        if c in ('"', "'"):
            stack.append(('str', c)); out.append(c); i += 1; continue
        if c == '`':
            stack.append(('tmpl',)); out.append(c); i += 1; continue
        if top and top[0] == 'interp':
            if c == '{':
                stack[-1] = ('interp', top[1] + 1)
            elif c == '}':
                if top[1] == 1:
                    stack.pop(); out.append(' '); i += 1; continue
                stack[-1] = ('interp', top[1] - 1)
        out.append(c); i += 1
    return ''.join(out)


def declared_names(src):
    names = set()
    for m in RE_FUNC_DECL.finditer(src):
        names.add(m.group(1))
    for m in RE_CLASS.finditer(src):
        names.add(m.group(1))
    for m in RE_VAR.finditer(src):
        names.add(m.group(1))
    for m in RE_METHOD.finditer(src):
        names.add(m.group(1))
        add_params(m.group(2), names)
    for rx in (RE_FN_PARAMS, RE_ARROW_BRACED, RE_CATCH, RE_FORHEAD):
        for m in rx.finditer(src):
            add_params(m.group(1), names)
    for m in RE_ARROW_SINGLE.finditer(src):
        names.add(m.group(1))
    for rx in (RE_DESTR_OBJ, RE_DESTR_ARR):
        for m in rx.finditer(src):
            for inner in re.split(r'[,:]', m.group(1)):
                inner = inner.strip()
                if re.match(r'^[A-Za-z_$][\w$]*$', inner):
                    names.add(inner)
    return names


def scan_bundle():
    """全量检查打包产物里的裸函数调用。"""
    if not os.path.isfile(BUNDLE):
        return [('assets/js/app.js', '（产物不存在，请先运行 tools/build.py）')]
    raw = io.open(BUNDLE, encoding='utf-8').read()
    # 调用点看掩码后的代码（去掉字符串/注释，避免 CSS 与文案里的 `xxx(` 误报）；
    # 声明名从原文收集（掩码遇到正则字面量等极端写法可能失准，宽松取声明更稳）。
    src = strip_strings(raw)
    declared = declared_names(raw)
    problems = []
    seen = set()
    for m in RE_CALL.finditer(src):
        name = m.group(1)
        if name in declared or name in GLOBALS or name in KEYWORDS:
            continue
        if name in seen:
            continue
        seen.add(name)
        line = src[:m.start()].count('\n') + 1
        problems.append((line, name))
    return problems


def scan_scope():
    """针对性检查：顶层函数体内的 container / root。"""
    problems = []
    for path in glob.glob(os.path.join(SRC, '**', '*.js'), recursive=True):
        lines = io.open(path, encoding='utf-8').read().split('\n')
        cur = None
        depth = 0
        body = []
        for ln in lines:
            if cur is None:
                m = FUNCS.match(ln)
                if m:
                    cur = (m.group(1), m.group(2))
                    depth = ln.count('{') - ln.count('}')
                    body = [ln]
                    if depth == 0 and '{' in ln:
                        cur = None
                continue
            body.append(ln)
            depth += ln.count('{') - ln.count('}')
            if depth <= 0:
                name, args = cur
                text = '\n'.join(body)
                for var in WATCH:
                    if re.search(r'\b' + var + r'\b', text) and var not in args:
                        if not re.search(r'\b(const|let|var)\s+' + var + r'\b', text):
                            problems.append((os.path.relpath(path, SRC), name, var))
                cur = None
    return problems


def main():
    bad = False

    calls = scan_bundle()
    if calls:
        bad = True
        print('❌ 调用了不存在的函数（运行时必然 ReferenceError）：')
        for line, name in calls:
            print('   assets/js/app.js:%s  调用了未声明的 %s()' % (line, name))

    scoped = scan_scope()
    if scoped:
        bad = True
        print('❌ 函数体引用了未声明的 container / root（运行时会白屏）：')
        for f, fn, var in scoped:
            print('   %s: %s() 使用了未声明的 %s' % (f, fn, var))

    if bad:
        return 1
    print('✅ 未发现「调用未声明函数」或「引用未声明 container/root」')
    return 0


if __name__ == '__main__':
    sys.exit(main())
