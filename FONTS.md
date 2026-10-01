# 字体来源与授权

本站**不在仓库中存放任何字体文件**。全部字体都由访问者的浏览器直接向公共 CDN 取用，
既减少仓库体积，也让字体流量完全不经过本站服务器。

字体地址集中在 `index.php` 顶部的 `FONTS` 常量里，按「主源 → 备用源」顺序逐个尝试；
某一组全部失败时安静地退回系统字体，页面功能不受影响。

---

## 1) MiSans —— 默认正文与界面字体（可变字重）

| 项目 | 内容 |
|---|---|
| 样式表 | `https://gcore.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css` |
| 备用源 | `https://cdn.jsdelivr.net/npm/misans@5.0.0/lib/Normal/MiSansVF.min.css` |
| npm 包 | `misans@5.0.0`（`lib/Normal/` 下 MiSansVF 可变字重组，56 个 unicode-range 分片） |
| 授权 | MiSans 为小米官方字体，可免费商用；字体知识产权归小米公司所有。详见 <https://hyperos.mi.com/font/> |

**注意**：该样式表声明的字体族名是 **`MiSans VF`**（带 `VF` 后缀），站内 CSS 必须按此书写。
写成 `'MiSans'` 会静默匹配失败，导致整站文字掉回系统字体。

## 2) ZCOOL KuaiLe —— 手绘风皮肤

| 项目 | 内容 |
|---|---|
| 样式表 | `https://gcore.jsdelivr.net/npm/@fontsource/zcool-kuaile@5.3.0/400.css` |
| 备用源 | `https://cdn.jsdelivr.net/npm/@fontsource/zcool-kuaile@5.3.0/400.css` |
| npm 包 | `@fontsource/zcool-kuaile@5.3.0`（93 个 unicode-range 分片） |
| 上游源码 | <https://github.com/google/fonts/tree/main/ofl/zcoolkuaile> |
| 授权 | SIL Open Font License 1.1 (OFL-1.1)，作者 站酷 ZCOOL，可免费商用 |

字体族名 `ZCOOL KuaiLe`，仅在设计风格为「手绘风」时注入，其余皮肤不发起请求。
该字体收录常用汉字约 6800 字，正文中未收录的生僻字由 MiSans 或系统字体兜底。

## 3) Fusion Pixel —— 像素风皮肤

| 项目 | 内容 |
|---|---|
| 样式表 | `https://gcore.jsdelivr.net/npm/@fontsource/fusion-pixel-12px-proportional-sc@5.3.0/400.css` |
| 备用源 | `https://cdn.jsdelivr.net/npm/@fontsource/fusion-pixel-12px-proportional-sc@5.3.0/400.css` |
| npm 包 | `@fontsource/fusion-pixel-12px-proportional-sc@5.3.0` |
| 上游源码 | <https://github.com/TakWolf/fusion-pixel-font> |
| 授权 | SIL Open Font License 1.1 (OFL-1.1)，作者 TakWolf，可免费商用 |

字体族名 **`Fusion Pixel 12px Proportional SC`**，仅在设计风格为「像素风」时注入。
该皮肤下全站文字均由其承载，因此不会再注入 MiSans。

---

## 为什么不用 npmmirror

淘宝的 npmmirror（`registry.npmmirror.com` / `cdn.npmmirror.com`）在国内更快，但它的响应
**不包含 `Access-Control-Allow-Origin` 头**。woff2 是跨域资源，缺少该头时浏览器会直接拒绝加载
字体 —— 样式表能取到，字体却用不上。因此这里统一使用 jsDelivr（响应头为 `*`）。
npmmirror 仍适合下载 tarball 等非浏览器场景。
