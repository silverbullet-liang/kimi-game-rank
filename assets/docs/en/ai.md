# AI Guide — Kimi Game Rank

> Official text of the "AI Guide" page.
> This page explains **what the AI assistant can do, how the quota is calculated, how to spend less of it**, and the related data and disclaimer notes.

## 1. What the assistant can do

The AI assistant is a conversational assistant that can browse the web and read data from this site:

| Capability | Notes |
|---|---|
| General questions | Everyday knowledge, writing and editing, translation, code, structuring ideas |
| Look up works | Search works, read a work's details and six-dimension scores, check its rank, read its comments |
| Read a web page | Give it a URL and it reads the main text before answering |
| Weather | Weather for any city |
| Time | Current time, date, weekday |
| Read site documents | Terms, Privacy Policy, Community Guidelines, Features, Scoring Standard, Admission Rules, AI Guide, Changelog — it can list all documents, search them by keyword, or read one in full |
| Site overview | Totals for works / users / comments, today's additions, works per category, latest announcement |
| Exact arithmetic | Addition, subtraction, multiplication, division, brackets, powers, modulo — let it compute instead of trusting a model's mental arithmetic |
| Random picks | A few random works (optionally by category) for browsing |
| Lunar calendar and festivals | Solar to lunar, lunar to solar, and festivals or commemorations on a given day |
| User profile | Public information about a user (name, role, month joined, number of listed works) — **private chat only** |

- In a single question the assistant can call **at most 3 lookups**, retrying up to 2 times on failure before answering.
- For safety, some addresses (such as internal network addresses) are never fetched.
- **The lookup process is visible**: every lookup shows up as a **card** inside the answer (in progress → done / failed, with a one-line result), so you can see what it is doing.

**Deep thinking (toggle)**: there is a **"Deep thinking"** switch above the input box. When it is on, the assistant reasons before answering (and switches to a model that supports it); the reasoning appears above the answer as a **collapsible block** you can open at any time, rendered as Markdown. If tools are called during a question, **reasoning may appear both before and after the call**, shown as several blocks — that is, **multiple rounds of deep thinking are supported**. Reasoning and tool cards are **saved with the conversation** and can be reviewed later (see section 6).

Besides the assistant, an **AI summary card** may appear at the top of search results: it turns the matching works into a short summary (how many matched, how good they look, how to pick one), so you can judge whether the list is worth opening. This card is a separate mechanism: it **only looks at matches on this site**, does not browse the web, does not read outside content, and cannot be asked follow-up questions. Its cost is covered by the site and **never uses your quota** — see section 3, item 3.

---

## 2. Two kinds of model and how they are charged

You can choose a model before chatting; the two are **charged in completely different ways**:

| | Site model (default) | Free model |
|---|---|---|
| Source | A model integrated by this site | A **free** model provided by a model gateway |
| Charged by | **Tokens (amount of text)** | **Number of calls** |
| Uses your call quota | No | Yes |
| Good for | Long answers, many follow-ups | Saving tokens, occasional use |

> The "Mine" page shows an **AI quota card** with the number of available models, your calls today, and the site-wide remainder. Those figures are authoritative.

---

## 3. Quota rules

### 1. Site model: charged by token

- **Daily base quota**: 100,000 tokens
- **Monthly base quota**: 3,000,000 tokens
- **Check-in bonus**:
  - Daily check-in: **+1,000 tokens for the day**;
  - 7 consecutive days: **+100,000 tokens for the month**.
- Metering: tokens **actually consumed** by the conversation (input + output), accumulated day by day and month by month, until exhausted.
- **Mentioning the official AI in World Chat**: its reply is charged by token, **deducted from the person who mentioned it**; the message and the most recent messages in World Chat are sent to the model provider to generate the reply. If the quota is insufficient you get a notice (the message itself is still posted). It can **query site data, do arithmetic, check weather, time, lunar dates and festivals** (at most 2 lookups per reply) and marks sources with a faint "Reference: ..." line. For privacy it **reads no personal account data**; "user profile" is available only in private chat and returns public information only.

### 2. Free model: charged by call (two limits)

Using the free model requires **both** of the following:

1. **Per person per day**: default **2 calls** (after that: "your free-model calls for today are used up");
2. **Site-wide daily quota**: **returned in real time** by the model provider; the free tier is usually **50 calls per day**. If the site's account has a higher quota, more calls are available (after that: "the site-wide free-model quota for today is used up").

**Double charging**: no. A call is checked first and reserved once; if the site-wide quota turns out to be exhausted, your personal count is **refunded**.

### 3. What does not use quota

- **A rejected or unstarted question**: empty content or a rate-limit rejection is not counted.
- **The AI summary on search results**: paid for by the site, **completely unrelated** to your daily calls and tokens; it appears whether or not you are signed in and whether or not your free calls are used up (you can turn it off under "Mine → Search").
- **Content moderation**: the three-stage automatic check before a comment or message is posted (the third stage is a 1–10 severity grading) and the image check are also paid for by the site. They **use none of your quota** and none of your free calls. The logic is published in article 9 of the [Terms of Service](?p=doc&arg=用户协议).

### 4. When quota resets

Quota resets per **calendar day in UTC**, i.e. **08:00 Beijing time** each day. The monthly token quota resets per month.

### 5. Other limits

- **Rate limit**: to prevent abuse, rapid consecutive questions are throttled (about 6 per minute); wait a moment and continue.
- **Administrators**: administrator accounts are not subject to these quotas.

---

## 4. About caching

1. **Answers are no longer cached**: previously, if someone had asked the same question as the first message of a new conversation, the old answer was reused instead of generated again, which made the assistant look like it was repeating itself. Now **every answer is generated**, so asking twice gives two independent answers.
2. **The only cache left is the search summary**: the AI summary card on search results is generated once per keyword, then read from cache by everyone (guests included), so it uses no quota. Its key is "keyword + number of matches" — if works are added, removed or renamed so the number changes, the old summary is discarded and regenerated rather than showing stale numbers.
3. **Your conversation records are unaffected**: caching never touches your account or conversation content. Records live only in your own account and can be cleared at any time (see section 6).

---

## 5. Spending less quota

1. **Use the free model for short questions**: keep the token quota for answers that need length.
2. **Start a new conversation for unrelated questions**: shorter history means less input consumed, and conversations are no longer cached, so every answer is generated fresh.
3. **Do not make it look things up repeatedly**: state the whole need in one message to reduce tool rounds.
4. **Check in daily**: check-ins genuinely raise your daily and monthly quota.
5. **Say when you do not need the web**: for example, "answer from your own knowledge" reduces fetching and lookup cost.
6. **Clear history you no longer need**: shorter history means less input consumed; conversations can be cleared on the "Mine" page.

---

## 6. Data, privacy and records

1. **Questions are sent to the model provider**: to generate an answer, your question is sent to the relevant model provider. See article 8 of the [Privacy Policy](?p=doc&arg=隐私政策). With deep thinking on, the reasoning is produced by the model provider and returned immediately; this site **saves it together with the tool cards in your conversation record** (open it again to review) and deletes it when you clear the conversation on "Mine".
2. **Do not enter sensitive information**: ID numbers, bank cards, passwords, health information and the like. You bear the consequences of submitting sensitive information yourself.
3. **Records and clearing**: your conversations and usage are stored in your own account, visible on the "Mine" page, and can be cleared at any time.
4. **The cache holds no personal information**: only the search summary is cached, keyed by **keyword and summary**, and contains no account information.

---

## 7. Disclaimer

1. AI-generated content is **produced automatically by an algorithm. It does not represent the views of this site and is not professional advice** (including but not limited to medical, legal, financial, investment or security matters). Do not rely on it as the sole basis for a decision.
2. The assistant may produce inaccurate, outdated or fabricated content (commonly called hallucination). This site makes no warranty as to its accuracy, completeness or timeliness.
3. You must not use the assistant to generate content prohibited by law, or for fraud, infringement, forgery or other unlawful purposes, and must not bypass quota limits by technical means.
4. This site is not liable for malfunctions caused by failures, changes or discontinuation of upstream model services.

---

## 8. FAQ

1. **It says "your free-model calls for today are used up" — what now?** Your daily free calls are exhausted. Switch to the **site model** (charged by token) or wait for the next reset.
2. **It says "the site-wide free-model quota for today is used up" — what now?** Everyone has used today's site-wide free quota. Use the site model, or come back tomorrow.
3. **Will the same question give the same answer twice?** No — conversations are no longer cached, each answer is generated, and the two may differ. Only the search summary reuses a cache.
4. **Why did the quota reset at an odd time?** It resets per UTC calendar day, i.e. 08:00 Beijing time — see section 3, item 4.
5. **Which is better, the site model or the free model?** They come from different sources and are good at different things, and both can be wrong. The free model is cheaper per call; the site model suits long answers and follow-ups.
6. **Do administrators run out of quota?** No, administrator accounts are not subject to the quota.

---

**Related documents**: [Features](?p=doc&arg=功能说明) · [Community Guidelines](?p=doc&arg=社区公约) · [Scoring Standard](?p=doc&arg=评分标准) · [Admission Rules](?p=doc&arg=入榜规则) · [Terms of Service](?p=doc&arg=用户协议) · [Privacy Policy](?p=doc&arg=隐私政策) · [Changelog](?p=doc&arg=更新日志)

> Last updated: 2026-10-22
