#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
解析 Material Components 的依赖树并下载 AAR/JAR（无 Gradle 构建用）。

主源：阿里云 Google 镜像（material / androidx 都在这里）；
回退：dl.google.com → 阿里云 public → 腾讯云 → repo1。
输出目录由环境变量 APK_DEPS_DIR 指定，默认 <脚本目录>/.toolchain/deps。
"""
import io, os, re, sys, urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.environ.get('APK_DEPS_DIR') or os.path.join(HERE, '.toolchain', 'deps')
MIRRORS = [
    'https://maven.aliyun.com/repository/google/',
    'https://dl.google.com/dl/android/maven2/',
    'https://maven.aliyun.com/repository/public/',
    'https://mirrors.cloud.tencent.com/nexus/repository/maven-public/',
    'https://repo1.maven.org/maven2/',
]
ROOT = ('com.google.android.material', 'material', '1.12.0')

os.makedirs(OUT + '/aar', exist_ok=True)
os.makedirs(OUT + '/jar', exist_ok=True)
os.makedirs(OUT + '/pom', exist_ok=True)


def fetch(path, timeout=45):
    for m in MIRRORS:
        try:
            with urllib.request.urlopen(m + path, timeout=timeout) as r:
                return r.read()
        except Exception:
            continue
    return None


def pom_path(g, a, v):
    return '%s/%s/%s/%s-%s.pom' % (g.replace('.', '/'), a, v, a, v)


def art_path(g, a, v, ext):
    return '%s/%s/%s/%s-%s.%s' % (g.replace('.', '/'), a, v, a, v, ext)


def parse_pom(xml):
    packaging = 'jar'
    m = re.search(r'<packaging>([^<]+)</packaging>', xml)
    if m:
        packaging = m.group(1).strip()
    parent = None
    mp = re.search(r'<parent>(.*?)</parent>', xml, re.S)
    if mp:
        pg = re.search(r'<groupId>([^<]+)</groupId>', mp.group(1))
        pa = re.search(r'<artifactId>([^<]+)</artifactId>', mp.group(1))
        pv = re.search(r'<version>([^<]+)</version>', mp.group(1))
        if pg and pa and pv:
            parent = (pg.group(1).strip(), pa.group(1).strip(), pv.group(1).strip())
    props = {}
    for pm in re.finditer(r'<properties>(.*?)</properties>', xml, re.S):
        for k, v in re.findall(r'<([A-Za-z0-9_.\-]+)>([^<]*)</\1>', pm.group(1)):
            props[k] = v.strip()
    depmgmt = {}
    for mg in re.finditer(r'<dependencyManagement>(.*?)</dependencyManagement>', xml, re.S):
        for dm in re.finditer(r'<dependency>(.*?)</dependency>', mg.group(1), re.S):
            blk = dm.group(1)
            g = re.search(r'<groupId>([^<]+)</groupId>', blk)
            a = re.search(r'<artifactId>([^<]+)</artifactId>', blk)
            v = re.search(r'<version>([^<]+)</version>', blk)
            if g and a and v:
                depmgmt['%s:%s' % (g.group(1).strip(), a.group(1).strip())] = v.group(1).strip()
    body = re.sub(r'<dependencyManagement>.*?</dependencyManagement>', '', xml, flags=re.S)
    deps = []
    for dm in re.finditer(r'<dependency>(.*?)</dependency>', body, re.S):
        blk = dm.group(1)
        g = re.search(r'<groupId>([^<]+)</groupId>', blk)
        a = re.search(r'<artifactId>([^<]+)</artifactId>', blk)
        v = re.search(r'<version>([^<]+)</version>', blk)
        sc = re.search(r'<scope>([^<]+)</scope>', blk)
        op = re.search(r'<optional>([^<]+)</optional>', blk)
        if not (g and a):
            continue
        if sc and sc.group(1).strip() in ('test', 'provided', 'system'):
            continue
        if op and op.group(1).strip() == 'true':
            continue
        deps.append({'g': g.group(1).strip(), 'a': a.group(1).strip(),
                     'v': v.group(1).strip() if v else ''})
    return packaging, parent, props, deps, depmgmt


def subst(v, props, parent):
    if not v:
        return v
    m = re.fullmatch(r'\$\{([^}]+)\}', v)
    if not m:
        return v
    key = m.group(1)
    if key in props:
        return props[key]
    if key == 'project.version' and parent:
        return parent[2]
    return ''


seen, order, failed = {}, [], []


def _ver_tuple(s):
    return tuple(int(x) for x in re.findall(r'\d+', s)) or (0,)


# kotlin 1.8+ 的 stdlib 已内含 jdk7/jdk8 实现，单列这两个会与 stdlib 重复类
_SKIP = ('kotlin-stdlib-jdk7', 'kotlin-stdlib-jdk8')


def resolve(g, a, v, depth=0, inherited=None):
    key = '%s:%s' % (g, a)
    if depth > 9 or a in _SKIP:
        return
    inherited = inherited or {}
    if not v:
        v = inherited.get(key, '')
    if v:
        v = v.strip('[]')
    if not v:
        failed.append('%s:%s (无版本)' % (g, a))
        return
    old = seen.get(key)
    if old is not None and _ver_tuple(v) <= _ver_tuple(old):
        return
    if old is not None:
        order[:] = [o for o in order if not (o[0] == g and o[1] == a)]
    body = fetch(pom_path(g, a, v))
    if body is None:
        failed.append('%s:%s:%s (pom 取不到)' % (g, a, v))
        return
    seen[key] = v
    xml = body.decode('utf-8', 'ignore')
    io.open('%s/pom/%s-%s.pom' % (OUT, a, v), 'w', encoding='utf-8').write(xml)
    packaging, parent, props, deps, depmgmt = parse_pom(xml)
    chain = dict(inherited)
    for k, vv in depmgmt.items():
        vs = subst(vv, props, parent)
        if vs:
            chain[k] = vs
    order.append((g, a, v, packaging))
    for d in deps:
        resolve(d['g'], d['a'], subst(d['v'], props, parent), depth + 1, chain)


resolve(*ROOT)

tasks = []
for g, a, v, packaging in order:
    ext = 'aar' if packaging == 'aar' else 'jar'
    dest = '%s/%s/%s-%s.%s' % (OUT, ext, a, v, ext)
    if os.path.isfile(dest) and os.path.getsize(dest) > 0:
        continue
    tasks.append((g, a, v, ext, dest))


def download_one(t):
    g, a, v, ext, dest = t
    b = fetch(art_path(g, a, v, ext), timeout=120)
    if b is None and ext == 'aar':
        b = fetch(art_path(g, a, v, 'jar'), timeout=120)
        if b is not None:
            dest = '%s/jar/%s-%s.jar' % (OUT, a, v)
    if b is None:
        return ('fail', '%s:%s:%s' % (g, a, v))
    io.open(dest, 'wb').write(b)
    return ('ok', dest)


ok = 0
with ThreadPoolExecutor(max_workers=24) as ex:
    futs = [ex.submit(download_one, t) for t in tasks]
    for f in as_completed(futs):
        st, msg = f.result()
        if st == 'ok':
            ok += 1
        else:
            failed.append(msg + ' (包取不到)')

# 清理同一 artifact 的旧版本文件，避免 d8 重复类
wanted = set()
for g, a, v, p in order:
    wanted.add('%s-%s.aar' % (a, v))
    wanted.add('%s-%s.jar' % (a, v))
for sub in ('aar', 'jar'):
    dd = os.path.join(OUT, sub)
    if os.path.isdir(dd):
        for f in os.listdir(dd):
            if f.endswith(('.aar', '.jar')) and f not in wanted:
                try:
                    os.remove(os.path.join(dd, f))
                except OSError:
                    pass

print('依赖 %d 个（本次新下载 %d），输出 %s' % (len(order), ok, OUT))
if failed:
    print('未解决 %d 条：' % len(failed))
    for f in failed[:30]:
        print('   ', f)
