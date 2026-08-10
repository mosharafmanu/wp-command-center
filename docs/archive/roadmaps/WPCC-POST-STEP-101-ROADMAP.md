# WP Command Center — Post-STEP-101 Architecture Review & STEP 102+ Roadmap

> Status: planning document (no code). Produced after STEP 101 (patch architecture
> deployed & production-validated). Indicative step numbers; STEP 102 is already
> partially consumed by the rollback-contract remediation (commit `a819f4f`).

---

## 1. Where WPCC stands today

After STEP 89→101, WPCC is no longer a "WordPress automation plugin." It is an
**AI-agent operations platform for WordPress** with a property almost nothing else
in the ecosystem has: **every mutation is structured, capability-scoped,
approvable, auditable, and reversible.**

**Production-validated foundation:**

- **~39 MCP tools / ~30 runtimes** spanning content, media (+enhancement), SEO
  (Rank Math/Yoast), ACF, WooCommerce (products/orders), Elementor, site builder,
  menus, widgets, CPT, comments, forms, users, options, settings, plugins, themes,
  search/replace, bulk ops, database inspection, reporting.
- **Safety spine:** Patch Engine (now with precise modes), Snapshot + Rollback
  (standardized contract), PatchGuard (header protection), syntax verification,
  DestructiveGuard (confirmation handshake), Security Modes
  (developer/client/enterprise), capability tokens, append-only audit log,
  self-heal.
- **Agent lifecycle:** sessions → tasks → plans → actions → patches, unified
  timeline, workflow runtime with single-approval execution + on-failure rollback.
- **Dual surface:** REST + MCP over the same OperationExecutor, token-only auth.

**This is the moat.** The hard, unglamorous infrastructure (reversibility,
approval gating, auditability, capability scoping) is built and proven. That is
exactly what competitors lack and what enterprises require.

---

## 2. Competitive comparison: WPCC vs AI Engine (MCP)

AI Engine (Meow Apps) is the most visible WP plugin with MCP. But the two products
sit on **opposite sides of the value chain.**

| Dimension | **WPCC** | **AI Engine MCP** |
|---|---|---|
| Core identity | Safe agent **operations layer** for WP | **AI features** for WP (chatbot, content/image gen) + MCP access |
| MCP tool breadth (structured WP ops) | ~39 typed runtimes | Smaller; exposes WP functions/REST more generically |
| **Approval workflow** | ✅ 3 security modes, human gate | ❌ |
| **Rollback / snapshots** | ✅ per-op + patch rollback | ❌ |
| **Patch engine (precise edits)** | ✅ 6 modes, syntax-verified, reversible | ❌ |
| **Capability-scoped tokens** | ✅ per-capability, read/write scopes | Basic key/bearer |
| **Audit trail** | ✅ append-only, full timeline | Limited/logs |
| **Destructive guardrails** | ✅ confirmation handshake | ❌ |
| AI content/image generation | ❌ | ✅ (strong) |
| Embeddings / semantic search | ❌ | ✅ |
| Chatbot / on-site assistant | ❌ | ✅ |
| Multi-provider AI | ❌ | ✅ |
| Commercial maturity (licensing, Pro tiers) | ❌ **(top gap)** | ✅ established |
| Brand / install base | Early | Large |

**Strategic read:** Do **not** chase AI Engine on content generation or chatbots —
that is a crowded, model-commoditized space. WPCC wins by being the layer those
tools are *unsafe without*: the trustworthy execution substrate for any AI agent
acting on a real WordPress site. The right framing is **"WPCC is to WordPress what
a safe deploy/rollback + RBAC + audit system is to production infra."**

**Two real gaps worth closing selectively:** (1) **semantic/code-aware retrieval**
(improves every agent interaction and neutralizes AI Engine's embeddings edge in
the operations context), and (2) **commercialization** (no Free/Pro gating = no
revenue capture for a production-ready product).

---

## 3. Prioritization framework

Each candidate scored 1–5 on **Business Value (BV)**, **MCP Usefulness (MU)**,
**Commercial Advantage (CA)**, and **Implementation Complexity (IC, lower =
easier)**. ROI ≈ (BV + MU + CA) / IC.

| # | Initiative | BV | MU | CA | IC | ROI | Tier |
|---|---|---|---|---|---|---|---|
| 1 | **Licensing & Free/Pro gating** | 5 | 1 | 5 | 2 | 5.5 | **Now** |
| 2 | **Atomic multi-file change sets** (transactional patch + single approval + combined rollback) | 5 | 5 | 4 | 2 | 7.0 | **Now** |
| 3 | **Change history & one-click rollback UI** | 5 | 2 | 4 | 2 | 5.5 | **Now** |
| 4 | **Semantic/code-aware search index** (retrieval over content + code) | 4 | 5 | 4 | 4 | 3.25 | Next |
| 5 | **Staging→production promote** (apply to staging, diff, promote) | 5 | 4 | 5 | 4 | 3.5 | Next |
| 6 | **Proactive maintenance agent** (scheduled health → recommendation → gated auto-fix) | 4 | 4 | 4 | 3 | 4.0 | Next |
| 7 | **Whole-site snapshot & restore** (DB + files DR) | 4 | 3 | 4 | 4 | 2.75 | Later |
| 8 | **Security & performance audit runtimes → actionable patches** | 4 | 4 | 4 | 3 | 4.0 | Next |
| 9 | **MCP "skills"/prompt templates + resources** (guided WP playbooks) | 3 | 5 | 3 | 2 | 5.5 | **Now** |
| 10 | Thin AI-provider bridge (optional content assist) | 3 | 3 | 2 | 3 | 2.7 | Later |

---

## 4. Roadmap (STEP 102+)

> STEP 102 is already partially consumed by the rollback-contract remediation
> (commit `a819f4f`). The phases below pick up from there; step numbers are
> indicative.

### Phase A — Capture value & harden the moat (STEP 102–105) — *highest ROI, low complexity*

**A1. Atomic multi-file change sets (STEP ~103).** Today a patch can touch multiple
files, but the agent experience and rollback should be explicitly *transactional*:
one proposal, one approval, all-or-nothing apply, one combined rollback id. This is
the single highest-MCP-value upgrade — real fixes span several files, and it makes
the agent loop dramatically faster and safer. Builds directly on the just-shipped
patch modes + standardized rollback contract.
- *Success metric:* a 3-file fix applies/rolls back as one unit; zero
  partial-apply states.

**A2. Change History & one-click rollback UI (STEP ~104).** Surface the existing
audit/timeline + snapshots in an admin screen: who/what/when, diff view, and a
Restore button per change. Converts the invisible safety infrastructure into a
**visible selling feature** ("every AI change is reversible from one screen").
Almost entirely a read/presentation layer over data you already store.
- *Success metric:* any applied change reversible in ≤2 clicks; non-technical
  admin can audit an agent session.

**A3. Licensing & Free/Pro gating (STEP ~105).** The top product gap. Gate advanced
runtimes (WooCommerce, multi-file change sets, staging, audit-fix, future AI
bridge) behind Pro; keep core read + single-file patch + rollback in Free. Without
this there is no revenue capture on a production-ready platform.
- *Success metric:* license check enforced server-side; Free tier still genuinely
  useful; clean upgrade path.

**A4. MCP Skills / prompt templates + resources (STEP ~105, parallel).** Ship
curated MCP prompts/resources ("safe plugin update with rollback," "fix a fatal,"
"bulk SEO cleanup," "Woo price update") that orchestrate existing tools. Cheap,
high MCP-usefulness, and a differentiator that makes WPCC feel like a *guided*
agent platform rather than a tool dump.

### Phase B — Differentiate on intelligence & trust (STEP 106–110) — *mid complexity, high CA*

**B1. Semantic/code-aware search index (STEP ~106).** A retrieval layer over site
content + theme/plugin code (lexical first, embeddings optional/pluggable so you
don't take a hard AI-provider dependency). Powers far better agent navigation and
"where is X used?" — and is the targeted answer to AI Engine's embeddings
advantage, scoped to operations rather than chat.

**B2. Security & performance audit runtimes (STEP ~107).** Read-only audits
(vulnerable/abandoned plugins, file permissions, exposed configs; slow queries,
autoload bloat, image weight) that **emit actionable, reversible patches** through
the existing engine. This closes the loop: detect → propose → approve → apply →
rollback. Strong recurring value and a natural Pro feature.

**B3. Proactive maintenance agent (STEP ~108).** Scheduled runs (cron) that execute
audits, file recommendations, and optionally apply low-risk fixes under the
approval policy. Turns WPCC from on-demand to **always-on guardian** — recurring
value that justifies subscription pricing. Reuses workflow + approval + audit
infrastructure.

**B4. Staging→production promote (STEP ~109–110).** Apply change sets to a staging
copy, run health/diff verification, then promote atomically (or roll back). This is
the enterprise-grade capability that no AI-WP tool offers and the strongest
commercial differentiator for agencies.

### Phase C — Resilience & reach (STEP 111+) — *higher complexity, later*

- **C1. Whole-site snapshot & restore** (DB + files) for disaster recovery, layered
  on the snapshot system.
- **C2. Thin, optional AI-provider bridge** — only if customer demand pulls it; keep
  it a thin assist layer (e.g., draft patch explanations, summarize audits) so
  model cost/commoditization stays out of the core.
- **C3. Team/RBAC & multi-site fleet management** — capability tokens already give
  the primitive; extend to org/team scopes and a fleet console (manage many sites'
  agents from one place) — a clear agency/enterprise upsell.

---

## 5. Recommendation — what to build next

**Build first (Phase A, in order): Atomic multi-file change sets → Change
History/rollback UI → Licensing → MCP Skills.**

Rationale: these have the best ROI, build directly on the foundation you just
hardened, are low-complexity, and together they (a) make the agent loop materially
better (multi-file), (b) make the moat *visible and sellable* (history UI +
skills), and (c) **turn the platform into a business** (licensing). They also
create the surfaces (Pro gating, history) that every later phase plugs into.

**Positioning to commit to:** *"The safe execution layer for AI agents on
WordPress — every change previewed, approved, audited, and reversible."* Compete
with AI Engine on **trust, reversibility, and operational breadth**, not on content
generation. Adopt semantic search and (optionally) an AI bridge only as scoped
enhancements to that thesis — never as the identity.

**Biggest risk to manage:** scope creep toward becoming "another AI content
plugin." The discipline of *every new tool ships with capability scoping +
approval awareness + rollback* is the competitive advantage; it must remain a hard
requirement for every STEP 102+ runtime.
