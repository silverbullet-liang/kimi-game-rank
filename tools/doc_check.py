#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""文档自检：确保规范与 README 里的示例「真的能用」。

检查三件事：
  1. 文档里出现的图标位置名都在白名单内（防止示例乱编不存在的名字）
  2. ```json 代码块里的完整主题能被 theme_check 校验通过
  3. 反例代码块必须标注为 ```json fail（显式标记为「预期失败」）

用法：python3 tools/doc_check.py
"""
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = ['themes/SPEC.md', 'themes/README.md']
GROUPS = ('nav', 'action', 'field', 'media', 'state', 'user', 'theme', 'doc', 'tab', 'ui')


def load_slots():
    src = open(os.path.join(ROOT, 'assets/js/src/theme-json.js'), encoding='utf-8').read()
    body = re.search(r'const SLOTS = \{(.*?)\n\};', src, re.S).group(1)
    return set(re.findall(r"'([a-z]+\.[a-zA-Z]+)':\s*1", body))


def main():
    slots = load_slots()
    problems = []
    blocks_checked = 0

    for doc in DOCS:
        path = os.path.join(ROOT, doc)
        text = open(path, encoding='utf-8').read()

        # 1) 位置名（反例代码块里的名字不算：那里本来就该写错的）
        text_no_fail = re.sub(r'```json\s+fail\n.*?```', '', text, flags=re.S)
        text_no_fail = re.sub(r'```text\n.*?```', '', text_no_fail, flags=re.S)
        for name in sorted(set(re.findall(r'\b([a-z]+\.[a-zA-Z]+)\b', text_no_fail))):
            grp = name.split('.')[0]
            if grp not in GROUPS:
                continue          # theme.json / theme.php 这类文件名
            if grp == 'theme' and name in ('theme.json', 'theme.php', 'theme.md'):
                continue
            if name not in slots:
                problems.append('%s：图标位置 %r 不在白名单内' % (doc, name))

        # 2) JSON 代码块
        for m in re.finditer(r'```json(\s+fail)?\n(.*?)```', text, re.S):
            expect_fail = bool(m.group(1))
            body = m.group(2).strip()
            if '"schema"' not in body:
                continue          # 片段，不是完整主题
            blocks_checked += 1
            with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False, encoding='utf-8') as f:
                f.write(body)
                tmp = f.name
            r = subprocess.run([sys.executable, os.path.join(ROOT, 'tools/theme_check.py'), tmp],
                               capture_output=True, text=True)
            os.unlink(tmp)
            ok = r.returncode == 0
            if expect_fail and ok:
                problems.append('%s：标注为反例的代码块居然通过了校验' % doc)
            if not expect_fail and not ok:
                first = [l for l in r.stdout.split('\n') if '✗' in l]
                problems.append('%s：示例代码块校验失败 —— %s' % (doc, first[0] if first else r.stdout.strip()[:120]))

    print('检查 %d 个文档，%d 个完整示例，白名单 %d 个位置' % (len(DOCS), blocks_checked, len(slots)))
    if problems:
        print('\n发现 %d 个问题：' % len(problems))
        for p in problems:
            print('  ✗ ' + p)
        return 1
    print('✅ 文档与实现一致')
    return 0


if __name__ == '__main__':
    sys.exit(main())
