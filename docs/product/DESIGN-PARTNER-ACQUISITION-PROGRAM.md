# WP Command Center — Design Partner Acquisition Program (PROGRAM-5)

> **Type:** Customer-discovery & design-partner acquisition. **Report only — no code, architecture, rollback, runtimes, MCP, security, DB, or infra work.**
> **Date:** 2026-06-24 · **Production HEAD:** `2657810` (Program-4 CLOSED) · **Plugin version:** `0.1.0`.
> **Authoritative inputs:** [`PRODUCT-REALITY-AUDIT.md`](PRODUCT-REALITY-AUDIT.md) · [`PRODUCT-MARKET-FIT-DISCOVERY.md`](PRODUCT-MARKET-FIT-DISCOVERY.md) · [`PRODUCT-MASTER-PLAN.md`](PRODUCT-MASTER-PLAN.md) · [`SESSION-HANDOFF-PHASE-3.md`](SESSION-HANDOFF-PHASE-3.md) · [`../governance/program-4/RUNNING-STATE.md`](../governance/program-4/RUNNING-STATE.md).
> **Objective:** acquire 3–5 real design partners and validate demand / willingness-to-pay. **Optimize for learning, not building.**
> **Stance:** brutally honest, assumption-challenging. Where a number is an estimate not evidence, it is labelled **[ESTIMATE — no data]**.

---

## 0. Three hard truths this program is built on (read first)

1. **The dominant risk is demand, and the cheapest test of demand is a conversation, not a demo.** This program front-loads *talking to people* over *showing them things*. Building a polished demo before confirming the pain is real would be the same mistake the product already made at the engineering level.

2. **The premise is unproven and may be wrong.** WPCC governs a behavior — AI agents mutating *client* WordPress sites — that very few people actually do in mid-2026. The first job of this program is not to sell; it is to find out whether the pain even exists outside the founder's head. *If we can't find 10 people who already feel it, the answer to PMF is "not yet," and that is a valid, valuable finding.*

3. **There is no self-serve onboarding and no human "do-it" UI (Reality Audit #3/#4).** Therefore every design-partner onboarding in this program is **concierge / white-glove** — the founder sets it up *with* or *for* the partner on a call. This is correct at this stage (concierge is how you learn), but it caps the partner pool at people technical enough to have an AI agent, and it means **this does not scale yet — and shouldn't.** The goal is 5 deep relationships, not 50 shallow signups.

---

## DELIVERABLE 1 — Design Partner Definition

### Qualifies as a design partner (ALL must be true)
- **Already uses AI in real WordPress work** (Claude/Cursor/ChatGPT/an MCP setup) — the premise must be true *for them*, or there is nothing to govern.
- **Manages real *client* sites** (not just their own hobby site) — "I might break a client's site" is the fear WPCC sells against; without clients there's no fear.
- **Can describe a specific moment** AI safety/auditability worried them on a real site (past behavior, not hypothetical interest).
- **Technical enough to connect an agent + accept a concierge setup** without needing a finished UI.
- **Willing to spend ~2 hours over 30 days** (kickoff + run + debrief) and be honest, including saying "this is useless."

### Disqualifies (any one kills it)
- **Doesn't currently use AI on client work** — they'd be evaluating a future, not a present pain. Polite interest, zero signal.
- **Wants fleet management / backups / updates** as the primary need — that's ManageWP/MainWP's job; WPCC is weakest there. Wrong product.
- **Non-technical** (can't or won't touch an agent/token) — cannot reach value with today's product.
- **Enterprise/procurement-bound** — wrong timing; a 6-month "no."
- **The founder's employee, co-founder, or closest friend** — their "yes" doesn't test the market. (At least 3 of 5 partners must be arms-length.)
- **"I'll look when it's on wp.org / when it's free / when it's mature"** — a tire-kicker, not a partner.

### Required characteristics
Active AI-in-WordPress practice · ≥3 client sites · a nameable past pain · technical self-sufficiency · honesty + time commitment.

### Nice-to-have characteristics
- A **content/SEO-heavy** book of business (the killer workflow lands hardest there).
- An **audience** (writes/streams/posts about WP+AI) — doubles as a distribution asset.
- **Accessibility or SEO** specialism (alt-text/meta are billable services for them).
- **Opinionated** — vocal partners give better feedback than agreeable ones.
- Runs **Rank Math or Yoast** (the SEO workflow is prod-proven there).

### Minimums (brutally realistic)
- **Minimum sites:** **3 client sites.** Below that, the "break a client site" fear is too weak to drive behavior. One-site owners are out.
- **Minimum AI experience:** has **personally run an AI tool that changed a WordPress site** at least once. Not "interested in AI" — has *done it*.
- **Agency vs freelancer:**
  - **Freelancer/solo operator:** ideal if AI-forward and ≥3 clients — low friction, fast decisions, founder-reachable. *Primary target.*
  - **Boutique agency (2–10):** ideal if there's a single AI-forward decision-maker who can say yes without a committee. *Primary target.*
  - **Mid/large agency (10+):** generally **out** for this program — org inertia, committee buying, and they want fleet. One exception: a *named* innovation lead with autonomy and a real AI practice.

### The honest constraint
Realistic addressable pool **today** = *technical, AI-forward freelancers and boutique-agency operators who already mutate client WordPress with AI.* That is a **small** population. Finding 5 is plausible through warm + community channels; finding 50 is not, and trying would mean lowering the bar to people without the pain — which would corrupt the learning.

---

## DELIVERABLE 2 — First 25 Candidate Profiles (ranked)

> Profiles, not names. Ranked by fit (S = best beachhead → C = weak/avoid). "Fit" = already has the pain + low friction to value. "Fail" = why they might not convert.

### Tier S — the beachhead (pursue first)
| # | Profile | Why they fit | Why they might fail |
|---|---|---|---|
| 1 | **AI-forward solo WordPress consultant** (uses Claude/Cursor on client sites) | Lives the exact pain daily; no UI needed; decides alone; fast | Small budget; may free-ride; tiny revenue ceiling |
| 2 | **Technical SEO consultant** (many client sites, hands-on) | SEO meta = the killer workflow = their billable service; reversibility de-risks it | Has strong opinions/own tooling; may find AI meta "good enough only" |
| 3 | **Boutique AI-first WordPress agency (2–10)** | AI-native, builds-in-public, one decision-maker; ideal reference logo | May be building their own thing; novelty-chaser churn |
| 4 | **Accessibility-focused consultant/agency** | Alt-text = compliance revenue; lowest-risk on-ramp; undeniable value | Niche; may want full a11y suite, not just alt-text |
| 5 | **Freelance WP developer who ships custom plugins/themes + tinkers with AI** | Technical, fearless, already automating; gets the governance instantly | May prefer to roll their own; "I could build this" |

### Tier A — strong, more friction (pursue second)
| # | Profile | Why they fit | Why they might fail |
|---|---|---|---|
| 6 | **Content-heavy WordPress agency** (high post volume) | Alt-text + SEO at scale = real time saved | Content team non-technical; needs the missing UI |
| 7 | **Local SEO agency** (many small-biz sites) | Bulk meta across many thin sites = direct value | Low-touch clients; price-sensitive; volume over depth |
| 8 | **White-label WordPress service provider** | Does bulk work for other agencies; efficiency-obsessed | Margin-driven; won't pay unless it clearly cuts cost |
| 9 | **WordPress care-plan / maintenance provider** | Recurring client sites; values audit trail + undo | Primary need is updates/backups (WPCC's weak spot) |
| 10 | **Multilingual/translation-focused agency** | Bulk meta/alt-text across languages = heavy repetitive work | Translation QA concerns; AI-quality skepticism |
| 11 | **Migration/cleanup freelancer** | Does bulk metadata/content fixes; one-off intensity | Project-based, not recurring → weak subscription fit |
| 12 | **WP educator / dev-rel with an audience** | Reference + distribution asset; understands the value | Wants it free as "content"; may not be a real operator |
| 13 | **Technical marketer at a small agency** (codes a bit) | Bridges content value + technical setup | Not the buyer; needs sign-off |
| 14 | **Independent WP product maker who also consults** | Technical, AI-curious, owns client sites | Distracted by own product; low time |
| 15 | **Small WooCommerce agency** | Catalog/description ops at scale | **Woo dormant on prod — postpone this workflow;** orders irreversible |

### Tier B — plausible, weaker (only if inbound)
| # | Profile | Why they fit | Why they might fail |
|---|---|---|---|
| 16 | **Mid-size agency "innovation" lead** | Budget + sites if autonomous | Committee, inertia, wants fleet |
| 17 | **Membership/LMS site operator** | Lots of structured content | Single site; niche AI fit |
| 18 | **Technical WooCommerce store owner** | Catalog pain is real | One site; low recurring need; Woo dormant |
| 19 | **SaaS marketing-site owner** (technical) | SEO meta value | One site; not the ICP |
| 20 | **Hosting reseller / small host** | Many sites | Wants white-label/fleet → partnership, not a partner |

### Tier C — avoid for this program
| # | Profile | Why listed | Why it fails |
|---|---|---|---|
| 21 | **Enterprise WP team pilot** | Governance resonates philosophically | Wrong timing; procurement; security review |
| 22 | **Non-technical marketing-agency owner** | Has sites + budget | Can't wire an agent; needs the missing UI |
| 23 | **Nonprofit web volunteer** | Real accessibility pain | No budget; not a buyer |
| 24 | **In-house dev at a publisher** | Technical | Not a buyer; procurement |
| 25 | **WordPress hobbyist/enthusiast** | Enthusiastic, reachable | No client pain, no money → false-positive signal |

**Targeting rule:** spend ~80% of outreach effort on **Tier S (1–5)**, ~20% on **Tier A (6–14)**. Tier B/C only if they come to you. **Profiles 21–25 will generate the most flattering conversations and the least valid signal — treat their enthusiasm as noise.**

---

## DELIVERABLE 3 — Design Partner Outreach System

> Goal: 3–5 qualified partners. To get there, target **~10–15 real conversations** with qualified people. Channel mix is built around the founder's only real assets: WordPress credibility + relationships.

### 3.1 Warm outreach (primary channel — highest yield)
- **Who:** Tier S/A people the founder already knows or is one intro away from.
- **How:** personal 1:1 message referencing a *specific* thing you know they do. Ask to **learn, not pitch** (see Deliverable 4D).
- **Rates [ESTIMATE — no data]:** response **40–60%** · conversation **30–45%** · partner conversion (of conversations) **30–50%**. → ~6–8 warm touches can yield 2–3 partners.
- **Risk:** warm contacts are often **traditional agencies (the trap ICP)** — filter hard on "do you actually run AI on client sites?" A warm "yes" from someone without the pain is worse than a cold "no" — it produces false validation.

### 3.2 Community outreach (secondary — best for arms-length signal)
- **Where AI-forward WP people actually are:** WP+AI corners of X/Twitter, Post Status (Slack), MCP/Claude developer communities, WP agency Discords/Slacks, r/WordPress AI threads, indie-hacker WP circles.
- **How:** **lead with the 90-second proof video** + an honest "looking for 3–5 people who run AI on client sites to try this and tell me if it's useless." The artifact does the talking.
- **Rates [ESTIMATE — no data]:** engagement highly variable; **1–2 qualified partners** from a well-targeted post + a few DMs is a realistic ceiling. Most responders will be Tier C enthusiasts — qualify ruthlessly.
- **Risk:** noise, hobbyists, "cool project" with no intent; perceived self-promotion if not framed as genuine ask.

### 3.3 Cold outreach (tertiary — low ROI, treat as learning not acquisition)
- **Who:** Tier S strangers found via their public AI-WordPress content (a blog post, a tweet, a talk).
- **How:** short, specific, learning-framed (Deliverable 4A/4C). Reference the *exact* post that shows they have the pain.
- **Rates [ESTIMATE — no data]:** response **1–5%** · partner conversion of responders **10–20%**. → getting even 1 partner cold needs **100+ targeted touches.**
- **Honest verdict:** **cold is the wrong primary channel for a solo founder.** Use it sparingly, mostly to *learn the language* people use for the pain (which sharpens warm/community messaging). Do not build the program on it.

### 3.4 Referral (compounding — switch on after first value)
- **When:** the moment a partner hits a value moment (Deliverable 6/7), ask: *"Who else do you know who runs AI on client WordPress and would get value from this?"*
- **Why best:** referrals arrive pre-qualified (the referrer already filtered for the pain) and pre-trusted.
- **Rates [ESTIMATE — no data]:** referral → conversation **50–70%** · → partner **40–60%**. Highest quality of any channel.
- **Risk:** none if value is real; if partners *aren't* referring, that itself is a **negative PMF signal** (Deliverable 7) — people refer things that helped them.

### Channel priority
**Warm → Community → Referral (after first value) → Cold (learning only).** Expect the 3–5 partners to come overwhelmingly from warm + community + referral.

---

## DELIVERABLE 4 — Outreach Assets

> Principles: short, human, **no hype, no AI buzzwords, no marketing fluff.** Every message asks to *learn*, not to sell. (Mom-Test discipline: pitching contaminates the signal.)

### A. LinkedIn message
> Hi [name] — I saw you've been using AI with WordPress on client work, which is still rare. I'm trying to understand how people handle AI making changes on a client's site — knowing what changed, being able to undo it. Not selling anything; I'd genuinely like to learn from how you do it. Open to 15 minutes this week?

### B. Facebook (group/DM)
> Hey [name] — you mentioned using AI on client WordPress in [group]. I'm looking into the "what did it just change, and can I undo it" problem. Would you be up for a quick chat about how you deal with that? Just trying to learn, no pitch.

### C. Direct email
> **Subject:** quick question about AI on client WordPress
>
> [name] — you work with both WordPress and AI, which is an unusual combination. I'm researching how people let AI make changes on client sites without breaking things. I'm not selling anything — I'd like to learn from your experience. 15 minutes this week?
>
> — Mosharaf

### D. Personal network
> Hey [name] — quick one. You know I build WordPress tools. I'm testing an idea and I need honest input from someone who actually runs client sites. Can I show you something for 5 minutes and have you tell me if it's useless? I'd rather hear "no" from you than from a stranger.

### E. Follow-up (after 7 days, send once)
> [name] — following up once in case this slipped past. Still just looking to learn, no pitch. If now isn't a good time, no problem at all — is there anyone you'd point me to who's hands-on with AI and WordPress?

*(Note the built-in referral ask in E — every dead end becomes a possible intro.)*

---

## DELIVERABLE 5 — Demo Strategy

> Rule for all three: **make the value obvious, never show sophistication.** The buyer cares about "AI did the work and I could undo it," not about 40 operations, MCP, capability matrices, or rollback internals. **Show the job. Hide the machine.**

### 5.1 The 90-second demo (async video — the GTM engine)
**Purpose:** stop the scroll, create one "oh, that's the thing I was scared about" moment. This is the single most important asset in the program.

| Time | Show | Say (one line) |
|---|---|---|
| 0:00–0:10 | A real client site | "I wanted AI to fix the alt-text on this client site — but I was scared of what it'd change." |
| 0:10–0:35 | AI proposes alt-text across many images (a list of proposals) | "It read every image and proposed alt-text — but it hasn't touched anything yet." |
| 0:35–0:55 | The review/approve step (clearly *not* auto-applied) | "I review, then approve. Nothing changes without me." |
| 0:55–1:15 | Click **undo** on one change + the change-history/audit list | "If I don't like one, I undo it — one click. And I can see exactly what changed, and when." |
| 1:15–1:30 | One closing frame | "Safe AI for client WordPress. Review and undo anything. If you run AI on client sites, I'd love 5 minutes." |

**Do NOT show:** the admin menu sprawl, the operations catalogue, MCP/JSON, tokens/scopes, security-mode internals, any settings screen, the word "governance."

### 5.2 The 5-minute demo (live or recorded, for a warm/qualified prospect)
**Purpose:** turn interest into "try it on one of my sites."
1. **0:00–0:30 — Their pain, in their words.** Ask first: "When you've used AI on a client site, what worried you?" Let them say it.
2. **0:30–2:00 — Alt-text loop** (the 90-second arc, slower): propose → review → approve → **undo** → audit trail.
3. **2:00–4:00 — SEO meta loop** on a real page: show one *before/after*, the review gate, and the undo. (This is the value they'd pay for.)
4. **4:00–5:00 — The ask:** "Want to try this on one of your own client sites this week? I'll set it up with you on a call." Stop. Let them answer.

**Do NOT show:** the full op list, workflows, plugin/theme/update features, dormant Woo/Elementor, anything irreversible, internal docs.

### 5.3 The 15-minute onboarding demo (concierge — do it *with* them)
**Purpose:** get the partner to a real value moment on a real site, founder-driven.
1. **Pick a low-stakes real client site** + **one workflow (alt-text first).**
2. **Concierge setup (founder drives):** connect their agent / set their BYO key. *Set a client-safe approval mode — NOT developer self-approve.* (This is a config step, not engineering.)
3. **Run alt-text together:** propose → review → approve a few → **undo one** → show change history.
4. **Run SEO meta on 1–2 pages:** show the before/after + undo.
5. **Agree the next step:** "Over the next 2 weeks, run this on [N] more pages/sites and note what saves you time and what annoys you." Book the debrief.

**Do NOT show:** the whole menu, every operation, the governance documentation, the security-mode matrix, the catalogue. **One site, one workflow, one value moment.** Confusion kills concierge onboarding faster than missing features.

---

## DELIVERABLE 6 — Design Partner Interview Framework

> Discipline: **non-leading, behavior-based, no pitching during questions.** Ask about what they *did*, not what they *would* do. Never ask "would you use X?" — ask "when did you last face this, and what did you do?"

### Before onboarding (discovery — is the pain real?)
- "Walk me through the last time you used AI to change something on a client site. What happened?"
- "What did you do right before and right after letting it make the change?"
- "Has AI (or any tool) ever changed something on a site you didn't expect? What did you do?"
- "How do you know today what changed on a client site, and who changed it?"
- "If a client asked 'what did you change last Tuesday,' how would you answer?"
- "What's the riskiest part of using AI on client work for you?"
- *(Listen for: a real, recent, emotional incident. No incident = weak pain.)*

### During onboarding (friction — where does it break?)
- "Tell me what you think is about to happen." (before the run)
- "What are you looking at right now? What's confusing?"
- "Would you trust this on a real client page right now? Why / why not?"
- (Silence — watch where they hesitate; note it, don't rescue it.)

### After first use (value & trust)
- "What just happened, in your words?"
- "What would you have done instead, without this?" (the counterfactual = the value)
- "Did the review/undo feel useful, or like it got in your way?" *(the make-or-break: is governance value or friction?)*
- "What's the first thing you'd want to do next with it?"
- "Who else do you know who'd want to see this?" (referral test)

### After 30 days (adoption & willingness-to-pay)
- "How many times did you actually use it? On what?" (behavior, not opinion)
- "What made you come back / what stopped you?"
- "If it disappeared tomorrow, what would you do instead?" *(strength-of-need test)*
- "Have you told anyone about it? What did you say?"
- **WTP (non-leading):** "What would you expect something like this to cost?" → "At what price would it be an easy yes?" → "At what price would you walk away?" → "What would have to be true for you to pay for it?"
- *(Never propose a price first. Let them anchor.)*

---

## DELIVERABLE 7 — Validation Scorecard

> Score each partner 0–2 per dimension (0 = absent, 1 = partial, 2 = strong). Track per partner and in aggregate across the 3–5.

| Dimension | What to measure | 0 (Failure) | 1 (Weak) | 2 (Strong) |
|---|---|---|---|---|
| **Activation** | Completed ≥1 real governed run on a real site | Never ran it | Ran only in the concierge call | Ran it again, alone, after the call |
| **Time-to-value** | Time from setup to first "useful" run | Never reached value | Days, with prompting | Same session, unprompted "oh nice" |
| **Trust** | Behavior, not words | Wouldn't run on a client site | Ran on a throwaway site only | Ran on a *real client* site willingly |
| **Repeat usage** | Runs in 30 days without prompting | 0–1 | 2–4 | 5+ across multiple sites |
| **Referrals** | Unprompted or warm intros given | None | Named someone vaguely | Made an actual intro |
| **Willingness to pay** | Concrete reaction to price/founding offer | "Only if free" | "Maybe, someday" | Said yes / put money down |

### Aggregate signal thresholds (across 3–5 partners)
- **Failure (kill/rethink):** most partners ≤1 on Activation/Trust/Repeat; nobody refers; WTP universally "only if free." → *the pain isn't strong enough; the premise (§0) is likely wrong.*
- **Weak signal (iterate, don't scale):** partners activate but don't repeat; like it but wouldn't pay; governance felt as friction. → *a vitamin, not a painkiller — narrow the workflow or the ICP and retest.*
- **Strong signal (lean in):** ≥3 partners repeat unprompted, ≥2 ran on real client sites, ≥1 referral, ≥2 positive WTP. → *real demand in this beachhead; proceed toward packaging.*
- **PMF signal (rare, decisive):** partners use it weekly without prompting, refer proactively, ask "how do I pay / can I add more sites," and would be "very disappointed" to lose it. → *the wedge is found; commercialize.*

**The single most predictive metric:** **unprompted repeat usage on a real client site.** Everything else can be politeness. Behavior is truth.

---

## DELIVERABLE 8 — Pricing Discovery Framework (gather evidence, do NOT set price)

> Output is **evidence about willingness-to-pay**, not a price. A price chosen before this data is a guess (see PMF Discovery §7 — the per-site/BYO-key hybrid is a *hypothesis to test*, not a decision).

### What to discover
1. **What they'd pay** — a range, anchored by them, not us.
2. **Why** — what value they're pricing against (time saved? risk avoided? client-billable service?).
3. **Preferred model** — per-site, per-seat, flat, usage, hybrid — in *their* mental model.
4. **Refusal triggers** — what makes it an instant "no."

### Methods (evidence, not opinion)
- **Counterfactual anchoring (strongest):** "What do you do today instead, and what does that cost you in time/money?" → the value is the gap, in their numbers.
- **Van Westendorp-style four questions** (after 30 days, never before value): at what price is it *too expensive* / *expensive but worth considering* / *a bargain* / *so cheap you'd doubt it?* → reveals an acceptable range without us anchoring.
- **The founding-offer reaction test:** present a concrete founding price *once* and **watch behavior**, not words — do they hesitate, negotiate, ask for an invoice (strong), or go quiet (weak)?
- **Model-preference probe:** "If you paid for this, would you rather pay per client site, a flat monthly, or per use? Why?" → captures the unit *they* think in (likely per-site, matching ManageWP habits — but let them say it).
- **Refusal mapping:** "What would make this not worth any price?" → surfaces dealbreakers (cost on top of their AI key, trust, the missing UI, etc.).

### Evidence quality ladder
- **Weakest:** "I'd probably pay something." (Ignore.)
- **Medium:** a specific number tied to a specific value ("I'd pay $X because it saves me Y hours").
- **Strongest:** *actually paid* a founding fee, or *asked to add more sites.* Money/expansion behavior > any stated number.

**Rule:** do not publish a price, a pricing page, or a Free/Pro split until ≥3 partners have given **medium-or-better** WTP evidence and at least one has shown **strongest-ladder** behavior.

---

## DELIVERABLE 9 — 90-Day Execution Plan

> One founder, no funding. **Every week ends in a learning, not a build.** The only "product" work permitted is *configuration to make the existing alt-text/SEO loop demonstrable* (turning on flags, setting a BYO key, picking content) — not engineering.

### Phase 1 — Validate the pain (Weeks 1–3)
| Wk | Objective | Activities | Success metric | Decision gate |
|---|---|---|---|---|
| 1 | **Confirm pain is real before building anything** | Build a list of ~15 Tier-S/A real people; send 5 warm + personal messages (Deliverable 4D/A) **asking to learn, not pitch** | ≥3 conversations booked | **GATE A:** if 0 of ~10 qualified people describe a real AI-on-client-site pain → *stop and rethink the premise.* |
| 2 | Hear the pain in their words | Run 3–5 discovery interviews (Deliverable 6 "before"); capture the *exact language* they use | ≥3 describe a specific past incident | If pain is vague/hypothetical → narrow ICP, retest before proceeding |
| 3 | Make the loop demonstrable | Configure the alt-text → SEO loop on the founder's own site with a real key; **record the 90-second video** | A video that makes one person say "oh, that's my problem" | If you can't make the value obvious in 90s, the wedge is wrong |

### Phase 2 — Recruit & onboard partners (Weeks 4–7)
| Wk | Objective | Activities | Success metric | Decision gate |
|---|---|---|---|---|
| 4 | Recruit with the video | Send the video to warm + community lists; book concierge onboardings | ≥3 onboardings booked | **GATE B:** <2 willing to try on a real site → demand is weak; investigate why before more outreach |
| 5–6 | Get partners to first value | Run 15-min concierge onboardings (Deliverable 5.3); one site, one workflow, real value moment; set client-safe mode | ≥3 partners reach a value moment; ≥2 run on a *real client* site | If partners stall in setup → the friction *is* the finding; document it |
| 7 | Capture first signals + first testimonial | "After first use" interviews; ask the referral question | ≥1 testimonial; ≥1 referral | — |

### Phase 3 — Adoption, WTP & decide (Weeks 8–13)
| Wk | Objective | Activities | Success metric | Decision gate |
|---|---|---|---|---|
| 8–10 | Watch real behavior | Partners use it on their own sites; track unprompted repeat usage; light-touch check-ins | ≥3 partners repeat *unprompted*; ≥2 on real client sites | **GATE C:** if usage is only prompted → it's a vitamin; stop scaling, re-narrow |
| 11 | Pricing evidence | "After 30 days" interviews + WTP methods (Deliverable 8) + one founding-offer reaction test | ≥3 medium+ WTP signals; ≥1 strongest-ladder behavior | — |
| 12 | First paying customer | Founding-member offer to partners who hit value | ≥1 paid founding customer | — |
| 13 | Synthesize & decide | Score all partners (Deliverable 7); write the verdict | A clear PMF read | **GATE D (the big one):** see below |

### At day 90 — what the result means
- **PMF is emerging (proceed):** ≥3 partners repeat unprompted, ≥2 ran on real client sites, ≥1 referral, ≥2 positive WTP, ≥1 paid. → *the beachhead is real; next program = make it self-serve usable + package.*
- **The idea is weak (iterate):** partners activate but don't repeat; like it but won't pay; governance felt as friction; no referrals. → *vitamin, not painkiller. Re-narrow the ICP or the workflow and run one more 30-day loop — do not build more.*
- **Stop is justified:** you cannot find ~10 qualified people with the pain (GATE A fails), or <2 will try it on a real site (GATE B fails), or zero repeat usage and zero WTP after genuine effort. → *the premise (§0) is wrong for now. WPCC is early. Shelve commercialization; the honest finding is "the market isn't here yet," and that is worth more than months of building for it.*

---

## DELIVERABLE 10 — Final Recommendation

### "If Mosharaf can work on only ONE thing next, what should he do tomorrow morning?"

**Tomorrow morning: do NOT touch the code, the demo, or the product. Open a blank doc, list the 10–15 real people you personally know (or are one intro from) who *already run AI on client WordPress sites*, and send the first 3 of them the personal-network message (Deliverable 4D) — asking to learn, not to pitch.**

**Why this and nothing else:**

1. **It tests the only thing that matters, at zero cost.** The dominant risk is "does anyone want this?" The cheapest possible test is three honest conversations with people who'd have the pain — not a video, not a feature, not a pricing page. If three AI-forward operators you respect can't recall a real moment AI scared them on a client site, **you've learned the premise is shaky before spending a single hour building** — and that is the most valuable outcome available tomorrow.

2. **It resists the trap you're standing in.** After four engineering programs, every instinct says "make the demo perfect first." That instinct is exactly what got the product to N=1 with a dormant flagship. The demo (Week 3) matters — but *only after* you've confirmed there's a pain worth demoing. Conversations before artifacts. Learning before building.

3. **It's the one action with no dependencies and no excuses.** It needs no key, no flag, no UI, no funding, no setup — just the founder's network and the courage to ask a real question and hear a real answer, including "no." Everything else in this 90-day plan hangs off what those first conversations reveal.

**The brutally honest framing:** WPCC's problem was never that it couldn't be built. It's that nobody has confirmed it should be. The bravest and highest-leverage thing Mosharaf can do tomorrow is not write another line or polish another screen — it's to **risk hearing that the pain isn't real, by asking three people who would know.** If the pain is real, this program runs. If it isn't, he just saved himself a year. Either way, tomorrow morning's three messages are worth more than anything he could build.

*Report only. No code, architecture, rollback, runtimes, MCP, security, database, or infrastructure work proposed. Customer-discovery, acquisition, onboarding, validation, and pricing-discovery exclusively.*
