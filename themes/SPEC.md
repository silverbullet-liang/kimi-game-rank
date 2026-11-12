# 主题 JSON 规范 · theme/1

一个主题是一个 JSON 文件。它描述：叫什么、什么颜色、多圆、背景什么样子、用哪些图标、什么字体、要不要强制明暗、点下去有什么反应、对话框长什么样。

改主题 = 换一个文件；写主题不需要碰站点代码。

- 结构定义：[schema/theme-1.schema.json](schema/theme-1.schema.json)
- 参考实现：[default.theme.json](default.theme.json)
- 校验命令：`python3 tools/theme_check.py themes/你的主题.json`

> 本文档里的每个完整示例都能通过校验器。改文档后请跑 `python3 tools/doc_check.py`——它会扫描文档中的位置名与示例，发现「写了不存在的东西」就报错。

---

## 1. 文件结构

```
themes/
  README.md                    用法与投稿
  SPEC.md                      本文件
  default.theme.json           站内默认主题
  schema/theme-1.schema.json   结构定义
  <id>.theme.json              收录的主题
```

单文件，UTF-8，上限 **1 MB**。图片与字体用 URL 或 data URL，不做多文件包。

---

## 2. 最小主题

```json
{
  "schema": "theme/1",
  "id": "minimal",
  "name": "极简",
  "colour": "#007AFF"
}
```

四行就够。主色蓝，圆角、背景、图标、字体、明暗全部回退默认。

---

## 3. 字段总表

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `schema` | string | 是 | 固定 `"theme/1"` |
| `id` | string | 是 | 唯一标识，小写字母数字与 `-` `_`，2~32 位 |
| `name` | object \| string | 是 | 多语言名称，见 §4 |
| `colourDesc` | object \| string | 否 | 主色的一句话描述，回退规则同 `name` |
| `colour` | object \| string | 是 | 主色，见 §5 |
| `radius` | object \| number | 否 | 圆角，见 §6 |
| `background` | array | 否 | 背景图层，可多层，见 §7 |
| `icons` | object | 否 | 按位置替换图标，见 §8 |
| `font` | object | 否 | 字体，见 §9 |
| `force` | string | 否 | `auto` / `light` / `dark`，见 §10 |
| `tokens` | object | 否 | 8 个白名单 CSS 变量，见 §11 |
| `borders` | object | 否 | 22 处元素的描边，见 §12 |
| `effects` | object | 否 | 点击效果，见 §13 |
| `dialog` | object | 否 | 对话框配色，见 §14 |
| `meta` | object | 否 | 作者、版本、许可，仅展示 |

---

## 4. name / colourDesc：多语言与回退

```json
"name": { "zh": "星空", "en": "Starry", "ja": "星空" }
```

也接受字符串简写，等价于只有中文：

```json
"name": "星空"
```

回退链：**当前界面语言 → zh → 对象里第一个非空的语种 → id**。

所以只写中文的主题，在英文界面显示中文，不会空白、也不会掉回 `id`。`colourDesc` 完全同理。

每个语种最长 32 字符；空串视为未定义，继续回退。

---

## 5. colour：主色

```json
"colour": { "accent": "#7C3AED", "accent2": "#A855F7", "ink": "#FFFFFF" }
```

| 键 | 必填 | 说明 |
|---|---|---|
| `accent` | 是 | 主色：强调色、按钮、选中态、链接 |
| `accent2` | 否 | 渐变副色，缺省按 `accent` 提亮推导 |
| `ink` | 否 | 主色上的文字色，缺省按对比度自动选黑或白 |

字符串简写 `"colour": "#7C3AED"` 等价于只给 `accent`。

只接受 `#RRGGBB`。

---

## 6. radius：圆角

```json
"radius": { "lg": 22, "md": 16, "sm": 11 }
```

数字简写 `"radius": 22` 表示大圆角 22px，中、小按 0.73 与 0.5 推导（22 → 16 → 11）。

范围 0~40 的整数。0 是直角，40 接近药丸。

---

## 7. background：背景图层

数组，**先写的在下**。每层是一张独立图片或一段 CSS，各自定位。

```json
"background": [
  { "type": "css", "css": "linear-gradient(165deg,#EEF0F5,#E6E9F2 55%,#EDE9F8)" },
  { "type": "svg",
    "src": "data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 200 200\'%3E%3Ccircle cx=\'100\' cy=\'100\' r=\'80\' fill=\'%237C3AED\'/%3E%3C/svg%3E",
    "position": "top -120px right -140px", "size": "620px 620px",
    "repeat": "no-repeat", "opacity": 0.55, "animate": "float" },
  { "type": "css",
    "css": "repeating-linear-gradient(0deg, rgba(20,20,40,.035) 0 1px, transparent 1px 64px)",
    "mask": "linear-gradient(180deg,#000 0%,transparent 70%)", "opacity": 0.5 }
]
```

| 键 | 适用 | 说明 |
|---|---|---|
| `type` | 全部 | `css` / `image` / `svg` |
| `css` | css | 任意背景 CSS（渐变、`url()` 也可以） |
| `src` | image / svg | https URL 或 `data:image/*` |
| `position` | 全部 | 直通 `background-position` |
| `size` | 全部 | 直通 `background-size` |
| `repeat` | 全部 | `no-repeat`（默认）/ `repeat` / `repeat-x` / `repeat-y` |
| `opacity` | 全部 | 0~1 |
| `blend` | 全部 | 直通 `mix-blend-mode` |
| `mask` | 全部 | 可选 `mask-image`，用于渐隐边缘 |
| `animate` | 全部 | `drift` / `float` / `pulse` |
| `fixed` | 全部 | `background-attachment: fixed` |
| `rect` | 全部 | 用 CSS 长度改这一层的几何：`top/right/bottom/left/width/height` |

约束：最多 12 层；单层 data URL（解码后）≤ 512 KB；合计 ≤ 2 MB；外链必须 https。

主题带背景时，站点会把页面本身的背景让位给第一层——第一层应当给一个底色。

---

## 8. icons：按位置替换图标

```json
"icons": {
  "nav.total": "M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 0 0 1 13 16.9V19h3v2H8v-2h3v-2.1A6 6 0 0 1 5.2 12H4a3 3 0 0 1 0-6h2V3z",
  "nav.chat": { "type": "url", "src": "https://example.com/chat.png" },
  "ui.menu": { "type": "css", "css": "background:linear-gradient(45deg,#f59e0b,#ef4444);border-radius:3px" },
  "tab.mine": null
}
```

**四种写法**

| 写法 | 含义 |
|---|---|
| 字符串 | SVG path（`d` 属性），渲染为 24×24 单色图标，最省事 |
| `{"type":"url","src":"..."}` | 外链图片（必须 https） |
| `{"type":"data","src":"data:image/..."}` | 内联图片 |
| `{"type":"css","css":"..."}` | 一段 CSS 声明，作用于图标元素 |
| `null` | 显式声明「这个位置用默认」 |

**回退**：未定义 → 内置默认 → 通用占位。外链加载失败（404 / 跨域 / 超时）自动退回内置默认，用户看不到破图。

### 8.1 位置命名表

命名规则 `分组.位置`，全小写。**表外的名字一律不生效**，也不会报错——只是什么都不会变。这张表是主题可替换面的全部。

**nav — 抽屉与主导航**（12）

| 名字 | 位置 |
|---|---|
| `nav.total` | 总排行榜 |
| `nav.vote` | 投票榜 |
| `nav.gods` | 诸神榜 |
| `nav.cold` | 冷门榜 |
| `nav.chat` | 世界对话 |
| `nav.submit` | 提交作品 |
| `nav.mine` | 我的 |
| `nav.help` | 功能说明 |
| `nav.feedback` | 反映问题 |
| `nav.admin` | 控制面板 |
| `nav.monitor` | 异常监测 |
| `nav.today` | 关于今天 |

**action — 作用于内容的操作**（18）

| 名字 | 位置 |
|---|---|
| `action.back` | 返回 |
| `action.close` | 关闭 |
| `action.more` | 更多 |
| `action.vote` | 投票 / 点赞 |
| `action.star` | 收藏 |
| `action.comment` | 评论 |
| `action.reply` | 回复 |
| `action.copy` | 复制链接 |
| `action.share` | 分享 |
| `action.external` | 打开原站 |
| `action.edit` | 编辑 |
| `action.delete` | 删除 |
| `action.report` | 举报 |
| `action.send` | 发送 |
| `action.stop` | 停止生成 |
| `action.retry` | 重试 |
| `action.download` | 下载 |
| `action.expand` | 展开全文 |

**tab — 底部标签栏**（3）

| 名字 | 位置 |
|---|---|
| `tab.rank` | 底部「榜单」 |
| `tab.chat` | 底部「对话」 |
| `tab.mine` | 底部「我的」 |

**ui — 顶栏按钮**（2）

| 名字 | 位置 |
|---|---|
| `ui.menu` | 菜单（抽屉）按钮 |
| `ui.theme` | 深浅色切换按钮 |

**field — 输入与筛选**（12）

| 名字 | 位置 |
|---|---|
| `field.search` | 搜索 |
| `field.password` | 显示 / 隐藏密码 |
| `field.emoji` | 表情 |
| `field.image` | 图片 |
| `field.video` | 视频 |
| `field.upload` | 上传 |
| `field.mention` | @ 提及 |
| `field.model` | 选择模型 |
| `field.think` | 深度思考开关 |
| `field.filter` | 筛选 |
| `field.sort` | 排序 |
| `field.refresh` | 刷新 |

**media — 媒体播放**（4）

| 名字 | 位置 |
|---|---|
| `media.play` | 播放 |
| `media.pause` | 暂停 |
| `media.fullscreen` | 全屏 |
| `media.mute` | 静音 |

**state — 状态与提示**（6）

| 名字 | 位置 |
|---|---|
| `state.loading` | 加载中 |
| `state.empty` | 空列表 |
| `state.error` | 出错 |
| `state.success` | 成功提示 |
| `state.warn` | 警告提示 |
| `state.offline` | 离线 |

**user — 账号**（5）

| 名字 | 位置 |
|---|---|
| `user.login` | 登录 |
| `user.logout` | 退出 |
| `user.avatar` | 默认头像 |
| `user.admin` | 管理员标识 |
| `user.verified` | 已认证标识 |

**theme — 外观**（5）

| 名字 | 位置 |
|---|---|
| `theme.light` | 浅色模式 |
| `theme.dark` | 深色模式 |
| `theme.auto` | 跟随系统 |
| `theme.palette` | 主题色选择 |
| `theme.lang` | 语言切换 |

**doc — 文档入口**（8）

| 名字 | 位置 |
|---|---|
| `doc.community` | 社区公约 |
| `doc.terms` | 用户协议 |
| `doc.privacy` | 隐私政策 |
| `doc.features` | 功能说明 |
| `doc.criteria` | 评分标准 |
| `doc.ranking` | 入榜规则 |
| `doc.changelog` | 更新日志 |
| `doc.ai` | AI 使用说明 |

共 **75** 个位置。

> 名字一旦发布不改。需要新位置只能追加。全部位置均已接入页面，不再有「写了不生效」的占位项。

### 8.2 每个位置出现在哪儿

75 个位置全部已接入——写进主题就会生效。下表列出各自对应的界面元素，改之前先确认它在哪儿，免得改了个看不见的地方。

**导航与抽屉（`nav.*`）**

| 位置 | 出现在 |
|---|---|
| `nav.total` | 顶栏品牌标、抽屉「总排行榜」 |
| `nav.vote` | 抽屉「投票榜」 |
| `nav.gods` | 抽屉「诸神榜」 |
| `nav.cold` | 抽屉「冷门榜」 |
| `nav.chat` | 抽屉「世界对话」 |
| `nav.submit` | 抽屉「提交作品」 |
| `nav.mine` | 抽屉「我的」 |
| `nav.help` | 抽屉「功能说明」 |
| `nav.feedback` | 抽屉「反映问题 / 回复反馈」 |
| `nav.admin` | 抽屉「控制面板」、我的页按钮 |
| `nav.monitor` | 抽屉「异常监测」、我的页按钮 |
| `nav.today` | 公告横幅、抽屉「关于今天」 |

**动作（`action.*`）**

| 位置 | 出现在 |
|---|---|
| `action.back` | 顶栏返回、详情页「返回榜单」 |
| `action.close` | 关闭搜索面板、关抽屉、关大图、对话框「取消」、撤回、取消标注、清除搜索 |
| `action.more` | 评论「查看更多」、聊天工具卡 |
| `action.vote` | 详情页「点赞作品」、评论「赞」 |
| `action.star` | 详情页总分前的星 |
| `action.comment` | 详情页「评论区」标题 |
| `action.reply` | 评论「回复」、回复对话框 |
| `action.copy` | 详情页「复制链接」 |
| `action.share` | 详情页「分享」 |
| `action.external` | 详情页「打开作品」 |
| `action.edit` | 面板「编辑」「设置」 |
| `action.delete` | 评论「删除」、聊天消息「删除」 |
| `action.report` | 评论「屏蔽 / 取消屏蔽」 |
| `action.send` | 聊天发送、发表评论、AI 发送、回复对话框「发送」 |
| `action.stop` | AI「停止生成」 |
| `action.retry` | 列表「重试」、评论「AI 重审」 |
| `action.download` | 文档「下载原始 md 文件」 |
| `action.expand` | 回到顶部、加载更多、展开全部回复、AI 总结「展开全部」 |

**输入与筛选（`field.*`）**

| 位置 | 出现在 |
|---|---|
| `field.search` | 顶栏搜索按钮、抽屉「搜索作品」 |
| `field.password` | 登录页密码显示 / 隐藏 |
| `field.emoji` | 聊天表情按钮（世界对话与 AI 对话） |
| `field.image` | 聊天图片按钮、详情页「作品预览」标题 |
| `field.video` | 聊天视频按钮 |
| `field.upload` | 聊天「上传本地图片」 |
| `field.mention` | 世界对话「@」按钮 |
| `field.model` | AI 模型选择 |
| `field.think` | 深度思考开关、思考块标题 |
| `field.filter` | 榜单分区行首 |
| `field.sort` | 榜单切换行首 |
| `field.refresh` | 面板「刷新列表」 |

**媒体（`media.*`）**

| 位置 | 出现在 |
|---|---|
| `media.play` | 视频卡播放层、注册页宣传片 |
| `media.pause` | 视频暂停时浮现的暂停标记 |
| `media.fullscreen` | 视频全屏按钮 |
| `media.mute` | 视频静音时的角标 |

**状态（`state.*`）**

| 位置 | 出现在 |
|---|---|
| `state.loading` | 按钮加载态 |
| `state.empty` | 所有列表与页面的空状态 |
| `state.error` | 错误提示、出错空态、红色轻提示 |
| `state.success` | 登录页协议勾选框、绿色轻提示 |
| `state.warn` | 「可能有恶意」标注、黄色轻提示 |
| `state.offline` | 断网提示条 |

**账号（`user.*`）**

| 位置 | 出现在 |
|---|---|
| `user.login` | 登录 / 注册按钮、抽屉登录入口 |
| `user.logout` | 退出登录按钮、抽屉退出 |
| `user.avatar` | 个人页、评论、聊天里的头像 |
| `user.admin` | 面板「用户管理」 |
| `user.verified` | 个人页的管理员 / 副管理员身份行 |

**外观与语言（`theme.*`）**

| 位置 | 出现在 |
|---|---|
| `theme.light` | 外观设置里「浅色」 |
| `theme.dark` | 外观设置里「深色」 |
| `theme.auto` | 外观设置里「跟随」、顶栏主题按钮（跟随系统时） |
| `theme.palette` | 外观设置里「设计风格」 |
| `theme.lang` | 「语言」卡片标题 |

**文档（`doc.*`）**

| 位置 | 出现在 |
|---|---|
| `doc.community` | 抽屉「社区公约」、详情页「文明发言」提示 |
| `doc.terms` | 抽屉「用户协议」 |
| `doc.privacy` | 抽屉「隐私政策」 |
| `doc.features` | 抽屉「功能说明」、详情页介绍标题 |
| `doc.criteria` | 抽屉「评分标准」 |
| `doc.ranking` | 抽屉「入榜规则」 |
| `doc.changelog` | 抽屉「更新日志」 |
| `doc.ai` | 抽屉「AI 使用说明」 |

**底部导航（`tab.*`）**

| 位置 | 出现在 |
|---|---|
| `tab.rank` | 底部导航「榜单」 |
| `tab.chat` | 底部导航「对话」 |
| `tab.mine` | 底部导航「我的」 |

**顶栏控件（`ui.*`）**

| 位置 | 出现在 |
|---|---|
| `ui.menu` | 顶栏菜单按钮 |
| `ui.theme` | 外观设置里「深浅色」行 |

> 一个位置可能有多处宿主（例如 `action.close` 同时用于关闭搜索面板、关抽屉、关大图、对话框取消、撤回、清除搜索）。它们共用同一个图标，改一次全部生效。

---

## 9. font：字体

```json
"font": {
  "body": {
    "kind": "stylesheet",
    "url": "https://cdn.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css",
    "family": "MiSans VF",
    "weight": "100 900"
  },
  "fallback": ["system-ui", "sans-serif"]
}
```

| 键 | 说明 |
|---|---|
| `body.url` | 字体地址（https） |
| `body.kind` | `stylesheet`（加载 CSS，默认）/ `font`（自建 `@font-face`） |
| `body.family` | 族名，**必须与字体文件内部族名完全一致** |
| `body.weight` | 可变字重范围，如 `"100 900"` |
| `fallback` | 回退字体族，最多 6 个 |

三条硬约束，都是踩出来的：

1. 字体文件是 CORS 资源，托管方必须返回 `Access-Control-Allow-Origin`。jsDelivr 可以，npmmirror 不行。
2. 族名按文件内部名写。MiSans 的实际族名是 `MiSans VF`，写成 `MiSans` 会静默失败、整站掉回系统字体。
3. 加载失败一律静默回退，**绝不阻塞渲染**。

---

## 10. force：明暗

| 值 | 行为 |
|---|---|
| `auto` | 不强制，跟随用户与系统设置 |
| `light` | 强制浅色 |
| `dark` | 强制深色 |

强制时切换按钮仍然显示，只给一句说明——直接隐藏会让人以为是坏了。

---

## 11. tokens：受限的 CSS 变量

```json
"tokens": {
  "--glass-blur": "18px",
  "--glass-alpha": ".62",
  "--shadow": "0 8px 24px rgba(20, 30, 60, .12)"
}
```

白名单只有 8 个：

```
--glass-blur  --glass-sat  --glass-alpha
--shadow      --shadow-sm
--border      --border-soft  --surface
```

值里不允许 `!important`、`var()`、`url()`。白名单外的键会被忽略并提示。

限制的理由：手写 CSS 皮肤时期出现过装饰盖住按钮、背景撑出横向滚动，所以只开放「观感」相关的几个变量。

---

## 12. borders：元素描边

```json
"borders": {
  "card": "border:1px solid rgba(255,255,255,.72);box-shadow:0 10px 30px rgba(30,25,80,.10)",
  "button": "border:1px solid rgba(124,58,237,.26);border-radius:14px",
  "input": "border:1px solid rgba(20,20,40,.10)"
}
```

**键是语义位置，映射写死在代码里**，主题改不了映射：

| 键 | 对应元素 |
|---|---|
| `card` | 卡片容器 |
| `cardTitle` | 卡片标题行 |
| `button` | 主按钮 |
| `ghost` | 次要按钮 |
| `iconButton` | 图标按钮 |
| `input` | 输入框 / 文本域 / 下拉 |
| `drawerItem` | 抽屉条目 |
| `drawer` | 抽屉面板 |
| `tabbar` | 底部标签栏 |
| `tab` | 单个标签 |
| `topbar` | 顶栏 |
| `seg` | 分段选择器 |
| `switch` | 开关 |
| `chip` | 标签胶囊 |
| `toast` | 轻提示 |
| `avatar` | 头像 |
| `bubble` | 聊天气泡 |
| `announce` | 公告条 |
| `monCard` | 监测卡片 |
| `panelPlain` | 控制面板的朴素卡片 |
| `toolCard` | 对话里的工具卡 |
| `empty` | 空状态块 |

允许的属性：`border*` `border-radius` `box-shadow` `outline*` `background*`。其余静默丢弃。

**为什么不让写选择器**：允许 `.card,body{display:none}` 就等于交出任意 CSS 注入权。

---

## 13. effects：点击效果

```json
"effects": {
  "click": {
    "css": "background:radial-gradient(circle,rgba(124,58,237,.55),transparent 70%);width:88px;height:88px;margin:-44px 0 0 -44px;animation:tj-ripple .62s ease-out forwards",
    "keyframes": "@keyframes tj-ripple{from{transform:scale(.2);opacity:.9}to{transform:scale(1.6);opacity:0}}"
  }
}
```

工作方式：每次按下（左键）在点击坐标生成一个 `<i class="tj-click">`，主题的 `css` 作用于它，1.5 秒后自动移除。

**站点固定、主题不可覆盖**的两项：

```css
position: fixed;
pointer-events: none;
```

这两项决定了它不可能变成一层盖满屏幕、吃掉所有点击的透明遮罩。`z-index`、`display:none` 同样禁止。

允许的属性：`width` `height` `margin*` `border*` `border-radius` `box-shadow` `background*` `transform` `opacity` `filter` `backdrop-filter` `animation*`。

`keyframes` 可选，名字必须以 `tj-` 开头，内容禁 `position` 与外链。

---

## 14. dialog：对话框配色

```json
"dialog": {
  "bg": "rgba(255,255,255,.92)",
  "fg": "#17171E",
  "border": "rgba(20,20,40,.10)",
  "backdrop": "rgba(10,10,20,.42)",
  "dark": { "bg": "rgba(30,30,38,.94)", "fg": "#F2F2F5" }
}
```

| 键 | 作用 |
|---|---|
| `bg` | 主体背景 |
| `fg` | 主体文字色 |
| `border` | 描边 |
| `backdrop` | 背后遮罩 |
| `dark` | 可选：深色模式另一套，只写要变的键 |

只接受纯色：`#RGB` / `#RRGGBB` / `#RRGGBBAA` / `rgb()` / `rgba()` / `hsl()` / `hsla()`。不接受 `var()` 与任何函数。

切深浅色时自动换用 `dark` 那套，不用写两遍。

---

## 15. 回退规则

| 层 | 规则 |
|---|---|
| 字段级 | 主题值 → 内置默认 |
| 语言级 | 当前语言 → zh → 首个非空 → id |
| 资源级 | 主题资源失败 → 内置默认 → 通用占位 |
| 明暗级 | `force` → 用户选择 → 系统偏好 |

任何一级失败都不抛错、不白屏、不阻塞渲染。主题坏了不能影响用。

---

## 16. 加载与优先级

```
内置默认 < 节日主题（按日期） < 用户选择的自定义主题 < 无障碍覆盖
```

- 节日主题当天自动生效，用户可手动切回（记住到当日结束）
- 自定义主题存本机，登录后同步到云端 **7 天**
- `prefers-reduced-motion: reduce` 时所有图层动画停止，保留静态位置

---

## 17. 威胁模型

| 攻击 | 对策 |
|---|---|
| XSS（SVG 内嵌脚本） | 含 `<script>`、`on*=`、`foreignObject`、`javascript:` 的 SVG **整份拒收**，两端一致 |
| CSS 注入 | `css` 字段禁 `@import` / `expression(` / `javascript:` / `{}` `<>` |
| 布局劫持 | 边框只接受描边类属性；图标 CSS 禁 `position` / `z-index` / `pointer-events` |
| 点击劫持 | 点击层的位置与穿透由站点写死，主题写了也丢弃 |
| 外链追踪 | 只允许 https，图片带 `referrerpolicy=no-referrer` |
| SSRF | 拒私网地址（`127.` `10.` `192.168.` `172.16-31.`）与 `localhost` |
| 体积炸弹 | 文件 ≤1 MB、单层 ≤512 KB、合计 ≤2 MB、图层 ≤12 |
| 字体炸弹 | 非阻塞加载，失败回退系统栈 |
| 存储污染 | 服务端二次校验 + 每人一份覆盖写 + 每小时限次 |

根原则：主题只能改变「看起来怎样」，不能改变「能做什么」。规范里没有脚本字段，将来也不会有。

---

## 18. 校验

```bash
python3 tools/theme_check.py themes/your-theme.json
```

通过：

```
✓ themes/your-theme.json  「星空」  图标 2 · 背景 2 层 · 边框 3 处 · 点击 点击 · 对话框 默认 · 强制 dark
```

不通过会指出具体位置：

```text
✗ 图标位置 'nav.nope' 不在命名表内（见规范 §8.1）
✗ colour.accent 必须是 #RRGGBB
✗ borders 位置 'body' 不在白名单内
```

校验分三层：

| 层 | 检查 |
|---|---|
| 结构 | 必填、类型、范围、体积、键是否在白名单 |
| 资源 | URL 可达、SVG 过滤后非空、字体 CORS |
| 渲染 | 正文对比度 ≥4.5:1、装饰与点击层 `pointer-events:none`、无横向滚动 |

**宽进严出**：站内导入从宽（未知键忽略并提示，危险声明丢弃，主题照常生效）；投稿与 CI 从严（必须改到通过）。

---

## 19. 提交与收录

在仓库提 Issue，用「主题投稿」模板，填三项：主题文件（或可下载链接）、预览截图、一句话说明。

评审四条硬指标：看得清（对比度）、不挡手（装饰不压交互区）、不刺眼（无高频闪烁）、不跑题（图标语义与位置相符）。

通过后放进 `themes/` 正式目录，署名与许可照填的写。

---

## 20. 已知限制

- 53 个已命名位置尚未接入（见 §8.2），写了不生效
- 主题只能覆盖单色图标；多色插画请用 `url` / `data` 形式
- 背景图层只做 `background-image`，不支持视频与 canvas
- 云端主题保留 7 天，过期需重新导入

---

## 附录：完整示例

见 [default.theme.json](default.theme.json)——15 个字段全用上：三语名称、主色与描述、圆角、三层背景、5 个图标、字体、强制策略、8 个 token、12 处边框、点击涟漪、对话框（含深色一套）。

片段节选：

```json
{
  "schema": "theme/1",
  "id": "default",
  "name": { "zh": "默认", "en": "Default", "ja": "デフォルト" },
  "colour": { "accent": "#7C3AED", "accent2": "#A855F7", "ink": "#FFFFFF" },
  "colourDesc": { "zh": "液态玻璃 · 紫", "en": "Liquid Glass · Violet", "ja": "リキッドグラス · バイオレット" },
  "radius": { "lg": 22, "md": 16, "sm": 11 },
  "force": "auto"
}
```
