#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
打包为 zip（保留真实文件时间戳，避免 1980-01-01 问题）
用法：python3 tools/zip.py  →  生成上级目录 kimi-game-rank.zip

交付包里只放「线上运行需要的东西」：
  · 构建脚本、设计文档、独立展示页、仓库配置（.github）都不进包；
  · 仓库文档（README / CHANGELOG / 贡献指南 / 行为准则 / 安全政策）与安装、诊断脚本
    只留在 GitHub —— 交付包与开源仓库保持完全分开；
  · storage 下只保留目录骨架（.gitkeep）与访问控制文件（.htaccess），
    锁文件 / 日志 / 缓存 / 时间戳等运行期产物一律排除。
"""
import os
import re
import time
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROJ = os.path.basename(ROOT)
OUT = os.path.join(os.path.dirname(ROOT), PROJ + '.zip')

SKIP = (
    os.path.join(PROJ, 'storage', 'cache') + os.sep,
    os.path.join(PROJ, 'storage', 'logs') + os.sep,
    os.path.join(PROJ, 'tools') + os.sep,          # 构建与校验脚本
    os.path.join(PROJ, 'docs') + os.sep,           # 设计与研究文档
    os.path.join(PROJ, 'standalone') + os.sep,     # 更新日志 / 跳转页独立版（另发）
    os.path.join(PROJ, '.github') + os.sep,        # 仓库配置：只属于 GitHub，不进站点
    os.path.join(PROJ, '.git') + os.sep,
)

# 单独文件级的排除
SKIP_FILES = (
    os.path.join(PROJ, '.gitignore'),
    os.path.join(PROJ, 'install.php'),      # 安装器：站点已装好，不随交付分发
    os.path.join(PROJ, 'diag.php'),         # 诊断入口：运维用，不随交付分发
    # 以下仓库文档只留在 GitHub —— 交付包与开源仓库完全分开
    os.path.join(PROJ, 'README.md'),
    os.path.join(PROJ, 'CHANGELOG.md'),
    os.path.join(PROJ, 'CONTRIBUTING.md'),
    os.path.join(PROJ, 'CODE_OF_CONDUCT.md'),
    os.path.join(PROJ, 'SECURITY.md'),
    os.path.join(PROJ, 'check.php'),        # 环境自检
    os.path.join(PROJ, 'selfcheck.php'),    # 站点自检
    os.path.join(PROJ, 'mimecheck.php'),    # MIME 自检
    os.path.join(PROJ, 'changelog.zip'),    # 更新日志独立版的压缩包
    os.path.join(PROJ, 'jump.zip'),         # 跳转页压缩包
)

# 被整目录排除后仍需保留的骨架文件。
# 少了它们，解压出来的包根本没有 storage/cache、storage/logs 这些目录，
# 线上写日志 / 写缓存 / 落迁移锁都会失败（锁写不进 → 每个请求都重跑一遍建表检查）。
SKELETON = ('.gitkeep', '.htaccess')

RE_STORAGE = re.compile('^' + re.escape(PROJ) + r'/storage/(.+)$')


def is_runtime_artifact(arc):
    """storage 下的运行期产物（锁、日志、缓存、时间戳…）不进包，只留骨架。"""
    m = RE_STORAGE.match(arc)
    if not m:
        return False
    return os.path.basename(m.group(1)) not in SKELETON


def ztime(ts):
    t = time.localtime(ts)
    return (t.tm_year, t.tm_mon, t.tm_mday, t.tm_hour, t.tm_min, t.tm_sec)


def main():
    if os.path.exists(OUT):
        os.remove(OUT)
    n = 0
    base = os.path.dirname(ROOT)          # 工程目录的上一层
    with zipfile.ZipFile(OUT, 'w', zipfile.ZIP_DEFLATED) as z:
        for dp, dns, fns in os.walk(ROOT):
            # dp 是绝对路径，SKIP 里存的是「kimi-game-rank/tools/」这样的相对路径，
            # 必须换算成相对 base 的形式再比较，否则所有排除规则都会静默失效。
            rel = os.path.relpath(dp, base).replace('\\', '/') + '/'
            skipped = any(rel.startswith(s) for s in SKIP)
            for fn in fns:
                p = os.path.join(dp, fn)
                arc = PROJ + '/' + os.path.relpath(p, ROOT).replace('\\', '/')
                if skipped and fn not in SKELETON:
                    continue
                if arc in SKIP_FILES or is_runtime_artifact(arc):
                    continue
                st = os.stat(p)
                zi = zipfile.ZipInfo(arc, ztime(st.st_mtime))
                zi.flag_bits |= 0x800                       # UTF-8 文件名
                zi.compress_type = zipfile.ZIP_DEFLATED
                zi.external_attr = (0o755 if st.st_mode & 0o111 else 0o644) << 16
                with open(p, 'rb') as f:
                    z.writestr(zi, f.read())
                n += 1
    print('打包完成：%s' % OUT)
    print('  文件数：%d' % n)
    print('  大小：%.1f KB' % (os.path.getsize(OUT) / 1024))


if __name__ == '__main__':
    main()
