#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
开源化泄漏自检（发布前必跑，CI 里也会跑）
用法：python3 tools/repo_check.py            退出码 0 = 干净，1 = 有泄漏

三层防线：
  A. 禁入文件 —— 密钥池、审核词库、运行期产物、内部归档，都不得进入待提交集合；
  B. 密钥指纹 —— GitHub PAT / OpenAI 风格 / 智谱 Key / 私钥；
  C. 数据库连接 —— 用 config/config.php 里的真实连接值（仅 db / db_admin 两段）反查全树，
     命中一律掩码输出，不打印原值。

待提交集合 = `git ls-files --cached --others --exclude-standard`
             （已跟踪 + 未跟踪但未被忽略 —— 这正是会被推上去的东西）
"""
import io, os, re, sys, fnmatch, subprocess

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# ---------- A. 禁入文件 ----------
FORBIDDEN = [
    'config/config.php',           # 数据库连接与口令
    'config/api_keys.php',         # 密钥池
    'app/data/moderation_words.txt',
    'app/data/moderation_allow.txt',
    'storage/backups/*', 'storage/logs/*', 'storage/cache/*', 'storage/uploads/*',
    'storage/*.lock', 'storage/schema_checked.txt',
    'docs/archive/*',                          # 历史归档
    'docs/Kimi API详解与研究指南.md',           # 逆向研究稿
    '*.zip',                                   # 交付包
]
ALLOW = ['storage/logs/.gitkeep', 'storage/cache/.gitkeep',
         'storage/uploads/.gitkeep', 'storage/backups/.gitkeep']

# ---------- B. 密钥指纹 ----------
SECRET_RE = [
    (r'ghp_[A-Za-z0-9]{20,}', 'GitHub PAT'),
    (r'sk-[A-Za-z0-9\-_]{20,}', 'OpenAI 风格密钥'),
    (r'\b[0-9a-f]{32}\.[A-Za-z0-9]{16}\b', '智谱 Key 形态'),
    (r'-----BEGIN [A-Z ]*PRIVATE KEY-----', '私钥'),
]

# ---------- C. 通用 DSN / 口令形态 ----------
DSN_RE = [
    (r'mysql://[^\s\'"]+:[^\s\'"@]+@', '带口令的 MySQL DSN'),
    (r'(?i)\b(db_pass|db_password|database_password|mysql_password)\b\s*[=:]\s*[\'"][^\'"]{6,}', '明文口令赋值'),
    (r'(?i)\bDATABASE_URL\s*=\s*\S', 'DATABASE_URL 注入'),
]

BENIGN = {'localhost', '127.0.0.1', 'root', 'mysql', 'utf8mb4', '3306'}
SKIP_DIRS = {'.git', 'node_modules', '__pycache__'}
TEXT_EXT = {'.php', '.js', '.mjs', '.json', '.md', '.html', '.css', '.txt',
            '.sql', '.yml', '.yaml', '.sh', '.py', '.htaccess', ''}


def db_conn_values():
    """从 config/config.php 的 db / db_admin 两段抽出真实连接值（只用于比对，不打印）。"""
    p = os.path.join(ROOT, 'config/config.php')
    if not os.path.isfile(p):
        return set()
    s = io.open(p, encoding='utf-8', errors='ignore').read()
    vals = set()
    for seg in ('db', 'db_admin'):
        m = re.search(r"'%s'\s*=>\s*array\s*\((.*?)\n    \)," % seg, s, re.S)
        if not m:
            continue
        for k, v in re.findall(r"'(\w+)'\s*=>\s*'([^']*)'", m.group(1)):
            v = v.strip()
            if k in ('host', 'name', 'user', 'pass', 'port') and len(v) >= 5 and v.lower() not in BENIGN:
                vals.add(v)
    return vals


def tracked_candidates():
    try:
        out = subprocess.check_output(
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard'],
            cwd=ROOT, stderr=subprocess.DEVNULL)
        return [l for l in out.decode('utf-8', 'ignore').splitlines() if l.strip()]
    except Exception:
        return None


def fallback_walk():
    out = []
    for dirpath, dirnames, filenames in os.walk(ROOT):
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
        for fn in filenames:
            out.append(os.path.relpath(os.path.join(dirpath, fn), ROOT).replace(os.sep, '/'))
    return out


def mask(v):
    return (v[:2] + '…%d字符' % len(v)) if len(v) > 4 else '…'


def main():
    files = tracked_candidates()
    mode = 'git 待提交集合'
    if files is None:
        files = fallback_walk()
        mode = '全树遍历（未检测到 git，禁入检查为尽力而为）'
    conn = db_conn_values()
    print('扫描范围：%s，共 %d 个文件' % (mode, len(files)))
    print('连接特征值：%d 个（已隐藏，取自 config/config.php 的 db / db_admin）\n' % len(conn))

    problems = []
    for r in files:
        if r in ALLOW:
            continue
        for pat in FORBIDDEN:
            if fnmatch.fnmatch(r, pat) or fnmatch.fnmatch(r, pat + '/*'):
                problems.append('❌ 禁入文件进入待提交集合：%s' % r)
        full = os.path.join(ROOT, r)
        if os.path.splitext(r)[1] not in TEXT_EXT or not os.path.isfile(full):
            continue
        try:
            if os.path.getsize(full) > 3_000_000:
                continue
            s = io.open(full, encoding='utf-8', errors='ignore').read()
        except Exception:
            continue

        for pat, name in SECRET_RE:
            for m in re.finditer(pat, s):
                if m.group(0).startswith(('ghp_', 'sk-')):
                    shown = m.group(0)[:6] + '…'
                else:
                    shown = mask(m.group(0))
                problems.append('❌ 疑似%s（%s:%d，%s）' % (name, r, s.count('\n', 0, m.start()) + 1, shown))

        for v in conn:
            for m in re.finditer(re.escape(v), s):
                problems.append('❌ 疑似数据库连接值泄漏（%s:%d，%s）'
                                % (r, s.count('\n', 0, m.start()) + 1, mask(v)))

        for pat, name in DSN_RE:
            for m in re.finditer(pat, s):
                problems.append('❌ %s（%s:%d）' % (name, r, s.count('\n', 0, m.start()) + 1))

    if problems:
        print('\n'.join(problems))
        print('\n结果：发现 %d 处泄漏，禁止发布。' % len(problems))
        return 1
    print('✅ 发布集干净：无禁入文件、无密钥指纹、无数据库连接泄漏。')
    return 0


if __name__ == '__main__':
    sys.exit(main())
