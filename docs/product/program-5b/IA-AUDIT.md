# PROGRAM-5B — Phase A: Complete Admin IA Audit

> **Branch:** `program-5b-product-usability-adoption-readiness`, stacked on the Program-5A tip `36b258c` (main untouched at `94a716c`). **Deviation note:** the mission said "branch from current main," but 5B explicitly audits/extends 5A's AI Setup, which is unmerged. Branching from pristine main would orphan 5A and force a duplicate, conflicting AI-Setup build. This branch therefore stacks on 5A so the two merge in order; `main` is unchanged.
> **Lens:** "I am a WordPress agency owner. I know nothing about MCP, APIs, Claude, or AI agents."

## 1. Full surface map (post-5A)
| Section (menu) | Tab | View | Purpose | Newcomer verdict |
|---|---|---|---|---|
| **Overview** | Home | `command-home` | Mission control + 5A first-run checklist | OK — best entry point |
| **Operate** | Approvals | `approval-center` | Review/approve gated AI ops | Good, but "Operate" label is vague |
| | Operations | `operations-explorer` | Read-only catalogue of 40 ops w/ risk/capability | **Developer-centric** ("risk tier", "capability") |
| | Runtime | `dashboard` | "Agent Runtime Dashboard" — sessions/tasks/queue | **Heavily developer-centric**, overlaps Overview |
| | (AI tabs) | proposals/alt-text/seo/ai-content | Flag-gated, OFF | Hidden by default (correct) |
| **Audit** | Changes | `change-history` | Change log + **governed Restore (undo)** | **Stale copy hides undo** (see §3) |
| | Patches | `patches` | Code patch review/apply/rollback | Advanced/developer |
| | Diagnostics | `diagnostics` | Health checks | Advanced |
| | Site Intelligence | `site-intelligence` | "Snapshot for AI agents" | Developer-centric |
| **Access** | Tokens & Capabilities | `token-capability-manager` | Tokens + capability matrix | **Stale copy hides create/revoke** (§3) |
| | Security Mode | `settings` | developer/client/enterprise (5A-enhanced) | Improved by 5A; still some jargon |
| **Connect** | AI Setup | `ai-setup` | Provider key/model/test (5A) | Good; naming clash w/ "AI Integrations" |
| | AI Integrations | `ai-integrations` | MCP **client** connection (Claude Desktop config) | "Integrations" vs "Setup" is confusing |
| | File Access | `file-access` | Read-only file browser | Developer |

## 2. What is confusing / duplicated / hidden / developer-centric

### Confusing
- **"AI Setup" vs "AI Integrations"** — both sound like "set up AI." One is *provider keys* (outbound AI), the other is *MCP client wiring* (Claude Desktop connects in). A newcomer can't tell them apart.
- **Section labels** — "Operate / Audit / Connect" are abstract; no section gives a one-line "what is this for."
- **"Runtime" / "Agent Runtime Dashboard"** — opaque; reads like an internals panel.

### Duplicated
- **Two operational dashboards** — Overview (home) and Operate → Runtime (`dashboard.php`) both show activity/queue. Overview is the modern one; Runtime is the legacy engineer view. Same data, two places (UX-2-style).

### Hidden (the most damaging finding)
- **Undo is hidden by stale copy.** `change-history.php` says *"restore controls arrive in a later release."* But governed Restore **shipped** (Program-4 + STEP 105). The product's headline safety feature is described as not-yet-available.
- **Token create/revoke is hidden by stale copy.** `token-capability-manager.php` says *"token create/revoke arrives in a later release."* But it **shipped** (STEP 107). A newcomer thinks they can't make a token here.
- These two lines actively *undersell shipped capability* — a direct adoption blocker.

### Developer-centric language (newcomer would not understand)
- "Agent Runtime Dashboard", "snapshot for AI agents", "operations the platform exposes", "risk tier", "the capability it requires", "MCP endpoint", "for AI agent investigation".
- Operations Explorer, Site Intelligence, File Access, Patches, Runtime are all engineer surfaces with no plain-language framing.

## 3. What a first-time agency owner would fail to understand
1. **"Can I undo what the AI did?"** — Yes, but the Changes page says undo isn't here yet. **Fails.**
2. **"How do I let Claude connect / make a token?"** — Tokens page says create "arrives later"; AI Setup vs AI Integrations is ambiguous. **Fails.**
3. **"Which 'AI' page do I use?"** — Setup vs Integrations unclear. **Fails.**
4. **"What is Runtime / Site Intelligence / Operations for?"** — no plain explanation. **Partially fails** (advanced, acceptable if de-emphasized).
5. **"What does each section do?"** — no section orientation. **Fails.**
6. **"What is safe to do first?"** — 5A first-run helps; needs the how-AI/approvals/undo story. **Partial.**

## 4. Prioritized fix list for 5B (all UI/copy; no schema/registry/MCP/REST/cap/rollback change)
| Priority | Fix | Phase |
|---|---|---|
| **P0** | Correct stale "arrives later" copy on **Changes** (undo) and **Tokens** (create/revoke) | C/G |
| **P0** | Disambiguate **AI Setup** vs **AI Integrations** (rename Integrations → "Connect an AI Agent (MCP)") | B |
| **P1** | Add **one-line section descriptions** (orientation) in the shell | B |
| **P1** | Plain-language **first-run** covering what-it-is/does/doesn't + how AI/approvals/undo work + safe start | C |
| **P1** | **Provider-aware AI Setup** (honest provider list, status, "why this model / what changes if I switch") | D/E |
| **P1** | **Safety Mode** consequence-led copy; Client recommended; prevent accidental self-approval | F |
| **P2** | Relabel **Runtime → "Runtime (advanced)"**; de-emphasize engineer surfaces | B |

## 5. STOP-condition pre-clearance
Every fix is admin **UI/copy** only. None touches schema, DB_VERSION, MCP/REST contracts, capabilities, the operation registry, rollback, or security architecture. Section/tab labels, descriptions, and view copy are presentation-layer; legacy slugs/redirects are preserved and extended. **No STOP condition triggered by the planned scope.**
