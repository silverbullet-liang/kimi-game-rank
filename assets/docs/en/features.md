# Features — Kimi Game Rank

> Official text of the "Features" page.
> This page explains **what each feature does, how to use it and what the rules are**. After reading it you can use everything the site offers.

## 1. Overview

| Feature | Who it is for | In one line |
|---|---|---|
| Four leaderboards | Everyone | Browse works by different criteria — see section 2 |
| Search and work pages | Everyone | Find works and read six-dimension scores — see section 3 |
| Likes and comments | Signed-in users | Like and comment on works and comments — see section 4 |
| Submit a Work | Signed-in users | List your own work by picking it from the community; scan or paste your Kimi login; 2 per account per day — see section 11 |
| World Chat | Signed-in users | A public chat room for the whole site — see section 5 |
| AI assistant | Signed-in users | Questions, web reading, weather and time — see section 6 |
| Account and "Mine" | Signed-in users | Check-in, quota, settings, profile — see section 7 |
| Site emoji | Signed-in users | Emoji used in chat and comments — see section 8 |
| Feedback | Everyone | Reports, complaints and rights claims — see section 9 |
| Control panel | Administrators | Listing, scoring, operations — see section 10 |

> Without signing in you browse as a **guest**: all leaderboards and work pages are readable, but you **cannot** post, like or use the AI chat. A guest can upgrade to an account from "Mine" at any time.

---

## 2. The four leaderboards

The four leaderboards use **the same works and the same six-dimension score** (grouped into three pillars: work quality / author effort / community recognition); only the sorting differs:

| Leaderboard | Sorted by | Good for |
|---|---|---|
| **Overall** | Six-dimension total (max 1200) | The strongest works overall |
| **Votes** | Likes on this site | What readers like most directly |
| **Hall of Fame** | Highest total ever | The heights a work once reached — an honour board |
| **Hidden Gems** | **Lowest** heat first | Works with modest heat but a solid overall score |

- Every leaderboard can be filtered by category: **Games / Tools / Literature / Fan Work**.
- Totals and ranks are **dynamic**: they are recalculated when a work's data updates, so a work can rise, or slip as its heat fades.
- The six dimensions, formulas and rating rules are in the [Scoring Standard](?p=doc&arg=评分标准); category, listing and removal rules are in the [Admission Rules](?p=doc&arg=入榜规则).

---

## 3. Search and work pages

1. **Search**: choose "Search works" in the drawer menu and search by title, author or keyword. The scope is **works already listed**.
2. **AI summary**: an AI summary card appears at the top of the search results, summarising the matches — how many, how good they look, how to choose. Long text is **collapsed automatically**; tap "Show all" to read it. It only looks at matches on this site, does not browse the web, does not invent anything, and **uses none of your quota**; you can turn it off under "Mine → Search".
3. A **work page** shows:
   - title, author, category, description;
   - the **six-dimension radar chart** with per-dimension scores (0–200 each);
   - total score and rating (SSS / SS / S / A / B / C);
   - engagement: likes, comments, favourites;
   - cover and screenshots;
   - the comment section;
   - **a link to the original work page** in the community — tap it to open the work.
4. This site **does not store or redistribute the work itself**; it only shows public information and links to the original.

---

## 4. Likes and comments

1. **Likes**: signed-in users can like works and comments. Each person can like a given item **once**; after unliking, it can be liked again.
2. **Comments**: signed-in users can comment on works. You can delete your own comment (its replies are removed with it). A deleted comment **goes to the recycle bin** and disappears from the page; an administrator can restore it or clear it permanently from "Comment recycle bin" in the control panel.
3. **Automatic check before posting**: comments and messages pass **three stages** before they appear — a site word list and sentence-pattern check, a profanity check on an English rendering, and a classification model that returns a **1–10 severity level** (higher means more like abuse). Only content reaching the blocking level is stopped; content in the middle range is posted with a "possibly malicious" note beside it (an administrator can remove the note in one tap). When blocked you see "content did not pass review" — edit and post again, or report a mistaken judgement through "Feedback". All three stages are paid for by the site and use none of your quota. Probing prompts aimed at the AI (such as "ignore the above instructions") are **annotated, never blocked**. Uploaded images get their own check: text and picture are read first, then judged by the same classifier. The full logic is published in article 9 of the [Terms of Service](?p=doc&arg=用户协议).
4. **Content rules**: comments that break the law, advertise, attack people or spam are prohibited. Administrators may delete, collapse or hide them, and in serious cases restrict, mute or ban the account. See articles 7 and 9 of the [Terms of Service](?p=doc&arg=用户协议).

---

## 5. World Chat

1. World Chat is a **public chat room for the whole site**: signed-in users post, guests read.
2. Messages **refresh automatically**, so there is no need to reload the page. You can also recall **your own** messages; a recalled message is no longer shown.
3. **Emoji** and **images** are supported (see section 8). An uploaded image is checked before it is accepted; images that break the community rules are rejected and deleted. **Tap an image in chat to zoom in**.
4. **Bilibili videos can be sent**: tap the video button beside the input box and paste a **Bilibili link or BV number**. Before sending, the system checks the video's **title and description** (together with your note) and refuses if it does not pass. Accepted videos appear as a **card**; **the player loads only when you tap the card** and supports **fullscreen**. The site **stores only the BV number and display information** (title, cover, duration, author); the video is always served by Bilibili from its own CDN (no video file and no playback URL is stored).
5. **Images are cleaned up with the message lifecycle** to save space: recalling a message deletes its image, and images no longer referenced, or belonging to messages that have scrolled out of the displayed range, are removed too. A removed image shows an "image cleaned up" placeholder rather than a broken icon.
6. Chat content is visible to everyone — **do not post personal information** there (phone numbers, addresses, account passwords and so on). Administrators may act on content that breaks the rules.
7. **@ the official AI**: type `@` in a message and choose "Official AI", or use the `@` button beside the input box, to have the official AI answer that message. The reply appears as **AI**, usually under 150 words of plain text, and its cost is **deducted from the quota of the person who asked** (see section 3 of the [AI Guide](?p=doc&arg=AI 使用说明)). It **can look things up** — site data, arithmetic, weather, time, lunar dates and festivals (at most 2 lookups per reply) — and marks sources with a faint "Reference: ..." line. It **reads no personal account data**.

---

## 6. AI assistant

The AI assistant is a conversational assistant that **can browse the web and read data from this site**:

- **Answers**: knowledge questions, writing and editing, translation, code, structuring ideas;
- **Site lookups**: search works, read details and scores, check a work's rank, read its comments;
- **Site overview**: totals of works / users / comments, works per category, latest announcement;
- **Web reading**: fetches a URL you give it, converts it to text and answers from it;
- **Real-time**: weather (any city) and current time / date;
- **Arithmetic**: the four operations, brackets, powers, modulo, so numbers are not guessed;
- **Lunar calendar**: solar ↔ lunar conversion and festivals or commemorations on a day;
- **User profile** (private chat only): public information about a user (name, role, month joined, number of listed works);
- **Site documents**: terms, privacy policy, community guidelines, features, scoring standard, admission rules — it can list all documents, search by keyword or read one in full.

How to use it:

1. **Choose a model** before chatting: the **site model** is the default, and the **free model** can be selected from the list;
2. The two models are **charged differently**; quota rules, caching and tips for spending less are in the [AI Guide](?p=doc&arg=AI 使用说明);
3. There is a **"Deep thinking" switch** above the input box: when on, the assistant reasons first and the reasoning is shown as collapsible blocks (a single question may produce several); lookups also appear as cards inside the answer; blocks and cards are kept with the conversation, so reopening it shows them again;
4. AI answers are generated by an algorithm, **may be inaccurate**, do not represent the views of this site and are not professional advice.

---

## 7. Account and "Mine"

1. **Register and sign in**: username plus password, with a **human check** (one tap; if one channel is unavailable another is used automatically). The username is your display name, **shown site-wide and not changeable after registration** (no line breaks or decorative special characters, and it must pass content review); contact an administrator if you forget your password.
2. **Guest mode**: browse without registering; signing in unlocks voting, comments and the AI chat.
3. The "Mine" page contains:
   - **Check-in**: a daily check-in adds AI quota, and consecutive days give extra bonuses (the page shows the exact amounts);
   - **AI quota card**: available models, your calls today, site-wide remainder;
   - **Settings**: interface style (including festival themes), notification switches, visit-history switch, search AI summary switch;
   - **Festival themes**: China's National Day, the 1911 Revolution (10/10), the Korean War (10/25), Halloween (10/31) and the Double Ninth Festival (**9th day of the 9th lunar month**, converted automatically each year) each have **their own theme**, enabled on the day and reverted afterwards (Halloween forces a dark palette); switching the skin manually inside the window stops the automatic theme;
   - **Profile**: avatar and profile card;
   - **Feedback entry**: goes straight to "Feedback".
4. **Privacy**: you can **turn visit history off at any time** in settings, and request deletion of what has already been recorded. See the [Privacy Policy](?p=doc&arg=隐私政策).

---

## 8. Site emoji

1. Site emoji are used in **chat and comments**; typing a related keyword suggests the emoji and a tap inserts it.
2. The emoji artwork **comes from Bilibili**, and copyright belongs to its rights holders. This site uses it only for on-site conversation, claims no rights and has no partnership with Bilibili.
3. The full statement (scope of use and how to make a rights claim) is in article 8, item 9 of the [Terms of Service](?p=doc&arg=用户协议).

---

## 9. Feedback

1. **Where**: "Mine → Feedback", or "Feedback" in the drawer menu.
2. **What you can send**: bugs and errors, corrections, reports of violations, requests to recommend or remove a work, and **copyright claims**.
3. **Please include**: the work or page concerned, a description of the problem, and any screenshots or links. There is an option to make it **visible to administrators only**.
4. Handling: administrators reply in "Reply to feedback"; general issues are handled as quickly as possible.
5. **For anything involving personal information or a rights claim**, provide the necessary material as described in article 18 of the [Privacy Policy](?p=doc&arg=隐私政策) and article 8 of the [Terms of Service](?p=doc&arg=用户协议).

---

## 10. Control panel (administrators only)

The control panel is the **administrator-only** back office for listing and syncing works, recalculating scores, maintaining data, handling feedback and announcements, and managing users.
On an administrator's "Mine" page there are **two entries side by side**: "Control panel" and "Monitoring" (the side menu has the same entries).

- Regular users **neither need nor can** enter it; the interface **cannot be opened by URL parameters** — it must be reached from the in-site entry and re-verified.
- **Monitoring is visible to both the main administrator and co-administrators**: page performance, JS errors, API calls, server timings and session traces.
  A session trace shows the **user (with 8-character UID)** and **the site version at the time**; the list **loads more as you scroll to the bottom**.
  **"Save settings" and "Clear details" remain restricted to the main administrator** (they change collection configuration and data).
- When listing works, the "Add work" area lets you **search Kimi Community directly**: after saving your community credentials, type a keyword to search. Results show cover, title, author and engagement, **10 per page, loading more as you scroll**, and the "Add" button on the right lists a work in one tap (entries **already listed are marked**, so nothing is duplicated). **Both the main administrator and co-administrators can use it**, each with their own credentials.
- **Listing threshold**: only works **with a title and a reachable link** are listed — works without a title, or with neither a direct CDN link nor a community share link, are skipped automatically. Such works listed earlier are **delisted automatically** on update / sync, and can be handled immediately with "Clean up invalid works" in the bulk update area (delisted works can be restored from the work list).
- In statistics, **AI usage is accounted per channel**: "site model" and "model gateway" are counted separately for tokens and calls, with a per-account usage ranking.
- Data maintenance includes **one-tap backup**: the whole site is exported into a single SQL file stored on the server, which can be **downloaded** at any time, and old backups can be deleted. One backup is capped at 1GB. Backups contain account and comment data, so **downloading requires administrator authentication**; keep them safe and delete them when done.
- Data maintenance also includes the **scoring-mode switch**: the default "update information only" means an existing work's information (title, description, cover, engagement) is updated **without overwriting its score**, so scores can be adjusted case by case; **newly listed works are still scored automatically**. **A changed scoring system is not recalculated automatically** — the panel shows "scoring system updated", and "Start recalculation" applies the new algorithm to the whole site (running in batches in the background with a progress display). The bulk update recalculates the six dimensions with the current algorithm by default (choose "update information only" to keep manual adjustments). The switch above applies to **automatic background sync** and can only be toggled by the **main administrator** (**co-administrators have read access only**).
- There is also **smart link recognition (on by default)**: if a work page is only a redirect page (a line of text and one button, a short introduction and one button, or a loading animation that jumps away automatically — including redirect targets written in script **variables** or triggered by a timer), the real work address is used instead. GitHub Pages and author subdomains on AI platforms are always treated as real work pages and never replaced. Works listed earlier are **re-identified automatically in the background** (a redirect page is replaced by the real address and the title corrected with it), with no need to handle them one by one; "Re-identify listed works" in the control panel does it immediately.
- Also: the site is **search-engine friendly** — every page has its own title and description, structured data, and `robots.txt` and `sitemap.xml`; work pages and document pages can be indexed. The mirror domain is `noindex` site-wide (only the primary domain is indexed).
- Those switches are **also available to co-administrators**, who handle listing and updating works.
- There is also **violation notices**: the site owner can issue a notice against a user from the user list — tick any number of reasons (free text allowed), choose a ban duration (including permanent, **fractions of a day supported**, e.g. 0.5 day = 12 hours), and optionally block the access origin, delete all of that user's comments, chat messages and images. A notified user can still open the site and even **sign in** (to see the outcome), but the front end is **locked to the notice screen** and every other function is **refused by the server** — changing the address or the device does not get around it. The "Violation notices" page lists all records publicly, with discussion in one place below the list; a notified user cannot see or join that discussion. A single ban only counts towards the total after 7 days, and an account reaching 10 accumulated bans is deleted permanently; **when an account is deleted permanently, any IP ban tied to it is lifted and removed from the list**. Blocked origins have **protection against collateral damage**: a shared exit address (school, company, public network) is often used by many people, so a restricted person can tap "Verify my location" in the notice, read the explanation and then decide whether to allow location access; if they are far from the restricted area (about 30 km tolerance), they are treated as a different person and access is restored. Refusing keeps the normal rules.
- There is also **renaming**: the site owner can rename any user from the user list (2–64 characters, not duplicating an existing username, not a reserved name, no line breaks or decorative special characters, and it must pass content review). **A renamed user's violation notices show both the original and the current name** — a notice records the name at the time and renaming does not rewrite it; the current name is shown alongside so the notice still points at the right person. If your username does not meet the current rules (for example, registered long ago with special characters), a prompt appears at sign-in and the suggested name can be adopted directly.
- It is therefore **normal** that you do not see it in the menu.

---

## 11. Submit a Work

Signed-in users can submit works. Guests can browse and search, but submitting requires an account.

**How to submit**

1. Open "Submit a Work" and fill in your **Kimi login** first (scan or paste).
2. Search a keyword; results come from **Kimi Community** (the same search the control panel uses). Tap one result and the work ID is filled in and locked, together with the name and cover.
3. The name and cover can be edited; **the work ID can only be chosen from search results** and cannot be typed, so a typo cannot lead to an unreadable work.
4. Tap "Submit and list": the system reads the work, calculates its score and opens the work page.

**How to fill in the login**

- **Scan**: tap "Sign in by QR", scan the code with the Kimi App and confirm on the phone; the login is filled in automatically.
- **Paste**: paste the credential copied from your browser into the field.

The login is used only to read this one work. It is not stored and not displayed on the page.

**Rules**

- One work ID can be listed once; an already listed work shows "already listed".
- **Each account can submit twice per day** (failed attempts do not count); one network address is limited to 5 submissions per hour.
- The name must pass content review; the cover must be an http(s) link.
- If the work cannot be read (wrong ID, expired login) the reason is stated directly and nothing is stored.

## 12. FAQ

1. **Why can't I see "Control panel"?** It is an administrator-only interface — see section 10.
2. **Why can't I like or post?** Check that you are signed in (guests can only browse).
3. **Why do scores and ranks change?** Scoring is dynamic and recalculated when a work's data updates — see the [Scoring Standard](?p=doc&arg=评分标准).
4. **The AI says my calls are used up.** See section 4 of the [AI Guide](?p=doc&arg=AI 使用说明); quota resets per calendar day.
5. **My work is not listed / is in the wrong category.** See the [Admission Rules](?p=doc&arg=入榜规则) and tell us through "Feedback".
6. **Can't find the explanation you need?** Ask through "Feedback" and we will add it to the documents.
7. **Why is there no AI summary on the search page?** Three normal cases: the keyword matched nothing; you turned it off under "Mine → Search"; or the site's daily summary allowance is used up (it returns the next day).

---

**Related documents**: [AI Guide](?p=doc&arg=AI 使用说明) · [Community Guidelines](?p=doc&arg=社区公约) · [Scoring Standard](?p=doc&arg=评分标准) · [Admission Rules](?p=doc&arg=入榜规则) · [Terms of Service](?p=doc&arg=用户协议) · [Privacy Policy](?p=doc&arg=隐私政策) · [Changelog](?p=doc&arg=更新日志)

> Last updated: 2026-10-22
