#!/usr/bin/env python3
"""自检：跨境数据传输协议接入 + 请求并发闸门（纯静态，不连库、不联网）

    python3 tools/verify_transfer.py

覆盖三件事：
  1. 文档本体（中文 + 英文）、文档页登记、侧栏入口、i18n 词条、互链
  2. 登录/注册的「单独同意」勾选与提交校验、后端留痕
  3. 前端请求并发闸门与网关类 5xx 重试
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ok = fail = 0
log = []


def chk(cond, name):
    global ok, fail
    if cond:
        ok += 1
        log.append("  PASS  " + name)
    else:
        fail += 1
        log.append("  FAIL  " + name)


def read(rel):
    p = os.path.join(ROOT, rel)
    return open(p, encoding="utf-8").read() if os.path.isfile(p) else ""


# ---------- 1. 文档本体 ----------
zh = read("assets/docs/跨境数据传输协议.md")
en = read("assets/docs/en/transfer.md")
chk(len(zh) > 3000, "中文协议存在且正文完整（%d 字符）" % len(zh))
chk(len(en) > 3000, "英文协议存在且正文完整（%d 字符）" % len(en))
chk("第三十八条" in zh and "第三十九条" in zh, "中文版引用了 PIPL 第 38/39 条")
chk("10 万人" in zh, "中文版写明出境规模门槛")
# 英文版必须是按各地法律重写，不是直译：应出现 GDPR 章节、英国、加州等本地框架
for kw, name in [
    ("Chapter V", "英文版以 GDPR 第五章组织转移机制"),
    ("UK GDPR", "英文版覆盖英国 GDPR"),
    ("CCPA", "英文版覆盖加州 CCPA/CPRA"),
    ("PIPEDA", "英文版覆盖加拿大 PIPEDA"),
    ("APP 8", "英文版覆盖澳洲 APP 8"),
    ("PDPA", "英文版覆盖新加坡 PDPA"),
    ("2021/914", "英文版引用 EU SCC 决定号"),
    ("10 July 2023", "英文版引用 EU-US DPF 充分性决定日期"),
]:
    chk(kw in en, name)
chk("个人信息保护法" not in en.replace("PRC Personal Information Protection Law", ""),
    "英文版没有整段照搬中文法条标题（按本地框架重写）")

# 文档页登记与入口
docjs = read("assets/js/src/pages/doc.js")
chk("'跨境数据传输协议': 'transfer'" in docjs, "文档页 SLUG 已登记 transfer")
appjs = read("assets/js/src/app.js")
chk("/doc/跨境数据传输协议" in appjs, "侧栏有该文档入口")
for lang, word in [("en", "International Data Transfer Notice"), ("ja", "国際データ移転通知")]:
    d = read("assets/i18n/%s.json" % lang)
    chk(word in d, "i18n %s 有文档名" % lang)
# 互链：中英文各 7 份旧文档都应指向新文档
zh_links = sum(1 for f in os.listdir(os.path.join(ROOT, "assets/docs"))
               if f.endswith(".md") and "/docs/transfer" in read("assets/docs/" + f))
en_links = sum(1 for f in os.listdir(os.path.join(ROOT, "assets/docs/en"))
               if f.endswith(".md") and "/docs/transfer" in read("assets/docs/en/" + f))
chk(zh_links >= 8, "中文文档互链已覆盖（%d 份）" % zh_links)
chk(en_links >= 8, "英文文档互链已覆盖（%d 份）" % en_links)

# ---------- 2. 单独同意 ----------
login = read("assets/js/src/pages/login.js")
chk("crossAgreed" in login, "登录页有独立的出境同意状态")
chk('id="crossBox"' in login, "勾选框与主协议分开")
chk("if (!crossAgreed)" in login, "未勾选时阻止提交")
chk("payload.cross_consent = 1" in login, "注册时上报同意标记")
auth = read("api/auth.php")
chk("cross_consent" in auth, "接口层读取同意标记")
core_auth = read("app/auth.php")
chk("consent_cross_at" in core_auth, "注册后写入同意时间")
mig = read("app/migrate.php")
chk("consent_cross_at" in mig, "迁移含 users.consent_cross_at")
chk("SCHEMA_VERSION', 49" in mig, "SCHEMA_VERSION 已到 49")

# ---------- 3. 并发闸门与 5xx 重试 ----------
core = read("assets/js/src/core.js")
chk("API_MAX_CONCURRENCY" in core, "存在全局并发上限")
chk("function apiSlot" in core and "function apiRelease" in core, "有名额获取/归还")
chk("await apiSlot()" in core, "api() 进入前排队")
chk("apiRelease();" in core, "finally 里归还名额")
chk("function apiRetriable" in core, "有可重试判定函数")
chk("c >= 500 && c <= 599" in core, "5xx 纳入可重试范围")
chk("c === 0" in core, "网络层失败仍可重试")
chk("!apiRetriable(e)" in core, "循环改用 apiRetriable 判定")
chk("主机繁忙" in core, "5xx 的 HTML 错误页给出「主机繁忙」而非「被拦截」")
chk("__noSlot" in core, "递归重放复用名额（防并发池自锁）")
# 4xx 不应被重试
chk(re.search(r"c === 0 \|\| \(c >= 500 && c <= 599\)", core) is not None,
    "4xx 与业务错误不被重试")

# ---------- 4. README 多语言 ----------
readme_hits = 0
for f in ["README.md", "README.zh-CN.md", "README.zh-TW.md", "README.ja.md",
          "README.ko.md", "README.it.md", "README.fr.md"]:
    t = read(f)
    if "Transfer" in t or "跨境" in t or "越境" in t or "국외" in t or "Trasferimenti" in t or "Transferts" in t:
        readme_hits += 1
chk(readme_hits == 7, "7 种语言的 README 都写入了该协议（%d/7）" % readme_hits)

print("\n".join(log))
print("-" * 40)
print("通过 %d 项，失败 %d 项" % (ok, fail))
sys.exit(1 if fail else 0)
