# Changelog

This site keeps evolving. Every version is recorded here — **what was added, what changed, what was removed, and what still is not good enough**. Newest first.

> This page is public and describes only **user-visible changes**; it contains no internal implementation details.
> This English page lists changes from v3.40.0 onward. Earlier versions are summarised in the repository's CHANGELOG.

---

## v3.56.0 · 2026-10-31 (Prepared for the Worst)

### Account
1. **Link an email while registering**: the sign-up form has an optional email field — fill it, request a code, type the code; leave it empty and registration works exactly as before, just without the email-based ways in.
2. **Two-factor sign-in**: a switch in "Mine" turns it on. Once on, signing in takes one more step — after the password, type the code sent to your email. It requires a linked address first, and flipping the switch asks for your current password.
3. **Code sign-in and two-factor do not stack**: the "email plus code" path already uses two factors, so it will not ask for another code.

## v3.55.0 · 2026-10-31 (Worthy of the Name)

### Account
1. **Email can now be linked**: in "Me", click "Bind email" — enter the address on top, request a code and type it below. The code is valid for two minutes; once it checks out the address is linked, and changing it uses the same dialog.
2. **One address belongs to one account**: an address already linked to another account is refused outright; each account holds exactly one address, so linking again simply replaces it.
3. **Sign in with any of three forms**: UID, username, or email — all with the password.
4. **Sign in with an email code**: the sign-in page has a new "Code sign-in" tab. Enter the address, request a code, sign in — no password to remember. If nothing arrives, check the spam folder first; accounts without a linked address can link one in "Me" and use this path right away.

### Categories
5. **Categories are now decided by content**: most feeds carry no category at all, so nearly everything used to land in "Games". Works are now sorted into Games / Tools / Literature / Derivative by **title and description**, and existing works are being re-classified in batches.

### Fixes
6. **Document URLs no longer 404**: paths such as `/docs/scoring` were being caught by a server rule meant for private directories and rendered "Page not found". They open normally now, and uppercase spellings are accepted too.

## v3.54.0 · 2026-10-29 (Ever Renewed)

### Festivals
1. **Long March Victory Day (22 October) added**: on this day in 1936 the Second and First Front Armies met at Jiangtaibao and the Long March ended. The name **counts the years automatically** — 2026 shows "90 Years Since the Long March", phrased naturally in Chinese, English and Japanese rather than by dropping a number into a template.
2. **A matching theme**: the snow mountains are outlines only with a pale snow line along the ridge, and a diagonal band widening from the bottom of the page runs into the distance for the column heading north. A single dark red note appears once; there is no festive colour, and drifting snow is the only motion.

### Five easter eggs
3. **Opening the developer tools** points out that the site is AGPL-3.0 and gives the repository. It only mentions it — nothing is blocked and nothing fights back.
4. **A console note**: version, licence and repository.
5. **Arrow-key password**: ↑↑↓↓←→←→BA scatters a burst of stars.
6. **Tap the site name seven times**: tells you the version and leaves a note from development.
7. **Visiting between midnight and 5 a.m.**: one line suggesting you get some sleep, once a day.

Each egg fires once per device and stays out of the way; on mobile the tap-based ones work without a keyboard.

## v3.53.0 · 2026-10-29 (In Due Season)

### Fix
1. **Lunar festivals no longer leak across years**: deciding "is today a lunar festival" used to compare only month and day, so last year's date for the same lunar day could be taken for today when the month and day happened to match. The clearest case was Chongyang: in 2025 it fell on 29 October, which made the Chongyang theme appear again on 29 October 2026 — while this year's Chongyang was actually 18 October. The check now compares the full date, and the year-crossing cases in the 12th and 1st lunar months still resolve correctly.

### New
2. **A note on the day itself**: opening the site on a festival day now says which festival it is and which theme is active, with a way into the full write-up; it appears once per day only.
3. **"About today"**: a new entry at the top of the drawer (only shown when a festival falls today) with the festival's **overview, origins, customs and story**. If two festivals share the day, both are shown in turn.
4. **All three languages**: each of the 48 festival themes has a document in Chinese, English and Japanese, section for section.

## v3.52.0 · 2026-10-28 (Measure Twice)

### Human verification
1. **Voting now requires verification**: tapping the vote button on a work opens a verification dialog; once it passes, the vote is submitted automatically — no second confirmation needed. Close the dialog or fail the check and nothing is voted.
2. **Submitting feedback requires it too**: the same dialog appears before a feedback post goes out.
3. **Undoing a vote does not**: removing a vote only subtracts, so it cannot be used to inflate anything and stays one tap.
4. **Sites without verification are unaffected**: voting and feedback remain single-tap.

### Vote count
5. **The work page shows the count**: the number of votes sits next to the button and updates immediately whether you vote or undo, with no refresh needed.

## v3.51.0 · 2026-10-28 (Shore Up the Foundations)

### Uploads
1. **Three gates on image uploads**: a pixel limit (12000 per side, 40 million in total) rejects images that are a few KB on disk but decode into hundreds of MB; content scanning rejects any file carrying script markers (the classic `GIF89a` header with PHP code after it); JPEG / PNG / WebP are re-encoded once, stripping metadata such as where the photo was taken along with any appended data. GIFs keep their animation and rely on scanning plus a non-executable directory.
2. **The upload directory cannot execute scripts**: code placed there will never run.

### Bounds
3. **Request bodies are capped at 1 MB with a nesting depth of 32**: a request carrying tens of MB is refused outright instead of exhausting memory first.
4. **Numeric inputs are clamped**: paging, offsets and batch sizes stay in sensible ranges, and batch operations have an item cap — one request cannot delete the whole site.

### Authorisation and null safety (reviewed)
5. **Every write endpoint was reviewed one by one**: each delete or edit checks "owner or administrator", administration actions require a second factor at the panel entrance, and form writes carry an anti-CSRF token. This round added the confirmation that personal settings cannot take someone else's identity, plus null-safety fallbacks on the front end — an endpoint returning an unexpected shape no longer blanks the page.

### Front-end tamper protection
6. **The entry script carries a content check (sha256)**: if the file is rewritten, the browser refuses to run it rather than running modified code.
7. **An integrity manifest for key files (sha256 + md5, signed)**: twenty files covering the entry, front-end output and security core are compared every 10 minutes; the control panel gains a "File integrity" block that can verify on demand and list any differing file. Changing a file without the manifest, or changing the manifest without the key, is detected either way.
8. **A runtime guard in the page**: it only counts two things — a key node of this page being removed, and a built-in browser API being replaced. Nodes added by browser extensions (image downloaders, translators, style managers) never count and are never touched, so normal extensions keep working. Findings are recorded only; nothing is blocked.

## v3.50.0 · 2026-10-28 (Names That Mean Something)

### Readable addresses
1. **Every path segment now says what it is**: the ranking is `/rankings`, a work is `/works/12`, yours is `/me`, the control panel is `/admin`, a ban notice is `/banned/12`, a violation notice is `/notices/12`, World Chat is `/chat`, submitting a work is `/submit`.
2. **Documents use short English names**: `/docs/features`, `/docs/ai`, `/docs/community`, `/docs/scoring`, `/docs/admission`, `/docs/terms`, `/docs/privacy`, `/docs/changelog`. The Chinese name (e.g. `/docs/入榜规则`) still works and redirects to the matching address.
3. **Search always uses `?q=`**: `/rankings?q=keyword`. Every search entry point takes that one parameter, so a copied link reproduces the same results.
4. **The short addresses from the previous release all redirect**: `/rank`, `/detail/12`, `/doc/…`, `/mine`, `/panel`, `/violation/12` and `/discipline/12` still open — they simply jump to the new spelling.

## v3.49.0 · 2026-10-28 (Back to Basics)

### Addresses
1. **Page addresses are plain paths now**: the ranking is `/rank`, a work is `/detail/12`, a document is `/doc/…`, with no `?p=rank&arg=12` query-style addresses and no `#` needed. Cleaner to paste to someone and cleaner for search engines.
2. **API addresses lose the `.php`**: endpoints are now `/api/works`, `/api/lobby` and so on. The old addresses ending in `.php` still work, so external scripts and caches are unaffected.
3. **Old links keep working**: opening a legacy address (`?p=detail&arg=12`, `#/detail/12`) jumps to the matching new one, and in-site links — including those inside documents — now navigate in place instead of reloading the whole page.
4. **Sub-directory deployments work too**: when the site lives in a sub-directory, styles, scripts and API paths pick up the prefix automatically.

## v3.48.2 · 2026-10-26 (Worthy of the Name)

### Documents
1. **The Privacy Policy now lists every cookie and browser storage item**: the interface language (`kimgr_lang`), the automatic-theme switch (`kimgr_skin_optout`), the session language-alignment marker, the "deep thinking" switch in World Chat, the QR sign-in device identifier, the location cache (`kimgr_geo`) and the read-notice marker — nine items in all. The page promised "all of them — there are no hidden entries" while the list was incomplete; it now matches reality.
2. **The location wording is more precise**: our server does not store coordinates in plain text; they are cached in your own browser for 24 hours for reuse.
3. **The English changelog catches up**: v3.46.0 through v3.48.1 — seven releases — are now recorded, matching the Chinese page version for version.

## v3.48.1 · 2026-10-26 (In Name and in Fact)

### Two refinements to human verification
1. **No more "primary channel unavailable" notes while everything is working**: the page no longer shows a message suggesting the widget is broken when it is in fact rendering fine. Channels are switched only on real failure: once the primary has rendered, nothing touches it, even if a one-off probe comes back empty.
2. **The fallback's self-check now waits 5 seconds instead of 3**, and it no longer reports failure once a result has already been obtained — a slow network will not get a component swapped out mid-initialisation.
3. **The reason for switching channels goes to the browser console only** (press F12 and look for `[captcha] switched to …`), not onto the page.

## v3.48.0 · 2026-10-26 (Impregnable)

### Human verification now uses Cloudflare Turnstile
1. **Sign-in and registration are verified by Cloudflare Turnstile**: usually one click, and in most cases no interaction at all — no more watching a progress bar while a puzzle is computed.
2. **Every result is checked server-side before you are let in**, and the server confirms the result was issued for this site's domain — a result obtained elsewhere cannot be reused here.
3. **A failed check is a refusal**: the door is never opened merely because the verification service itself is down.
4. **Results are valid for 5 minutes and can be used once**: after a failed submission you are asked to verify again rather than reusing an old result.
5. **The third-party entries in the Privacy Policy and the Terms of Service are updated**: the provider is now Cloudflare Turnstile, stating that it receives your access IP and browser environment signals, and noting that the check does not use cookies for cross-site tracking.

## v3.47.0 · 2026-10-24 (Four Seasons, Eight Nodes)

Limited-time festival themes grow from 11 to **48 skins** — **37 new ones**, each designed on its own with its own palette and its own translations, none built from a shared template.

### Fixed solar dates (24 new)
1. **New Year's Day** (1/1): the first light of the year — a thin line along the horizon, a few tick marks, one faint band of dawn light leaking from behind it.
2. **Chinese People's Police Day** (1/10): an eight-pointed badge and warning tape — deep navy with silver-grey, the outline of an eight-pointed badge top right, two diagonal strips of tape on the left.
3. **Valentine's Day** (2/14): a single-line rose — white ground, one rose drawn with a single unbroken line, petals unfilled.
4. **April Fools' Day** (4/1): an upside-down question mark — one inverted question mark and a dashed line. The least serious set on the site, and its colours are remarkably plain.
5. **Qingming** (4/5): slanting rain and a weeping willow — grey-green ground, a willow top right, fine rain drifting down at an angle.
6. **Earth Day** (4/22): the rim of a sphere — a sphere drawn as its outline only, one cloud band crossing it, between blue and green.
7. **World Book Day** (4/23): a cone of lamplight — warm light falling as a triangle, a single sheet of paper inside it.
8. **International Workers' Day** (5/1): a wrench and a hammer — deep ochre ground, the two crossed, industrial, no ornamental pattern.
9. **Youth Day** (5/4): a paper plane climbing — one plane angling upward with a long trail behind it.
10. **World Smile Day** (5/8): one arc and two dots — an arc and two dots, and nothing else at all.
11. **International Museum Day** (5/18): case glare and a clay jar — the diagonal reflection on a glass display case, a plain clay jar inside.
12. **International Children's Day** (6/1): three blocks and a paper plane — three coloured blocks stacked unevenly, a paper plane top right.
13. **World Environment Day** (6/5): the veins of one leaf — a whole leaf's veins drawn in fine lines, green kept restrained.
14. **International Olympic Day** (6/23): five thin rings — five rings outlined and unfilled, interlocking.
15. **International Day Against Drug Abuse** (6/26): a broken chain — deep blue and silver; three intact links, then a fourth slipped out of line.
16. **CPC Founding Day** (7/1): a single five-pointed star — vermilion and muted gold; the most formal set here: no gradient, no texture, just two colours and clean geometry.
17. **July 7 Incident** (7/7): a crenellated wall with a gap — a length of battlement along the bottom, a breach in the middle, a dark red note inside the gap.
18. **PLA Day** (8/1): army-green cloth — the page carries a very fine warp-and-weft grid, a red star top right. Unlike the flat sets, this one has the texture of fabric.
19. **Surrender of Japan** (8/15): a flag at half-mast — plain white, one pole, the flag stopped at mid-pole and hanging without wind, outlined only.
20. **Chinese Doctors' Day** (8/19): one heartbeat trace — a line running flat from the left, then one deep trough and one sharp peak, teal with mint.
21. **Victory of the War of Resistance** (9/3): a horizon and a sliver of dawn — a horizon line with a half-arc of warm light rising behind it. No fireworks, no cheering.
22. **Teachers' Day** (9/10): a stick of chalk and three specks — blackboard green, a chalk stick lying at an angle, three specks of chalk dust beside it.
23. **September 18 Incident** (9/18): a torn calendar cell — a calendar cell top right, with a crack splitting the middle one from top to bottom.
24. **Martyrs' Day** (9/30): a memorial wall and one small star — rows of fine vertical lines along the bottom like a row of name plates, one very small star top right, unlit. The darkest and quietest set on the site.

### Lunar dates (11 new)
25. **Laba Festival** (8th day of the 12th lunar month): seven grains and a bowl of porridge — a rough clay bowl, seven grains suspended above the rim, a wisp of faint steam.
26. **Weiya** (16th day of the 12th lunar month): the banquet winding down — one low-hanging lamp, a corner of tablecloth, a pair of chopsticks set down.
27. **Northern Xiaonian** (23rd day of the 12th lunar month): candy melon and paper-cut lattice — an octagonal paper-cut window with a piece of candy melon beside it.
28. **Southern Xiaonian** (24th day of the 12th lunar month): dusting and a bamboo broom — a bamboo broom leaning at an angle, four specks of dust drifting up.
29. **Chinese New Year's Eve** (last day of the 12th lunar month): seeing the year out to the final tick — a watch face with the hands at the end, a 福 character beside it.
30. **Lantern Festival** (15th day of the 1st lunar month): lanterns and tangyuan — a bowl of tangyuan, three hanging lanterns of different sizes above.
31. **Shangsi Festival** (3rd day of the 3rd lunar month): cups on a winding stream — a small cup floating on the water, three ripples spreading outward.
32. **Dragon Boat Festival** (5th day of the 5th lunar month): one angular zongzi and water ripples — a sharply angular zongzi tied with string, three ripples below.
33. **Qixi Festival** (7th day of the 7th lunar month): a river of stars and one star on each bank — a diagonal band of stars across the top, a bright starred point on each side.
34. **Zhongyuan Festival** (15th day of the 7th lunar month): one river lantern — a lamp on the water, a soft halo beneath it, three ripples. The only set here with no outlines and no shadows: everything is done with glow.
35. **Mid-Autumn Festival** (15th day of the 8th lunar month): a full moon and a thin cloud — a full moon with a halo, one thin cloud crossing its face.

### By weekday (2 new)
36. **Mother's Day** (2nd Sunday of May): a three-petal carnation — one three-petal carnation with a fine stem, soft pink on off-white.
37. **Father's Day** (3rd Sunday of June): the shoulders of an old coat — the shoulder line of a worn coat, brown tones, no ornament at all.

### Calendar rules
1. All four window types are evaluated in Beijing time: fixed solar dates, lunar dates, the Nth Sunday or Thursday of a month, and multi-day windows.
2. **Lunar dates across the solar new year**: dates in the 12th and 1st lunar months can fall in January or February of the following solar year. Laba, Xiaonian and the New Year's Eve previously went missing in some years and now match correctly.
3. When two sets fall on the same day, a fixed order decides: in some years 8/19 is both Chinese Doctors' Day and Qixi.
4. Switching the skin away manually inside the window stops the automatic theme, as with the existing festivals.

### Translations
Names and descriptions for all 37 sets exist in Chinese, English and Japanese. Titles use established public wording with no evaluative language (for example Surrender of Japan / 日本の降伏; the July 7 and September 18 incidents are given with both the internationally used and the local name — 七・七事変（盧溝橋事件）, 九・一八事変（満州事変）).

## v3.46.3 · 2026-10-24

### Six new limited-time themes
1. **1024 Programmer's Day** (10/24): binary green — dark green ground with terminal green, three columns of faint 0s and 1s drifting down, a breathing cursor top left, a `</>` top right, a very light scanline layer over the page; all digits in monospace.
2. **May 12 Commemoration** (5/12): slate blue-grey — one candle, two white chrysanthemums, a layer of mist. A thin line along the bottom runs from the left and stops at the middle of the screen, quietly noting that this day is also half of 1024.
3. **Spring Festival** (nine days from the 1st day of the 1st lunar month; solar dates computed each year): vermilion and gilded — two lanterns swaying gently, an octagonal window flower, four flakes of gold drifting down.
4. **Journalists' Day** (11/8): lead type and red ink — paper white with lead black, three faint newspaper column rules on each side, a hand-drawn red circle top right (the circle never closes), a pen nib bottom right, a double rule under the masthead; headings switch to a serif face.
5. **Winter Clothing Festival** (1st day of the 10th lunar month; solar date computed each year): frost and a folded garment — a cold moon, six very slow frost grains, the silhouette of a folded winter coat bottom right. This is a day of remembrance: no warm colour anywhere, no festive motion.
6. **Thanksgiving** (4th Thursday of November; the date changes each year): pumpkin and maple leaves — cream paper with pumpkin orange, a pumpkin bottom right, two maple leaves spinning down, a pool of warm light along the bottom.

All six themes switch names and descriptions with the interface language; the English and Japanese wording follows local reading habits. Switching the skin away manually inside the window stops the automatic theme, as with the existing festivals.

## v3.46.2 · 2026-10-23

### Fixes
1. **Submit a Work: a successful QR scan now fills the login in automatically.** Previously you scanned the code and confirmed on the phone, but the box stayed empty with only "scan to authorise" showing — Kimi returns the status name with a prefix this site did not recognise. All four states (waiting for scan / scanned, awaiting confirmation / success / expired) are now recognised, and a success fills the login straight in.
2. **The work search has a Search button**: type a keyword and either press the button or hit Enter; search-as-you-type still works.
3. **The AI chat page no longer raises a "page script error"**: opening the AI chat and loading model information could break the page and stop the answer. Fixed.
4. **The same class of hazard was swept site-wide**: every variable that could clash with the translation mechanism has been renamed, so the same error cannot reappear on another branch.

### Re-check
Rankings, World Chat, Mine, About, Submit, Feedback and the document pages were all walked through in the Chinese, English and Japanese interfaces with no script errors.

## v3.46.1 · 2026-10-23

### Fixes
1. **"Sign in by QR" on the Submit page works again**: it used to fail with a service-unavailable message. The site now requests the code on your behalf first, and the browser asks Kimi directly if the site cannot; only when both fail are you told to paste instead.
2. **"Show all" in a work's comments no longer errors**: comments with many replies now expand and collapse cleanly.
3. **The QR code carries a device identifier**, matching Kimi's own scan links, so phones recognise it more reliably.

### Translation completion
1. **The hero headline and the page title follow the language**: English and Japanese interfaces no longer flash a Chinese headline first, and the browser tab follows too.
2. **The whole About page is translated**: introduction, promo video, references, plans, acknowledgements, support and the moderator list.
3. **Additional coverage**: the sign-in and registration forms, the three steps of submitting a work, feedback, Mine (check-in, accent colour, blocklist rules, back-animation note), World Chat (emoji, images, video, @ the official AI, deep thinking), violation notices, the image viewer, comment actions, the announcement banner and top-bar tooltips.
4. **Late-loading content is translated too**: the moderator list, announcements and the human-verification widget used to arrive after translation had run; they are now covered.
5. **Document downloads use a localised file name**: English interfaces no longer show Chinese document names.

## v3.46.0 · 2026-10-22

### Languages
1. **The interface speaks Chinese, English and Japanese**: switch under Mine → Language. The first visit follows your browser's preferred language, and a manual choice is remembered. Ranking and category labels, buttons, forms, prompts and error messages, the design styles and accent colours in appearance settings, and the limited-time festival themes are all translated; **the control panel and the anomaly monitor are admin surfaces and stay in Chinese**.
2. **All eight documents have English editions**: features, AI guide, community rules, scoring, admission, terms, privacy policy and this changelog, section for section with the Chinese version.
3. **Documents follow the interface language**: a Chinese interface shows Chinese, English shows English, Japanese shows the English edition (no Japanese documents yet); the top of each document page states which edition you are reading.

### Fixes
1. **Image links in chat can no longer break the page**: an image address containing quotes and other special characters used to interrupt the chat page's script and leave the whole page unusable. Image addresses are now treated as plain text, and invalid addresses are rejected outright.
2. **Videos play inside the document page**: the Bilibili video in this changelog plays in-page instead of sending you elsewhere.
3. **Limited-time festival themes use neutral names in international interfaces**: English and Japanese wording follows local reading habits; Chinese names are unchanged.

### Note: image uploads may fail
The host is patching a PHP security issue, during which **any file-upload request may return 403**. Sending text, recalling and deleting, submitting works and QR sign-in, rankings and scoring are unaffected. Progress is tracked on the host's status page: https://status.infinityfree.com/issues/2026-10-21-403-errors/

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
