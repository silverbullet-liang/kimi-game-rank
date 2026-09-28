# 参与贡献

感谢你愿意一起完善「Kimi 游戏榜」。请先读一遍下面的约定，能省掉很多来回。

## 提交之前

### 绝对不要提交的东西

- 任何真实密钥：`config/api_keys.php`、模型 Key、Token；
- 数据库连接与口令：`config/config.php`；
- 审核词库：`app/data/moderation_words.txt`、`moderation_allow.txt`；
- 用户数据、日志、备份、上传文件。

以上均已在 `.gitignore` 中。请在提交前跑：

```bash
python3 tools/repo_check.py
```

**它不通过，就不要提 PR。** 它会检查禁入文件、密钥指纹与数据库连接值是否泄漏。

### 代码基线

- **PHP 基线是 7.0**：不要用 7.1+ 语法（如 `: void` 返回类型、可空类型声明）。
- 前端源码在 `assets/js/src/`，改完必须重新打包：

```bash
python3 tools/build.py      # 产物：assets/js/app.js（勿手改产物）
```

### 四条自检

```bash
python3 tools/phpcheck.py   # PHP 语法 + 基线
python3 tools/jscheck.py    # 前端重名冲突
python3 tools/repo_check.py # 泄漏自检
```

## 代码风格

- 命名语义化，注释只写「为什么」，不写「是什么」；
- 一个函数只做一件事；能用数据结构表达的分支，不要堆 `if`；
- 健壮性优先：空状态、错误边界、并发竞态、资源清理都要考虑到；
- 不要为一个小改动引入新的运行时依赖。

## 提交信息

用一句话说清「做了什么」，必要时补一行「为什么」。例如：

```
修复 AI 回答收尾时报错导致回复中断
```

## Pull Request

1. 从 `main` 切分支，一个 PR 只做一件事；
2. 描述里写：改了什么、为什么、怎么验证的；
3. 涉及界面改动的，附一张截图；
4. 确认四条自检都通过。

## 安全问题

**不要开公开 Issue**，请按 [SECURITY.md](SECURITY.md) 的私密渠道报告。

## 行为准则

参与本项目即表示你同意遵守 [行为准则](CODE_OF_CONDUCT.md)。
