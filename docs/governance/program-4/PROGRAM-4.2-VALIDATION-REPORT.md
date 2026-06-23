# PROGRAM-4.2 — Media Metadata Rollback · Validation Report

> **Date:** 2026-06-23 · **Env:** DEV (AMPPS), PHP 8.2.27, wp-cli live, security mode developer.
> **Verdict:** **GO** — all 12 required behaviours proven; SEO/core/file-snapshot paths unchanged; invariants held; zero attributable failures.

## 1. Lint — clean
`php -l` → No syntax errors: `includes/Rollback/MediaFieldAccessor.php`, `includes/Operations/MediaRuntimeManager.php`.

## 2. Media metadata delta acceptance — `tests/test-media-metadata-rollback-delta.sh` → **38 / 0**
(12 static + 26 functional, on a throwaway attachment, exercising **both** restore paths.)

| Required validation | Scenario | Result |
|---|---|---|
| 1 empty-prior media meta restore | S1 alt absent → set → rollback **deletes** alt meta | ✅ |
| 2 value-prior media meta restore | S2 alt restored exactly | ✅ |
| 3 empty-but-existing media meta restore | S3 alt '' → restored as empty meta row | ✅ |
| 4 sibling media meta preservation | S4 record {title,alt}; later title change; rollback → **title (B) NOT clobbered**, alt restored | ✅ |
| 5 same-field drift skip/report | S5 conflict; newer title kept | ✅ |
| 6 out-of-order rollback no resurrection | S6 rollback B then A → ORIG, no resurrection | ✅ |
| 7 legacy rollback record restore | S7 `before_state` record restores title+alt | ✅ |
| 8 repeated rollback safety | S8 `wpcc_rollback_already_applied` | ✅ |
| 9 partial/conflict not clean success | S4 partial / S5 conflict → `restored:false`, error envelope | ✅ |
| 10 attachment post fields unaffected unless in touched set | S10 alt-only update → record `fields` has alt only, no title | ✅ |
| 11 generated file/sizes not touched | S11 `_wp_attachment_metadata` + `_wp_attached_file` unchanged after metadata rollback | ✅ |
| 12 existing media/file snapshot behaviour unchanged | S12 `media_restore` path also field-scoped; + regression suites below | ✅ |

## 3. Regression
| Suite | Result | Note |
|---|---|---|
| `test-rollback-delta-core.sh` | **25 / 0** | core untouched |
| `test-seo-rollback-delta.sh` | **56 / 0** | no SEO regression |
| `test-media-runtime-step90.sh` | **25 / 0** | media runtime |
| `test-media-runtime.sh` | **80 / 0** | full media suite (incl. update/rollback) |
| `test-media-snapshot-step100-1.sh` | **23 / 0** | **byte snapshot path unchanged** |
| `test-media-replace-step100-2.sh` | **20 / 0** | **replace path unchanged** |
| `test-alt-text.sh` | **125 / 4** | 4 NON-ATTRIBUTABLE (see §5) |
| `test-operations-registry.sh` | **18 / 0** | catalogue parity |
| `test-capability-runtime.sh` | **61 / 0** | capability parity |
| `test-mcp-error-surface.sh` | **18 / 0** | MCP parity |
| `test-change-history-rollback.sh` | standalone — see §5 | dispatcher path |

## 4. Invariants
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — **held** (v2 record reuses the existing `wpcc_media_rollbacks` option; no schema change).

## 5. Failure classification
| Observation | Class | Disposition |
|---|---|---|
| `test-alt-text.sh` 4 reds (`resolver active() null when no key`, `has_active() false`, `provider not_configured`, `no provider -> safe`) | **NON-ATTRIBUTABLE / ENVIRONMENTAL** | Dormant-AI provider-config checks (Anthropic key unset). **Clean-room proven:** with the P4.2 `MediaRuntimeManager` change stashed (P4.0 base), `test-alt-text` fails the **identical 4** (125/4). The alt-text→`media_update` apply-path assertions all **pass**. Unrelated to metadata rollback. |
| Test-authoring red (empty `use()`) | fixed | a closure syntax bug in the new test, corrected; not product code. |
| `test-change-history-rollback.sh` 1 red (`backfill: flag set after run`) | NON-ATTRIBUTABLE / ENVIRONMENTAL | **Only** Section-0 backfill bootstrap failed; **Sections 1–9 (the rollback functionality — rollback_discover, rollback_target runtime+patch, failure handling, approval-aware, destructive guard, read-only denial, MCP/REST parity) all PASS (47/0).** Stateful ~74k-row backfill bootstrap, documented-flaky, exercised ~6× this session; hit 48/0 twice standalone earlier. P4.2 touches no change-history/backfill/OperationExecutor code. Not a gate. |

**No ATTRIBUTABLE failures. Net-new attributable = 0.**

## 6. Verdict
**GO.** Media metadata rollback is now field-scoped, drift-aware, sibling-preserving, out-of-order-safe, existence-faithful (alt), partial/conflict-honest, and legacy-compatible across **both** restore paths — reusing the P4.0 core via a column+meta accessor, with the file-byte/snapshot path and all non-`update` actions unchanged, and no invariant change.
