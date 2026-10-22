# Scoring Standard — Kimi Game Rank

> Official text of the "Scoring Standard" page · full algorithm disclosure
> This page publishes **the whole scoring algorithm**: every formula, weight and threshold comes from the live configuration and can be checked against reality.

## Overview

Every listed work receives a **hierarchical six-dimension score**. The six dimensions form **three pillars**; each dimension is out of **200**, the total out of **1200**.

| Pillar | Dimension | Max | What it measures |
|:---:|---|:---:|---|
| **Work quality** | Playability | 200 | Runs, interaction density, completeness of instructions |
| | Creativity | 200 | Title distinctiveness, variety of expression, novelty of form |
| | Depth | 200 | Scale of text, structure, code depth, depth of discussion |
| **Author effort** | Scale | 200 | File size, code size, amount of text |
| | Effort | 200 | Effort inferred from investment: is it explained clearly, is it delivered |
| **Community recognition** | Reputation | 200 | Engagement (likes / comments / favourites) |

The total is aggregated from the three pillars with a **weighted geometric mean** — **a weak pillar drags the total down multiplicatively** (short-board effect), rather than each dimension adding up independently.

> All scores are **calculated automatically** by the published algorithm below. **The site owner may also correct an individual work's score by hand** based on its actual state; corrections are visible on the work page. This site **does not accept requests to adjust scores**.

---

## 1. Notation and basic functions

| Symbol | Meaning |
|:---:|---|
| L / C / F | Likes / comments / favourites |
| T / X / B | Title / author's description / body text |
| H | Work file (HTML plus the scripts it references; empty if none) |
| bytes / lines / funcs | Bytes / lines / function occurrences in H |
| age | Hours since the work was listed |

```
clamp01(x)    = restrict x to [0, 1]
lns(x, n)     = ln(1+x) / ln(1+n)          ← diminishing returns
bell(x, a, b) = 1 if x is in [a, b], falling linearly to 0 outside with decay=10
```

> The **number of media items (images / audio / video) in a work does not count towards any dimension**; media is only displayed on the work page.

---

## 2. Formulas (6 × 200)

**① Playability**
```
e1 runs        = has a work file or a shareable artifact → 1
e2 interaction = min(1, interactive tags ÷ 12)   (button/input/select/textarea/canvas/svg)
e3 instructions= share of the vocabulary {operate, click, select, input, key, drag, level, difficulty, mode} present
playability = 200 × clamp01(0.40×e1 + 0.40×e2 + 0.20×e3) × (runs ? 1 : 0.8)
```

**② Creativity**
```
s1 title distinctiveness = 1 − 2-gram similarity to the closest title in the database (max 300 compared; 1 if empty)
s2 title length          = bell(len(T), 4, 20)
s3 variety of expression = distinct words ÷ total words
s4 novelty of form       = min(1, (0.15×has artifact + 0.10×has work file) ÷ 0.25)
creativity = 200 × clamp01(0.20×s1 + 0.10×s2 + 0.40×s3 + 0.30×s4)
```

**③ Depth**
```
d1 text         = min(1, len(X+B) ÷ 1200)
d2 structure    = min(1, (paragraphs + list markers) ÷ 8)
d3 code         = max( min(1, lines ÷ 800), min(1, funcs ÷ 60) )
d4 discussion   = clamp01( lns(C, 30) )
depth = 200 × clamp01(0.30×d1 + 0.20×d2 + 0.30×d3 + 0.20×d4)
```

**④ Scale (cost)**
```
c1 files = min(1, bytes ÷ 300KB)
c2 code  = min(1, lines ÷ 1200)
c3 text  = min(1, len(X+B) ÷ 3000)
scale = 200 × clamp01( (0.44×c1 + 0.31×c2 + 0.25×c3) ÷ sum of weights )
(without a work file, c1 and c2 are dropped and only text counts)
```

**⑤ Effort (inferred from investment)**
```
baseline   = scale score ÷ 200
a1 explained = how carefully the description and body are written (0–1; judged by a model first, graded by description length if unavailable)
a2 delivered = has a work file or a shareable artifact → 1; else 0
effort = 200 × clamp01(0.35×baseline + 0.45×a1 + 0.20×a2)
```
> Without behavioural data, "attitude" cannot be measured directly, while "scale" is itself an objective measure of investment. Scale is used as the baseline, and "is it explained clearly" plus "is it delivered" separate careful work from casual work.

**⑥ Reputation (Bayesian shrinkage)**
```
s_raw = 0.45×lns(L,100) + 0.35×lns(C,50) + 0.20×lns(F,30)
n     = L + C + F
s     = (25×s_prior + n×s_raw) ÷ (25 + n)      ← s_prior is the site-wide mean reputation
reputation = 200 × s × (boost of at most 20% within 24 hours of listing)
```
> **Bayesian shrinkage**: with few interactions, reputation is pulled towards the site-wide mean, which rules out "one like, instant legend"; the more interactions, the closer it gets to the real level.

---

## 3. Hierarchical weights (AHP × CRITIC)

**① Subjective weights (AHP, Saaty 1980)** — derived from a pairwise comparison matrix:

```
Pillar judgement matrix A:
       quality  effort  recognition
quality   1        2        3
effort    1/2      1        2
recognition 1/3    1/2      1
→ pillar weights [0.5396, 0.2970, 0.1634]; λmax≈3.009, CR=0.008 < 0.1 (consistency check passed)

Within work quality: playability / creativity / depth = 0.45 / 0.25 / 0.30
Within author effort: scale / effort               = 0.55 / 0.45
```

**② Objective weights (CRITIC)** — computed from **the site-wide corpus**, measuring **contrast intensity** and **conflict** between indicators:
```
For the normalised six-dimension matrix: C_j = σ_j × Σ_k (1 − r_jk)
(σ_j is the standard deviation of dimension j; r_jk the correlation between j and k)
objective weight w_obj ∝ C_j
```
> A dimension that is **highly correlated** with the others (overlapping information) gets a lower weight; independent dimensions get more. This is how "relatedness" between dimensions is expressed mathematically.

**③ Combined weights**
```
w = 0.5 × w_AHP + 0.5 × w_CRITIC
```
(falls back to pure AHP weights when the corpus is too small.)

---

## 4. Aggregation and rating

**Normalised pillar value**: dimensions inside a pillar are averaged by their combined weights and divided by 200, giving [0,1].
**Total (weighted geometric)**:
```
P' = 0.10 + 0.90 × P                         (floor, so a pillar never zeroes the total)
total = 1200 × Π ( P'^α )                    (α is the pillar weight)
```

**Rating**: assigned by **cumulative rank percentage** — equal scores share a tier, and **no tier exceeds its quota** (SSS top 2%, SS top 10%, S top 25%, A top 50%, B top 75%, the rest C). Ratings float with the health of the ecosystem instead of being stretched apart by fixed cut-off scores.

| Rating | Percentile |
|:---:|:---:|
| ✨ SSS | top 2% |
| SS | top 10% |
| S | top 25% |
| A | top 50% |
| B | top 75% |
| C | the rest |

> With too small a corpus, a set of fixed cut-offs is used instead (e.g. 1000 / 870 / 780 / 660 / 540).

---

## 5. Missing signals and anomalies

1. **Missing signal**: when a signal cannot be obtained, **it is dropped and the remaining weights are renormalised equally** — it is not scored as zero, so thin data does not penalise a work.
2. **Anomalies**: a sudden spike in engagement is flagged for review; confirmed anomalies allow the related data to be cleared and the score recalculated.
3. **Cold start**: with too small a corpus, objective weights and percentile ratings fall back to subjective weights and fixed cut-offs, without affecting scoring.

## 6. Data source and update timing

| Item | Notes |
|---|---|
| Source | **Public information** about the work on [Kimi Community](https://www.kimi.com): title, description, body, likes / comments / favourites, the work file and the scripts it references |
| First calculation | Six dimensions are computed and stored **when the work is listed** |
| Subsequent calculation | Recomputed **each time new data is synced** (whether old scores are overwritten depends on the scoring-mode switch) |
| Cadence | Determined by the sync cadence configured by the administrator |
| Objective weights / ratings | Refreshed once a day from the site-wide corpus |

## 7. What each dimension counts and does not count

| Pillar | Dimension | Counted (measurable) | **Not counted** (subjective) |
|:---:|:---:|---|---|
| Quality | Playability | Runs, interactive tags, instruction vocabulary | "Is it fun" |
| Quality | Creativity | Title distinctiveness and length, variety of expression, novelty of form | "Is it creative" |
| Quality | Depth | Text, structure, code lines and functions, discussion | "Is it deep" |
| Effort | Scale | File size, code size, text | Effort that cannot be quantified |
| Effort | Effort | Inferred from investment + description care + delivery | "Is the attitude good" |
| Recognition | Reputation | Likes, comments, favourites (Bayesian-corrected) | Page views, off-site popularity |

**Key point**: this site **makes no human judgement about creativity, beauty or how interesting a work is**. All six dimensions rest on **measurable, reproducible** signals — that is the premise of calling this leaderboard's algorithm fully public.

## 8. Self-check: how to raise your score

| If you want to raise | What you can do |
|---|---|
| Playability | Make sure the work **runs**; add buttons, canvas or other interaction; write clear operating instructions |
| Creativity | Pick a title that is not a duplicate; write a fuller, more varied description; provide a shareable artifact |
| Depth | Explain the gameplay and design in the body; use paragraphs and lists; split code into functions by purpose |
| Scale | Not something to "optimise" — it is objective input; we encourage careful work rather than padding |
| Effort | Finish the work and invest more; explain the gameplay and updates in the description |
| Reputation | Get more people to **like, comment on and favourite** the work; the first-day exposure boost has a window |

## 9. Change log

| Date | Change |
|---|---|
| 2026-10-17 | v3.44.0: **ratings moved to cumulative rank** (equal scores share a tier, no tier exceeds its quota — fixes "SSS inflation"); removed free points and volume-based points — playability weighs interaction more (0.40/0.40/0.20), creativity weighs variety and form more (0.20/0.10/0.40/0.30), effort no longer equals volume (0.35 baseline + 0.45 explanation + 0.20 delivery); thresholds for full marks on text / code / bytes raised across the board; pillar floor 0.20 → 0.10 |
| 2026-10-15 | v3.41.0: **media count (images / audio / video) no longer counts towards any dimension**; listed works **re-identify redirect links automatically** (redirect pages replaced by the real address) |
| 2026-10-14 | **System rebuild v3.40.0**: **three pillars + hierarchical AHP weights**, **CRITIC objective weighting (combined weights)**, **pillar-wise weighted geometric aggregation**, **Bayesian shrinkage for reputation**, **percentile rating** |
| 2026-10-14 | v3.39.0: effort dimension now **inferred from cost**; missing signals dropped and renormalised; code volume includes scripts referenced by the page; shared artifacts count as "runs" |
| 2026-10-04 | Scoring mode default changed to "update information only": listed works keep their scores on update, and only the main administrator can switch it |
| Earlier | Six-dimension weights and thresholds fixed and published (200 per dimension / 1200 total) |

**Related documents**: [Features](?p=doc&arg=功能说明) · [AI Guide](?p=doc&arg=AI 使用说明) · [Community Guidelines](?p=doc&arg=社区公约) · [Admission Rules](?p=doc&arg=入榜规则) · [Terms of Service](?p=doc&arg=用户协议) · [Privacy Policy](?p=doc&arg=隐私政策)

---

> This standard is interpreted by the site owner; rules may change as the community evolves and changes are published on this page.
> Last updated: 2026-10-22
