#!/usr/bin/env bash
#
# Kimi游戏榜 · 部署工具 —— 无 Gradle 构建脚本（含 Material Components 依赖）
#
#   1) 解析并下载 Material Components 依赖（国内镜像）
#   2) 展开 AAR：取 classes.jar + res
#   3) aapt2 编译应用与库资源 → aapt2 link（--auto-add-overlay + --extra-packages）
#   4) javac → d8 → classes.dex 装进 base.apk → zipalign → apksigner 签名
#
# 为什么不用 Gradle：整个工程零手写依赖，只用 Material 一个 UI 库，
# 用 Gradle 要拉一整套 AGP + Kotlin 插件，为几十 KB 的壳工程不值得。
#
# 用法：
#   bash apk/build.sh                # 自动准备工具链与依赖后出包
#   bash apk/build.sh --skip-tools   # 工具链/依赖已就绪，跳过下载
#
# 环境变量：
#   APK_DEPS      依赖目录（默认 apk/.toolchain/deps）
#   APK_KS_PASS   签名密钥库口令（默认 kimigame-rank-deploy）
#
set -euo pipefail

APK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS="$APK_DIR/.toolchain"
OUT="$APK_DIR/out"
LIBS="$APK_DIR/libs"
BT="$TOOLS/build-tools"
PLATFORM="$TOOLS/platform"
DEPS="${APK_DEPS:-$TOOLS/deps}"
FAST="$APK_DIR/../../skills/chrome-kernel-installer/scripts/parallel_fetch.sh"

BT_URLS=(
  "https://dl.google.com/android/repository/build-tools_r35_linux.zip"
  "https://mirrors.cloud.tencent.com/AndroidSDK/build-tools_r35_linux.zip"
)
PLAT_URLS=(
  "https://dl.google.com/android/repository/platform-35_r02.zip"
  "https://mirrors.cloud.tencent.com/AndroidSDK/platform-35_r02.zip"
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
    if [ -x "$FAST" ] && bash "$FAST" "$u" "$dest" 512 512 >/dev/null 2>&1 && [ -s "$dest" ]; then
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
  if [ -x "$BT/aapt2" ] && [ -f "$BT/lib/d8.jar" ] && [ -f "$JAR" ]; then
    log "工具链已就绪"
    return 0
  fi

  log "准备工具链（build-tools r35 + android-35）"
  mkdir -p "$TOOLS"

  if [ ! -x "$BT/aapt2" ]; then
    grab "$TOOLS/bt.zip" "${BT_URLS[@]}" || die "build-tools 下载失败（可手动放到 $TOOLS/bt.zip）"
    rm -rf "$TOOLS/bt_raw"; mkdir -p "$TOOLS/bt_raw"
    unzip -oq "$TOOLS/bt.zip" -d "$TOOLS/bt_raw"
    local src; src="$(dirname "$(find "$TOOLS/bt_raw" -maxdepth 3 -name aapt2 -type f | head -1)")"
    [ -n "$src" ] || die "build-tools 压缩包结构异常"
    rm -rf "$BT"; cp -a "$src" "$BT"
    chmod +x "$BT/aapt2" "$BT/d8" "$BT/zipalign" "$BT/apksigner" 2>/dev/null || true
  fi

  if [ ! -f "$JAR" ]; then
    grab "$TOOLS/plat.zip" "${PLAT_URLS[@]}" || die "platform 下载失败（可手动放到 $TOOLS/plat.zip）"
    rm -rf "$TOOLS/plat_raw"; mkdir -p "$TOOLS/plat_raw"
    unzip -oq "$TOOLS/plat.zip" -d "$TOOLS/plat_raw"
    local src; src="$(dirname "$(find "$TOOLS/plat_raw" -maxdepth 3 -name android.jar -type f | head -1)")"
    [ -n "$src" ] || die "platform 压缩包结构异常"
    rm -rf "$PLATFORM"; cp -a "$src" "$PLATFORM"
  fi

  [ -f "$JAR" ] || die "android.jar 缺失"
  info "build-tools → $BT"
  info "platform    → $PLATFORM"
}

# ---------------------------------------------------------------- 依赖

prepare_deps() {
  if [ -d "$DEPS/aar" ] && [ -n "$(ls -A "$DEPS/aar" 2>/dev/null)" ]; then
    info "依赖已就绪（$(ls "$DEPS"/aar/*.aar 2>/dev/null | wc -l) 个 AAR）"
    return 0
  fi
  log "解析并下载 Material Components 依赖（国内镜像）"
  APK_DEPS_DIR="$DEPS" python3 "$APK_DIR/deps_resolve.py"
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
prepare_deps

JAR="$PLATFORM/android.jar"
[ -f "$JAR" ] || die "找不到 $JAR（先跑一次不带 --skip-tools 的构建）"

log "清理输出"
rm -rf "$OUT"
mkdir -p "$OUT/gen" "$OUT/classes" "$OUT/dex" "$OUT/work/cpjars" "$OUT/work/libres" "$OUT/work/aar"
WORK="$OUT/work"

log "编译应用资源（aapt2 compile）"
"$BT/aapt2" compile --dir "$APK_DIR/res" -o "$OUT/app_res.zip"

log "展开依赖 AAR（只取 classes.jar 与 res；res 走 tmpfs，避开 overlayfs 慢）"
PKGS=""
TMPD="/dev/shm/aarwork"
if [ ! -d /dev/shm ] || ! mkdir -p "$TMPD" 2>/dev/null; then TMPD="$WORK/aar"; mkdir -p "$TMPD"; fi
rm -rf "$TMPD"; mkdir -p "$TMPD"
for aar in "$DEPS"/aar/*.aar; do
  [ -e "$aar" ] || continue
  n="$(basename "$aar" .aar)"
  d="$TMPD/$n"
  mkdir -p "$d"
  # classes.jar：只取这一项，不解压整包
  if unzip -p "$aar" classes.jar > "$WORK/cpjars/$n.jar" 2>/dev/null && [ -s "$WORK/cpjars/$n.jar" ]; then :; else rm -f "$WORK/cpjars/$n.jar"; fi
  # manifest：取包名（生成库 R 类用）
  unzip -p "$aar" AndroidManifest.xml > "$d/AndroidManifest.xml" 2>/dev/null || true
  pkg="$(grep -o 'package="[^"]*"' "$d/AndroidManifest.xml" 2>/dev/null | head -1 | sed 's/package="//;s/"//')"
  [ -n "$pkg" ] && PKGS="$PKGS:$pkg"
  # res：只解压 res/ 到 tmpfs，编译成 zip 后立即删
  if unzip -oq "$aar" 'res/*' -d "$d" </dev/null 2>/dev/null && [ -d "$d/res" ]; then
    "$BT/aapt2" compile --dir "$d/res" -o "$WORK/libres/$n.zip" </dev/null 2>/dev/null || info "  跳过 $n 资源"
  fi
  rm -rf "$d"
done
info "$(ls "$DEPS"/aar/*.aar 2>/dev/null | wc -l) 个 AAR，$(ls "$WORK"/cpjars/*.jar 2>/dev/null | wc -l) 个 classes.jar，资源包 $(ls "$WORK"/libres/*.zip 2>/dev/null | wc -l) 个"

log "链接资源（应用 + 库，--auto-add-overlay）"
RARGS=()
for z in "$WORK"/libres/*.zip; do [ -e "$z" ] && RARGS+=(-R "$z"); done
AOPT=(); [ -d "$APK_DIR/assets" ] && AOPT=(-A "$APK_DIR/assets")
"$BT/aapt2" link -o "$OUT/base.apk" \
  -I "$JAR" \
  --manifest "$APK_DIR/AndroidManifest.xml" \
  --java "$OUT/gen" \
  --auto-add-overlay \
  --min-sdk-version 26 --target-sdk-version 35 \
  --extra-packages "${PKGS#:}" \
  "${AOPT[@]}" \
  "${RARGS[@]}" \
  "$OUT/app_res.zip"

log "探测 javac 源级别"
SRC="$(probe_source)" || die "javac 不接受 -source 8/11/17 中的任何一个"
info "使用 -source $SRC -target $SRC"

log "编译 Java（javac）"
CP="$JAR"
[ -s "$LIBS/jsch.jar" ] && CP="$CP:$LIBS/jsch.jar"
for j in "$WORK"/cpjars/*.jar "$DEPS"/jar/*.jar; do [ -e "$j" ] && CP="$CP:$j"; done
find "$APK_DIR/src" "$OUT/gen" -name '*.java' > "$OUT/sources.txt"
if [ ! -s "$LIBS/jsch.jar" ]; then
  grep -v '/Sftp\.java$' "$OUT/sources.txt" > "$OUT/sources2.txt" || true
  mv "$OUT/sources2.txt" "$OUT/sources.txt"
  info "未内置 SSH 库 → 跳过 Sftp.java（自动部署仅 FTP）"
fi
info "$(wc -l < "$OUT/sources.txt") 个源文件，classpath $(echo "$CP" | tr ':' '\n' | wc -l) 项"
javac -source "$SRC" -target "$SRC" -nowarn -encoding UTF-8 \
  -bootclasspath "$JAR" -classpath "$CP" \
  -d "$OUT/classes" @"$OUT/sources.txt"

log "自检：X25519（RFC 7748 测试向量）"
java -cp "$OUT/classes" com.kimigame.deploy.X25519 >/dev/null \
  || die "X25519 自检未通过，互通密钥可能算错，先别出包"

log "转 dex（d8）"
DEX_IN="$(find "$OUT/classes" -name '*.class' | tr '\n' ' ')"
for j in "$WORK"/cpjars/*.jar "$DEPS"/jar/*.jar; do [ -e "$j" ] && DEX_IN="$DEX_IN $j"; done
[ -e "$LIBS/jsch.jar" ] && [ -s "$LIBS/jsch.jar" ] && DEX_IN="$DEX_IN $LIBS/jsch.jar"
"$BT/d8" --release --min-api 26 --lib "$JAR" --output "$OUT/dex" $DEX_IN
[ -f "$OUT/dex/classes.dex" ] || die "d8 未产出 classes.dex"

log "装 dex 进 APK（多 dex：classes.dex + classes2.dex …，均不压缩）"
python3 - "$OUT/base.apk" "$OUT/dex" <<'PY'
import os, sys, zipfile
apk, dexp = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(apk, "a", zipfile.ZIP_STORED) as z:
    names = set(z.namelist())
    for fn in sorted(os.listdir(dexp)):
        if fn.endswith(".dex") and fn not in names:
            z.write(os.path.join(dexp, fn), fn, compress_type=zipfile.ZIP_STORED)
            print("   %s → APK" % fn)
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
# overlayfs 上 apksigner 多线程读文件会偶发 EIO：先搬到 tmpfs 再签，签完搬回
SIGN_TMP="$(mktemp -d /dev/shm/apksign.XXXXXX 2>/dev/null || mktemp -d)"
cp "$OUT/aligned.apk" "$SIGN_TMP/in.apk"
"$BT/apksigner" sign \
  --ks "$KS" --ks-key-alias "$KS_ALIAS" \
  --ks-pass "pass:$KS_PASS" --key-pass "pass:$KS_PASS" \
  --min-sdk-version 26 --v1-signing-enabled true --v2-signing-enabled true \
  --out "$SIGN_TMP/out.apk" "$SIGN_TMP/in.apk"
cp "$SIGN_TMP/out.apk" "$OUT/kimi-game-rank-deploy.apk"
rm -rf "$SIGN_TMP"

log "校验签名"
"$BT/apksigner" verify --print-certs "$OUT/kimi-game-rank-deploy.apk" 2>/dev/null | sed 's/^/   /'

log "完成"
info "$(ls -lh "$OUT/kimi-game-rank-deploy.apk" | awk '{print $5}')  $OUT/kimi-game-rank-deploy.apk"
