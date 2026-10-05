# Kimi游戏榜 · 部署工具（Android）

把 [kimi-game-rank](../) 部署到任意支持 PHP + MySQL 的主机。Android 8.0（API 26）及以上。
零第三方 UI 依赖，界面为自绘 Material 3（含深色模式）；唯一可选内置件是 SSH 库 JSch。

## 流程

```
首屏（全屏提示）
  ├── 自动部署 → 选连接方式（FTP / SSH 隧道）→ 列表
  └── 手动部署 → 列表
列表右下角「+」新增站点 → 全屏表单（选源码版本 → 填配置 → 保存）
列表底部按钮（随站点数量变化）：
  1 个站点 → 「开始自动部署」/「生成压缩包」
  ≥2 个站点 → 「站点配对」+ 主操作
```

**自动部署**跑完：下载（显示速度 / 剩余）→ 解压 → 删除冗余文件 → 处理（站点名）→ 打包 → 存到下载目录 → 上传。
**手动部署**少最后一步：只产出压缩包，自己上传。

压缩包命名 `kimi-game-rank-<站点名>.zip`，保存到
`/storage/emulated/0/Download/kimi-game-rank/`（Android 10+ 走 MediaStore，不需要存储权限）。

## 表单里几处容易填错的

| 项 | 说明 |
|---|---|
| 源码版本 | 从仓库 CHANGELOG 动态读（走 raw.githubusercontent），选「默认分支」即取主分支最新 |
| 管理员密钥 | 填**原文**，写进站点配置；下方实时算出 **sha256**，可一键复制 —— 那串 sha256 才是后台**登录密码**，两样都要留着 |
| 用户数据库名 / 管理员数据库名 | 主机面板里给的两个库名，原样照抄（如 `b33_43085350_user_data` / `b33_43085350_admin_data`） |
| AI Key | 选填，一行一个（多个组成 Key 池） |
| 分页加载页数 | 选填，1–50；留空用站点默认值 |
| FTP 数据连接 | **被动 PASV**（默认，绝大多数主机与网络都适配）/ **主动 PORT**（主机明确要求时才切） |
| SSH 私钥 | 选填，填了优先于口令；粘贴 OpenSSH / PEM 私钥全文 |

## 多站互通

点「站点配对」自动做 N 站**全互联**：每个站各生成一对 X25519 密钥（已生成过的不会重新生成，
否则已部署的站会全部失联），并把其余站点的名称 / 域名 / 公钥写进各自的 `peers` 配置。
任意两站之间都能双向同步；新增第 N+1 个站后重跑一次配对即可并入。

## 构建

不需要 Gradle：

```bash
bash apk/build.sh
# 产出：apk/out/kimi-game-rank-deploy.apk
```

脚本按 `aapt2 compile → aapt2 link → javac → d8 → 装 dex → zipalign → apksigner` 顺序执行，
并跑一次 X25519 的 RFC 7748 测试向量自检（不通过就拒绝出包）。

工具链从官方仓库取，失败时依次回退：

| 件 | 首选 | 备选 |
|---|---|---|
| build-tools r35 | `dl.google.com/android/repository/build-tools_r35_linux.zip` | 腾讯云 AndroidSDK 镜像 |
| android-35 平台 | `dl.google.com/android/repository/platform-35_r02.zip` | 腾讯云 AndroidSDK 镜像 |
| JSch 0.2.20（可选） | 腾讯云 Maven 镜像 | 阿里云 / Maven Central |

下载后缓存在 `apk/.toolchain/`（不进仓库）。清华源**没有** AndroidSDK 与 maven-central 目录（实测 404），
故默认走上面这些源；`dl.google.com` 在国内多数网络可直连。

环境变量：

| 变量 | 默认 | 作用 |
|---|---|---|
| `APK_NO_SSH` | `0` | 设 `1` 则不内置 JSch，自动部署只能用 FTP（手动部署不受影响） |
| `APK_KS` | `apk/.toolchain/apk.keystore` | 签名密钥库路径 |
| `APK_KS_PASS` | `kimigame-rank-deploy` | 密钥库口令 |

签名密钥库**不进仓库**（随交付包分发）。同一台机器务必长期保留同一个密钥库，
否则新版无法覆盖安装升级已装的旧版。首次构建会自动生成，也可先自备：

```bash
keytool -genkeypair -keystore apk/.toolchain/apk.keystore -storetype PKCS12 \
  -alias kimgr -keyalg RSA -keysize 2048 -validity 10950
```

> 未内置 Material Components / AndroidX：界面全部用框架 API 自绘，只为不让安装包徒增几 MB。

## 数据放在哪

站点配置（含数据库口令、FTP/SSH 口令、互通私钥）只写在应用私有目录
`/data/data/com.kimigame.deploy/files/sites.json`，卸载即清，不往任何服务器上传。

联网只发生在两处：读仓库版本列表 / 下载源码包（GitHub），以及把文件传到**你自己填的那台主机**。

## 许可

AGPL-3.0，与主仓库一致。
