# PROGRAM-4B — Integration + Core Hardening · Implementation Report

> **Branch:** `program-4b-integration-core-hardening`. **No push/deploy/merge.**

## Integration
Octopus-merged P4.0 + P4.1–P4.5 → merge `6a8aad0`, **0 conflicts** (disjoint files). All six commits are ancestors of HEAD; clean pre-hardening baseline green.

## Core hardening (new + changed)
**New core (`includes/Rollback/`):**
- `RollbackDelta::build_record()` (**D1**) — versioned field map + scaffolding; byte-identical to the per-runtime inline records.
- `RollbackDelta::result()` (**D2**) — complete/partial/conflict response envelope; standardised messages.
- `RollbackStore` (interface) + `OptionListRollbackStore` (list-in-option, FIFO cap) + `OptionKeyedRollbackStore` (keyed-by-id) (**D3**).

**Migrated runtimes (5):** Settings, Media, Content, Comments, User — each `store_*_delta` now calls `build_record()` + a `RollbackStore`; each rollback v2 envelope now calls `result()`.
- **Settings** + **Content** use the store **end-to-end** (persist + resolve + mark_applied), since each has a dedicated rollback method (Content via `OptionKeyedRollbackStore`).
- **Media, Comments, User** use the store for **persist** + `result()` for the envelope, keeping their existing **inline resolution** because their `rollback()` is shared across multiple non-delta actions (Media replace/upload/delete/featured; Comments trash/delete; User create/delete/roles) — touching that shared resolution was out of scope and unnecessary.
- **SEO is intentionally not migrated** (postmeta-per-record + indexed-SELECT is already the most scalable store and is the reference the helpers were extracted from; migrating its heavily-tested legacy paths adds risk for no Woo/ACF/Bulk benefit). SEO record format frozen.

**Net effect:** the 5 runtimes **shrank** (inline record/envelope code removed); duplication moved to the core. P4B diffstat (vs `6a8aad0`): 14 files, +330/−161; the runtime managers all net-smaller.

## Backward compatibility
`build_record` emits the identical v2 record; stores read/write the same options in the same shape/cap. Verified by probe: a hand-built **pre-hardening v2 record** and a **legacy before_state record** both restore correctly under P4B.

## Tests adjusted
Re-pointed each runtime's static structural guards to the core (`RollbackDelta.php`) for the moved `build_record`/`result`/`version` strings (added delegation guards: `build_record`, `persist`, `result`). Functional assertions unchanged. **Idempotency fix:** Media/User legacy fixtures switched from fixed ids (`legacy-media-1`, `legacy-user-1`) to unique ids (`legacy-<entity>-<id>`) — closes the list-storage re-run shadowing seen in the midpoint audit.

## Not touched
SEO runtime + `SeoProvider`; `FieldAccessor`/`PostMetaAccessor` + the 5 accessors; OperationExecutor; OperationRegistry/CapabilityRegistry/McpServerRuntime/Schema/REST/UI. No DB_VERSION/schema change. No Woo/ACF/Bulk/Plugin/Theme.
