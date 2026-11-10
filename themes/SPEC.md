# 主题 JSON 规范 v1.0

> 目标：把主题从「一套 CSS 文件」变成「一个可校验、可分发的 JSON 数据」。
> 换主题 = 换一个文件；皮肤作者不需要碰站点代码。

---

## 0. 一句话

一个主题 = 一个 JSON 文件，描述「叫什么、什么颜色、多圆、背景什么样子、用哪些图标、什么字体、强制明暗」。

---

## 1. 为什么值得换

现在的皮肤是 `assets/css/skins/*.css`，每份 200~400 行，问题有三个：

1. **只能改样式，不能改资源**。背景装饰、图标、字体全是写死的 CSS 与 DOM，换不了一套图。
2. **无法校验**。少个变量、选择器写错，页面只是悄悄变丑，没人知道哪里错了。
3. **无法分发**。用户想用别人做的主题，只能整份 CSS 覆盖，等于把站点控制权交出去。

JSON 化之后：字段缺了会回退，资源挂了会回退，主题可以签名、可以校验、可以由用户上传而不触碰代码。

---

## 2. 主题包

```
themes/
  default.theme.json        内置默认（液态玻璃）
  xinhai.theme.json         节日主题（由现有 CSS 平移）
  <user-slug>.theme.json    用户自定义
```

单文件，UTF-8，无外部依赖。图片与字体用 URL 或 data URL 内联，不做多文件包——分发时一个文件就能带走。

---

## 3. 字段总表

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `schema` | string | 是 | 固定 `"theme/1"`，用于将来升级 |
| `id` | string | 是 | 唯一标识，`^[a-z0-9][a-z0-9_-]{1,31}$` |
| `name` | object \| string | 是 | 多语言名称，见 §4 |
| `colour` | object \| string | 是 | 主题色，见 §5 |
| `colourDesc` | object \| string | 否 | 主题色的一句话描述，多语言，回退规则同 `name` |
| `radius` | object \| number | 否 | 圆角，见 §6 |
| `background` | array | 否 | 背景图层，可多层，见 §7 |
| `icons` | object | 否 | 按位置覆盖图标，见 §8 |
| `font` | object | 否 | 字体，见 §9 |
| `force` | string | 否 | `"auto"` / `"light"` / `"dark"`，见 §10 |
| `tokens` | object | 否 | 逃生舱：覆盖白名单内的 CSS 变量，见 §11 |
| `borders` | object | 否 | 12 处元素的边框与阴影，只接受声明不给选择器，见 §22.1 |
| `effects` | object | 否 | 点击任意位置的效果（形状 / 颜色 / 扩散动画），见 §22.2 |
| `dialog` | object | 否 | 对话框背景 / 文字 / 描边 / 遮罩，可另给深色一套，见 §22.3 |
| `meta` | object | 否 | 作者、版本、许可、主页，仅展示用 |

---

## 4. name / colourDesc：多语言与回退

```json
"name": { "zh": "辛亥革命纪念日", "en": "Xinhai Revolution Day", "ja": "辛亥革命記念日" }
```

也接受字符串简写，等价于只有 `zh`：

```json
"name": "辛亥革命纪念日"
```

**回退链（逐级向下，取第一个非空）**：

```
当前界面语言 → zh → 对象里第一个非空的语种 → id
```

也就是说：只写中文的主题，英文界面下显示中文，不会显示 `id` 或空白。日文同理。这条对 `colourDesc` 完全一致。

约束：每个语种最长 32 字符；`name` 与 `colourDesc` 都不允许空串（空串视为未定义，继续回退）。

---

## 5. colour：主题色

```json
"colour": { "accent": "#7C3AED", "accent2": "#A855F7", "ink": "#FFFFFF" }
```

| 键 | 必填 | 说明 |
|---|---|---|
| `accent` | 是 | 主色，全站强调色、按钮、选中态、链接 |
| `accent2` | 否 | 渐变色副色。缺省按 `accent` 提亮 12% 推导 |
| `ink` | 否 | 主色上的文字色。缺省按 `accent` 的 WCAG 对比度自动选黑或白 |
| `soft` | 否 | 主色的浅色底（用于选中背景）。缺省 `color-mix(in srgb, accent 14%, transparent)` |

字符串简写 `"colour": "#7C3AED"` 等价于只给 `accent`。

为什么保留 `accent2` 与 `ink`：本站是渐变 + 强调色体系，只有一个色时渐变段只能算，可控性差；`ink` 手工指定能保证深色主题里文字对比度达标。两者都可省，省了自动算。

---

## 6. radius：圆角

```json
"radius": { "lg": 22, "md": 16, "sm": 11 }
```

对应 `--radius-lg` / `--radius-md` / `--radius-sm`。

数字简写 `"radius": 22` 表示「大圆角 22px」，中、小按 0.73 与 0.5 等比推导（22 → 16 → 11，与现网默认值一致）。

范围 0~40，单位 px，只接受整数。0 = 直角（新粗野主义风格），40 = 药丸。

---

## 7. background：背景图层

数组，**顺序即层级**（先写的在下，后写的在上）。每一项是一层，用 CSS 定位。

```json
"background": [
  { "type": "css",   "css": "linear-gradient(160deg,#F4F1FF,#E8EEFF 60%,#F7F3FF)" },
  { "type": "image", "src": "https://cdn.example.com/stars.svg", "position": "top center",
    "size": "auto 62%", "repeat": "no-repeat", "opacity": 0.42, "blend": "soft-light" },
  { "type": "svg",   "src": "data:image/svg+xml;utf8,<svg ...>", "position": "bottom right",
    "size": "320px auto", "opacity": 0.3 }
]
```

| 键 | 适用 | 说明 |
|---|---|---|
| `type` | 全部 | `css` / `image` / `svg` |
| `css` | css | 任意背景 CSS：渐变、`radial-gradient`、`conic-gradient`、`url()` |
| `src` | image / svg | http(s) URL，或 `data:image/*` |
| `position` | 全部 | 直通 `background-position`，如 `top right`、`60% 20%` |
| `size` | 全部 | 直通 `background-size`，如 `cover`、`auto 60%`、`320px auto` |
| `repeat` | 全部 | `no-repeat`（默认）/ `repeat` / `repeat-x` / `repeat-y` |
| `opacity` | 全部 | 0~1，默认 1 |
| `blend` | 全部 | 直通 `mix-blend-mode`，默认 `normal` |
| `mask` | 全部 | 可选 `mask-image`，用于渐隐边缘 |
| `animate` | 全部 | 内置动画名：`drift`（缓慢平移）/ `float`（上下浮动）/ `pulse`（明灭）；也可写完整 CSS `animation` 值 |
| `fixed` | 全部 | `true` 时用 `background-attachment: fixed`（移动端自动降级为 `scroll`，iOS 支持差） |

**多图标就是这么来的**：装饰不是一张大图，而是若干独立图层，各自定位、各自透明度、各自动画。星星放右上、月亮放左下、光晕铺满——三行 JSON，不需要写 CSS 选择器。

约束：

- 单层 `src`（含 data URL 解码后）≤ 512 KB，全部图层合计 ≤ 2 MB
- `data:` 只接受 `image/svg+xml` / `image/png` / `image/jpeg` / `image/webp`
- 外链图片若返回非 200 或非图片类型，该层静默跳过，不影响其余层
- 所有图层统一 `pointer-events: none`、禁止拦截交互；`z-index` 固定在最底层（`-1`），永不上浮到正文之上

---

## 8. icons：按位置覆盖

```json
"icons": {
  "nav.rank":   "M6 3h12v3h2a3 3 0 0 1 0 6h-1.2A6 6 ...",
  "nav.chat":   { "type": "url",  "src": "https://cdn.example.com/chat.png" },
  "action.send":{ "type": "data", "src": "data:image/svg+xml;utf8,<svg ...>" },
  "state.empty":{ "type": "css",  "css": "background:url(/img/empty.svg) center/contain no-repeat" },
  "user.login": null
}
```

**四种写法**

| 写法 | 含义 |
|---|---|
| 字符串 | 直接当 SVG path（`d` 属性值），最省事；渲染为 24×24 viewBox 的单色图标 |
| `{"type":"url","src":"..."}` | 外链图片，运行时 `<img>` 加载 |
| `{"type":"data","src":"data:..."}` | 内联图片 |
| `{"type":"css","css":"..."}` | 一段 CSS 声明，作用于图标的 `<i>` 元素（可做纯 CSS 图标、也可引字体字形） |
| `null` | 显式声明「这个位置用默认」，便于覆盖父主题 |

**回退（三级，逐级向下）**

```
主题定义且加载成功 → 内置默认图标 → 通用占位（一个方框）
```

外链图片加载失败（404 / 跨域 / 超时 3s）自动换回内置默认，用户看不到破图。

---

### 8.1 图标位置命名表

命名规则：`分组.位置`，全小写，点分隔。分组是界面区域，位置是该区域里的具体控件。**名字一旦发布不再改动**，新增只能追加。

**nav — 抽屉与主导航**

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
| `nav.today` | 关于今天（节日入口） |

**tab — 底部标签栏**

| 名字 | 位置 |
|---|---|
| `tab.rank` | 榜单 |
| `tab.chat` | 对话 |
| `tab.mine` | 我的 |

**ui — 顶栏与切换**

| 名字 | 位置 |
|---|---|
| `ui.menu` | 菜单（抽屉）按钮 |
| `ui.theme` | 深浅色切换按钮 |

**action — 作用于内容的操作**

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

**field — 输入与筛选**

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

**media — 媒体播放**

| 名字 | 位置 |
|---|---|
| `media.play` | 播放 |
| `media.pause` | 暂停 |
| `media.fullscreen` | 全屏 |
| `media.mute` | 静音 |

**state — 状态与提示**

| 名字 | 位置 |
|---|---|
| `state.loading` | 加载中 |
| `state.empty` | 空列表 |
| `state.error` | 出错 |
| `state.success` | 成功提示 |
| `state.warn` | 警告提示 |
| `state.offline` | 离线 |

**user — 账号**

| 名字 | 位置 |
|---|---|
| `user.login` | 登录 |
| `user.logout` | 退出 |
| `user.avatar` | 默认头像 |
| `user.admin` | 管理员标识 |
| `user.verified` | 已认证标识 |

**theme — 外观**

| 名字 | 位置 |
|---|---|
| `theme.light` | 浅色模式 |
| `theme.dark` | 深色模式 |
| `theme.auto` | 跟随系统 |
| `theme.palette` | 主题色选择 |
| `theme.lang` | 语言切换 |

**doc — 文档入口**

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

共 8 组 66 个位置。这张表就是主题作者的全部可替换面——表外的东西一律不接受。

---

## 9. font：字体

```json
"font": {
  "body": {
    "url": "https://cdn.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css",
    "family": "MiSans VF",
    "kind": "stylesheet"
  },
  "title": { "family": "MiSans VF" },
  "fallback": ["system-ui", "-apple-system", "sans-serif"]
}
```

| 键 | 必填 | 说明 |
|---|---|---|
| `body.url` | 否 | 字体地址。`kind:"stylesheet"` 时是 CSS 文件地址；`kind:"font"` 时是 woff2/woff/ttf 直链 |
| `body.family` | 是（给了 url 时） | CSS `font-family` 名。**必须与字体文件内部族名完全一致**，否则静默失败 |
| `body.kind` | 否 | `stylesheet`（默认，加载 `<link>`）/ `font`（自建 `@font-face`） |
| `body.weight` | 否 | 可变字重范围，如 `"100 900"`；静态字体写单值 |
| `body.display` | 否 | `font-display`，默认 `swap` |
| `title` | 否 | 标题字体，缺省跟随 `body` |
| `fallback` | 否 | 回退族，默认系统栈 |

**硬约束（踩过的坑，写进规范防止重复）**

1. 字体文件是 CORS 资源，托管方必须返回 `Access-Control-Allow-Origin`。jsDelivr 可以，npmmirror 不行。
2. 官方 CSS 里的族名可能是 `MiSans VF`（带变体后缀），按文件内部名写，不要按包名写。
3. 加载失败（网络 / CORS / 族名不匹配）时静默回退到 `fallback`，**页面绝不因此阻塞**：字体 `<link>` 一律非阻塞加载，正文先渲染。
4. 字体文件 ≤ 8 MB；超过则拒收（移动端流量代价太大）。

---

## 10. force：明暗

```json
"force": "auto"
```

| 值 | 行为 |
|---|---|
| `auto` | 不强制。用户选浅色就是浅色，选深色就是深色，未选则跟随系统 |
| `light` | 强制浅色。切换按钮仍显示，但选了深色也只应用浅色，并给出说明 |
| `dark` | 强制深色。同上 |

为什么保留「切换按钮可见但无效」：直接隐藏会让用户以为是 bug；显示 + 一句「本主题仅提供浅色」才说得通。

`force` 只管明暗，不管主题色——后者由 `colour` 决定，用户仍可覆盖（除非 `tokens` 里锁死）。

---

## 11. tokens：逃生舱（受限）

```json
"tokens": {
  "--glass-blur": "16px",
  "--glass-alpha": ".62",
  "--shadow": "0 8px 24px rgba(20, 30, 60, .12)"
}
```

白名单只有这 8 个，且不接受 `!important`、不接受 `var()` 嵌套引用、不接受任何能影响布局尺寸的变量：

```
--glass-blur  --glass-sat  --glass-alpha
--shadow  --shadow-sm
--border  --border-soft  --surface
```

超出白名单的键一律忽略并记录一条警告（面板可见）。这条限制是为了防止主题把正文挤没、把点击区盖住——历史上手写 CSS 皮肤就出过这类问题。

---

## 12. 回退链总览

```
字段级：主题值 → 内置默认值
语言级：当前语言 → zh → 首个非空 → id
资源级：主题资源加载失败 → 内置默认资源 → 通用占位
明暗级：force 强制 → 用户选择 → 系统偏好
```

任何一级失败都不抛错、不白屏、不阻塞渲染。主题是装饰，装饰坏了不能影响用。

---

## 13. 加载与优先级

```
内置默认(default) < 节日主题(按日期) < 用户选择的自定义主题 < 无障碍覆盖(prefers-reduced-motion / 强制对比度)
```

- 节日主题在当天自动生效，用户可手动切回默认（记住到当日结束）
- 自定义主题由用户在「我的 → 外观」里选择，存 localStorage + 账号偏好（登录后同步）
- `prefers-reduced-motion: reduce` 时，所有 `animate` 图层动画停止，保留静态位置
- 主题切换应用在 `<html>` 上：`data-theme`（明暗）、`data-accent`（主题色）、`data-skin`（主题 id），并注入一层 `<style>` 承载变量与图层

---

## 14. 安全边界

主题 JSON 可能来自他人，必须当不可信输入处理：

1. **SVG 过滤**：`icons` 的字符串与 `background` 的 svg data URL 都要过白名单——去掉 `<script>`、所有 `on*` 属性、`<foreignObject>`、`xlink:href`、`href` 指向 `javascript:` 的部分。
2. **CSS 过滤**：`css` 字段禁止 `@import`、`expression(`、`javascript:`、`url(http` 之外的协议；`tokens` 只认白名单键。
3. **URL 约束**：外链一律 https；图片走站点代理（`/api/img.php?u=`），避免被第三方统计与防盗链拖累。
4. **体积上限**：单主题 JSON ≤ 1 MB（含内联 data URL）；超出直接拒收。
5. **不执行代码**：规范里没有任何「脚本」字段，将来也不会有。

---

## 15. 校验

校验器分三层，缺一不可：

| 层 | 时机 | 检查 |
|---|---|---|
| 结构 | 保存 / 导入 | 必填字段、类型、正则、数值范围、体积、图标 key 是否在命名表内 |
| 资源 | 应用前 | 图片 URL 可达且是图片、字体 URL 可达且返回 CORS 头、SVG 过滤结果非空 |
| 渲染 | 应用后 | 正文对比度 ≥ 4.5:1、装饰层与点击层 `pointer-events:none`、无横向滚动条 |

渲染层这三项是「主题好不好看」之外能不能用的底线，历史上手写皮肤踩过：装饰盖住按钮、暗色主题正文看不清、背景图把页面撑出横向滚动。

---

## 16. 与现有 48 套 CSS 皮肤的关系

**不批量迁移。** 现有节日皮肤继续以 CSS 生效，按需逐个手工转成 JSON——每份转换都要人工调装饰层的位置与透明度，批量脚本会丢掉「只可意会」的那部分。

转换时的映射关系：

| CSS 现状 | JSON 字段 |
|---|---|
| `html[data-skin="x"] { --accent: ... }` | `colour.accent` |
| `--accent-2` | `colour.accent2` |
| `--radius-lg/md/sm` | `radius` |
| `--bg-1 / --bg-2` | `background[0].css`（渐变或纯色） |
| `.festival-deco span.fd-*` | `background[]` 的多个图层 |
| `--shadow / --glass-*` | `tokens` |
| 皮肤专属变量（`--xh-ink` 之类） | 并入 `tokens` 白名单或改写成具体色值 |

---

## 17. 示例

见同目录 `default.theme.json`——把现网默认主题（液态玻璃 · 紫）完整写成 JSON，包含三层背景、六个图标覆盖、可变字体与 `force: auto`。

---

## 附：版本策略

- `schema: "theme/1"` 期间只做**加字段**，不改已有字段语义
- 破坏性变更升 `theme/2`，同时保留 v1 解析器至少一个大版本
- 图标命名表只增不改：`nav.rank` 永远是总排行榜，改名要新增 `nav.rank-v2` 并双写

---

## 18. 前端实现（渲染层）

解析器在 `assets/js/src/theme-json.js`，对外只有一个入口：`window.ThemeJSON`。

| 方法 | 作用 |
|---|---|
| `parse(raw)` | 校验，返回 `{ok, theme, errors, warns}`，不改动页面 |
| `applyRaw(raw)` | 校验并应用；失败时保持当前主题不变 |
| `apply(theme)` | 应用已校验对象 |
| `clear()` | 回到站点默认（含抽屉重绘） |
| `current()` | 当前主题摘要（名称、主色、层数、图标数、force） |
| `reapply()` | 只重写变量，供明暗切换后补写 |

**应用顺序**（每一步都可独立失败而不影响后续）：

```
写 CSS 变量（!important） → 建背景层 → 换图标 → 注入图标 CSS / 字体 → 设 force → 重绘抽屉
```

四个实现细节，都是踩出来的：

1. **变量必须带 `!important`**。节日皮肤用 `html[data-skin] { --accent: … !important }` 锁色，普通内联样式压不过去，只有内联的 `!important` 优先级更高。
2. **主题带背景时让 body 背景让位**。站点 body 自带三层渐变且铺满，`z-index:-1` 的图层会被它遮死。所以应用背景层时给 `html` 加 `data-tjbg`，把 body 背景设为透明，由主题的第一层充当底色。
3. **抽屉不会自己重绘**。图标是渲染时写进 DOM 的，换图标后必须显式重绘抽屉，否则要等下次开页面才看到。
4. **`applyTheme()` 会覆盖主题色**。用户切深浅色时会重写 `--accent`，所以主题在它之后补写一次（`__TJSON_REAPPLY`）。

**已接入的图标位**（换图标立即生效）：`nav.*`（12 个）、`doc.*`（8 个）、`user.login/logout/admin/monitor`、`field.search`、`theme.*`（由 gear 承载）。

**已命名、尚未接入**：`action.*`、`field.emoji/image/video`、`media.*`、`state.*`。这些位置的图标直接写在页面片段里（不是走注册表），接入需要逐处改成 `T(slot, path)`。未接入时主题里写了也不报错——回退到默认，不会破图。

## 19. 导入与云同步

**本机**：导入成功后原文存 `localStorage['kimgr_tjson']`，下次打开自动恢复。恢复时同样过一遍校验（localStorage 可被篡改）。

**云端**（登录用户）：随账号走，换设备可用。

| 项 | 规则 |
|---|---|
| 接口 | `api/theme.php` 的 `save` / `get` / `del` |
| 保留期 | 7 天（`expires_at = now + 7d`） |
| 过期行为 | 读取时判定，过期立即删除并返回 `expired:1`；GC 另有兜底 |
| 份数 | 一人一份，保存即覆盖 |
| 频率 | 每小时 12 次 |
| 匿名 | 只能本机，无云端 |

导入方式两种：选文件（`.json`）、粘贴文本。两条路走同一个校验函数，超过 1 MB 直接拒绝。

## 20. 威胁模型

主题是「他人的数据」，攻击面比普通设置项大。逐条列出与对策：

| 攻击 | 表现 | 对策 |
|---|---|---|
| XSS（SVG 内嵌脚本） | `data:image/svg+xml` 里塞 `<script>` 或 `onload=` | **两侧一致：直接拒绝整份主题**（不做修补，避免用户误以为用的是净化过的图）；`sanitizeSvg` 仍作为第二道，负责去掉外链 href 与 style |
| CSS 注入 | `css` 字段写 `@import`、`expression()`、`javascript:` | 双侧黑名单；`css` 字段禁 `{}`、`<>`，长度 ≤1200 |
| 布局劫持 | 图标 CSS 里写 `position:fixed` 盖住按钮 | 图标 CSS 禁 `position:fixed/absolute/sticky`、`z-index`、`pointer-events`、`display:none` |
| 点击层劫持 | 主题造一层透明遮罩吃掉所有点击 | 点击特效的位置与穿透由站点固定（`position:fixed` + `pointer-events:none`），主题的 `position` / `pointer-events` / `z-index` 一律丢弃 |
| 背景遮挡点击 | 图层覆盖正文 | 图层容器固定 `z-index:-1`、`pointer-events:none`；层不参与文档流 |
| 外链追踪 | 图片来自第三方统计域名 | 只允许 https；图片带 `referrerpolicy=no-referrer`；域名由站点代理白名单兜底 |
| SSRF / 内网探测 | URL 指向 `127.0.0.1`、`192.168.*` | 前端拒私网地址；外链仅 https |
| 体积炸弹 | 塞 50 MB data URL 拖垮内存 | 单文件 ≤1 MB、单层 ≤512 KB、合计 ≤2 MB、层数 ≤12 |
| 字体炸弹 | 8 MB 字体拖慢首屏 | 字体非阻塞加载，失败回退系统栈；文件大小超出直接不加载 |
| 伪装钓鱼 | 主题名字写「点击领取」并带链接 | 主题不含任何可点击字段；名称仅展示，纯文本渲染 |
| 存储污染 | 用主题反复写库刷量 | 服务端校验 + 每小时 12 次 + 一人一份覆盖写 |

**根原则**：主题只能改变「看起来怎样」，不能改变「能做什么」。规范里没有任何脚本字段，将来也不会有。

## 21. 提交与收录

**提交**：在仓库提 Issue，用「主题投稿」模板，填三项——主题文件链接（或直接贴 JSON）、预览截图、一句话说明。

**自动校验**：CI 会对 `themes/*.json` 跑 `python3 tools/theme_check.py`，结构不合规直接标红。

**人工评审**四条**：看得清（正文对比度 ≥4.5:1）、不挡手（装饰不压交互区）、不刺眼（无高频闪烁）、不跑题（图标语义与位置相符）。

**收录**：通过后放进 `themes/` 正式目录，标注作者与许可。未通过的会在 Issue 里说明具体哪条不过——不是「感觉不好看」，是具体指标。

---

## 22. 交互效果与边框

主题除了「长什么样」，还能定义「点下去有什么反应」和「元素的描边」。两者都只接受 CSS 声明，**不接受选择器**。

### 22.1 borders：元素边框

```json
"borders": {
  "card": "border:1px solid rgba(255,255,255,.72);box-shadow:0 10px 30px rgba(30,25,80,.10)",
  "button": "border:1px solid rgba(124,58,237,.26);box-shadow:0 6px 18px rgba(124,58,237,.20)",
  "input": "border:1px solid rgba(20,20,40,.10);border-radius:11px"
}
```

键是**语义位置**，映射到站内既有元素，主题改不了映射：

| 键 | 对应元素 |
|---|---|
| `card` | 卡片容器 |
| `cardTitle` | 卡片标题行 |
| `button` | 主按钮 |
| `ghost` | 次要按钮 |
| `iconButton` | 图标按钮 |
| `input` | 输入框 / 文本域 / 下拉 |
| `drawerItem` | 抽屉条目 |
| `tabbar` | 底部标签栏 |
| `tab` | 单个标签 |
| `avatar` | 头像 |
| `bubble` | 聊天气泡 |
| `announce` | 公告条 |
| `topbar` | 顶栏 |
| `seg` | 分段选择器 |
| `switch` | 开关 |
| `chip` | 标签胶囊 |
| `toast` | 轻提示 |
| `drawer` | 抽屉面板 |
| `monCard` | 监测卡片 |
| `panelPlain` | 控制面板的朴素卡片 |
| `toolCard` | 对话里的工具卡 |
| `empty` | 空状态块 |

允许的属性（其余静默丢弃）：

```
border  border-*  border-radius  box-shadow  outline  outline-offset
background  background-color  background-image
```

**为什么不让写选择器**：一旦允许 `.card,body{display:none}` 这类写法，主题就成了任意 CSS 注入点——既能藏起按钮，也能把整页挪走。用语义键，主题只能在既定位置改描边。

### 22.2 effects.click：点击任意位置的效果

```json
"effects": {
  "click": {
    "css": "background:radial-gradient(circle,rgba(124,58,237,.55),rgba(124,58,237,0) 70%);width:88px;height:88px;margin:-44px 0 0 -44px;animation:tj-ripple .62s cubic-bezier(.2,.7,.3,1) forwards",
    "keyframes": "@keyframes tj-ripple{from{transform:scale(.18);opacity:.9}to{transform:scale(1.55);opacity:0}}"
  }
}
```

工作方式：

1. 站点在每次按下（`pointerdown`，仅左键）时，于点击坐标生成一个 `<i class="tj-click">`；
2. 主题的 `css` 作用于这个元素——决定它多大、什么形状、怎么扩散；
3. `keyframes` 可选，名字必须以 `tj-` 开头，供 `animation` 引用；
4. 元素 1.5 秒后自动移除，不残留 DOM。

站点**固定**的两项，主题不可覆盖：

```css
position: fixed;        /* 只相对视口，不参与布局 */
pointer-events: none;   /* 永不拦截点击，连点也不会被吃掉 */
```

允许的属性：

```
width  height  margin-*  border*  border-radius  box-shadow
background  background-color  background-image
transform  opacity  filter  backdrop-filter  animation*
```

**为什么禁 position / pointer-events / z-index**：这三个一起改就能做出一层盖满屏幕、吃掉所有点击的透明层——那就是点击劫持。现在它们由站点写死，主题只能决定「长什么样」，不能决定「挡不挡手」。

### 22.3 dialog：对话框配色

```json
"dialog": {
  "bg": "rgba(255,255,255,.92)",
  "fg": "#17171E",
  "border": "rgba(20,20,40,.10)",
  "backdrop": "rgba(10,10,20,.42)",
  "dark": { "bg": "rgba(30,30,38,.94)", "fg": "#F2F2F5", "border": "rgba(255,255,255,.12)" }
}
```

| 键 | 作用 |
|---|---|
| `bg` | 对话框主体背景 |
| `fg` | 主体文字色 |
| `border` | 描边 |
| `backdrop` | 背后遮罩色（默认半透明黑） |
| `dark` | 可选，深色模式下另给一套；只写要变的键，其余沿用上面 |

只接受纯色：`#RGB` / `#RRGGBB` / `#RRGGBBAA` / `rgb()` / `rgba()` / `hsl()` / `hsla()`。不接受 `var()`、`url()` 与任何函数。

切换深浅色时会自动换用 `dark` 那一套，不需要主题作者再写两遍。

### 22.4 缺省行为

| 情况 | 结果 |
|---|---|
| 没有 `borders` | 用站点默认描边 |
| `borders` 里某个键没写 | 该元素用默认 |
| 没有 `effects.click` | 不绑定任何监听，零开销 |
| 声明全部被过滤 | 该项视为未定义，回到默认 |

### 22.5 校验口径

- **投稿/CI**（`theme_check.py`）从严：未知键、无可用声明、非法 keyframes 一律报错，必须改到通过
- **站内导入**从宽：未知键忽略并提示，危险声明丢弃，主题照常应用

宽进严出是有意的：用户随手改的 JSON 不该因为一个拼写错误就完全用不了；而进入官方目录的主题必须干净。
