# PROGRAM-4B — Integration Branch + Rollback Core Hardening · Final Report

> **Branch:** `program-4b-integration-core-hardening`. **No merge / push / deploy.**
> Companion: [Design](PROGRAM-4B-DESIGN.md) · [Implementation](PROGRAM-4B-IMPLEMENTATION-REPORT.md) · [Validation](PROGRAM-4B-VALIDATION-REPORT.md) · [Independent Audit](PROGRAM-4B-INDEPENDENT-AUDIT.md).

## 1. Integration commit graph
```
main a41a9d7
 └─ P4.0 2234dcc  RollbackDelta core
      ├─ P4.1 0788720  Settings ─┐
      ├─ P4.2 8982e6c  Media    ─┤
      ├─ P4.3 dbc7c47  Content  ─┼─ octopus ─> 6a8aad0 ─> <P4B core-hardening>
      ├─ P4.4 4ccf18b  Comments ─┤
      └─ P4.5 6b5d0ef  User     ─┘
```
Octopus merge `6a8aad0` (5 parents; P4.0 reachable via each); **0 conflicts**; all six commits are ancestors of HEAD.

## 2. Changed files (P4B hardening, vs merge `6a8aad0`)
**New:** `includes/Rollback/RollbackStore.php`, `OptionListRollbackStore.php`, `OptionKeyedRollbackStore.php`.
**Modified:** `includes/Rollback/RollbackDelta.php` (+`build_record`, +`result`); `SettingsRuntimeManager`, `MediaRuntimeManager`, `ContentManager`, `CommentsRuntimeManager`, `UserManager`; the 5 focused tests; +5 P4B reports.

## 3. Diff stat (P4B hardening)
```
 5 runtime managers       | net SMALLER (inline record/envelope removed)
 RollbackDelta.php        | +73  (build_record + result)
 RollbackStore.php        | +46  (interface)
 OptionListRollbackStore  | +78
 OptionKeyedRollbackStore | +52
 5 tests                  | re-pointed guards + unique-id fixtures
 14 files, +330 / -161
```

## 4. Tests run — pass/fail
core **25/0** · SEO **56/0** · Settings **38/0** · Media **41/0** · Content **30/0** · Comments **27/0** · User **28/0** · operations-registry **18/0** · capability-runtime **61/0** · mcp-error-surface **18/0** · change-history-rollback (alone) confirmatory. **Attributable failures: none.**

## 5. Invariant status
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — held.

## 6. Backward-compatibility verdict
**COMPATIBLE.** `build_record` is byte-identical to the prior inline records; stores read/write the same options/shape/cap. **Proven** by probe: a pre-hardening v2 record and a legacy `before_state` record both restore correctly under P4B. SEO record format frozen (SEO not migrated).

## 7. GO / NO-GO for commit
**GO.** Integration complete + conflict-free; core hardened (D1+D2+D3); 5 runtimes consolidated with identical behaviour; backward-compatible; invariants held; no forbidden/contract/schema drift; no Woo/ACF/Bulk slip. **Commit on `program-4b-integration-core-hardening` only — no merge/push/deploy.**

## 8. Suggested commit message
```
refactor(rollback): harden RollbackDelta core — build_record + result + RollbackStore (P4B)

Integration branch (P4.0 + P4.1–P4.5, octopus merge, 0 conflicts) + core hardening
addressing the midpoint audit D1/D2/D3:

  - RollbackDelta::build_record()  v2 record builder (D1; byte-identical output)
  - RollbackDelta::result()        complete/partial/conflict response envelope (D2)
  - RollbackStore + OptionListRollbackStore + OptionKeyedRollbackStore  storage API (D3)

Settings/Media/Content/Comments/User migrate onto the core: each store_*_delta now
uses build_record + a RollbackStore; each rollback v2 envelope uses result().
Settings/Content use the store end-to-end; Media/Comments/User use persist + result
with their existing inline (multi-action-shared) resolution. SEO is intentionally not
migrated (its postmeta-per-record store is already scalable and is the reference).

Behaviour-preserving and backward-compatible (a pre-hardening v2 record and a legacy
before_state record both restore under P4B). Runtimes net-smaller; duplication moved
to the core. Tests: core 25/0, seo 56/0, settings 38/0, media 41/0, content 30/0,
comments 27/0, user 28/0. Invariants 34/23/40/40/2.5.0 held; no schema/op/cap/MCP/
REST/UI change; no Woo/ACF/Bulk.

PROGRAM-4B.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

## 9. Can Woo begin from this branch?
**YES.** P4.6 Woo should branch from this integration branch: it reuses `build_record` + `result` + `OptionListRollbackStore` (a `WooProductAccessor` is the only new piece for products; orders deferred). ACF likewise (whole-def + drift-guard). **Bulk** adds a per-item `RollbackStore` implementation on this same interface. The non-scaling list-scan is now encapsulated behind the store, ready to be swapped per runtime.
