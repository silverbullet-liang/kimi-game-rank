#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
前端构建：把 assets/js/src/ 下的模块合并为 assets/js/app.js 单文件。

为什么合并：共享主机（如 InfinityFree）限制并发请求，模块化拆分会产生十余个
并发请求，任一被中断都会导致整站脚本失效。单文件加载彻底规避。

用法：python3 tools/build.py
"""
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'assets', 'js', 'src')
OUT = os.path.join(ROOT, 'assets', 'js', 'app.js')

ORDER = [
    # md.js：Markdown 渲染，文档页与 AI 回复共用
    'core.js', 'md.js', 'theme.js', 'transitions.js',
    'pages/rank.js', 'pages/detail.js', 'pages/lobby.js', 'pages/mine.js',
    'pages/login.js', 'pages/panel.js', 'pages/doc.js', 'pages/feedback.js',
    'pages/violation.js',
    'app.js',
]


def strip_module(src):
    out = []
    for line in src.split('\n'):
        s = line.strip()
        if re.match(r'^import\s', s) or re.match(r'^export\s*\{', s):
            continue
        line = re.sub(r'^(\s*)export\s+(async\s+function|function|const|let|var|class)\b', r'\1\2', line)
        out.append(line)
    return '\n'.join(out)


def strip_multiline_imports(src):
    src = re.sub(r"import\s*\{[^}]*\}\s*from\s*'[^']*';?", '', src, flags=re.S)
    src = re.sub(r"import\s+[^;\n]+from\s*'[^']*';?", '', src)
    return src


def check_sources():
    """构建前静态校验：跨文件/同文件重名会互相覆盖，必须拦下"""
    # 合并器是「整行剥离 import」，因此 `import { A as B }` 的别名会被静默丢弃，
    # 合并产物里只剩对 B 的使用、没有定义 → 线上 ReferenceError，页面脚本失效。
    # 这类错误语法检查与重名检查都拦不住，必须在这里显式拦下。
    for fn in ORDER:
        p = os.path.join(SRC, fn)
        if not os.path.isfile(p):
            continue
        with open(p, encoding='utf-8') as f:
            for i, line in enumerate(f, 1):
                if re.match(r'^\s*import\s', line) and re.search(r'\bas\b', line):
                    raise SystemExit('构建中止：%s 第 %d 行使用了 import 别名（合并器不支持，会静默丢弃）：%s'
                                     % (fn, i, line.strip()))
    r = subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'jscheck.py')],
                       capture_output=True, text=True)
    out = (r.stdout or '').strip()
    if out:
        print(out)
    if r.returncode != 0:
        raise SystemExit('前端源码校验未通过，构建中止（详见上方报告）')



def check_undecl(path):
    """未声明函数调用 / 未声明 container、root：运行时报 ReferenceError，页面失效。

    为什么放在这里：必须在**合并产物**上检查才能发现「跨文件调用了一个不存在
    的函数」（openEditor 就是这么漏到线上的）；语法检查与重名检查都拦不住。
    传入的是临时产物，因此坏包不会覆盖上一版可用文件。
    """
    r = subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'undecl.py'), path],
                       capture_output=True, text=True)
    out = (r.stdout or '').strip()
    if out:
        print(out)
    if r.returncode != 0:
        raise SystemExit('前端存在未声明的调用，构建中止（未覆盖上一版可用文件）')


def syntax_check(path):
    """真语法闸门：用 node --check 校验合并产物。

    为什么必须做：jscheck 只查标识符重名，查不出「模板字符串被截断」这类
    语法错误——这类错误一旦出包，线上整站脚本直接失效（整页空白）。
    没有 node 时降级为提示，不阻断构建。
    """
    exe = shutil.which('node') or shutil.which('nodejs')
    if not exe:
        print('  语法检查：跳过（未找到 node，建议本地装一个以启用）')
        return
    r = subprocess.run([exe, '--check', path], capture_output=True, text=True)
    if r.returncode != 0:
        err = (r.stderr or r.stdout or '').strip()
        raise SystemExit('❌ 构建产物存在语法错误，已中止（未覆盖上一版可用文件）：\n' + err)
    print('  语法检查：通过（node --check）')


def main():
    check_sources()
    parts = []
    for rel in ORDER:
        path = os.path.join(SRC, rel)
        if not os.path.exists(path):
            raise SystemExit('缺少源文件：' + rel)
        src = open(path, encoding='utf-8').read()
        src = strip_multiline_imports(src)
        src = strip_module(src)
        if rel == 'app.js':
            src = re.sub(r'\n\s*setNavigate\(navigate\);', '', src)
        parts.append('/* ========== %s ========== */\n%s' % (rel, src.strip()))

    header = (
        '/**\n'
        ' * Kimi游戏榜 前端（单文件构建版）\n'
        ' * 由 assets/js/src/ 下模块合并而成，避免共享主机的并发模块加载限制。\n'
        ' * 修改源码后请重新执行 tools/build.py。\n'
        ' */\n'
    )
    merged = header + '\n\n'.join(parts) + '\n'

    # 先写临时文件并做语法校验，通过后才替换正式产物——坏包绝不覆盖好包。
    # 临时文件必须以 .js 结尾，否则 node --check 会因未知扩展名拒绝解析。
    tmp = OUT + '.check.js'
    open(tmp, 'w', encoding='utf-8').write(merged)
    syntax_check(tmp)
    check_undecl(tmp)
    os.replace(tmp, OUT)

    print('构建完成 → assets/js/app.js')
    print('  大小：%.1f KB' % (len(merged.encode('utf-8')) / 1024))
    print('  残留 import：%d，残留 export：%d' % (
        len(re.findall(r'^import\s', merged, re.M)),
        len(re.findall(r'^export\s', merged, re.M)),
    ))


if __name__ == '__main__':
    main()
