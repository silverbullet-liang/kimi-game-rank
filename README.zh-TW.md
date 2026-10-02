# Kimi 遊戲榜 · kimi-game-rank

[English](README.md) · [简体中文](README.zh-CN.md) · **繁體中文** · [日本語](README.ja.md) · [한국어](README.ko.md) · [Italiano](README.it.md) · [Français](README.fr.md)

面向 **Kimi 社群**的六維綜合評分排行榜。PHP + MySQL，前端零建置（原生 HTML / CSS / JavaScript）。

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> 本專案**非 Kimi 官方專案**，與月之暗面（Moonshot AI）無從屬關係。

---

## 這是什麼

一個把社群裡的遊戲、工具、文學、二創作品收集起來、依六個維度評分並排行的網站。評分由演算法自動完成，站長可依實際情況對個別作品做人工修正。

## 功能一覽

| 模組 | 說明 |
|---|---|
| 榜單 | 四分類 × 四榜（總榜 / 投票榜 / 諸神榜 / 冷門榜），排名徽章與評級標籤 |
| 作品詳情 | 六維雷達圖、六維明細、介紹與留言區（任意層級樓中樓） |
| 對話 | 世界對話（即時輪詢）+ AI 對話（串流輸出、聯網搜尋、注入站內規則） |
| 我的 | 頭像、AI 用量、IP 資訊與造訪紀錄、外觀設定 |
| 登入 | 密碼登入 / 註冊 / 訪客模式，登入與註冊含一次點擊即過的人機驗證 |
| 內容審核 | 留言、對話與上傳圖片在發布前先打一個 1–10 的違規檔位：達到攔截檔才攔下，中間檔放行並標註「可能有惡意」 |
| 控制台 | 收錄與批次更新、資料庫備份、留言審核、標籤管理、副管理員 |
| 智慧連結辨識 | 預設開啟：作品頁若只是轉址頁，改用其中的真實位址 |
| 違紀通報 | 一鍵通報使用者：多條理由、封禁天數（含永久）、封禁來源、清理其留言 / 對話 / 圖片；被通報者造訪時 302 到違紀介面，介面下方是理由與留言區 |
| 面板 | 多套視覺面板；字型由瀏覽器直連公共 CDN 取得，不占用本站流量 |

## 技術堆疊與需求

- **PHP ≥ 7.0**（程式碼基準為 7.0，不使用 7.1+ 語法）
- **MySQL / MariaDB**（InnoDB，`utf8mb4`）
- 前端：原生 ES 模組原始碼 → 單一檔案產物，**無需 Node 執行環境**（僅建置時使用）
- 無 Composer 相依、無前端框架

## 部署

```bash
git clone https://github.com/silverbullet-liang/kimi-game-rank.git
cd kimi-game-rank

# 1) 主設定：複製範本並填好資料庫連線
cp config/config.sample.php config/config.php
$EDITOR config/config.php

# 2) 金鑰池：複製範本並填入你自己的 Key
cp config/api_keys.sample.php config/api_keys.php
$EDITOR config/api_keys.php

# 3) 審核詞庫：官方詞表不公開，複製範例後依自己的尺度維護
cp app/data/moderation_words.sample.txt app/data/moderation_words.txt
cp app/data/moderation_allow.sample.txt app/data/moderation_allow.txt
```

把網站根目錄指向本專案根目錄，然後用瀏覽器開啟首頁——**初始化會自動完成**（建資料庫、建資料表、產生金鑰）。若資料庫尚未就緒，頁面會給出明確提示。

> 詞庫檔案缺少時，審核模組會自動降級為「不攔截」，網站仍可正常運作。

## 設定說明

| 檔案 | 是否入庫 | 說明 |
|---|---|---|
| `config/config.php` | ❌ **禁止提交** | 資料庫連線（主庫 + 管理庫）、網站資訊、AES / cron 金鑰、管理員憑證 |
| `config/api_keys.php` | ❌ **禁止提交** | 模型 API Key 池（智譜、OpenRouter） |
| `app/data/moderation_words.txt` | ❌ **禁止提交** | 審核詞庫（官方詞表不公開） |
| `config/config.sample.php` | ✅ | 主設定範本 |
| `config/api_keys.sample.php` | ✅ | 金鑰池範本 |

上面的 ❌ 三項均已寫入 `.gitignore`。提交前請先跑一遍洩漏自檢：

```bash
python3 tools/repo_check.py
```

它會檢查三件事：禁入檔案是否混入待提交集合、文字裡有無金鑰指紋、**有無資料庫連線值外洩**。

## 目錄結構

```
├── api/            介面層（JSON 入口，驗證 + CSRF + 限流）
├── app/            業務層（設定、資料庫、評分、驗證、內容審核、AI 通道…）
│   └── data/       審核詞庫（僅保留 .sample）
├── assets/
│   ├── css/        樣式與面板
│   ├── docs/       站內文件（公開頁面的正文）
│   ├── emoji/      聊天表情
│   └── js/
│       ├── src/    前端原始碼（ES 模組，依頁面拆分）
│       └── app.js  建置產物（由 src 打包，請勿手改）
├── config/         設定與金鑰（僅 .sample 入庫）
├── docs/           設計與演算法文件
├── sql/            資料表結構
├── standalone/     獨立展示頁（更新日誌 / 轉址頁）
├── storage/        執行期資料（快取、日誌、上傳、備份）
└── tools/          建置與校驗腳本
```

字型不存放於倉庫，全部由瀏覽器直連公共 CDN 取得；來源與授權見 [FONTS.md](FONTS.md)。

## 建置前端

改了 `assets/js/src/` 下的原始碼後，必須重新打包：

```bash
python3 tools/build.py          # src/* → assets/js/app.js
```

## 提交前自檢

```bash
python3 tools/phpcheck.py       # PHP 語法與基準檢查
python3 tools/jscheck.py        # 前端同名衝突檢查
python3 tools/repo_check.py     # 洩漏自檢（金鑰 / 詞庫 / 資料庫連線）
```

## 安全性與滲透測試

我們歡迎安全研究者審閱與測試本專案的程式碼。**授權範圍、允許與禁止的行為、漏洞回報方式**，請先閱讀 [SECURITY.md](SECURITY.md)。

## 開源授權

本專案採用 **[GNU Affero General Public License v3.0](LICENSE)**（AGPL-3.0）。

你可以自由使用、修改、散布，但有兩個關鍵限制：

1. **衍生作品必須以同樣的授權開源**；
2. **把修改後的版本作為網路服務提供給他人使用，也必須向使用者提供完整原始碼**——這正是 AGPL 與 GPL 的差別，也是選用它的原因。

## 參與貢獻

見 [CONTRIBUTING.md](CONTRIBUTING.md)。提交前請確認：**沒有夾帶任何金鑰、資料庫連線或審核詞庫**。

## 免責聲明

- 本專案與 Kimi / 月之暗面無從屬關係，非官方專案。
- 使用者需自行遵守所在地法律法規，以及所呼叫第三方服務的條款。
- 程式碼依「現狀」提供，不附帶任何明示或默示擔保。
