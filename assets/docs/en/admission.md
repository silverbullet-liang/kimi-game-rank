# Admission Rules — Kimi Game Rank

> Official text of the "Admission Rules" page.
> This page explains **what can be listed, how works get listed, how duplicates and updates are handled, and when a work is removed**.

## 1. General

This site is a third-party interest leaderboard for **public works on [Kimi Community](https://www.kimi.com)**. Work information and scores are **collected and calculated automatically** from public data, so that good works reach more people. There is no commercial partnership or financial interest involved.

## 2. What can be listed

A work must meet **all** of the following:

1. **Publicly reachable**: published on [Kimi Community](https://www.kimi.com) and reachable through a public link.
2. **Complete information**: a recognisable title and a basic description. **Empty shells, placeholders, tests and unreachable works are not listed.**
3. **Compliant content**: nothing prohibited by law, nothing against common public decency.
4. **Not already listed**: the work does not exist in the database yet (one record per work, identified by the community work ID).
5. **Evaluable**: at least part of the material needed for scoring can be retrieved (title, text or work files).

> Authors do not need to apply or register a work. Listing is the result of **systematic collection**.

## 3. Category rules

Works are grouped into four leaderboards. The decision has a **strict priority order**:

**Fan work > Literature > Tools > Games**

| Category | Works it covers |
|:---:|---|
| 💬 Fan Work | Derivative works based on an existing IP or work |
| 📖 Literature | Novels, scripts, setting collections — works whose main body is written narrative |
| 🔧 Tools | Utilities, productivity tools, plugins, scripts, generators |
| 🎮 Games | Playable game works |

**How the decision is made** (from the wording of the title and description):

- **Fan work**: phrasing such as "adapted from / original author / fan fiction / derivative / side story / spin-off / setting collection / parallel world / tribute".
- **Literature**: phrasing such as "novel / literature / prose / poetry / serialised / short story / chapter / collection / essay".
- **Tools / Games**: their own keyword sets (tool / plugin / script / assistant / generator; game / gameplay / level / battle / puzzle).

**Fallback rule**: when none of the above matches, the work **goes to the Games leaderboard**. Games are the main section of this site, so the fallback keeps misclassification to a minimum; a work that "does not obviously fit anywhere" appearing under Games is therefore a **normal outcome, not an error**.

**Why this order**: "a fan game adapted from a novel" is first of all a fan work, not a game. Reversing the order would push a large number of derivative works into Games.

> Administrators can preview how a re-categorisation would turn out before applying it; **the preview and the actual run use the same logic, so what you see is what you get**.

## 4. How works get listed

Collection is handled by **administrators**, and regular users can also submit their own works:

1. **Manual collection**: an administrator pulls a work by its ID from the community into the database.
2. **Automatic sync**: with automatic mode enabled, the system discovers new works from the community feed in batches and records them.
3. **Submitting your own work**: signed-in users can use "Submit a Work" in the drawer menu — pick your work from the community search results and confirm. The work ID is filled in automatically and cannot be typed by hand. Two submissions per account per day; failed attempts do not count.

Titles, descriptions, covers and six-dimension scores are **generated automatically** when a work is listed. If you want to recommend a work, tell us through "Feedback".

## 5. Duplicates and updates

1. **Uniqueness**: one record per work, deduplicated by **community work ID**.
2. **Incremental updates**: when an already listed work is found again, only the **changed** fields are updated (title, description, engagement numbers, cover). No duplicate record is created.
3. **Whether scores are recalculated depends on the scoring mode**: the default is "update information only" — an existing work keeps its scores when its information changes (so the site owner can adjust scores case by case); **newly listed works are always scored automatically**. To recalculate older works with the current algorithm, the main administrator switches to "automatic scoring" and runs a full update.
4. **History is kept**: the system records each work's **highest total score ever**, used for the Hall of Fame leaderboard. History is not lost when scores are recalculated.
5. **Rankings move**: total score changes are reflected in the rankings immediately — a work can climb back, or slip as its heat fades.

## 6. The four leaderboards

| Leaderboard | Sorted by | Notes |
|:---:|---|---|
| Overall | Total score (sum of six dimensions) | Overall strength, the main board |
| Votes | Likes on this site | How much readers like it directly |
| Hall of Fame | Highest total score ever | An honour board of past heights |
| Hidden Gems | **Lowest** heat first | Works with modest heat but a solid overall score, worth a look |

Every leaderboard can be filtered by category (Games / Tools / Literature / Fan Work).

## 7. What a work page shows

A work page normally shows: title, author, category, six-dimension radar chart with per-dimension scores, total score and rating, engagement (likes / comments / favourites), description, cover and screenshots, the comment section, and a link to the original work. Following that link takes you straight to the community page.

## 8. Removal

A work is taken off the leaderboard when any of these applies:

1. **The work is deleted, hidden or made unreachable in the community**;
2. **Plagiarism, copyright infringement, score manipulation or similar violations**, confirmed after review;
3. **Content that breaks the law or common public decency**;
4. **The author asks an administrator to remove it**;
5. **Long-term unreachable**: repeated syncs fail to retrieve usable information;
6. **Other**: removal judged reasonable by an administrator for operational or compliance reasons.

> Most removals are **hide (unlist)** operations — the work stops appearing on the leaderboard but the data is not physically deleted, so it can be restored after a mistake or a successful appeal. For deleted content and data handling, see [Terms of Service](/docs/terms) and [Privacy Policy](/docs/privacy).

## 9. Corrections and appeals

1. **Wrong information**: if a title, description, author or cover is wrong, report it through "Feedback" with the original link and the correct information.
2. **As the author, asking for a change or removal**: contact us through "Feedback", ideally with **material that proves ownership** (such as a screenshot of the community work page). We will act after verification.
3. **Disagreeing with a category or a score**: categories are decided by the rules in section 3, scores are calculated by the algorithm in [Scoring Standard](/docs/scoring). If you believe a **rule** itself has a clear flaw, tell us — we adjust **rules**, not individual scores.

## 10. Disclaimer

1. This site is an **interest leaderboard built by the community**; scores are generated by an algorithm and are **for browsing and discussion only. They are not an official assessment, endorsement or recommendation**.
2. Information about listed works comes from public sources and **may be wrong or out of date**; tell us through "Feedback" and we will correct it.
3. Copyright in a work belongs to its author or rights holder. This site only displays public information and links to the original work. **If you believe your rights are infringed, contact us through "Feedback" and we will correct, delete or unlink the material promptly after verification.**
4. These rules may change as the community and the site evolve; changes are published on this page.

## 11. FAQ

1. **My work is not listed — can I submit it myself?** Yes. Signed-in users can use "Submit a Work"; collection by administrators also continues.
2. **Why was my work put under Games?** The category follows the priority order in section 3; when nothing matches, the **fallback is Games**. If a category is clearly wrong, tell us.
3. **Will my score change?** Depends on the scoring mode: with the default "update information only", an existing work's score is not overwritten by later updates (the site owner may still adjust it); newly listed works are always scored automatically. The Hall of Fame records the highest total ever.
4. **Can I buy or fake scores?** No. Confirmed manipulation leads to removal and possibly action against the account; see section 10 of the [Scoring Standard](/docs/scoring), "Anti-manipulation".
5. **Can a removed work come back?** Most removals are **hide** operations; if it was a mistake or an appeal succeeds, the work can be shown again.
6. **How is this site related to Kimi officially?** There is no affiliation or partnership. This is a third-party interest leaderboard; work data comes from public information on [Kimi Community](https://www.kimi.com) and does not represent its official position.

---

**Related documents**: [Features](/docs/features) · [Community Guidelines](/docs/community) · [Scoring Standard](/docs/scoring) · [Terms of Service](/docs/terms) · [Privacy Policy](/docs/privacy) · [Changelog](/docs/changelog)

> Last updated: 2026-10-22
