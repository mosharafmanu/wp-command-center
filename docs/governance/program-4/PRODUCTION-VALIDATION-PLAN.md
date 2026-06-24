# PROGRAM-4 — Production Validation Plan (Phase C)

> **Type:** the exact post-deploy validation checklist (audit-only design; not executed here — deploy-coupled).
> **Transport:** governed REST, namespace `wp-command-center/v1`, token Bearer. Routes: `POST /operations/<op>/run`, `POST /operations/<op>/rollback`. (MCP `tools/call` is equivalent.)
> **Rollback routing:** SEO + Content are **action-based** (executor maps `seo_manage→seo_restore`, `content_manage→content_rollback`; call `/run` with that action or `/rollback` with `rollback_id`). The other 8 expose a **public `rollback()`** (call `/operations/<op>/rollback` with `rollback_id`). All certified surfaces surface a `rollback_id` on apply.

---

## 0. Pre-flight (run before per-surface tests)
1. Confirm live HEAD = merged RC via `~/wpcc-deploy.log` (and `git rev-parse HEAD` over SSH if available).
2. Anonymous HTTP smoke: homepage 200 · `/wp-json/wp-command-center/v1` 200 · a token route → 401 without token · **no 500s**.
3. Invariants live: `OPERATION_MAP=34 · capabilities=23 · catalogue=40 · MCP=40 · DB_VERSION=2.5.0`.
4. Posture: AI flags **OFF**, Anthropic key **unset**, security mode **`developer`** — unchanged.
5. Confirm which optional plugins are active on prod (ACF, Elementor, WooCommerce); skip those surfaces if inactive (runtimes no-op gracefully).
6. **Use sandbox/throwaway entities** (draft posts, a test product, a test user) — never production-critical content.

## 1. Generic test pattern (applies to every surface)
- **Apply:** run the op's update on a sandbox entity with a known field value → **expect** success + non-empty `rollback_id`; verify the field changed.
- **Rollback:** call rollback with that `rollback_id` → **expect** `status:complete`/`restored:true`; verify the field reverted to the prior value, and that **sibling fields are unchanged**.
- **Drift:** apply (capture `rid`) → make a SECOND change to the same field/entity → rollback `rid` → **expect** `error:true` + `code:wpcc_rollback_conflict` (or `partial`), `reversible:false`, and the **second change PRESERVED** (refuse-not-clobber).
- **Audit:** read `/agent/timeline` (or AuditLog) → **expect** an apply event and a rollback event with **honest status** (restored/skipped), no PHP warning in `error_log`.
- **Idempotency (spot):** repeat a completed rollback → **expect** `already_applied`.

---

## 2. Per-surface checklist

| # | Surface (op) | Apply test | Rollback test | Drift test | Audit test | Expected result |
|---|---|---|---|---|---|---|
| 1 | **SEO** (`seo_manage`) | `seo_update` title/desc on a draft post | `seo_restore` with `rollback_id` | re-`seo_update` title, then restore first | timeline: `seo.update` + `seo.restored` (status) | clean: title restored exactly; drift: `conflict`, sibling/newer kept |
| 2 | **Settings** (`settings_manage`) | update a safe option (e.g. `posts_per_page`) | `/rollback` `rollback_id` | change the option again, restore first | `settings.*` audit honest | clean: option restored; drift: conflict (no clobber) |
| 3 | **Media** (`media_manage`) | `media_update` alt/caption on a test attachment | `/rollback` | re-update alt, restore first | `media.*` audit | clean: alt restored; bytes untouched; drift: conflict |
| 4 | **Content** (`content_manage`) | `content_update` title+status on a draft | `content_rollback` | re-update title, restore first | timeline `content.update` — **verify `old_status` is the genuine prior (D2 fix), no warning** | clean: title+status restored; drift: conflict; **old_status correct** |
| 5 | **Comments** (`comments_manage`) | approve/unapprove a test comment | `/rollback` | re-change status, restore first | `comments.*` audit | clean: status restored; drift: conflict |
| 6 | **Users** (`user_manage`) | update display_name/email on a test user | `/rollback` | re-change field, restore first | `user.*` audit | clean: field + roles restored; drift: conflict |
| 7 | **Woo Products** (`woocommerce_manage`) | `product_update` regular_price on a test product | `/rollback` | re-update price, restore first | `product.updated`/`product.rollback` | clean: price restored, siblings (name/stock) intact; drift: conflict |
| 8 | **Bulk** (`bulk_manage`) | `bulk_publish` 2 draft posts | `/rollback` (batch `rollback_id`) | externally change one item's status, rollback batch | `bulk.rollback` aggregate (restored/skipped/missing) | clean: both → draft, **titles NOT corrupted**; drift: partial, drifted item skipped, other restored |
| 9 | **ACF value** (`acf_manage`) | `acf_value_update` on a sandbox post's text field | `/rollback` | re-update the value, restore first | `acf.value.updated`/`acf.value.restored` | clean: value restored (existence-faithful); drift: conflict; **key-selector restores prior (AR-MED-1)** |
| 10 | **Elementor** (`elementor_manage`) | `elementor_update_text` on a test page widget | `/rollback` | edit a DIFFERENT widget, rollback first | `elementor.update`/`elementor.rollback` | clean: widget restored; drift (any widget changed): `conflict`, other widget's change preserved |

## 3. Negative / honesty checks
- **Plugin/Theme update (BLK-3):** dry-run a `plugin_update`/`theme_update` (or inspect the response on a real update in staging) → **expect** `reversible:false` + `reversible_note` (no `rollback_id`, no false promise).
- **Bogus rollback_id** on any surface → **expect** `wpcc_rollback_not_found` (HTTP 4xx), not 500.
- **Conflict surfaces as failure:** confirm a drift `conflict` makes the operation's `success=false` (executor honors `error:true`).

## 4. Acceptance criteria (production)
1. All 10 surfaces: clean apply→rollback **complete**; drift → **conflict/refuse with no clobber**; audit honest; **no PHP warnings** (esp. content.update old_status).
2. Plugin/theme update report `reversible:false`.
3. Invariants live `34·23·40·40·2.5.0`; no 500s; AI/security posture unchanged.
4. **Serial T2 on the deploy host: net-new attributable failures = 0** vs `tests/regression-baseline.tsv` (the `test-alt-text` 4-red dormant-AI environmental is the known non-attributable baseline).

## 5. If any check fails
Stop the rollout, capture the response + `error_log`, and execute the **deploy rollback sequence** (revert `main` to `a41a9d7` + push → cron resets ~1 min). Per-feature data is safe (additive records, dual-read legacy).
