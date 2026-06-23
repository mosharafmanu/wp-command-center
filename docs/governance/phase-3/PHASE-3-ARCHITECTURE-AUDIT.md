# Phase 3 — Architecture Audit (F-1 Rollback Over-Reach)

**Program:** WP Command Center — Phase 3 Autonomous Governance Remediation
**Defect:** F-1 (HIGH) — rollback full-snapshot over-reach / layered rollback corruption
**Production baseline:** `a254a52` · OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0
**Author:** Phase 3 program (automated)
**Date:** 2026-06-23

---

## 1. Purpose

Classify every rollback-capable runtime by its rollback record format and restore
scope, and determine which are vulnerable to **F-1 layered corruption**, which are
**in Phase 3 scope**, and which are **deferred**.

### F-1 definition (the failure mode under audit)

A rollback is **F-1 vulnerable** when it captures and restores a **full object
snapshot** rather than only the fields the originating operation actually touched.
Under layered changes this produces:

- **Sibling-field loss** — Change A touches field X (snapshots the whole object,
  including field Y), Change B touches field Y; rolling back A rewrites Y from A's
  stale snapshot, destroying B.
- **Out-of-order resurrection** — rolling back an older change re-applies values that
  a newer change had already superseded.
- **Misleading applied history** — history claims a clean revert while siblings were
  silently clobbered.
- **Audit/Rollback guarantee breach** — the Four Guarantees (Approval, Rollback,
  Audit, Capability Scoping) are violated because Rollback is no longer truthful.

The canonical F-1 reproduction lives in the SEO runtime and is the **primary
Phase 3 target**.

---

## 2. F-1 in the SEO runtime (the in-scope target)

### Store path — `SeoRuntimeManager::seo_update()` / `store_rollback()`

`includes/Operations/SeoRuntimeManager.php`

```php
// L98-99
$before      = SeoProvider::read( $post->ID, $provider );   // FULL SEO object
$rollback_id = $this->store_rollback( $post->ID, $provider, $before, $context );
```

```php
// L334-348  store_rollback()
$record = [
    'id'               => $rollback_id,
    'post_id'          => $post_id,
    'provider'         => $provider,
    'before_state'     => $before,        // ← FULL 11-field object snapshot
    'rollback_applied' => false,
    'created_at'       => time(),
    ...
];
add_post_meta( $post_id, self::ROLLBACK_META_PREFIX . $rollback_id, $record, true );
```

`SeoProvider::read()` (`SeoProvider.php` L121-131) returns **all 11 unified fields**
(`title, description, focus_keyword, canonical, og_title, og_description, og_image,
twitter_title, twitter_description, twitter_image, robots`), regardless of what
`seo_update` was asked to change.

### Restore path — `SeoRuntimeManager::seo_restore()`

```php
// L205
SeoProvider::write( (int) $record['post_id'], $record['before_state'], $record['provider'] );
```

`SeoProvider::write()` (`SeoProvider.php` L140-159) writes **every key present in the
supplied array** (and the whole `before_state` carries all 11), deleting any whose
value is `''`. So a restore rewrites all 11 fields, not just the originally touched
one(s).

### Confirmed F-1 reproduction (disjoint fields)

1. Change A: `seo_update { title: "A" }` → `before_state` = `{title:"", description:"orig", ...}`.
2. Change B: `seo_update { description: "B" }` → live SEO now `{title:"A", description:"B", ...}`.
3. Rollback A → `SeoProvider::write(post, before_state_A)` rewrites **all 11 fields**,
   restoring `description:"orig"` and **destroying Change B's description**.

### Aggravating sub-issue: existed-vs-empty collapse

`SeoProvider::read()` casts every scalar via `(string) get_post_meta(...)`, so an
**absent** meta and an **empty-string** meta both read back as `''`. On restore,
`write()` deletes any field whose `before_state` value is `''`. Net effect: the
current code cannot distinguish "field did not exist → should be deleted on rollback"
from "field existed but was empty". For the new delta record we must capture an
explicit **prior-existence flag** via `metadata_exists()`.

### Dispatch / governance context (must be preserved)

- `seo_restore` is the registered rollback action for `seo_manage`
  (`OperationExecutor::ACTION_ROLLBACKS['seo_manage'] => 'seo_restore'`, L404).
- All restores route **through the governed chokepoint** —
  `OperationExecutor::rollback('seo_manage', ['rollback_id'=>...])` →
  `SeoRuntimeManager::run(['action'=>'seo_restore', ...])`. The admin Undo and
  Change-History rollback both arrive here (`ChangeHistoryRuntimeManager.php` L288).
- Capability: `seo_manage => content_manage` (`CapabilityRegistry.php` L102).
- Rollback record store (Slice 4c): one protected post-meta row per rollback,
  `_wpcc_seo_rb_{id}`; legacy fallback reads the draining `wpcc_seo_rollbacks` option.

---

## 3. Cross-runtime classification

Evidence gathered by reading each manager's store/restore code. Severity is the
F-1-style layered-corruption risk; **scope** is the Phase 3 decision.

| Runtime | Rollback record | Restore scope | F-1 verdict | Phase 3 scope |
|---|---|---|---|---|
| **SEO** (`SeoRuntimeManager`) | full 11-field `before_state` (post-meta `_wpcc_seo_rb_*`) | full object write | **Confirmed vulnerable** | **IN SCOPE (primary)** |
| ACF (`ACFRuntimeManager`) | full group/field object `before_state` (opt `wpcc_acf_rollbacks`) | `acf_update_field(_group)( $before )` | Confirmed vulnerable | Deferred |
| Media update (`MediaRuntimeManager`) | full 4-field metadata `before_state` (opt `wpcc_media_rollbacks`) | restore all of title/caption/desc/alt | Confirmed vulnerable | Deferred |
| WooCommerce (`WooCommerceRuntimeManager`) | full 15-field product snapshot (opt `wpcc_woo_rollbacks`) | `restore_product()` writes all present | Confirmed vulnerable | Deferred |
| Content (`ContentManager`) | 4 post fields title/status/content/excerpt (opt `wpcc_content_rollbacks`) | restores all 4 | Likely vulnerable | Deferred |
| User update (`UserManager`) | 4 user fields (opt `wpcc_user_rollbacks`) | `wp_update_user( $before )` | Confirmed (update action) | Deferred |
| Settings (`SettingsRuntimeManager`) | fixed per-action option set (opt `wpcc_settings_rollbacks`) | restores all options in set | Likely vulnerable | Deferred |
| Elementor (`ElementorRuntimeManager`) | full `_elementor_data` JSON (opt `wpcc_elementor_rollbacks`) | rewrites whole page tree | Confirmed vulnerable | Deferred |
| Menu (`MenuRuntimeManager`) | action-specific delta / inverse (opt `wpcc_menu_rollbacks`) | mostly field-scoped; location_assign stores only prior menu_id | Likely (location_assign layering) | Deferred |
| Options (`OptionManager`) | single option old/new value (opt `wpcc_option_rollbacks`) | restores one option | **Not vulnerable** | Out of scope |
| Media enhance (`MediaEnhancementRuntimeManager`) | `snapshot_id` + `created_files` list | delete created files + byte-restore snapshot | **Not vulnerable** | Out of scope |
| Media snapshot (`MediaSnapshot`) | byte-immutable files + full attachment metadata | byte-for-byte file restore | Not vulnerable (caller scopes deltas) | Out of scope |
| Theme (`ThemeManager`) | `previous_slug` only | inverse op (`switch_theme`) | **Not vulnerable** | Out of scope |
| Plugin (`PluginManager`) | active state only | inverse op (`deactivate_plugins`) | **Not vulnerable** | Out of scope |
| Patch / file (`PatchApproval` + `RollbackManager`) | byte-exact file snapshot per affected file | whole-file byte restore | By-design (see note) | Out of scope |
| Snapshot (Operations `SnapshotManager`) | delegates to file snapshot store | byte-for-byte file restore | Not vulnerable (delegates) | Out of scope |
| Workflow (`WorkflowRuntimeManager`) | no own record; delegates to sub-op rollbacks in reverse order | inherits sub-op scope | Inherits (Elementor/SEO/etc.) | Out of scope (benefits from SEO fix) |

### Note on Patch / file rollback (intentionally full-content)

Patch rollback restores **whole-file bytes** by design and is **not** reclassified by
Phase 3. The distinction from SEO: a patch is a single reviewed, atomic change to a
file with its own pre-patch snapshot, applied through the approval pipeline; layering
two patches on the same file and rolling back the older one is a known,
human-reviewed operation, not a silent multi-writer meta race. Field-scoped diffs for
future per-hunk patch modes are noted as possible later work but are **out of Phase 3
scope**.

---

## 4. Scope decision and rationale

**In scope for Phase 3: SEO runtime only.**

Rationale:

1. The mission statement scopes Phase 3 to F-1 in SEO ("Current SEO rollback captures
   and restores the full SEO object snapshot").
2. SEO is the runtime where F-1 was field-reproduced and escalated (Stage B4 /
   F-1 escalation: layered SEO rollbacks wipe sibling changes and resurrect reverted
   values).
3. SEO is the **live AI-write surface** (proposal → governed apply → seo_manage), so
   layered changes are realistic in production once AI flags enable.
4. The remaining confirmed/likely-vulnerable runtimes (ACF, Media-update, Woo,
   Content, User, Settings, Elementor, Menu-location) share the **identical
   full-snapshot pattern**. Fixing SEO first establishes a proven delta-rollback
   pattern that a follow-on program can replicate per runtime — without bundling a
   high-blast-radius multi-runtime change into one release.

**Deferred (same pattern, follow-on program):** ACF, Media-update, WooCommerce,
Content, User-update, Settings, Elementor, Menu location_assign. Additional
full-`before_state` runtimes confirmed during consumer analysis and added to the
deferred set: **Forms** (`FormsRuntimeManager` L96), **Bulk** (`BulkRuntimeManager`
L93/L102), **Comments** (`CommentsRuntimeManager` L274), **SiteBuilder**
(`SiteBuilderRuntimeManager` L254/L316) — all store a full `before_state` and restore
it wholesale. These are documented here so the deferral is explicit, not an oversight.
None are AI-write surfaces today; their layered-corruption exposure is lower than
SEO's.

**Consumer-coupling check (informs design safety):** No code outside
`SeoRuntimeManager` reads the SEO rollback record's internal shape (`grep` for
`_wpcc_seo_rb_` / `before_state` returns only `SeoRuntimeManager` and unrelated
runtimes). Change-History rollback consumes only the **result envelope** returned by
`OperationExecutor::rollback`, not the stored record. The SEO rollback record format
can therefore be evolved internally without breaking any consumer.

**Out of scope (not vulnerable / by-design):** Options, Media-enhance, Media-snapshot,
Theme, Plugin, Patch/file, Snapshot, Workflow (inherits; benefits transitively from
the SEO fix because workflow SEO steps will rollback field-scoped).

---

## 5. Constraints carried into design

- Restore must keep routing through `OperationExecutor::rollback` →
  `seo_restore` (no new route/op/cap/tool).
- `rollback_id` public contract (uuid4 returned by `seo_update`, recorded by
  ChangeRecorder, consumed by Change-History rollback) is unchanged.
- Legacy full-snapshot records (`before_state`-only meta rows **and** the draining
  `wpcc_seo_rollbacks` option) must still restore via the legacy path. No destructive
  migration.
- Invariants frozen: OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 ·
  DB_VERSION 2.5.0. No schema change intended.

---

## 6. Verdict

F-1 is **confirmed** in the SEO runtime and is a **systemic full-snapshot pattern**
across the plugin. Phase 3 will remediate the **SEO runtime** with a field-scoped,
drift-aware, existence-faithful, legacy-compatible delta rollback, and **explicitly
defer** the structurally identical runtimes to a follow-on program. Proceed to
Engineering Design.
