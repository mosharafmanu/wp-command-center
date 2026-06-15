# STEP 101 — Next Validation Plan

**Status:** 101.1 (discovery) complete. The steps below are a proposal; **do not begin until instructed.**

## Guardrails for all subsequent sub-steps

- **Dev/staging only.** Never run writes against production (`mosharafmanu.com`).
- Read-only/diagnostic actions first; writes only with explicit go-ahead.
- Every destructive/critical action stays behind its existing handshake — validate that the handshake *works*, never bypass it.
- Capture request + response evidence per tool into `artifacts/step-101-runtime-validation/`.

## Proposed sequencing (safe → risky)

### 101.2 — Read-only / diagnostic sweep (zero data risk)
Exercise every `diagnostic` action across all 29 runtimes and confirm each returns a well-formed, non-error result:
- `system_info`, `database_inspect`, `file_manage`, `code_search`, `search_manage`, `report_manage` (all 8 reports), `approval_manage` (list views), and all `*_list`/`*_get`/`*_audit`/`*_verify` sub-actions (content, media, woo, acf, menu, settings, seo, elementor, site_builder, snapshot, rollback, capability, workflow).
- Also read all 7 MCP resources.
- **Goal:** prove discovery accuracy + baseline that read paths are healthy. This is the natural place to confirm/deny the "thin parameter schema" observations.

### 101.3 — Reversible write validation on dev (create→verify→rollback)
For each write-capable runtime, perform one low-risk write and immediately roll it back, verifying state restoration:
- Content (create draft → rollback), Media (`media_update` → rollback / `media_enhance` thumbnail_regenerate → `/media_enhance/rollback`), ACF, SEO, Menu, Widgets, Options/Settings, Elementor, Site Builder, WooCommerce (test product), Patch (create→apply→rollback on a dev throwaway file).
- **Goal:** prove the snapshot/rollback contract holds end-to-end per runtime.

### 101.4 — Approval + security-mode gating validation
- Confirm `requires_approval` ops produce the structured `pending_approval` response in client/enterprise mode and execute the Request→Approval→Queue→Execute pipeline.
- Confirm diagnostic sub-actions bypass approval in all modes (per-action gating).

### 101.5 — Destructive-guard validation (handshake, NOT destruction)
- Verify `plugin_delete`/`theme_delete`/`user_delete`/`unused_media_cleanup`/`capability_remove` **reject** missing/incorrect confirmation phrases and reasons (negative tests), on disposable dev fixtures only.
- Verify `unused_media_cleanup` trashes (never permanently deletes) and is reversible.

### 101.6 — Error-surface validation (STEP 89 contract)
- Trigger known business failures and confirm `{isError, code, message}` results (not opaque `-32000`).

## Recommended next validation step

**Begin with 101.2 (read-only/diagnostic sweep).** It carries zero data risk, validates the discovery inventory in this step against live behavior, and resolves the open "missing metadata" questions (thin param schemas on `settings_manage`/`woocommerce_manage`/`menu_manage`/`user_manage`, and the `wp_cli_bridge` command registry) before any write is attempted.
