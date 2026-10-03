#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""PHP 语法粗检（沙箱无 PHP 运行时）：剥离字符串/注释后做括号平衡 + 兼容性与禁用调用扫描。
仅扫描 <?php ... ?> 段，避免被 HTML 内联 CSS/JS 误报。"""
import os, re, shutil, subprocess, sys

PHP_BIN = shutil.which('php')          # 沙箱/本机有 PHP 时用真编译器，没有则退回静态检查


def php_lint(path):
    """有 php 就用真编译器做语法检查——静态规则查不出 parse error。"""
    if not PHP_BIN:
        return []
    try:
        r = subprocess.run([PHP_BIN, '-l', path], capture_output=True, text=True, timeout=20)
    except Exception:
        return []
    if r.returncode != 0:
        msg = (r.stdout or '') + (r.stderr or '')
        return ['PHP 语法错误：' + ' '.join(msg.split())[:200]]
    return []

BAD = [
    (r':\s*void\b', '返回类型 : void（PHP 7.1+）'),
    (r'\)\s*:\s*\?', '可空返回类型 ?T（PHP 7.1+）'),
    (r'\?\?=', '??= 运算符（PHP 7.4+）'),
    (r'\bfn\s*\([^)]*\)\s*=>', '箭头函数 fn()=> （PHP 7.4+）'),
    (r'\bmatch\s*\(', 'match 表达式（PHP 8.0）'),
    (r'\bstr_contains\s*\(', 'str_contains()（PHP 8.0）'),
    (r'\bstr_starts_with\s*\(', 'str_starts_with()（PHP 8.0）'),
    (r'\bnet_curl_init\s*\(', '调用了已移除的 net_curl_init()（v3.11.0 起出站代理模块已删除）'),
    (r'\bnet_outbound_proxy\s*\(', '调用了已移除的 net_outbound_proxy()（同上）'),
    (r'\bstr_ends_with\s*\(', 'str_ends_with()（PHP 8.0）'),
    (r'#\[\w', '属性 #[Attr]（PHP 8.0）'),
    (r'\benum\s+\w+\s*\{', 'enum（PHP 8.1）'),
    (r'readonly\s+\$', 'readonly 属性（PHP 8.1）'),
    (r'\bnever\b\s*\{', 'never 返回类型（PHP 8.1）'),
]


def strip_code(src: str) -> str:
    """把 PHP 字符串与注释替换为等长空白，保留结构与换行。"""
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        # 单行注释
        if src.startswith('//', i) or c == '#':
            j = src.find('\n', i)
            j = n if j < 0 else j
            out.append(' ' * (j - i)); i = j; continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            j = n if j < 0 else j + 2
            out.append(' ' * (j - i)); i = j; continue
        # 字符串
        if c in ('"', "'"):
            q = c; j = i + 1; buf = [c]
            while j < n:
                if src[j] == '\\':
                    buf.append('  '); j += 2; continue
                if src[j] == q:
                    buf.append(q); j += 1; break
                buf.append(' '); j += 1
            out.append(''.join(buf)); i = j; continue
        out.append(c); i += 1
    return ''.join(out)


def php_segments(src: str):
    """仅取 <?php ... ?> 段。逐字符扫描并跳过字符串/注释，避免把正则里的 ?> 误判为结束标记。"""
    if '<?php' not in src:
        return src                      # 无标签 → 视为纯 PHP
    segs, i, n = [], 0, len(src)
    while True:
        a = src.find('<?php', i)
        if a < 0:
            break
        j = a + 5
        while j < n:
            c = src[j]
            if c in ('"', "'"):
                q = c
                j += 1
                while j < n:
                    if src[j] == '\\':
                        j += 2
                        continue
                    if src[j] == q:
                        j += 1
                        break
                    j += 1
                continue
            if src.startswith('//', j) or c == '#':
                k = src.find('\n', j)
                j = n if k < 0 else k
                continue
            if src.startswith('/*', j):
                k = src.find('*/', j + 2)
                j = n if k < 0 else k + 2
                continue
            if src.startswith('?>', j):
                break
            j += 1
        segs.append(src[a + 5:j])
        i = j + 2
    return '\n'.join(segs)


FUNC_RE = re.compile(r'^function\s+([A-Za-z_]\w*)\s*\(', re.M)
CLASS_RE = re.compile(r'^class\s+([A-Za-z_]\w*)', re.M)
GUARD = re.compile(r"(?:function_exists|class_exists)\s*\(\s*['\"]")


def _defined(code, path):
    """收集顶层函数/类定义（行首无缩进；有 function_exists/class_exists 守卫的不计入冲突）。"""
    out = []
    for rex, kind in ((FUNC_RE, '函数'), (CLASS_RE, '类')):
        for m in rex.finditer(code):
            head = code[max(0, m.start() - 240):m.start()]
            if GUARD.search(head):          # 带存在性守卫，重复也不会致命
                continue
            line = code[:m.start()].count('\n') + 1
            out.append((m.group(1), kind, line, path))
    return out


def check(path: str):
    src = open(path, encoding='utf-8', errors='replace').read()
    code = strip_code(php_segments(src))
    errs = php_lint(path)
    for op, cl in (('{', '}'), ('(', ')'), ('[', ']')):
        if code.count(op) != code.count(cl):
            errs.append(f'括号不平衡 {op}{cl}: {code.count(op)} vs {code.count(cl)}')
    for pat, msg in BAD:
        m = re.search(pat, code)
        if m:
            errs.append(f'{msg} @ 偏移 {m.start()}')
    return errs


def scan_redeclaration(files):
    """跨文件汇总顶层定义：PHP 函数表是全局的，同名即致命 Cannot redeclare。"""
    seen, dups = {}, []
    for f in sorted(files):
        src = open(f, encoding='utf-8', errors='replace').read()
        code = strip_code(php_segments(src))
        for name, kind, line, path in _defined(code, f):
            key = (name, kind)
            if key in seen:
                dups.append((name, kind, seen[key], f'{path}:{line}'))
            else:
                seen[key] = f'{path}:{line}'
    return dups


def main():
    targets = sys.argv[1:] or ['app', 'api']
    files = []
    for t in targets:
        if os.path.isdir(t):
            for root, _, fs in os.walk(t):
                if os.path.basename(root) == 'adblock':
                    continue          # 规则分片是数据文件（十几万条域名），不是源码
                files += [os.path.join(root, f) for f in fs if f.endswith('.php')]
        elif t.endswith('.php'):
            files.append(t)
    bad = 0
    for f in sorted(files):
        errs = check(f)
        if errs:
            bad += 1
            print(f'❌ {f}')
            for e in errs:
                print('   -', e)
    # 跨文件重复定义：线上 Cannot redeclare 的头号成因
    dups = scan_redeclaration(files)
    for name, kind, first, second in dups:
        bad += 1
        print(f'❌ 重复{kind} {name}()')
        print(f'   - 首次定义 {first}')
        print(f'   - 再次定义 {second}  ← 线上会直接 Cannot redeclare')
    print(f'扫描 {len(files)} 个 PHP 文件，问题 {bad} 个')
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
