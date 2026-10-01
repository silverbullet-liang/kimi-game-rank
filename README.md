# Kimi Game Rank · kimi-game-rank

**English** · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md) · [日本語](README.ja.md)

A six-dimension scoring leaderboard for the **Kimi community**. PHP + MySQL, zero-build frontend (vanilla HTML / CSS / JavaScript).

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> This is an **unofficial project**. It is not affiliated with Kimi or Moonshot AI.

---

## What is this

A site that collects games, tools, literary works and fan creations from the community, scores them across six dimensions, and ranks them. Scoring is done automatically by an algorithm; the site owner can manually adjust individual works.

## Features

| Module | Description |
|---|---|
| Leaderboards | 4 categories × 4 boards (Overall / Votes / Legends / Hidden Gems), rank badges and rating labels |
| Work details | Six-dimension radar chart, dimension breakdown, description and comment threads (unlimited nesting) |
| Chat | World chat (live polling) + AI chat (streaming output, web search, site rules injected) |
| Profile | Avatar, AI usage, IP information and visit log, appearance settings |
| Sign-in | Password login / registration / guest mode |
| Admin panel | Ingest and bulk refresh, database backup, comment moderation, tag management, sub-admins |
| Smart link resolution | On by default: when a work page is only a redirect page, use the real address behind it |
| Skins | Several visual skins; fonts are loaded straight from public CDNs, so they cost your host no traffic |

## Tech stack and requirements

- **PHP ≥ 7.0** (the code baseline is 7.0; no 7.1+ syntax is used)
- **MySQL / MariaDB** (InnoDB, `utf8mb4`)
- Frontend: ES module sources → single-file bundle, **no Node runtime required** (build-time only)
- No Composer dependencies, no frontend framework

## Deployment

```bash
git clone https://github.com/silverbullet-liang/kimi-game-rank.git
cd kimi-game-rank

# 1) Main config: copy the template and fill in your database connection
cp config/config.sample.php config/config.php
$EDITOR config/config.php

# 2) API key pool: copy the template and add your own keys
cp config/api_keys.sample.php config/api_keys.php
$EDITOR config/api_keys.php

# 3) Moderation word lists: the official lists are not public, so start from the samples
cp app/data/moderation_words.sample.txt app/data/moderation_words.txt
cp app/data/moderation_allow.sample.txt app/data/moderation_allow.txt
```

Point your site root at this project's root directory and open the homepage in a browser — **initialization is automatic** (creates the database, tables and keys). If the database is not ready, the page will say so clearly.

> If the word lists are missing, the moderation module degrades to "block nothing" and the site keeps working.

## Configuration

| File | Committed? | Description |
|---|---|---|
| `config/config.php` | ❌ **never commit** | Database connections (main + admin), site info, AES / cron keys, administrator credentials |
| `config/api_keys.php` | ❌ **never commit** | Model API key pool (Zhipu, OpenRouter) |
| `app/data/moderation_words.txt` | ❌ **never commit** | Moderation word list (the official list is not public) |
| `config/config.sample.php` | ✅ | Main config template |
| `config/api_keys.sample.php` | ✅ | Key pool template |

The three ❌ entries are already listed in `.gitignore`. Before committing, run the leak check:

```bash
python3 tools/repo_check.py
```

It verifies three things: whether forbidden files slipped into the staged set, whether any key fingerprint appears in text, and **whether database connection values leaked**.

## Directory layout

```
├── api/            Endpoints (JSON entry points: auth + CSRF + rate limiting)
├── app/            Business layer (config, database, scoring, auth, moderation, AI providers…)
│   └── data/       Moderation word lists (only .sample files are kept)
├── assets/
│   ├── css/        Styles and skins
│   ├── docs/       In-site documents (bodies of the public pages)
│   ├── emoji/      Chat emoji
│   └── js/
│       ├── src/    Frontend sources (ES modules, split by page)
│       └── app.js  Build output (bundled from src — do not edit by hand)
├── config/         Configuration and keys (only .sample files are committed)
├── docs/           Design and algorithm documents
├── sql/            Table schema
├── standalone/     Standalone pages (changelog / redirect page)
├── storage/        Runtime data (cache, logs, uploads, backups)
└── tools/          Build and verification scripts
```

Fonts are not stored in this repository; the browser fetches them directly from public CDNs. See [FONTS.md](FONTS.md) for sources and licensing.

## Building the frontend

After changing anything under `assets/js/src/`, you must rebuild:

```bash
python3 tools/build.py          # src/* → assets/js/app.js
```

## Checks before committing

```bash
python3 tools/phpcheck.py       # PHP syntax and baseline check
python3 tools/jscheck.py        # frontend duplicate-name check
python3 tools/repo_check.py     # leak check (keys / word lists / database connections)
```

## Security and penetration testing

We welcome security researchers reviewing and testing this project. Please read [SECURITY.md](SECURITY.md) first for the **authorized scope, what is allowed and forbidden, and how to report vulnerabilities**.

## License

This project is released under the **[GNU Affero General Public License v3.0](LICENSE)** (AGPL-3.0).

You are free to use, modify and distribute it, with two key constraints:

1. **Derivative works must be open-sourced under the same license**;
2. **If you offer a modified version to others as a network service, you must also provide them with the complete source code** — this is exactly what separates AGPL from GPL, and why it was chosen here.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Before submitting, make sure you are **not carrying any keys, database connections or moderation word lists**.

## Disclaimer

- This project has no affiliation with Kimi / Moonshot AI and is not an official project.
- Users must comply with the laws of their jurisdiction and the terms of any third-party services they call.
- The code is provided "as is", without warranty of any kind, express or implied.
