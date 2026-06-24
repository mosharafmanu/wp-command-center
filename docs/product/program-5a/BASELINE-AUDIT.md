# PROGRAM-5A — Phase 0 Baseline Audit

> **Branch:** `program-5a-product-usability-adoption-readiness` (off `94a716c` = `main` = `origin/main`).
> **Date:** 2026-06-24 · **Scope:** read-only baseline before any change.

## 1. Git / HEAD / tree state
- Pre-branch HEAD = `94a716c983ff1265f095c2ce830c108515701b60` (`docs(program-4): synchronize…`).
- `main` == `origin/main` == `94a716c` (0 ahead / 0 behind). Production HEAD per handoffs = `2657810` (ancestor lineage; docs synced at `94a716c`).
- Working tree before branching: clean except untracked PROGRAM-5 product report docs (`docs/product/*.md` from prior report-only passes). No uncommitted code.
- New branch created off `94a716c`. All Program-5A work happens here. No push, no deploy, no merge.

## 2. Program-4 presence (confirmed)
`includes/Rollback/` contains the full Program-4 set: `RollbackDelta.php`, `PostMetaRollbackStore.php`, `RollbackStore.php`, `RollbackManager.php`, `SnapshotManager.php`, and the field accessors (Option/Content/Media/Comment/User/PostMeta/Seo/AcfValue/WooProduct/ElementorData/BulkAcf/BulkWoo). **Program-5A will not touch any of these.**

## 3. Invariants (verified at baseline)
| Invariant | Required | Measured | Method |
|---|---|---|---|
| OPERATION_MAP | 34 | **34** | counted `=>` pairs in `CapabilityRegistry::OPERATION_MAP` |
| capabilities | 23 | **23** (per handoff; `ALL_CAPABILITIES`) | registry constant |
| catalogue (operations) | 40 | **40** | counted `'id' =>` keys in `OperationRegistry` |
| MCP tools | 40 | **40** (1:1 with catalogue, by construction in `McpServerRuntime::tools_list`) | derived |
| DB_VERSION | 2.5.0 | **2.5.0** | `Schema::DB_VERSION` |

These are the **must-not-regress** targets re-checked in Phase 8.

## 4. Admin UI baseline

### 4.1 Menu / IA (already consolidated — 5-C)
`AdminMenu` registers ONE top-level menu (`wp-command-center`, cap `manage_options`) + 5 section submenus, each rendered by `AppShell`:
- **Overview** (`wp-command-center`) → `command-home`
- **Operate** (`wpcc-operate`) → tabs: Approvals (`approval-center`), Operations (`operations-explorer`), Runtime (`dashboard`) + build-flagged AI tabs (Drafts/Alt Text/SEO/AI Content — all OFF by default)
- **Audit** (`wpcc-audit`) → Changes (`change-history`), Patches, Diagnostics, Site Intelligence
- **Access** (`wpcc-access`) → Tokens & Capabilities (`token-capability-manager`), Security Mode (`settings`)
- **Connect** (`wpcc-connect`) → AI Integrations (`ai-integrations`), File Access (`file-access`)

Legacy slugs (`wpcc-approvals`, `wpcc-tokens`, `wpcc-settings`, etc.) redirect into the 5-C IA via `AppShell::legacy_map()` + `AdminMenu::redirect_legacy_slugs()` (runs at `admin_menu` priority 0 to beat core's page-access check). **This is a deliberate, working IA — not sprawl.**

**Finding (IA):** the prompt's suggested 9-item flat IA (Overview/Work/Approvals/History/AI Setup/Access/Settings/Diagnostics/Developer Tools) would *fragment* the existing, coherent 5-C model and fight the legacy-redirect system. Re-flattening to 9 top-level items is a **broad admin UI rewrite** (a STOP condition). **Decision:** keep the 5-C IA; improve *clarity within it* (Phase 1) and add a dedicated **AI Setup** tab under Connect (Phases 3–5) rather than restructuring.

### 4.2 Submenu count
1 top-level + 5 sections (Overview reuses parent slug). Within sections, tabs are the second level. Effective top-level items = **5** (already collapsed from the historical ~12).

### 4.3 Per-surface read/write classification (relevant to this program)
- `command-home` (Overview) — **read-only** aggregate; has a `#wpcc-home-readiness` placeholder (JS/REST-driven) but **no server-rendered first-run guidance** today → Phase 2 gap.
- `settings` (Access → Security Mode) — **already has a working Security Mode UI** (3 radio cards + descriptions, same-page POST + `check_admin_referer('wpcc_settings')` + `manage_options`, writes `wpcc_security_mode`). Gaps: no client-safe **recommendation**, no **confirmation** when selecting the self-approving Developer mode, **no audit event** on mode change → Phase 6.
- `ai-integrations` (Connect → AI Integrations) — about **MCP client** wiring (Claude Desktop config generation + token gen + an MCP-endpoint connection test). It does **NOT** manage a provider API key/model. → the **provider key/model/test gap** is Phases 3–5.

### 4.4 AI key/model/provider state (the core gap)
- WPCC's only outbound AI transport is `Ai\AnthropicClient` (BYO key, single path, errors-as-data, secret-scrubbed). **Anthropic is the only wired provider.**
- Key/model already resolve from options the client reads:
  - `wpcc_anthropic_api_key` (option) / `WPCC_ANTHROPIC_API_KEY` (constant) — canonical; legacy `wpcc_alt_text_api_key` / `WPCC_VISION_API_KEY`.
  - `wpcc_anthropic_model` (option) / `WPCC_ANTHROPIC_MODEL` (constant) — canonical; legacy equivalents.
- Helpers exist and never leak the secret: `is_configured()`, `key_source()`, `model()`, `send()` (short-circuits `not_configured` with **no** network call when no key).
- **There is no admin UI to set/mask/clear the key or pick the model.** A design partner today must define a PHP constant or hand-set an option — developer-only. This is the central adoption gap Phases 3–5 close.
- **Decision (providers):** implement **Anthropic** fully (the only provider with a runtime). Show **OpenAI / Gemini** as **Planned (not yet supported)** — disabled, clearly labelled — because storing keys for providers with no transport would be fake, misleading functionality. This honours "implement only providers that fit existing architecture."

### 4.5 First-run state
No first-run/onboarding panel is server-rendered. A fresh install lands on Overview with JS-loaded cards; a non-developer has no guided "what do I do first / is AI on / what's my safety mode / where do I undo" surface. → Phase 2.

### 4.6 Security posture (unchanged, must stay so)
- Default security mode = `developer` (`SecurityModeManager::DEFAULT_MODE`). Program-5A will **not** change the stored mode; it will only make the choice **clearer and safer to set** (recommend client mode for partners; confirm before choosing developer).
- AI flags `WPCC_PROPOSALS_DEV_UI` / `WPCC_ALT_TEXT_UI` / `WPCC_SEO_META_UI` / `WPCC_AI_CONTENT_UI` all OFF by default (constant-or-filter, default false). Program-5A will **not** enable any of them.
- Anthropic key UNSET. Program-5A will **not** set a key.

## 5. Planned changes (smallest safe set) and STOP-condition pre-clearance
| Phase | Change | Touches schema/registry/MCP/REST? | STOP risk |
|---|---|---|---|
| 1 | Clarity tweaks within 5-C; add **AI Setup** tab (new view, new tab + legacy-map entry) in Connect | No | None (additive nav) |
| 2 | Server-rendered first-run panel in `command-home` + read-only `AdoptionStatus` helper | No | None |
| 3 | `ai-setup` view + `AiSetupController` — save/mask/clear `wpcc_anthropic_api_key` via same-page POST + nonce + cap + audit (no secret) | No (existing option) | **Cleared:** option storage is the *existing* pattern the client already reads; UI masks + never echoes; documented limitation. Not a *new* insecure pattern. |
| 4 | Model dropdown → `wpcc_anthropic_model` option | No | None |
| 5 | "Test connection" → `AnthropicClient::send()` minimal ping (no key ⇒ no call) | No | None (no expensive call, no mutation, no proposal) |
| 6 | Security Mode UI: recommendation + developer-mode confirm + audit event | No | **Cleared:** does not change stored mode automatically; only adds clarity/audit |
| 7 | Honest onboarding copy | No | None |

**No phase requires:** DB migration, DB_VERSION bump, OPERATION_MAP/capability/catalogue/MCP change, or a REST contract change. All settings use **same-page admin POST + nonce + capability**, matching the existing `settings.php` / `ai-integrations.php` pattern — **no new or changed REST routes.**

**Verdict:** baseline green; no STOP condition triggered by the planned scope. Proceed to Phase 1.
