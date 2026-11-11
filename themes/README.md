# 主题

本站外观由一个 JSON 文件决定：叫什么、什么颜色、多圆、背景什么样子、用哪些图标、什么字体、要不要强制明暗、点下去什么反应、对话框长什么样。

- 完整规范：[SPEC.md](SPEC.md)
- 结构定义：[schema/theme-1.schema.json](schema/theme-1.schema.json)
- 现成示例：[default.theme.json](default.theme.json)（站内默认主题）

---

## 怎么用

站内「我的 → 外观设置 → 设计风格」，列表最后一张卡片就是导入入口：

- 点卡片 → 选一个 `.json` 文件（最大 1 MB）
- 或者点下方「粘贴 JSON」直接贴内容
- 导入后卡片变成主题自己的名字与主色，点它即切换
- 点回任意内置主题会自动停用导入的那套（文件还留着，随时切回）

登录状态下会同步到云端，保留 **7 天**，换设备也能用。

---

## 最小主题

```json
{
  "schema": "theme/1",
  "id": "minimal",
  "name": "极简",
  "colour": "#007AFF"
}
```

四行。主色蓝，其余全部回退默认。

只写中文名也可以——英文界面会显示中文，不会空白。

---

## 能改什么

| 想改 | 用哪个字段 |
|---|---|
| 名字（中/英/日） | `name` |
| 主色 | `colour.accent` |
| 主色的一句话描述 | `colourDesc` |
| 圆角 | `radius` |
| 背景、装饰 | `background` |
| 图标 | `icons` |
| 字体 | `font` |
| 强制深色 / 浅色 | `force` |
| 玻璃模糊、阴影等 | `tokens` |
| 卡片、按钮的描边 | `borders` |
| 点击效果 | `effects.click` |
| 对话框配色 | `dialog` |

---

## 背景：可以叠好几层

```json
"background": [
  { "type": "css", "css": "linear-gradient(180deg,#0B1220,#14213D)" },
  { "type": "svg", "src": "data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 200 200\'%3E%3Ccircle cx=\'60\' cy=\'40\' r=\'2\' fill=\'%23fff\'/%3E%3C/svg%3E",
    "position": "top left", "size": "420px auto", "opacity": 0.7, "animate": "pulse" }
]
```

先写的在下。每层各自定位（`position` / `size` / `repeat`）、各自透明度、可以加 `mask` 渐隐或 `animate` 缓慢动效。

星星放右上、光晕铺满、网格渐隐——三层，不写一行选择器。

上限 12 层，单层 data URL 不超过 512 KB。

---

## 图标：75 个位置

```json
"icons": {
  "nav.total": "M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 5.2 12H4a3 3 0 0 1 0-6h2V3z",
  "tab.chat": { "type": "url", "src": "https://example.com/chat.png" },
  "ui.menu": { "type": "css", "css": "background:linear-gradient(45deg,#f59e0b,#ef4444);border-radius:3px" }
}
```

四种写法：SVG path 字符串 / `{"type":"url"}` / `{"type":"data"}` / `{"type":"css"}`。写 `null` 表示「用默认」。

**位置名必须来自 SPEC §8.1 那张表**（共 75 个，如 `nav.total`、`tab.rank`、`ui.theme`、`field.search`）。写表外的名字不会生效，也不会报错——查表再写。

没写的、写成 `null` 的、加载失败的，一律回退默认图标。

注意：表里有一部分位置**尚未接入**（`action.*`、`media.*`、`state.*` 等），写了暂时看不到变化，详见 SPEC §8.2。

---

## 边框：22 处

```json
"borders": {
  "card": "border:1px solid rgba(255,255,255,.72);box-shadow:0 10px 30px rgba(30,25,80,.10)",
  "button": "border:1px solid rgba(124,58,237,.26);border-radius:14px",
  "input": "border:1px solid rgba(20,20,40,.10)"
}
```

键从 SPEC §12 那 22 个里选：`card` `button` `input` `tabbar` `topbar` `seg` `switch` `chip` `toast` `drawer` `toolCard` 等。写别的键会被忽略。

只接受描边类属性，不接受选择器——主题只能改描边，不能改布局。

---

## 点击效果

```json
"effects": {
  "click": {
    "css": "background:radial-gradient(circle,rgba(124,58,237,.55),transparent 70%);width:88px;height:88px;margin:-44px 0 0 -44px;animation:tj-ripple .62s ease-out forwards",
    "keyframes": "@keyframes tj-ripple{from{transform:scale(.2);opacity:.9}to{transform:scale(1.6);opacity:0}}"
  }
}
```

点哪儿在哪儿出现，1.5 秒消失。位置与穿透由站点固定，主题写 `position` 或 `pointer-events` 会被丢弃——这是防止做出「盖住全屏、吃掉点击」的层。

---

## 对话框配色

```json
"dialog": {
  "bg": "rgba(255,255,255,.92)",
  "fg": "#17171E",
  "border": "rgba(20,20,40,.10)",
  "backdrop": "rgba(10,10,20,.42)",
  "dark": { "bg": "rgba(30,30,38,.94)", "fg": "#F2F2F5" }
}
```

`dark` 是深色模式那一套，切深浅色时自动换用。只接受纯色值。

---

## 校验

```bash
python3 tools/theme_check.py themes/your-theme.json
```

通过时：

```
✓ themes/your-theme.json  「星空」  图标 2 · 背景 2 层 · 边框 3 处 · 点击 点击 · 对话框 默认 · 强制 dark
```

不通过会指出具体哪一条，例如：

```text
✗ 图标位置 'nav.nope' 不在命名表内（见规范 §8.1）
✗ colour.accent 必须是 #RRGGBB
```

提交前本地跑一遍最省事——CI 会对 `themes/*.json` 全量跑同一个检查。

---

## 投稿

提 Issue 选 **主题投稿** 模板，填三样：主题文件（或可下载链接）、预览截图、一句话说明。

评审四条：看得清（对比度 ≥4.5:1）、不挡手（装饰不压交互区）、不刺眼（无高频闪烁）、不跑题（图标语义与位置相符）。

过了就收进 `themes/` 正式目录，署名与许可照你填的写；没过会指明卡在哪一条。

---

## 目录

```
themes/
  README.md                  本文件
  SPEC.md                    完整规范
  default.theme.json         站内默认主题
  schema/theme-1.schema.json 结构定义
  <id>.theme.json            收录的主题
```
