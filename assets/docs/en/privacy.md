# Privacy Policy

This site ("Kimi Game Rank") respects and protects the personal information of every visitor. This policy follows the [Personal Information Protection Law of the People's Republic of China](https://flk.npc.gov.cn/), the [Cybersecurity Law](https://flk.npc.gov.cn/) and the [Data Security Law](https://flk.npc.gov.cn/), and explains what we collect, why, how we use and protect it, and the rights you have.

**Please read this policy before using the site. Registering, signing in or visiting as a guest means you have understood and accepted it.**

---

## 1. Introduction and scope

1. This policy applies to your access to and use of the service through any means, including web pages and mobile browsers.
2. This policy **does not apply** to information that a third party collects on its own after you follow a link away from this site; that is governed by the third party's own privacy policy.
3. This policy is an inseparable part of the Terms of Service. Where the two differ on personal information matters, this policy prevails.

## 2. Data controller

1. **Controller**: this site is operated independently by an individual developer, who is the controller of personal information.
2. **Contact**: for anything relating to personal information, use the site's "Feedback" (optionally marked "visible to administrators only") or contact the site owner, `admin`.
3. This site **has no** dedicated data protection office; given its size, requests are handled directly by the operator, normally within 15 working days.

## 3. What we collect

We follow the **principle of minimum necessity** and collect only what is needed to provide the service:

| Category | Details | How it is collected | Purpose |
| --- | --- | --- | --- |
| Account information | Username, password digest (irreversible), account ID, registration time, role | Provided by you | Identification, sign-in verification, permission control |
| Preferences | Interface style, accent colour, notification switches, visit-history switch, blocked words | Set by you | Remembering and syncing your interface and feature preferences |
| Access information | **Masked form** of your IP, access time, region (country / province / city), carrier | Collected automatically | Security, rate limiting, troubleshooting, access statistics |
| Interaction content | Comments, World Chat messages, feedback, likes, uploaded images | Provided by you | Community features and content display |
| AI usage | Conversation content, number of calls, token consumption | Provided by you | Generating answers, quota management, usage display |
| Log information | Error logs, API call results, page performance and diagnostics (including the site version at the time; no sensitive content) | Collected automatically | Troubleshooting, performance tuning, security auditing |
| Location (optional) | The browser's **approximate location** (only when you actively authorise it to avoid collateral blocking on a shared network; coordinates are not stored in plain text, only the resulting regional decision) | Authorised by you | Judging whether an access origin belongs to the same person, so unrelated people on a shared network are not affected |

**We do not collect**: real names, identity document numbers, phone numbers, email addresses, contacts, precise location (**location is obtained only with your active authorisation, only for the regional decision, and coordinates are not kept**), device identifiers (IMEI/IDFA and similar), biometric information, health or financial account information.

> Note: **do not** submit such sensitive personal information in comments, chat or feedback. You bear the consequences of doing so voluntarily.

## 4. How we collect it

1. **Provided by you**: information you submit when registering, signing in, posting, commenting, uploading images, sending feedback or using the AI chat.
2. **Collected automatically**: when you visit, the server records necessary technical information such as the origin IP and request time. An IP used to resolve the region is sent to third-party lookup services (see section 8).
3. **Local storage**: sign-in state and preferences kept in browser cookies and `localStorage` (see section 7).
4. We **do not** passively collect behavioural data through third-party analytics, advertising or social plugin SDKs.
5. **Browser geolocation (only with your authorisation)**: when an access origin is restricted and you may simply be someone else on the same network, the interface offers a "Verify my location" entry. We **explain the purpose in a dialog before requesting the browser's location permission**, and call geolocation only after you agree. The result is used only to compute a rough distance from the restricted area; **our server does not store coordinates in plain text** (they are cached in your own browser for 24 hours for reuse) and they are never used to track you.

## 5. Purposes and legal basis

Our legal bases are: **your consent**, **performance of a contract to which you are a party**, **compliance with legal obligations**, and, within reasonable limits, **legitimate interests in keeping the service secure**.

Specific purposes:

1. Providing, maintaining and improving the service;
2. Identification, sign-in verification and permission control;
3. Security: rate limiting, anti-manipulation, anti-spam, detecting anomalies and attacks;
4. Statistics about access and usage (in **aggregated, de-identified** form);
5. Meeting legal obligations and cooperating with lawful investigations by regulators.

**We do not** sell or rent your personal information, or use it for advertising profiles or automated marketing unrelated to this service.

**Exceptions**: where the law provides, we may process personal information **without consent** when necessary to perform statutory duties, to respond to a public health emergency or to protect life, health or property in an emergency, when processing information you have published yourself or that is lawfully public within reasonable limits, or in other cases provided by law.

## 6. Sensitive personal information and automated decision-making

1. **Sensitive personal information**: this site **does not collect sensitive personal information as the goal of any feature** (biometrics, religious belief, specific identity, medical health, financial accounts, movement traces, information about minors under 14, and so on). Where an action of yours could lead to sensitive information being processed, we will give prominent notice and obtain your **separate consent** first.
2. **Automated decision-making**: leaderboard ordering and scoring **are computed from public work data with a fixed algorithm and are not automated decisions about you as a person**. This site **does not** perform personalised recommendations, user profiling or differentiated pricing based on personal information.
3. Comment filtering by blocked words is **configured by you and runs on your own device**; it involves no server-side automated processing of your personal information.
4. **Content moderation**: comments, messages and uploaded images are judged automatically by the system (rules in article 9 of the [Terms of Service](/docs/terms)). The judgement **concerns only the content or the image**, does not analyse your identity, behaviour or personal information, is not an automated assessment of your credit, ability or similar, and does not affect your other rights here. The result only decides whether that piece of content or image is published. Mid-range content, prompts aimed at the AI (prompt injection) and passive-aggressive wording are annotated beside the content (the annotation does not affect publishing and administrators can remove it).

## 7. Cookies and local storage

- **Handling of access origins**: when recording where an access came from, we keep only an **irreversible hash fingerprint** to identify anomalies and enforce blocks. **Plain IP addresses are not stored**, and the interface always shows a masked form.

We use a small number of cookies and browser storage to **provide and protect the service**. These are **all** of them — there are no hidden entries:

| Name | Type | Purpose | Retention |
|---|---|---|---|
| Session identifier | Cookie | Keeps your session, used for form security checks (CSRF protection) | Expires when the browser closes |
| `kimgr_skin` | Cookie | Remembers your **interface style** and applies it next visit | About 1 year |
| `kimgr_skin_optout` | Cookie | Remembers your choice to **turn the automatic theme off** inside a festival window, so it is not applied again until the window ends | About 1 year |
| `kimgr_lang` / `lang` / `doclang` | Cookie / local storage | Remembers your **interface language**, and whether a document page shows the Chinese or the English edition | About 1 year (cookie) / until you clear it (local storage) |
| `kimgr_lang_synced` | Session storage | Whether this session's language is already aligned with the server, to avoid repeated reloads | Expires when the browser closes |
| `kimgr_token` | Local storage | Stores your **sign-in credential** to keep you signed in | About 7 days (sign in again after that) |
| `kimgr_prefs` | Local storage | Stores your **interface preferences** (theme choice and similar) | Until you clear it |
| Cooldown counters (`kimgr_cd_*`) | Local storage | Records recent actions to **prevent duplicate submissions** | Short-lived, expires automatically |
| Blocked words | Local storage / account settings | Stores your **blocked words**; saved with the account when signed in, on the device only for guests | Until you clear or change them |
| `kimgr_ai_think` | Local storage | Remembers the **"deep thinking" switch** in World Chat | Until you clear it |
| `kimgr_qrdev` / `kimi_qr_wid` | Local storage | Remembers the **device identifier** used for QR sign-in (a browser identifier supplied by Kimi, used only to generate the code) | Until you clear it |
| `kimgr_geo` | Local storage | Caches the **location** you authorised (latitude, longitude, time) for a later check of the same restriction | 24 hours, then expires |
| `kimgr_disc_seen` | Local storage | Remembers the **latest violation notice** you have read, so it is not shown again | Until you clear it |
| `kimgr_ban` / `kimgr_b` | Local storage, session storage, IndexedDB, Cache Storage, cookie | Records **whether the access origin is restricted**, so the restriction notice appears immediately on refresh or navigation; written only while restricted and cleared automatically when lifted | Until lifted or expired (at most 30 days) |

1. **We do not use**: third-party analytics (traffic analysis, tracking SDKs), advertising cookies, cross-site tracking; we embed no social plugins and **never sell** cookie or local-storage data.
2. **How to inspect and manage**: use your browser's "Settings → Privacy and security" to view and clear them. In a private window, the data is cleared when the window closes (and you are signed out). You can also adjust interface preferences, notifications and the visit-history switch under "Mine → Settings".
3. **Effects of clearing**: you will need to sign in again and interface preferences return to defaults; **guest** blocked words are lost (a signed-in user's blocked words stay with the account). Clearing data **does not delete your account or content** — signing in restores everything.
4. **Private mode and refusal**: you may refuse cookies and local storage in your browser, but sign-in and some features may be affected.
5. **Local record of a restricted state**: when an access origin is restricted, we keep a minimal marker in **several local storages** (local storage, session storage, IndexedDB, Cache Storage and one marker cookie) so that you **see the restriction notice immediately** on refresh or navigation without waiting for the network; any one of them being present is enough. When the restriction is lifted, all of them are **cleared automatically**. The marker contains only "restricted or not" and necessary timing information, never your content.

## 8. Sharing, entrusted processing, transfer and disclosure

We **never sell** your personal information. We share it only in the following **minimum necessary** cases. The table lists the third-party services this site actually calls, by full name; the names link to their official sites or API documentation:

| Service (full name) | Site / docs | What is shared | Purpose |
| --- | --- | --- | --- |
| Moonshot AI · Kimi Community | [www.kimi.com](https://www.kimi.com) | No personal information (public work information only); when an administrator chooses "fill in by QR code", their browser submits a login code once to the Kimi login service | Retrieving work data and pages; letting an administrator obtain their own community credential |
| Miren API (BugPk resolver) | [api.mir6.com](https://api.mir6.com) | The Bilibili video link / BV number you submit | Resolving video information and playback address (used only for the pre-send preview and playback) |
| Bilibili | [www.bilibili.com](https://www.bilibili.com) | Public information about the Bilibili video (video page) | Getting title, description, cover and duration for display and moderation |
| Zhipu AI (Beijing Zhipu Huazhang) | [open.bigmodel.cn](https://open.bigmodel.cn) | Question content in AI conversations; the **search keyword** used for the AI summary; comments and messages awaiting publication; uploaded images (fallback image-moderation channel) | Generating AI answers, content moderation (text and images) |
| OpenRouter | [openrouter.ai](https://openrouter.ai) | Question content in AI conversations (only when using its free model) | Generating AI answers (free-model gateway) |
| Cloudflare Turnstile (primary) | [cloudflare.com](https://www.cloudflare.com/products/turnstile/) | Your access IP and browser environment signals | Human verification for sign-in and registration |
| Cap human verification (fallback) | [trycap.dev](https://trycap.dev) | Your access IP | Human verification (used automatically when the primary is unavailable) |
| classifier.dev (Jev classifier) | [classifier.dev](https://classifier.dev) | Comments and messages awaiting publication | Model judgement for content moderation |
| OVHcloud AI Endpoints | [OVHcloud AI Endpoints](https://www.ovhcloud.com/en/public-cloud/ai-endpoints/) | Images you upload | Image moderation (reading text in the image and detecting prohibited imagery) |
| MyMemory translation | [mymemory.translated.net](https://mymemory.translated.net) | Comments and messages awaiting publication (first 300 characters) | Translating to English for profanity detection |
| PurgoMalum | [purgomalum.com](https://www.purgomalum.com) | The English rendering above | English profanity detection |
| mir6 region lookup | [www.mir6.com](https://www.mir6.com) | Your access IP | Resolving the access region |
| ip-api | [ip-api.com](https://ip-api.com) | Your access IP (fallback channel) | Resolving the access region |
| Open-Meteo | [open-meteo.com](https://open-meteo.com) | The city name you query (not personal information) | Weather and geocoding |
| wttr.in | [wttr.in](https://wttr.in) | The city name you query (not personal information) | Weather (fallback channel) |
| Bilibili | [www.bilibili.com](https://www.bilibili.com) | None (emoji artwork is cached locally and nothing is sent when used) | Source of site emoji artwork |
| InfinityFree (shared hosting) | [www.infinityfree.com](https://www.infinityfree.com) | All site data is hosted there | Server and database hosting |

Notes:

- **API endpoints**: Kimi Community data comes from its public API `www.kimi.com/apiv2/*`; the Zhipu API is `open.bigmodel.cn/api/paas/v4/*`; OpenRouter's model and quota endpoints are `openrouter.ai/api/v1/*`; Open-Meteo provides `geocoding-api.open-meteo.com` and `api.open-meteo.com`.
- **Off-site images**: images from other sites in works and chat are fetched by your browser directly from the origin; this site no longer proxies them. **The image host therefore receives your access information (including IP) as if you had visited it yourself**, and its own privacy policy applies. This site no longer shields that layer for you.
- **AI web lookups**: when you ask the AI to read a page, the request originates from this site's server. Third-party sites cited in AI answers (weather, news and similar) are for you to judge; their content and privacy practices follow their own policies.
- **Search summaries**: the AI summary on search results sends the model provider only the **search keyword** and **public information** about matching works (title, category, score, description). **No account, IP or identifier is included.** Results are cached per keyword, are not personalised and are not linked to your account.
- **Content moderation ("three stages")**: before a comment or message is posted it is checked — first against the site word list and sentence patterns, then the first 300 characters are translated to English and checked by an English profanity service, and finally a classifier returns a 1–10 severity level (only content reaching the blocking level is stopped; mid-range content is published with a "possibly malicious" note; prompts aimed at the AI and passive-aggressive wording are annotated, never blocked; if the classifier is unavailable a large model makes a binary judgement). The full logic is published in article 9 of the [Terms of Service](/docs/terms). **Only the content itself is sent** — no account, UID, IP or other identifier. Conclusions are cached against the content, so identical content is not sent again.
- **Image moderation**: an uploaded image is judged by a vision model (reading text and detecting prohibited imagery) and by the classifier before it is written to disk; if one is unavailable the other vision model decides directly, and conclusions are cached against the image. **Only the image itself is sent**, with no account, UID or IP.
- **Services may change**: if a service becomes unavailable we switch to an equivalent fallback channel, whose name and purpose fall within this table. The services listed are independent of one another, and this site has no affiliation with or joint control over them.

1. **Entrusted processing**: the model services and region lookups above are entrusted processing. We provide the minimum necessary information and require processors to handle it as agreed. Processing a processor does for its own purposes is governed by its own privacy policy.
2. **No joint processing**: this site does not jointly decide the purposes and means of processing with any third party.
3. **Legally required disclosure**: we may disclose necessary information where required by law or by a binding order of a judicial or administrative authority.
4. **Protection of rights**: we may disclose necessary information within the limits of the law to protect the lawful rights of this site, users or the public (for example when investigating fraud or a security incident).
5. **Transfer**: if this site is merged, acquired or its operator changes, your information may be transferred as an asset; we will require the successor to remain bound by this policy.
6. Each third party above has its **own privacy policy and terms**; their collection and processing are governed by those, and this site is not responsible for them. Please read their policies before submitting information to them. All the official entries above are clickable in the table.

## 9. Special note on IP addresses

1. **Purpose**: security only (sign-in rate limiting, anti-manipulation, troubleshooting) and access-origin statistics.
2. **Masked storage**: the database keeps only the **first 3 and last 2 characters** of an IP (for example `114****89`), with the middle masked; **the full address cannot be reconstructed**.
3. **Sign-in rate limiting**: verification uses an **irreversible hash** of the IP, never the plain address.
4. **Your control**: you can **turn visit history off** at any time under "Mine → Settings". No new entries are recorded afterwards, and you may request deletion of existing ones.
5. **Region resolution**: to obtain region and carrier information an IP is sent to third-party lookup services (see section 8), which receive only the IP, never your account information.
6. **Human verification**: the primary channel is Cloudflare Turnstile, which receives your access IP and browser environment signals; our server then validates the result with Cloudflare once more and keeps only "passed or not", never anything else it returns. When the primary is unavailable a Cap fallback channel is used automatically (it likewise receives only your access IP). The check does not use cookies for cross-site tracking.
7. **Protection against collateral blocking**: a single exit address is often shared by many people (schools, companies, public networks). To avoid affecting unrelated people, a restricted origin that **you actively authorise location for** is compared with the restricted area; if clearly outside it, the access is judged to be a different person and restored. The tolerance is about **30 km** (adjustable). Refusing location keeps the normal rules and never adds restrictions.

## 10. Storage and cross-border transfer

1. **Location**: this site runs on shared hosting, and servers **may be located outside the People's Republic of China**. Using the site means you understand and agree that your information may be transferred to and processed on servers abroad.
2. **Encrypted transport**: HTTPS is enforced; HTTP requests are redirected to HTTPS to prevent interception or tampering.
3. **Cross-border compliance**: we take reasonable measures under applicable law to ensure an equivalent level of protection for personal information transferred abroad, and will follow any required outbound procedures.

## 11. Security measures

1. **Password protection**: passwords are stored as **irreversible salted slow hashes**; **the server cannot recover the plain password**.
2. **Credential isolation**: administrator credentials are kept **separately** from ordinary business data, with additional verification.
3. **Transport and storage security**: HTTPS site-wide; the database is accessed with least-privilege accounts; sensitive operations in the admin area require re-verification.
4. **Server-side validation**: all validation, normalisation and sensitive handling happen on the server; the front end only displays and passes data.
5. **Least privilege**: only the operator can access the data, and only for operational needs.
6. **Risk notice**: we take reasonable technical and organisational measures, but **no system can be absolutely secure**.
7. **Incident response**: in the event of leakage, tampering or loss, we will take remedial measures immediately and, as required by law, inform you through site announcements or page notices of the basic facts, possible impact, measures taken and what you can do; where necessary we report to regulators.
8. **Backups**: to prevent data loss an administrator may create a full backup. Backup files are stored where **they cannot be reached through a URL**, and downloading requires administrator authentication; backups are used only for disaster recovery and migration.

## 12. Retention

| Category | Retention |
| --- | --- |
| Account information | While the account exists; deleted or anonymised after closure |
| AI conversations | The most recent 200 per user; you can clear them at any time |
| Interaction content (comments / messages / feedback) | Until you delete it; sanctioned content may be kept briefly for audit |
| Chat images | Cleaned with the message lifecycle: deleted on recall, along with images no longer referenced or scrolled out of the displayed range |
| Access records | Kept for a reasonable period needed for security and audit, then removed |
| Sign-in rate-limit and security logs | Kept for a reasonable period for security |
| Database backups | Created by an administrator as needed; deleted from the server on removal, with no other copy kept |

1. Where the law requires longer retention, that applies.
2. Beyond the retention period, information is deleted or anonymised.

## 13. Your rights

Under the Personal Information Protection Law and similar laws you have the right to:

- **Be informed and decide**: know and decide how your personal information is processed, and restrict or refuse particular processing.
- **Access and copy**: see account information, AI usage and access records on the "Mine" page, and request a copy of your personal information.
- **Correct and complete**: request correction of inaccurate information through "Feedback" (the username is a unique identifier and cannot be changed once created).
- **Delete**: delete your own comments and chat messages, and request deletion of personal information in the cases the law provides (for example the purpose has been fulfilled, you withdraw consent, or our processing is unlawful).
- **Close the account**: apply to close it; afterwards we delete or anonymise the account and related data within a reasonable period.
- **Withdraw consent**: turn notifications off, turn visit history off, or sign out and continue as a guest. Withdrawal does not affect processing already carried out on the basis of consent.
- **Portability**: where technically feasible and required by law, request transfer of your personal information to a controller you designate.
- **Explanation**: ask us to explain our processing rules.
- **Complain**: complain to this site, or report to regulators as provided by law.

To exercise these rights, contact us as described above; we will respond within a reasonable period.

## 14. Protection of minors

1. This site is aimed at the general public. If you are **under 14**, use it with a guardian, who should read and accept this policy.
2. We do not **actively** collect personal information of minors under 14. If a guardian finds that we have collected such information without prior consent, contact us and we will delete it promptly.
3. Guardians with questions, or requests to access, correct or delete a minor's personal information, may contact us as described above.

## 15. Updates to this policy

1. This policy may be updated as features change or the law requires; the new version is published on this page with an updated date.
2. **Material changes** (substantive changes to purposes, categories or recipients) are announced through site announcements and similar reasonable means; where fresh consent is required, we will obtain it as the law provides.
3. Continuing to use the site means accepting the updated policy.

## 16. Disclaimer and limitation of liability

1. This site provides the service free of charge "as is". **We are not liable for leakage, loss or damage caused by your own actions, force majeure, third-party service failures, network attacks or the hosting provider**, though we will do our best to remedy it.
2. Information you **publish publicly** here (public comments, public feedback, World Chat messages) may be seen, reposted or used by others; this site is not responsible for their actions.
3. Information you voluntarily submit to third-party services while using the AI chat or web lookups is governed by those third parties' policies; this site is not responsible.
4. To the maximum extent permitted by law, this site is not liable for any indirect, incidental or consequential loss arising from the use of, or inability to use, the service.
5. This article does not exclude or limit liability that cannot be excluded or limited by law.

## 17. Definitions

1. **Personal information**: information recorded electronically or otherwise relating to an identified or identifiable natural person, excluding anonymised information.
2. **Anonymisation**: the process by which personal information can no longer identify a specific natural person and cannot be restored.
3. **De-identification**: the process by which personal information can no longer identify a specific natural person without additional information.
4. **Entrusted processing**: a controller entrusting another party to process personal information.

## 18. Contact and remedies

1. For any question, appeal, complaint or rights request about personal information, use the site's "Feedback" (optionally marked "visible to administrators only") or contact the site owner, `admin`.
2. We respond within a reasonable period, normally no more than 15 working days.
3. If you believe our processing infringes your lawful rights and you are not satisfied with our answer, you may **complain to the authority responsible for personal information protection** (the relevant laws and remedies can be found in the [National Laws and Regulations Database](https://flk.npc.gov.cn/)), or bring an action before a competent people's court.

**Related documents**: [Terms of Service](/docs/terms) · [Community Guidelines](/docs/community) · [Features](/docs/features) · [AI Guide](/docs/ai) · [Scoring Standard](/docs/scoring) · [Admission Rules](/docs/admission) · [Changelog](/docs/changelog)

---

> Last updated: 2026-10-22
> This policy, together with the [Terms of Service](/docs/terms), forms your agreement on using this service. Please read both.
