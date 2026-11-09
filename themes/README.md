# 主题

本站的外观可以完全由一个 JSON 文件决定：叫什么、什么颜色、多圆、背景什么样子、用哪些图标、什么字体、要不要强制深色。

- 规范全文：[SPEC.md](SPEC.md)
- 结构定义：[schema/theme-1.schema.json](schema/theme-1.schema.json)
- 现成示例：[default.theme.json](default.theme.json)（站内默认主题）

---

## 怎么用

站内「我的 → 外观设置 → 主题 JSON」可以导入，两种方式都行：

1. 选一个 `.json` 文件
2. 直接粘贴 JSON 文本

文件不超过 1 MB。导入后立即生效，不需要刷新；登录状态下会同步到云端，保留 7 天。

---

## 最小示例

```json
{
  "schema": "theme/1",
  "id": "my-theme",
  "name": "我的主题",
  "colour": "#007AFF",
  "radius": 18,
  "force": "auto"
}
```

这五行就是一个合法主题：主色蓝、圆角 18、不强制明暗，其余全部回退默认。

只写中文名也可以——英文界面下会显示中文，不会显示空白或 id。

---

## 加背景和图标

```json
{
  "schema": "theme/1",
  "id": "starry",
  "name": { "zh": "星空", "en": "Starry", "ja": "星空" },
  "colour": { "accent": "#1E3A8A", "accent2": "#3B82F6" },
  "colourDesc": { "zh": "深蓝夜幕上的星点" },
  "radius": { "lg": 14, "md": 10, "sm": 7 },
  "background": [
    { "type": "css", "css": "linear-gradient(180deg,#0B1220,#14213D)" },
    { "type": "svg",
      "src": "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Ccircle cx='60' cy='40' r='2' fill='%23fff'/%3E%3C/svg%3E",
      "position": "top left", "size": "420px auto", "opacity": 0.7, "animate": "pulse" }
  ],
  "icons": {
    "nav.total": "M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 5.2 12H4a3 3 0 0 1 0-6h2V3z",
    "nav.chat": { "type": "url", "src": "https://example.com/chat.png" }
  },
  "font": {
    "body": {
      "kind": "stylesheet",
      "url": "https://cdn.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css",
      "family": "MiSans VF"
    }
  },
  "force": "dark"
}
```

`background` 是数组，**先写的在下**。每层可以用 `position` / `size` / `repeat` / `opacity` / `blend` / `mask` / `animate` 独立控制——星星放右上、光晕铺满、网格渐隐，就是三层，不写一行 CSS 选择器。

`icons` 的键必须是 SPEC §8.1 里那 66 个位置之一，写错位置名会校验失败。没写的、写 `null` 的、加载失败的，一律回退默认图标。

---

## 校验

```bash
python3 tools/theme_check.py themes/你的主题.json
```

通过时输出：

```
✓ themes/starry.json  「星空」  图标覆盖 2 个 · 背景 2 层 · 强制 dark
```

不通过会指出具体哪一条，例如：

```
✗ 图标位置 'nav.nope' 不在命名表内（见规范 §8.1）
✗ colour.accent 必须是 #RRGGBB
✗ tokens 含白名单外的键：--font-size
```

CI 会对 `themes/*.json` 全量跑这个检查，所以提交前本地先跑一遍最省事。

---

## 投稿

想要自己的主题进官方目录，去提 Issue，选 **主题投稿** 模板，填三样：

1. 主题文件（贴 JSON 或给可下载链接）
2. 预览截图
3. 一句话说明想法

评审看四条硬指标，不看个人口味：

| 指标 | 标准 |
|---|---|
| 看得清 | 正文对比度 ≥ 4.5:1 |
| 不挡手 | 装饰不压住按钮与输入框 |
| 不刺眼 | 无高频闪烁、无全屏强对比跳变 |
| 不跑题 | 图标语义与所在位置相符 |

通过就收进 `themes/` 正式目录，署名与许可照你填的写。没过会在 Issue 里说明卡在哪一条。

---

## 目录

```
themes/
  README.md                  本文件：用法与投稿
  SPEC.md                    完整规范（21 节）
  default.theme.json         站内默认主题
  schema/theme-1.schema.json JSON Schema（编辑器补全与校验用）
  <你的主题>.theme.json       收录后的作品
```
