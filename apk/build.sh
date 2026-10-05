#!/usr/bin/env bash
#
# Kimi游戏榜 · 部署工具 —— 无 Gradle 构建脚本
#
#   aapt2 编译资源 → aapt2 链接出 base.apk → javac 编译 → d8 转 dex
#   → 把 classes.dex 塞进 base.apk → zipalign → apksigner 签名
#
# 为什么不用 Gradle：整个工程零依赖（只可选内置一个 SSH 库），
# 用 Gradle 要拉一整套 AGP + Kotlin 插件，纯粹为了打一个几十 KB 的 APK 不值得。
#
# 用法：
#   bash apk/build.sh                # 自动准备工具链后出包
#   bash apk/build.sh --skip-tools   # 工具链已就绪，跳过下载
#
# 环境变量（都有默认值）：
#   APK_KS_PASS   签名密钥库口令（默认 kimigame-rank-deploy）
#   APK_KS        密钥库路径（默认 apk/.toolchain/apk.keystore）
#   APK_NO_SSH=1  不内置 SSH 库（则自动部署只能用 FTP）
#
set -euo pipefail

APK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS="$APK_DIR/.toolchain"
OUT="$APK_DIR/out"
LIBS="$APK_DIR/libs"
BT="$TOOLS/build-tools"
PLATFORM="$TOOLS/platform"
JAR="$PLATFORM/android.jar"
FAST="$APK_DIR/../../skills/chrome-kernel-installer/scripts/parallel_fetch.sh"

BT_URLS=(
  "https://dl.google.com/android/repository/build-tools_r35_linux.zip"
  "https://mirrors.cloud.tencent.com/AndroidSDK/build-tools_r35_linux.zip"
)
PLAT_URLS=(
  "https://dl.google.com/android/repository/platform-35_r02.zip"
  "https://mirrors.cloud.tencent.com/AndroidSDK/platform-35_r02.zip"
)
JSCH_URLS=(
  "https://mirrors.cloud.tencent.com/nexus/repository/maven-public/com/github/mwiede/jsch/0.2.20/jsch-0.2.20.jar"
  "https://maven.aliyun.com/repository/public/com/github/mwiede/jsch/0.2.20/jsch-0.2.20.jar"
  "https://repo1.maven.org/maven2/com/github/mwiede/jsch/0.2.20/jsch-0.2.20.jar"
)

KS="${APK_KS:-$TOOLS/apk.keystore}"
KS_PASS="${APK_KS_PASS:-kimigame-rank-deploy}"
KS_ALIAS="kimgr"

log()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
die()  { printf '\033[31m错误：%s\033[0m\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "缺少命令 $1"; }

SKIP_TOOLS=0
[ "${1:-}" = "--skip-tools" ] && SKIP_TOOLS=1

need java; need javac; need keytool; need unzip
need python3
command -v curl >/dev/null 2>&1 || die "缺少 curl"

# ---------------------------------------------------------------- 下载

grab() {                       # grab <输出文件> <URL...>
  local dest="$1"; shift
  [ -s "$dest" ] && return 0
  local u
  for u in "$@"; do
    info "← $u"
    if [ -x "$FAST" ] && bash "$FAST" "$u" "$dest" 8 >/dev/null 2>&1 && [ -s "$dest" ]; then
      return 0
    fi
    if curl -fL --retry 3 --connect-timeout 20 --max-time 1800 -o "$dest.part" "$u" 2>/dev/null; then
      mv "$dest.part" "$dest"; return 0
    fi
    rm -f "$dest.part"
  done
  return 1
}

# ---------------------------------------------------------------- 工具链

prepare_tools() {
  if [ -x "$BT/aapt2" ] && [ -f "$BT/lib/d8.jar" ] && [ -f "$BT/lib/apksigner.jar" ] \
     && [ -f "$JAR" ]; then
    log "工具链已就绪"
    return 0
  fi

  log "准备工具链（build-tools r35 + android-35）"
  mkdir -p "$TOOLS"

  if [ ! -f "$JAR" ]; then
    grab "$TOOLS/bt.zip" "${BT_URLS[@]}" || die "build-tools 下载失败（可手动放到 $TOOLS/bt.zip）"
    rm -rf "$TOOLS/bt_raw"; mkdir -p "$TOOLS/bt_raw"
    unzip -q "$TOOLS/bt.zip" -d "$TOOLS/bt_raw"
    local src; src="$(dirname "$(find "$TOOLS/bt_raw" -maxdepth 3 -name aapt2 -type f | head -1)")"
    [ -n "$src" ] || die "build-tools 压缩包结构异常"
    rm -rf "$BT"; mv "$src" "$BT"
    chmod +x "$BT/aapt2" "$BT/d8" "$BT/zipalign" "$BT/apksigner" 2>/dev/null || true
  fi

  if [ ! -f "$JAR" ]; then
    grab "$TOOLS/plat.zip" "${PLAT_URLS[@]}" || die "platform 下载失败（可手动放到 $TOOLS/plat.zip）"
    rm -rf "$TOOLS/plat_raw"; mkdir -p "$TOOLS/plat_raw"
    unzip -q "$TOOLS/plat.zip" -d "$TOOLS/plat_raw"
    local src; src="$(dirname "$(find "$TOOLS/plat_raw" -maxdepth 3 -name android.jar -type f | head -1)")"
    [ -n "$src" ] || die "platform 压缩包结构异常"
    rm -rf "$PLATFORM"; mv "$src" "$PLATFORM"
  fi

  [ -f "$JAR" ] || die "android.jar 缺失"
  info "build-tools → $BT"
  info "platform    → $PLATFORM"
}

prepare_ssh() {
  [ "${APK_NO_SSH:-0}" = "1" ] && { info "按要求跳过 SSH 库（自动部署仅 FTP）"; return 0; }
  mkdir -p "$LIBS"
  if [ -s "$LIBS/jsch.jar" ]; then
    info "SSH 库已就绪"
    return 0
  fi
  if grab "$LIBS/jsch.jar" "${JSCH_URLS[@]}"; then
    info "SSH 库（JSch）已下载"
  else
    rm -f "$LIBS/jsch.jar"
    info "SSH 库不可达 —— 本次构建不含 SSH，自动部署只用 FTP（不影响手动部署）"
  fi
}

# ---------------------------------------------------------------- 源级别探测

probe_source() {
  local p="$TOOLS/probe" t
  mkdir -p "$p"
  printf 'class P {}\n' > "$p/P.java"
  for t in 8 11 17; do
    rm -rf "$p/out"; mkdir -p "$p/out"
    if javac -source "$t" -target "$t" -nowarn -d "$p/out" "$p/P.java" >/dev/null 2>&1; then
      printf '%s' "$t"; return 0
    fi
  done
  return 1
}

# ---------------------------------------------------------------- 主流程

[ "$SKIP_TOOLS" = "1" ] || prepare_tools
prepare_ssh

JAR="$PLATFORM/android.jar"
[ -f "$JAR" ] || die "找不到 $JAR（先跑一次不带 --skip-tools 的构建）"

log "清理输出"
rm -rf "$OUT"
mkdir -p "$OUT/gen" "$OUT/classes" "$OUT/dex"

log "编译资源（aapt2 compile）"
"$BT/aapt2" compile --dir "$APK_DIR/res" -o "$OUT/res.zip"

log "链接资源（aapt2 link）"
AOPT=(); [ -d "$APK_DIR/assets" ] && AOPT=(-A "$APK_DIR/assets")
"$BT/aapt2" link -o "$OUT/base.apk" \
  -I "$JAR" \
  --manifest "$APK_DIR/AndroidManifest.xml" \
  -R "$OUT/res.zip" \
  --java "$OUT/gen" \
  "${AOPT[@]}"

log "探测 javac 源级别"
SRC="$(probe_source)" || die "javac 不接受 -source 8/11/17 中的任何一个"
info "使用 -source $SRC -target $SRC"

log "编译 Java（javac）"
CP="$JAR"
find "$APK_DIR/src" "$OUT/gen" -name '*.java' > "$OUT/sources.txt"
if [ -s "$LIBS/jsch.jar" ]; then
  CP="$CP:$LIBS/jsch.jar"
else
  grep -v '/Sftp\.java$' "$OUT/sources.txt" > "$OUT/sources2.txt" || true
  mv "$OUT/sources2.txt" "$OUT/sources.txt"
  info "未内置 SSH 库 → 跳过 Sftp.java（自动部署仅 FTP）"
fi
info "$(wc -l < "$OUT/sources.txt") 个源文件"
javac -source "$SRC" -target "$SRC" -nowarn -encoding UTF-8 \
  -bootclasspath "$JAR" -classpath "$CP" \
  -d "$OUT/classes" @"$OUT/sources.txt"

log "自检：X25519（RFC 7748 测试向量）"
java -cp "$OUT/classes" com.kimigame.deploy.X25519 >/dev/null \
  || die "X25519 自检未通过，互通密钥可能算错，先别出包"

log "转 dex（d8）"
DEX_IN="$(find "$OUT/classes" -name '*.class' | tr '\n' ' ')"
[ -s "$LIBS/jsch.jar" ] && DEX_IN="$DEX_IN $LIBS/jsch.jar"
"$BT/d8" --release --min-api 26 --lib "$JAR" --output "$OUT/dex" $DEX_IN
[ -f "$OUT/dex/classes.dex" ] || die "d8 未产出 classes.dex"

log "装 dex 进 APK（classes.dex 不压缩存放）"
python3 - "$OUT/base.apk" "$OUT/dex/classes.dex" <<'PY'
import sys, zipfile
apk, dex = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(apk, "a", zipfile.ZIP_STORED) as z:
    if "classes.dex" in z.namelist():
        raise SystemExit("base.apk 里已存在 classes.dex")
    z.write(dex, "classes.dex", compress_type=zipfile.ZIP_STORED)
print("   classes.dex → %s" % apk)
PY

log "对齐（zipalign）"
"$BT/zipalign" -f -p 4 "$OUT/base.apk" "$OUT/aligned.apk"

if [ ! -f "$KS" ]; then
  log "生成签名密钥库（仅此一次，请妥善保存）"
  mkdir -p "$(dirname "$KS")"
  keytool -genkeypair -v -keystore "$KS" -storetype PKCS12 -alias "$KS_ALIAS" \
    -keyalg RSA -keysize 2048 -validity 10950 \
    -storepass "$KS_PASS" -keypass "$KS_PASS" \
    -dname "CN=Kimi Game Rank Deploy, OU=Tools, O=kimi-game-rank, C=CN" >/dev/null
  info "密钥库 → $KS"
fi

log "签名（apksigner）"
"$BT/apksigner" sign \
  --ks "$KS" --ks-key-alias "$KS_ALIAS" \
  --ks-pass "pass:$KS_PASS" --key-pass "pass:$KS_PASS" \
  --min-sdk-version 26 --v1-signing-enabled true --v2-signing-enabled true \
  --out "$OUT/kimi-game-rank-deploy.apk" "$OUT/aligned.apk"

log "校验签名"
"$BT/apksigner" verify --print-certs "$OUT/kimi-game-rank-deploy.apk" | sed 's/^/   /'

log "完成"
info "$(ls -lh "$OUT/kimi-game-rank-deploy.apk" | awk '{print $5}')  $OUT/kimi-game-rank-deploy.apk"
