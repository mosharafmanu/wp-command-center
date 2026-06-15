# STEP 101.3 — Observed Issues (reproducible only)

All issues below were directly observed via live MCP/REST calls on dev. Each includes reproduction steps. No speculation.

---

## F-1 — Content rollback is unreachable (HIGH)

**Runtime:** Content (`content_manage`).
**Impact:** `content_update`/`content_delete`/`content_publish`/`content_unpublish` all return a `rollback_id`, but that id **cannot be consumed**, so content writes are effectively irreversible through the public API.

**Reproduction:**
1. `content_manage {action: content_update, content_id: 15733, title: "X [EDITED]"}` → returns `rollback_id: a750a8ef-7c4d-4d83-8d89-de8a6f9e7882`.
2. `content_manage {action: content_rollback, rollback_id: a750a8ef-…}` → `{"isError":true,"code":"wpcc_invalid_content_action","message":"Invalid content action."}`.

**Root cause (confirmed in code):**
- `ContentManager::run()` line 24 rejects any action not in `ContentRegistry::ACTIONS`.
- `ContentRegistry::ACTIONS` (lines 33–35) does **not** include `content_rollback`.
- The dispatch arm `'content_rollback' => $this->rollback_content(...)` (line 56) and the entire `rollback_content()` method (lines 63–100) are therefore dead/unreachable.
- `ContentManager` has **no public `rollback()`** method, and there is **no** REST `/operations/content_manage/rollback` route — so neither the unified dispatcher nor a REST route provides an alternative.

**Fix direction (for a later step):** add `'content_rollback'` to `ContentRegistry::ACTIONS` (one line), or expose a public `rollback()` + REST route consistent with the other runtimes.

---

## F-2 — Menu rollback_id not surfaced; menu_update not handled in rollback (MEDIUM-HIGH)

**Runtime:** Menu (`menu_manage`).
**Impact:** Menu writes store a rollback record server-side but the caller never receives the id, and `menu_update` isn't reversible even if the id were known.

**Reproduction:**
1. `menu_manage {action: menu_update, menu_id: <id>, name: "… RENAMED"}` → response `{"action":"menu_update","menu_id":<id>}` — **no `rollback_id`**.
2. There is no `menu_rollback_list` action to discover the stored id, so REST `/operations/menu_manage/rollback {rollback_id}` cannot be driven.

**Root cause (confirmed in code):**
- `MenuRuntimeManager::store_rollback()` generates `wp_generate_uuid4()` and stores it in `wpcc_menu_rollbacks`, but the write methods return payloads without the id (e.g. line 81: `return ['action'=>'menu_update','menu_id'=>$id]`). Static audit: **13 `store_rollback()` calls, only 1 return includes `rollback_id`.**
- `MenuRuntimeManager::rollback()` switch handles `menu_create/menu_delete/menu_item_*/location_*` but **not `menu_update`**.

---

## F-3 — `rollback_id` inconsistently surfaced across runtimes (MEDIUM, systemic)

**Runtimes:** ACF, User, WooCommerce, Settings (and partially others).
**Impact:** Each performs the write and stores a rollback record, but the response omits `rollback_id`, and no per-runtime `rollback_list` exists — so the documented REST `/operations/<rt>/rollback` routes are undriveable for those actions. The reversal *mechanism* exists; the *contract* to invoke it is incomplete.

**Reproductions (all return success payloads WITHOUT a `rollback_id`):**
- `acf_manage {action: acf_group_update, group_id, title}` → `{"action":"acf_group_update","group_id":"…"}`
- `user_manage {action: user_update, user_id, display_name}` → `{"action":"user_update","user_id":426,"updated_fields":[…]}`
- `woocommerce_manage {action: price_update, product_id, regular_price}` → `{"action":"price_update","product_id":15738,"regular_price":"19.99"}`
- `settings_manage {action: settings_media_update, …}` → `{"action":"settings_media_update","updated":true}`

**Static audit (store_rollback calls vs. returns that include rollback_id):**
| Manager | store_rollback | returns with rollback_id |
|---|---|---|
| MenuRuntimeManager | 13 | 1 |
| WooCommerceRuntimeManager | 23 | 7 |
| UserManager | 7 | 2 |
| SettingsRuntimeManager | 2 | 1 |
| FormsRuntimeManager | 3 | 1 |
| CommentsRuntimeManager | 3 | 2 |
| ACFRuntimeManager | 14 | 5 |

Contrast — runtimes that **do** surface it and round-trip cleanly: Option, SEO, Media, MediaEnhancement, Patch (via `rollback_manage`), Workflow, SiteBuilder.

**Fix direction:** include the generated `rollback_id` in every write response that calls `store_rollback()`, and/or add a per-runtime `rollback_list` so ids are discoverable.

---

## Non-issue (documented to prevent re-flagging)

- **Create operations don't emit `rollback_id`** (Content/Menu/ACF/WooCommerce-product create vary). This is by design: a create is reversed by a delete, not a rollback record. Confirmed reversible-by-delete during cleanup. Not a defect.
- **`patch_create` reports `status: pending_approval`** yet `patch_apply` proceeds in developer mode — expected (developer mode bypasses the approval gate; the status field reflects the mode-agnostic request lifecycle).

---

## Positive confirmations

- STEP 89 structured-error contract holds on the write path (every negative returned `{isError, code, message}`; no opaque failures, no crashes, no hangs).
- DestructiveGuard handshake works: `user_delete` required `confirm + DELETE_USER + reason`.
- Workflow single-approval execution + per-step unified rollback works (`workflow_rollback` reversed a `media_update` step via the STEP 97 dispatcher).
- Audit + timeline records are generated for write operations.
