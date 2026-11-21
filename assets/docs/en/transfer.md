# International Data Transfer Notice

**Effective date**: 21 November 2026
**Applies to**: visitors and registered users of Kimi Game Rank ("the Site") located outside mainland China, in particular in the European Economic Area, the United Kingdom, Switzerland, the United States, Canada, Australia and Singapore.
**Companion document**: the Chinese-language 跨境数据传输协议 governs transfers assessed under PRC law. Where you are located outside mainland China, **this notice prevails** for your region.

> This notice is written for the legal frameworks that actually apply to you, not translated from another jurisdiction. It covers the EU GDPR (including Chapter V), the UK GDPR, the Swiss revFADP, US state privacy statutes (CCPA/CPRA and the Virginia, Colorado, Connecticut and Utah Acts), Canada's PIPEDA, Australia's Privacy Act (APP 8) and Singapore's PDPA.

## 1. Who is responsible

The Site is operated by an individual developer, not a company. For GDPR purposes the operator is the **data controller**; for CCPA purposes, a **business** that does not sell or share personal information.

Contact: use "Report a problem" on the Site (you may mark it "visible to admins only"), or reach the site owner `admin`.

**EU/UK representative.** Article 27 GDPR and Article 27 UK GDPR require a representative where a controller outside the EU/UK offers goods or services to individuals there. We consider the exemption in Article 27(2) to apply: processing is occasional, does not involve large-scale regular monitoring, and does not involve special category data. If a supervisory authority takes a different view, a representative will be appointed and this notice updated before that happens.

## 2. The shape of the processing

The Site is a small, non-commercial leaderboard. It runs on a shared hosting account outside your region, behind a global CDN, and calls a moderation model and an AI provider when you use those features. That architecture — not any business model — is the reason data crosses borders.

We do **not** sell personal information, do not share it for cross-context behavioural advertising, and run no analytics, advertising or tracking SDKs. There are no cookies for advertising; the only cookies are the session credential and a UI theme preference.

## 3. What leaves your region

| Category | Content | Why it crosses a border |
|---|---|---|
| Account data | Username, user ID, password digest (irreversible), optional email | Stored in the hosting database |
| Technical data | Access IP (kept as a salted digest), request time and path, basic browser/device headers | CDN routing, abuse prevention |
| User content | Work titles/descriptions, comments, world-chat messages, feedback, uploaded images | Stored, displayed, moderated |
| AI content | Your prompts and the context attached to them | Sent to the AI provider to produce an answer |
| Verification data | IP and browser characteristics used by the CAPTCHA, plus the pass/fail result | Bot mitigation |

We do not process special category data (Art. 9 GDPR) and do not intentionally collect data from children.

## 4. Recipients and where they are

| Recipient | Country | Data | Safeguard relied on |
|---|---|---|---|
| Shared hosting provider | Outside the EEA/UK | All stored data | SCCs (Module 2) / IDTA |
| Cloudflare, Inc. | United States, global edge | IP, headers, request path; CAPTCHA data | EU–US DPF (certified) and SCCs; UK Extension to the DPF |
| classifier.dev | United States | Text submitted for moderation, work metadata | SCCs / IDTA (see 5.4 for the DPF caveat) |
| OVHcloud AI Endpoints | France (EU) | Uploaded images | Intra-EEA transfer — no Chapter V transfer |
| Zhipu Open Platform | Mainland China | AI prompts, text and images for moderation | SCCs / IDTA plus supplementary technical measures (see 5.5) |

Sub-processors are listed because the chain should be auditable, not hidden. Each recipient is bound to use the data only to provide the service to the Site.

## 5. Transfer mechanisms, by jurisdiction

### 5.1 EEA — GDPR Chapter V

We rely on, in order of preference:

1. **Adequacy (Art. 45)** where the recipient is certified under the EU–US Data Privacy Framework. The European Commission adopted that adequacy decision on **10 July 2023**; it covers organisations on the DPF List. Cloudflare is certified. Adequacy means no additional safeguards are legally required, though we still apply the technical measures in section 7.
2. **Standard Contractual Clauses (Art. 46(2)(c))** — Commission Implementing Decision **(EU) 2021/914**, Module 2 (controller to processor), for recipients that are not DPF-certified and for the hosting provider. Clause 14 (transfer impact assessment) has been completed: we assessed the laws of the destination country, the volume and sensitivity of the data, and the likelihood of government access, and concluded that the SCCs plus the measures in section 7 provide essentially equivalent protection.
3. **Article 49(1)(a) explicit consent** is *not* our primary mechanism. It is available only for occasional, non-repetitive transfers, and a leaderboard's routine storage is neither. We mention it only where a one-off transfer genuinely cannot be covered by 1 or 2.

### 5.2 United Kingdom — UK GDPR

The UK GDPR retains the same architecture post-Brexit:

- **UK adequacy regulations** for transfers to the EEA and to countries the UK has adequacy-listed;
- the **UK Extension to the EU–US Data Privacy Framework**, in force since **12 October 2023**, for US recipients certified under the DPF and listed for the Extension (Cloudflare);
- otherwise the **International Data Transfer Agreement (IDTA)** or the **International Data Transfer Addendum to the EU SCCs**, both issued by the ICO and in force since 21 September 2022.

### 5.3 Switzerland

The revised FADP (in force 1 September 2023) follows the same logic: transfers to states with an adequate level of protection are permitted; otherwise we rely on the Swiss–EU mutual recognition of SCCs and the FADP's equivalent safeguards.

### 5.4 United States — state privacy statutes

Under US law a transfer to a server in another country is not itself a regulated "transfer"; the obligations attach to **disclosure, sale and sharing**:

- **CCPA/CPRA (California)**: we are a business that collects identifiers, internet activity and user-generated content. We **do not sell** personal information and **do not share** it for cross-context behavioural advertising, so no "Do Not Sell or Share My Personal Information" opt-out is required — nevertheless the right exists and we honour it if you assert it. We do not use or disclose sensitive personal information beyond the purposes permitted by §1798.121. We do not discriminate against you for exercising any right (§1798.125).
- **Virginia, Colorado, Connecticut, Utah**: where those Acts apply, you have rights to access, correct, delete, obtain a copy and opt out of targeted advertising, sale and certain profiling. We do not conduct targeted advertising, sale or profiling. You also have a **right to appeal** a refused request — reply to our decision and it will be reviewed by the site owner.
- **No DPF reliance for all recipients**: the EU–US DPF and its UK Extension apply only to organisations on the official list. `classifier.dev` is not on it, which is why section 5.1 relies on SCCs for that recipient.

### 5.5 Mainland China

Zhipu Open Platform is located in mainland China. Transfers there are covered by SCCs/IDTA, and the practical risk (government access under PRC law) is mitigated by data minimisation: only the text fragment under moderation is sent, never your account identifier.

### 5.6 Other English-speaking jurisdictions

- **Canada (PIPEDA)**: cross-border transfers require knowledge and consent. This notice, plus the separate tick at sign-in, provides both. You may withdraw consent at any time; processing before withdrawal remains lawful.
- **Australia (Privacy Act, APP 8)**: we remain accountable for personal information disclosed overseas and take reasonable steps to ensure the recipient does not breach the Australian Privacy Principles.
- **Singapore (PDPA s.26)**: we transfer only where the recipient is bound by legally enforceable obligations to provide a standard of protection comparable to the PDPA — the SCCs serve that function.

## 6. Your rights

### 6.1 EEA and UK (GDPR / UK GDPR)

Access (Art. 15), rectification (16), erasure (17), restriction (18), portability (20), objection (21), and not to be subject to solely automated decisions with legal effect (22 — we do not make any). You may lodge a complaint with your local supervisory authority (in the UK, the ICO). We respond within **one month**, extendable by two further months for complex requests, with notice.

### 6.2 United States

Know/access, delete, correct, obtain a copy, opt out of sale/sharing (not applicable in practice), limit use of sensitive personal information (not applicable in practice), non-discrimination, and where applicable the right to appeal. We verify requests by requiring you to be signed in to the account concerned, or by matching the email bound to it.

### 6.3 Everywhere

You can always: view and edit your profile, delete your own works and comments, request an export (JSON/SQL), and request account deletion. Use "Report a problem" and allow up to 15 working days.

## 7. Security measures that travel with the data

1. TLS everywhere — browser-to-Site and Site-to-upstream.
2. Access IPs stored as salted digests, not reversible plaintext.
3. Data minimisation at each hop: moderation receives the text under review, not your identity.
4. Admin access requires a second-factor key; sensitive actions are logged.
5. No surplus copies of moderation payloads; no training on your content by us.

## 8. Consent: what the tick actually does

At sign-in you tick a box **separate from the Terms and Privacy Policy**, reading that you have read this notice and agree to the international transfer. That tick:

- satisfies GDPR Art. 49(1)(a) where we ever need it, and evidences transparency under Art. 13/14;
- satisfies PIPEDA's knowledge-and-consent requirement;
- is **not** a substitute for the SCCs — it is an addition to them, not a replacement.

You may withdraw at any time. Because the data physically resides abroad, withdrawal stops new processing and triggers deletion on request — it cannot repatriate data instantaneously. Withdrawal does not affect prior lawful processing.

## 9. Retention

Account data: until account deletion. Access logs used for security: 30 days, then aggregated. Moderation payloads: not retained separately. Backups: 30 days rolling.

## 10. Children

The Site is intended for users **aged 16 and over** (13 in the United States, per COPPA). Where an EU Member State sets a lower digital age of consent, the local rule applies but we do not knowingly process data of anyone under 13. Guardians may request deletion.

## 11. Changes

Material changes — new recipients, new categories, new purposes — are announced on the Site and trigger a fresh tick. We do not rely on "continued use means acceptance" for transfers.

## 12. Law and forum

This notice is governed by the laws of the People's Republic of China, **without prejudice to** any mandatory right you have under the law of your own country, including GDPR Chapter V, UK GDPR, the CCPA and APP 8. If you are an EEA or UK data subject, nothing here limits your right to complain to your supervisory authority or to seek a remedy in your local courts.

**Related documents**: [Terms of Service](/docs/terms) · [Privacy Policy](/docs/privacy) · [Data Transfer Notice](/docs/transfer) · [Community Rules](/docs/community) · [Features](/docs/features) · [AI Usage Notes](/docs/ai) · [Changelog](/docs/changelog)

---

> Last updated: 21 November 2026
> Where this notice and the Chinese-language 跨境数据传输协议 differ, this notice governs for users outside mainland China.
