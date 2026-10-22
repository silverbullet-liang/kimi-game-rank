# Changelog

This site keeps evolving. Every version is recorded here — **what was added, what changed, what was removed, and what still is not good enough**. Newest first.

> This page is public and describes only **user-visible changes**; it contains no internal implementation details.
> This English page lists changes from v3.40.0 onward. Earlier versions are summarised in the repository's CHANGELOG.

---

## v3.45.1 · 2026-10-22

### Fixes
1. **An image link could break the chat page**: someone entered an image address containing quote characters, which interrupted the chat page's script and made the whole page unusable. Image addresses are now treated as plain text, and invalid addresses are rejected outright.
2. **Videos play inside the document page**: the Bilibili video in this changelog now plays in-page instead of sending you elsewhere.

## v3.45.0 · 2026-10-20

### Submit a Work
1. **Signed-in users can submit their own work**: fill in the name, work ID and cover; the system reads the work, scores it and lists it. **Two submissions per account per day.**
2. **Pick the work from community search**: the search box queries Kimi Community directly (the same search the control panel uses), and tapping a result fills in the work ID, name and cover. The work ID cannot be typed, so it cannot be mistyped.
3. **Login by QR code**: tap "Sign in by QR", scan with the Kimi App and the login is filled in; pasting also works. The login is used only for this one read and is not stored.
4. **Choose your own cover**: leave it empty to use the work's own cover, or fill in an image to use yours.

### Release video

BV1yjnm6bEoH

## v3.44.0 · 2026-10-17

### Scoring
1. **No more SSS inflation**: ratings are assigned by **rank** (only the top 2% are SSS, the top 10% SS, equal scores share a tier, and no tier exceeds its quota). Previously they were assigned by a score cut-off, so any cluster of equal scores flooded the SSS / SS tiers.
2. **Scores spread out — no more easy high scores**: free points that almost everyone collected are gone, and padding is worth less —
   - **Playability** weighs interaction more: having a work file counts 0.40 (was 0.60), interaction 0.40, instructions 0.20;
   - **Creativity** weighs variety of expression and novelty of form more (0.40 / 0.30); title distinctiveness and length fall to 0.30 combined;
   - **Effort** no longer equals volume: the investment baseline counts 0.35 (was 0.60), and "is it explained clearly" rises to 0.45;
   - **Full marks for text / code / files need more** (body text 800 → 1200 characters, lines 400 → 800, bytes 150KB → 300KB), so padding with words or code no longer reaches the top.
3. The aggregation **floor** drops from 0.20 to 0.10: the gap between strong and weak shows more clearly and low-scoring works are no longer lifted as a group.

### Deployment
1. **A new scoring system no longer recalculates automatically**: after deploying a new version the control panel shows "scoring system updated, site not yet recalculated", and "Start recalculation" runs it (in batches in the background, with progress). You can check the new rules first and decide when to run it.

## v3.43.0 · 2026-10-16

### Scoring
1. **New scoring algorithm, whole site recalculated automatically**: once the scoring system is updated, the site **recalculates every work's six dimensions and total with the new algorithm** after deployment — no manual step, and no more "new algorithm, old scores".
2. **Dimension names and descriptions updated**: the six dimensions are now **playability / creativity / depth / scale / effort / reputation** (grouped into work quality, author effort and community recognition). Old names (experience / cost / attitude / heat) have been replaced everywhere on work pages, in the control panel and in the documents.
3. **Progress is visible**: recalculation runs in the background and the "Update" area of the control panel shows progress; "Start update" speeds it up.

### Maintenance
4. The manual "Start update" now **recalculates the six dimensions with the current algorithm** by default (it used to refresh information only, which was easy to miss); bulk recalculation gets a higher outbound allowance so that works processed later are not scored low because their pages could not be read in full.
5. To keep scores you adjusted by hand, choose "update information only" in the "Update" area.

## v3.42.0 · 2026-10-15

### Site
1. **Optimised for search engines**: the site used to be a pure front-end app, so a search engine that runs no scripts saw nothing. The server now emits **a dedicated title and description**, a canonical link and **structured data** for every page, and **renders leaderboards and work content into the page** — it can be browsed without scripts, and the page contains real links for crawlers to follow.
2. **robots.txt and sitemap.xml added**: search engines can discover all work pages and document pages from them. The mirror domain is set to **noindex** site-wide, so only the primary domain is indexed and the two domains do not dilute each other.

## v3.41.0 · 2026-10-15

### Scoring
1. **Media count no longer counts**: the number of images, audio and video items **no longer affects any dimension** (it used to nudge playability and scale slightly). How many pictures a work has no longer moves its score — scoring looks only at **whether it runs, interaction, instructions, creativity, depth, investment and reputation**.

### Listing
2. **Listed works re-identify their links automatically**: after the detection logic was upgraded, listed works are **re-checked automatically** in the background, and any redirect page is replaced by the real work address (with the title corrected); the control panel can also trigger a re-check immediately.

## v3.40.0 · 2026-10-14

### Scoring system rebuild (more rigorous, dimensions related to each other)
1. **Three pillars**: the six dimensions form **work quality / author effort / community recognition**, and the total is aggregated from the pillars — **a weak pillar pulls the total down multiplicatively** (short-board effect) instead of each dimension standing alone.
2. **Weights with a basis**: pillar and dimension weights come from **AHP** (pairwise comparison matrices with a consistency ratio) and are then **corrected objectively from site-wide data** (CRITIC, weighting by dispersion and correlation) — no more weights picked by feel.
3. **More credible reputation**: the reputation dimension applies **Bayesian shrinkage** to engagement, pulling towards the site-wide mean when samples are few, so one like cannot make a legend.
4. **Dynamic ratings**: ratings follow **percentiles of the site-wide total** (top 2% / 10% / 25% / 50% / 75%) and float with the overall level.
5. Existing works change score under the new algorithm and are recalculated through "update / sync" after deployment.

---

> Earlier versions are recorded in the Chinese edition of this page and summarised in the repository's CHANGELOG:
> https://github.com/silverbullet-liang/kimi-game-rank
