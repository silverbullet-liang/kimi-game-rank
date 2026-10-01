# Kimi 게임 랭킹 · kimi-game-rank

[English](README.md) · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md) · [日本語](README.ja.md) · **한국어** · [Italiano](README.it.md) · [Français](README.fr.md)

**Kimi 커뮤니티**를 위한 6개 축 종합 점수 순위표입니다. PHP + MySQL, 프런트엔드는 빌드가 필요 없습니다(순수 HTML / CSS / JavaScript).

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> 이 프로젝트는 **Kimi의 공식 프로젝트가 아닙니다**. Moonshot AI와 아무런 제휴 관계가 없습니다.

---

## 무엇인가

커뮤니티의 게임, 도구, 문학, 2차 창작물을 모아 6개 축으로 점수를 매기고 순위를 정하는 사이트입니다. 점수는 알고리즘이 자동으로 계산하며, 운영자는 필요에 따라 개별 작품의 점수를 직접 수정할 수 있습니다.

## 기능 개요

| 모듈 | 설명 |
|---|---|
| 순위표 | 4개 분류 × 4개 보드(종합 / 투표 / 전설 / 숨은 명작), 순위 배지와 등급 라벨 |
| 작품 상세 | 6축 레이더 차트, 축별 내역, 소개와 댓글 영역(무제한 중첩 답글) |
| 채팅 | 월드 채팅(실시간 폴링) + AI 채팅(스트리밍 출력, 웹 검색, 사이트 규칙 주입) |
| 마이페이지 | 아바타, AI 사용량, IP 정보와 접속 기록, 외형 설정 |
| 로그인 | 비밀번호 로그인 / 회원가입 / 게스트 모드 |
| 관리자 패널 | 수집과 일괄 갱신, 데이터베이스 백업, 댓글 검수, 태그 관리, 부관리자 |
| 스마트 링크 해석 | 기본 켜짐: 작품 페이지가 단순히 이동 페이지만이면 그 뒤의 실제 주소를 사용합니다 |
| 스킨 | 여러 가지 시각 스킨. 글꼴은 브라우저가 공개 CDN에서 직접 받아오므로 서버 트래픽을 쓰지 않습니다 |

## 기술 스택과 요구 사항

- **PHP ≥ 7.0** (코드 기준선은 7.0이며 7.1 이상 문법은 사용하지 않습니다)
- **MySQL / MariaDB** (InnoDB, `utf8mb4`)
- 프런트엔드: ES 모듈 소스 → 단일 파일 산출물, **Node 실행 환경 불필요**(빌드 시에만 사용)
- Composer 의존성 없음, 프런트엔드 프레임워크 없음

## 배포

```bash
git clone https://github.com/silverbullet-liang/kimi-game-rank.git
cd kimi-game-rank

# 1) 메인 설정: 템플릿을 복사하고 데이터베이스 연결 정보를 채웁니다
cp config/config.sample.php config/config.php
$EDITOR config/config.php

# 2) 키 풀: 템플릿을 복사하고 자신의 키를 넣습니다
cp config/api_keys.sample.php config/api_keys.php
$EDITOR config/api_keys.php

# 3) 검수 단어 목록: 공식 목록은 비공개이므로 예시를 복사해 직접 관리합니다
cp app/data/moderation_words.sample.txt app/data/moderation_words.txt
cp app/data/moderation_allow.sample.txt app/data/moderation_allow.txt
```

사이트 루트를 이 프로젝트의 루트 디렉터리로 지정한 뒤 브라우저로 첫 페이지를 열면 **초기화가 자동으로 완료됩니다**(데이터베이스와 테이블 생성, 키 생성). 데이터베이스가 준비되지 않았다면 페이지에 명확한 안내가 표시됩니다.

> 단어 목록 파일이 없으면 검수 모듈은 자동으로 "아무것도 차단하지 않음"으로 낮추어 동작하며, 사이트는 그대로 정상 작동합니다.

## 설정 파일

| 파일 | 커밋 | 설명 |
|---|---|---|
| `config/config.php` | ❌ **커밋 금지** | 데이터베이스 연결(메인 + 관리), 사이트 정보, AES / cron 키, 관리자 자격 증명 |
| `config/api_keys.php` | ❌ **커밋 금지** | 모델 API 키 풀(Zhipu, OpenRouter) |
| `app/data/moderation_words.txt` | ❌ **커밋 금지** | 검수 단어 목록(공식 목록은 비공개) |
| `config/config.sample.php` | ✅ | 메인 설정 템플릿 |
| `config/api_keys.sample.php` | ✅ | 키 풀 템플릿 |

위 ❌ 항목 세 개는 이미 `.gitignore`에 등록되어 있습니다. 커밋하기 전에 유출 점검을 실행하세요:

```bash
python3 tools/repo_check.py
```

다음 세 가지를 확인합니다: 금지 파일이 스테이징 집합에 섞이지 않았는지, 텍스트에 키 지문이 들어 있지 않은지, **데이터베이스 연결 값이 유출되지 않았는지**.

## 디렉터리 구조

```
├── api/            엔드포인트 계층(JSON 진입점. 인증 + CSRF + 속도 제한)
├── app/            비즈니스 계층(설정, 데이터베이스, 점수 계산, 인증, 콘텐츠 검수, AI 경로…)
│   └── data/       검수 단어 목록(.sample만 보관)
├── assets/
│   ├── css/        스타일과 스킨
│   ├── docs/       사이트 내 문서(공개 페이지의 본문)
│   ├── emoji/      채팅 이모지
│   └── js/
│       ├── src/    프런트엔드 소스(ES 모듈, 페이지 단위로 분리)
│       └── app.js  빌드 산출물(src에서 생성. 직접 수정하지 마세요)
├── config/         설정과 키(.sample만 커밋)
├── docs/           설계와 알고리즘 문서
├── sql/            테이블 구조
├── standalone/     독립 표시 페이지(업데이트 기록 / 이동 페이지)
├── storage/        실행 중 데이터(캐시, 로그, 업로드, 백업)
└── tools/          빌드와 검증 스크립트
```

글꼴은 저장소에 두지 않으며 모두 브라우저가 공개 CDN에서 직접 가져옵니다. 출처와 라이선스는 [FONTS.md](FONTS.md)를 참고하세요.

## 프런트엔드 빌드

`assets/js/src/` 아래의 소스를 수정했다면 반드시 다시 빌드해야 합니다:

```bash
python3 tools/build.py          # src/* → assets/js/app.js
```

## 커밋 전 점검

```bash
python3 tools/phpcheck.py       # PHP 문법과 기준선 점검
python3 tools/jscheck.py        # 프런트엔드 이름 충돌 점검
python3 tools/repo_check.py     # 유출 점검(키 / 단어 목록 / 데이터베이스 연결)
```

## 보안과 모의 해킹

이 프로젝트의 코드를 검토하고 테스트해 주실 보안 연구자를 환영합니다. **허용 범위, 허용되는 행위와 금지되는 행위, 취약점 보고 방법**은 먼저 [SECURITY.md](SECURITY.md)를 읽어 주세요.

## 라이선스

이 프로젝트는 **[GNU Affero General Public License v3.0](LICENSE)**(AGPL-3.0)으로 배포됩니다.

자유롭게 사용, 수정, 배포할 수 있지만 두 가지 핵심 제약이 있습니다:

1. **파생 저작물은 반드시 같은 라이선스로 공개해야 합니다**;
2. **수정한 버전을 네트워크 서비스로 다른 사람에게 제공한다면, 이용자에게 완전한 소스 코드도 제공해야 합니다** — 이것이 AGPL과 GPL의 차이이며, 이 프로젝트가 AGPL을 선택한 이유입니다.

## 기여

[CONTRIBUTING.md](CONTRIBUTING.md)를 참고하세요. 제출 전에 **어떤 키, 데이터베이스 연결, 검수 단어 목록도 포함하지 않았는지** 반드시 확인하세요.

## 면책 조항

- 이 프로젝트는 Kimi / Moonshot AI와 아무런 제휴 관계가 없는 비공식 프로젝트입니다.
- 이용자는 거주 지역의 법률과 이용하는 제3자 서비스의 약관을 스스로 준수해야 합니다.
- 코드는 "있는 그대로" 제공되며, 명시적이든 묵시적이든 어떠한 보증도 하지 않습니다.
