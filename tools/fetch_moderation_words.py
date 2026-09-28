#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成随包审核词库（app/data/moderation_words.txt）
================================================
来源：GitHub 仓库 konsheng/Sensitive-lexicon（经 jsDelivr CDN 取得）。
按用户指示「直接用这个库，只排除部分分类」：只取下面 CATS 列出的分类，
其余（政治、宗教、网址、广告、时政等）一律不取。

过滤规则全部与内容无关，只为压掉误伤与体积：
  ① 去首尾空白、去空行
  ② 丢弃长度 < 2 的条目（单字误伤面最大，例如与游戏术语同字）
  ③ 丢弃长度 > 16 的条目（多为整句，正则更合适）
  ④ 丢弃含空白或含 http 的条目
  ⑤ 去重

⚠ 本脚本只输出统计数字，绝不打印词条内容。
"""
import io, os, sys, json, subprocess, hashlib
from urllib.parse import quote

REPO   = 'konsheng/Sensitive-lexicon'
REF    = '1.2'
CDN    = 'https://gcore.jsdelivr.net/gh/%s@%s/Vocabulary/' % (REPO, REF)
CATS   = ['色情词库', '色情类型', '涉枪涉爆']          # 只取这三类
OUT    = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                      'app/data/moderation_words.txt')


def get(url):
    """用 curl 取（沙箱内 Python 的 urllib TLS 握手会超时，curl 稳定）。"""
    r = subprocess.run(['curl', '-sSL', '--retry', '3', '--max-time', '60', url],
                       capture_output=True)
    if r.returncode != 0 or not r.stdout:
        raise SystemExit('抓取失败：' + url)
    return r.stdout.decode('utf-8', 'ignore')


def main():
    seen, stat = set(), []
    for cat in CATS:
        raw = get(CDN + quote(cat) + '.txt').splitlines()
        kept = 0
        for line in raw:
            w = line.strip()
            if not w or len(w) < 2 or len(w) > 16:  continue
            if any(c.isspace() for c in w):          continue
            if 'http' in w.lower():                  continue
            if w in seen:                            continue
            seen.add(w); kept += 1
        stat.append((cat, len(raw), kept))

    words = sorted(seen)
    body  = '\n'.join(words) + '\n'
    digest = hashlib.sha256(body.encode('utf-8')).hexdigest()[:16]

    head = ('# 审核词库（站点自托管）\n'
            '# 来源：GitHub %s（经 jsDelivr CDN），取用分类：%s\n'
            '# 过滤：去单字条 / 去超长条 / 去含空白与网址条 / 去重\n'
            '# 生成：tools/fetch_moderation_words.py　条目 %d　指纹 %s\n'
            '# 每行一个词条；# 开头为注释。本文件不对公网提供。\n' % (REPO, '、'.join(CATS), len(words), digest))

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    io.open(OUT, 'w', encoding='utf-8').write(head + body)

    for cat, n0, n1 in stat:
        print('  分类「%s」：读入 %d → 保留 %d' % (cat, n0, n1))
    print('  合计词条 %d 条｜文件 %.1f KB｜指纹 %s' % (len(words), os.path.getsize(OUT) / 1024, digest))
    print('  输出：%s' % OUT.replace(os.path.dirname(os.path.dirname(OUT)) + '/', ''))


if __name__ == '__main__':
    main()
