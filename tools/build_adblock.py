#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
构建广告/恶意域名规则文件（分片索引版）
------------------------------------------------------------
输入：AdGuard 过滤规则文本（Adblock 语法）
输出：app/data/adblock/bNNN.php（NNN = crc32(域名) % 128）+ meta.json

为什么分片：17 万条规则放一个大 JSON，每次请求解析 3MB 太贵。
按 crc32 取模 128 分片后，每片仅约 1400 条 —— require 一个这样的 PHP 数组
只要零点几毫秒；若主机开了 opcache，编译结果被缓存，后续几乎零成本。
选 crc32 而不是首字符，是因为域名首字符分布极不均（'s' 片独占 20%，
跟踪类域名大量以 s 开头），而 crc32 取模的最大/中位比只有 1.07。

用法：python3 tools/build_adblock.py <规则文本> [输出目录]
"""
import os
import re
import sys
import json
import zlib
from datetime import datetime, timezone

SHARDS = 128
RULE_RE = re.compile(r'^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,12}$')


def parse_rules(path):
    """解析 Adblock 语法，只取 ||domain^ 形式里的纯域名。"""
    out = set()
    with open(path, encoding='utf-8', errors='ignore') as f:
        for line in f:
            line = line.strip()
            if not line or line[0] in '!@[':
                continue
            if not line.startswith('||'):
                continue
            body = line[2:]
            # ^ 是域名结束分隔符；出现在中间即为复合规则，跳过
            if '^' in body and not body.endswith('^'):
                continue
            d = body.replace('^', '')
            if any(c in d for c in '*/|'):
                continue
            d = d.rstrip('.').lower()
            if len(d) > 191 or not RULE_RE.match(d):
                continue
            out.add(d)
    return out


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 1
    src = sys.argv[1]
    dest = sys.argv[2] if len(sys.argv) > 2 else os.path.join(
        os.path.dirname(os.path.abspath(__file__)), '..', 'app', 'data', 'adblock')
    dest = os.path.abspath(dest)
    os.makedirs(dest, exist_ok=True)

    hosts = parse_rules(src)
    if not hosts:
        print('未解析出任何规则，中止')
        return 1

    buckets = [[] for _ in range(SHARDS)]
    for h in hosts:
        buckets[zlib.crc32(h.encode()) % SHARDS].append(h)

    total = 0
    for i, b in enumerate(buckets):
        b.sort()
        body = ','.join("'%s'=>1" % h for h in b)
        with open(os.path.join(dest, 'b%03d.php' % i), 'w', encoding='utf-8') as f:
            f.write('<?php return array(' + body + ');')
        total += len(b)

    meta = {
        'count': total,
        'shards': SHARDS,
        'built_at': datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S'),
        'source': os.path.basename(src),
    }
    with open(os.path.join(dest, 'meta.json'), 'w', encoding='utf-8') as f:
        json.dump(meta, f, ensure_ascii=False, indent=2)

    print('规则 %d 条 → %d 片，最大片 %d，输出到 %s'
          % (total, SHARDS, max(len(b) for b in buckets), dest))
    return 0


if __name__ == '__main__':
    sys.exit(main())
