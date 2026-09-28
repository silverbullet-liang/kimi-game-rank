# Kimi 游戏榜 · kimi-game-rank

面向 **Kimi 社区**的六维综合评分排行榜。PHP + MySQL，前端零构建（原生 HTML / CSS / JavaScript）。

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> 本项目**非 Kimi 官方项目**，与月之暗面（Moonshot AI）无从属关系。

---

## 这是什么

一个把社区里的游戏、工具、文学、二创作品收集起来、按六个维度打分并排行的网站。评分由算法自动完成，站长可按实际情况对个别作品做人工修正。

## 功能一览

| 模块 | 说明 |
|---|---|
| 榜单 | 四分类 × 四榜（总榜 / 投票榜 / 诸神榜 / 冷门榜），排名徽章与评级标签 |
| 作品详情 | 六维雷达图、六维明细、介绍与评论区（任意层级楼中楼） |
| 对话 | 世界对话（实时轮询）+ AI 对话（流式输出、联网搜索、注入站内规则） |
| 我的 | 头像、AI 用量、IP 信息与访问记录、外观设置 |
| 登录 | 密码登录 / 注册 / 游客模式 |
| 控制面板 | 收录与批量更新、数据库备份、评论审核、标签管理、副管理员 |
| 智能链接识别 | BETA，默认关闭：作品页若只是跳转页，改用其中的真实地址 |
| 皮肤 | 多套视觉皮肤；字体站内自托管，不依赖第三方 CDN |

## 技术栈与要求

- **PHP ≥ 7.0**（代码基线为 7.0，不使用 7.1+ 语法）
- **MySQL / MariaDB**（InnoDB，`utf8mb4`）
- 前端：原生 ES 模块源码 → 单文件产物，**无需 Node 运行时**（仅构建时用）
- 无 Composer 依赖、无前端框架

## 部署

```bash
git clone https://github.com/silverbullet-liang/kimi-game-rank.git
cd kimi-game-rank

# 1) 主配置：复制模板并填好数据库连接
cp config/config.sample.php config/config.php
$EDITOR config/config.php

# 2) 密钥池：复制模板并填入你自己的 Key
cp config/api_keys.sample.php config/api_keys.php
$EDITOR config/api_keys.php

# 3) 审核词库：官方词表不公开，复制示例后按自己的尺度维护
cp app/data/moderation_words.sample.txt app/data/moderation_words.txt
cp app/data/moderation_allow.sample.txt app/data/moderation_allow.txt
```

把站点根目录指到本项目根目录，然后浏览器打开首页——**初始化会自动完成**（建库、建表、生成密钥）。若数据库未就绪，页面会给出明确提示。

> 词库文件缺失时，审核模块会自动降级为「不拦截」，站点仍可正常运行。

## 配置说明

| 文件 | 是否入库 | 说明 |
|---|---|---|
| `config/config.php` | ❌ **禁止提交** | 数据库连接（主库 + 管理库）、站点信息、AES / cron 密钥、管理员凭证 |
| `config/api_keys.php` | ❌ **禁止提交** | 模型 API Key 池（智谱、OpenRouter） |
| `app/data/moderation_words.txt` | ❌ **禁止提交** | 审核词库（官方词表不公开） |
| `config/config.sample.php` | ✅ | 主配置模板 |
| `config/api_keys.sample.php` | ✅ | 密钥池模板 |

上面的 ❌ 三项均已写入 `.gitignore`。提交前请跑一遍泄漏自检：

```bash
python3 tools/repo_check.py
```

它会检查三件事：禁入文件是否混入待提交集合、文本里有无密钥指纹、**有无数据库连接值泄漏**。

## 目录结构

```
├── api/            接口层（JSON 入口，鉴权 + CSRF + 限流）
├── app/            业务层（配置、数据库、评分、鉴权、内容审核、AI 通道…）
│   └── data/       审核词库（仅保留 .sample）
├── assets/
│   ├── css/        样式与皮肤
│   ├── docs/       站内文档（公开页面的正文）
│   ├── fonts/      自托管字体
│   └── js/
│       ├── src/    前端源码（ES 模块，按页拆分）
│       └── app.js  构建产物（由 src 打包，勿手改）
├── config/         配置与密钥（仅 .sample 入库）
├── docs/           设计与算法文档
├── sql/            表结构
├── standalone/     独立展示页（更新日志 / 跳转页）
├── storage/        运行期数据（缓存、日志、上传、备份）
└── tools/          构建与校验脚本
```

## 构建前端

改了 `assets/js/src/` 下的源码后，必须重新打包：

```bash
python3 tools/build.py          # src/* → assets/js/app.js
```

## 提交前自检

```bash
python3 tools/phpcheck.py       # PHP 语法与基线检查
python3 tools/jscheck.py        # 前端重名冲突检查
python3 tools/repo_check.py     # 泄漏自检（密钥 / 词库 / 数据库连接）
```

## 安全与渗透测试

我们欢迎安全研究者审阅与测试本项目的代码。**授权范围、允许与禁止的行为、漏洞报告方式**，请先读 [SECURITY.md](SECURITY.md)。

## 开源协议

本项目采用 **[GNU Affero General Public License v3.0](LICENSE)**（AGPL-3.0）。

你可以自由使用、修改、分发，但有两个关键约束：

1. **衍生作品必须以同样的协议开源**；
2. **把修改后的版本作为网络服务提供给别人使用，也必须向使用者提供完整源码**——这正是 AGPL 与 GPL 的区别，也是选用它的原因。

## 参与贡献

见 [CONTRIBUTING.md](CONTRIBUTING.md)。提交前请确认：**没有夹带任何密钥、数据库连接或审核词库**。

## 免责声明

- 本项目与 Kimi / 月之暗面无从属关系，非官方项目。
- 使用者需自行遵守所在地法律法规，以及所调用第三方服务的条款。
- 代码按「现状」提供，不附带任何明示或默示担保。
