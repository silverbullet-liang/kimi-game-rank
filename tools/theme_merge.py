#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""把 _t/festival_batch_*.json 的 tokens/icons/deco 合并进 themes/*.theme.json。

- 幂等：重复运行结果一致；
- 只新增 tokens / icons / deco 三个字段，不动 id/name/colour/colourDesc/radius；
- --check 只报告不写盘。

用法：python3 tools/theme_merge.py [--check] [--src ../_t]
"""
import glob
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
THEMES = os.path.join(ROOT, 'themes')
FIELDS = ('tokens', 'icons', 'deco')


def load_batches(src):
    out = {}
    for p in sorted(glob.glob(os.path.join(src, 'festival_batch_*.json'))):
        with open(p, encoding='utf-8') as fh:
            d = json.load(fh)
        for k, v in d.items():
            if k in out:
                sys.exit('重复主题：%s（%s）' % (k, p))
            out[k] = v
    return out


def main():
    check = '--check' in sys.argv
    src = os.path.join(os.path.dirname(ROOT), '_t')
    if '--src' in sys.argv:
        src = sys.argv[sys.argv.index('--src') + 1]

    batches = load_batches(src)
    files = {os.path.basename(p)[:-len('.theme.json')]: p
             for p in glob.glob(os.path.join(THEMES, '*.theme.json'))}

    missing = sorted(set(batches) - set(files))
    if missing:
        sys.exit('缺少主题文件：%s' % ', '.join(missing))

    changed = 0
    for key in sorted(batches):
        path = files[key]
        with open(path, encoding='utf-8') as fh:
            data = json.load(fh)
        ext = batches[key]
        dirty = False
        for f in FIELDS:
            val = ext.get(f)
            if not isinstance(val, dict):
                sys.exit('%s 缺少字段 %s' % (key, f))
            if data.get(f) != val:
                data[f] = val
                dirty = True
        if dirty:
            changed += 1
            if not check:
                with open(path, 'w', encoding='utf-8') as fh:
                    json.dump(data, fh, ensure_ascii=False, indent=2, sort_keys=False)
                    fh.write('\n')

    print('主题 %d 个，需写入 %d 个%s' % (len(batches), changed, '（--check 未写盘）' if check else ''))
    if check and changed:
        sys.exit(1)


if __name__ == '__main__':
    main()
