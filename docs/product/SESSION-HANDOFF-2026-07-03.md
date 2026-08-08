# Session Handoff — 2026-07-03

> **Current authoritative handoff.** Supersedes `SESSION-HANDOFF-2026-06-27.md`.
> Focus of this session: fixing real bugs + DX gaps surfaced by a live production
> MCP session on **purplesurgical.com**. Everything below is **local & uncommitted**.

## TL;DR
A live production session on purplesurgical.com surfaced **12 issues** (data-loss
patch bug, transport idempotency, ACF term-meta gap, audit-integrity, opaque
errors, schema/handler drift, missing term lookup + cache purge). **11 are fully
fixed and code-complete**; the 12th (Issue 7 full registry→schema codegen) is
partially done — the concrete param gaps are fixed, the architectural refactor is
deferred. All changes are **staged in the working tree only** — nothing committed,
pushed, or deployed. A verified build is on the Desktop as `wp-command-center.zip`
for upload-and-test on the live site.

## Repository state
| Item | Value |
|---|---|
| Branch | `main` |
| HEAD | `13549c2` (unchanged this session) |
| Local vs `origin/main` | **ahead 0 / behind 0** — HEAD == origin == prod |
| Production | `origin/main` `13549c2` via pull-deploy — **has NONE of this session's fixes** |
| Working tree | **50 files changed, ~854 insertions** — all UNCOMMITTED |
| Build artifact | `~/Desktop/wp-command-center.zip` (~823 KB, 309 files) — runtime-only, verified |

## Invariants — CHANGED this session
Was `34·23·40·40·2.5.0` → **now `34·23·42·42·2.6.0`**
- OPERATION_MAP **34** (unchanged — new ops are unrestricted)
- ALL_CAPABILITIES **23** (unchanged)
- Operation catalogue **40 → 42** (added `term_manage`, `cache_manage`)
- MCP tools **40 → 42** (one per operation)
- DB_VERSION **2.5.0 → 2.6.0** (new `wpcc_idempotency` table)

## Issues & status
| # | Issue | Status | Key change |
|---|---|---|---|
| 1 | Patch same-file multi-op clobber (data loss) | ✅ | `PatchOperation::normalize_files()` chains same-path ops on evolving content + collapses; `wpcc_change_set_conflict` atomic reject; `PatchApproval::apply()` post-apply content diff |
| 2 | Relay lost-response / no idempotency | ✅ | Relay AbortController timeout+retry+idempotency_key; `IdempotencyStore` (atomic claim); `change_history operation_status` lookup |
| 3 | No ACF term/user/option value write | ✅ | New `acf_value_set` action (object_type routing + location-mismatch guard + rollback) |
| 4 | Failed calls logged as "applied" | ✅ | `OperationExecutor` routes in-band `{error:true}` to `rejected`/`failed` record |
| 5 | media_search param mismatch | ✅ | schema `search` + aliases (query/s) + param-naming error |
| 6 | acf_group_get field_count: 0 | ✅ | `summarize_group()` counts `acf_get_fields()` |
| 7 | Schema/handler param drift | ⚠️ Partial | Concrete gaps declared in schema; `acf_describe`/`woo_describe` added. **DEFERRED: registry→schema codegen + CI diff** |
| 8 | Generic "Tool execution failed" | ✅ | Existing `try/catch(\Throwable)` → `wpcc_handler_exception` envelope; validation errors name params |
| 9 | Invalid-action errors don't list valid | ✅ | ACF/Woo errors carry `valid_actions` + `*_describe` actions |
| 10 | acf_value_get ignores object_type | ✅ | Routes via `resolve_acf_selector`; errors instead of silent post_id 0 |
| 11 | No term lookup | ✅ | New `term_manage` runtime (list/get/search/describe, read-only) |
| 12 | Cache purge needs shell | ✅ | New `cache_manage` runtime (LiteSpeed/Rocket/W3TC/SuperCache/CacheEnabler/object-cache, no shell) |

## Files changed (uncommitted)

### New files
- `includes/Mcp/IdempotencyStore.php` — idempotency journal (claim/complete/lookup/release)
- `includes/Operations/TermRuntimeManager.php` — Issue 11
- `includes/Operations/CacheRuntimeManager.php` — Issue 12
- `tests/test-patch-samepath-chain.sh`, `tests/test-production-session-fixes.sh`,
  `tests/test-dx-drift-fixes.sh`, `tests/test-term-cache-runtimes.sh` — regression suites

### Modified — runtime code
- `wp-command-center.php` — `array_is_list()` PHP-8.0 polyfill
- `includes/Core/Schema.php` — DB 2.6.0 + `wpcc_idempotency` table
- `includes/Mcp/McpServerRuntime.php` — idempotency claim/complete in `tools_call` + `build_tool_response()`
- `includes/Operations/OperationExecutor.php` — in-band-error truthful record (Issue 4) + `resolve_handler` cases for term/cache
- `includes/Operations/ChangeRecorder.php` — `rejected` treated like `failed`
- `includes/Operations/OperationRegistry.php` — media `search`; acf object params; `term_manage`+`cache_manage` entries; `operation_status`/`acf_describe`/`woo_describe` risks
- `includes/Operations/ACFRuntimeManager.php` — `acf_value_set`/`value_get` routing, guard, `describe`, field_count, valid-action errors
- `includes/Operations/ACFRegistry.php` — `ACTION_VALUE_SET`
- `includes/Operations/WooCommerceRuntimeManager.php` — valid-action errors + `woo_describe`
- `includes/Operations/MediaRuntimeManager.php` — media_search aliases
- `includes/Operations/ChangeHistoryRuntimeManager.php` — `operation_status` action
- `includes/Operations/CapabilityRegistry.php` — `term_manage` in READ_ONLY_SCOPE_OPERATIONS
- `includes/Operations/PatchOperation.php` — same-path chaining + conflict + deploy-tracked advisory
- `includes/PatchSystem/PatchApproval.php` — post-apply content-integrity verify
- `sdk/javascript/wpcc-mcp-relay.mjs` — v2.1.0: timeout, retry, idempotency key, stderr key log
- `includes/Admin/views/ai-integrations.php` — token-fill config field (earlier this session)

### Modified — tests (invariant sweep only)
33 `tests/test-*.sh` files: catalogue/MCP-tool assertions `40 → 42`;
`test-operations-explorer.sh` `unmapped_count 6 → 8`. (Also `artifacts/step-36-validation/validation-evidence.json` — pre-existing noise, ignore.)

## Verification done
- `php -l` clean on every changed PHP file; `node --check` clean on relay.
- Standalone proofs: patch same-path chaining (old clobbers, new composes); `get_operations()` count = 42 with both new ids present.
- Test-suite invariant assertions swept to 42.

## NOT yet verified (needs the live site — shell/WP-CLI disabled on host)
- End-to-end ACF `acf_value_set`/`acf_value_get` on a real term.
- `term_manage` / `cache_manage` against real taxonomies + LiteSpeed.
- Idempotency dedupe + `operation_status` round-trip through the relay.
- Full T2 acceptance suite run (needs wp-cli bootstrap).

## How to resume
1. Everything is in the working tree — `git status` shows the 50 files above. Nothing to restore.
2. Rebuild the test ZIP any time:
   ```
   # copies includes/ assets/ sdk/ + 3 root files (runtime-only), excludes tests/docs/junk
   # see the build one-liner used this session (staging → zip -rqX --filesync ~/Desktop/wp-command-center.zip)
   ```
3. Deploy path (when ready): `git add -A && git commit && git push origin main` → hPanel cron pull-deploy (~1 min). Activation runs the **DB 2.6.0** upgrade automatically.
4. On the live client site: **Client mode** should be set (client-safe seed only affects fresh installs).

## Next actions (in priority order)
1. **User is upload-and-testing the Desktop ZIP on purplesurgical.com.** Await results per issue.
2. If green → **commit the whole set** (one release commit) and decide push/deploy.
3. **Deferred:** Issue 7 full registry→schema codegen + CI drift check (largest remaining item).
4. Before prod deploy: run the **full T2 acceptance suite** to confirm the 42/42 invariants and 0 net-new failures.

## Memory pointers
- `project_prod_session_fixes_2026_07_03.md` — full 12-issue detail + new invariants
- `project_patch_engine_samepath_bug.md`, `project_transport_idempotency_gap.md` — the two HIGH findings
