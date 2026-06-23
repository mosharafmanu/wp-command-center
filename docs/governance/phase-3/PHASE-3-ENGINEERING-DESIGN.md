# Phase 3 — Engineering Design (Field-Scoped, Drift-Aware SEO Rollback)

**Defect:** F-1 (HIGH) — SEO rollback full-snapshot over-reach
**Scope:** SEO runtime only (per Architecture Audit §4)
**Invariants frozen:** OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0
**No schema change. No new route/op/cap/tool. `rollback_id` contract unchanged.**

---

## 1. Goal

Replace SEO's full-object rollback with a record that captures **only the fields the
operation touched**, and a restore that is **field-scoped, drift-aware, idempotent,
existence-faithful, and history-honest**, while keeping legacy full-snapshot records
restorable through the legacy path.

---

## 2. Separation of concerns: semantic drift vs byte-faithful restore

The design splits the two jobs that the old code conflated:

- **Drift detection — semantic, at the unified-field level.** Compare the current live
  unified value of a touched field to the `after` value the operation produced.
- **Restore — byte-faithful, at the backing-meta-key level.** Restore the exact prior
  raw meta values (and their existence), provider-correct, with no normalization loss.

This makes drift detection match the user's mental model ("did this field change since
I applied it?") while restore reproduces the precise prior storage, including Yoast's
multi-key robots split and Rank Math's array.

---

## 3. Delta rollback record (format v2)

Stored exactly as today — one protected post-meta row `_wpcc_seo_rb_{id}` — but with a
new shape. New rollbacks write **v2**; legacy records keep their old shape.

```php
[
    'id'               => '<uuid4>',     // unchanged public rollback_id contract
    'version'          => 2,             // delta discriminator (absent ⇒ legacy)
    'post_id'          => <int>,
    'provider'         => 'rankmath'|'yoast',
    'created_at'       => <time>,
    'session_id'       => <?string>,
    'task_id'          => <?string>,
    'rollback_applied' => false,
    'fields'           => [               // ONLY fields this operation touched
        '<unified_field>' => [
            'after' => <mixed>,           // unified value the op applied (drift compare)
            'keys'  => [                  // backing meta keys (faithful restore)
                '<meta_key>' => [
                    'existed' => <bool>,  // metadata_exists() BEFORE the op wrote
                    'prior'   => <mixed>, // raw get_post_meta value BEFORE the op
                ],
                // robots on Yoast expands to 3 keys; scalars to 1
            ],
        ],
    ],
]
```

Per the requirements, each touched field record captures: field name (the map key),
prior value (`keys[*].prior`), prior existence flag (`keys[*].existed`), applied/after
value (`after`), provider (record `provider`), content/post id (record `post_id`),
timestamp (`created_at`), and operation/change identity (`session_id`/`task_id`; the
`rollback_id` is the change identity carried by ChangeRecorder). The full SEO object is
**no longer stored** for new records.

### Field → backing-key mapping

`SeoProvider::backing_keys($field, $provider)`:

- Scalar field → its one mapped key (`SeoProvider::meta_key`).
- `robots`, Rank Math → `['rank_math_robots']` (array meta).
- `robots`, Yoast → `['_yoast_wpseo_meta-robots-noindex',
  '_yoast_wpseo_meta-robots-nofollow', '_yoast_wpseo_meta-robots-adv']`.

---

## 4. Store path — `seo_update`

Refactor so the record is persisted **after** the write (we need the `after` values),
while prior capture happens **before** the write:

1. `$touched = array_keys( $fields )` (the requested fields only).
2. **Before write** — for each touched field, for each backing key: capture
   `existed = metadata_exists('post', $post_id, $key)` and `prior = get_post_meta(..., true)`.
3. `$updated = SeoProvider::write( $post_id, $fields, $provider )` (unchanged).
4. **After write** — `after[$field] = $updated[$field]` (already the post-write unified read).
5. Persist v2 record via `store_rollback()` → returns the same uuid4 `rollback_id`.
6. Audit `seo.updated` gains `rollback_format => 'delta'` (and keeps `fields`).

The `rollback_id` is still returned in the `seo_update` result and recorded by
ChangeRecorder — contract unchanged.

---

## 5. Restore path — `seo_restore`

Record resolution by `rollback_id` (indexed meta_key lookup) is unchanged. After
loading the record:

- `version === 2` (or `isset($record['fields'])`) → **delta restore**.
- else (`before_state` present) → **legacy full restore** (existing code, untouched).

### Delta restore algorithm

```
restored = []; skipped = []; conflicts = []
foreach ($record['fields'] as $field => $spec):
    $current = SeoProvider::read_field($post_id, $field, $provider)
    if ( ! values_equal($current, $spec['after'], $field) ):        # DRIFT
        $skipped[] = $field
        $conflicts[] = { field, reason:'drift', expected:$spec['after'], current:$current }
        continue
    foreach ($spec['keys'] as $key => $meta):                       # faithful restore
        if ($meta['existed']):  update_post_meta($post_id, $key, $meta['prior'])
        else:                   if (metadata_exists(...)) delete_post_meta($post_id, $key)
    $restored[] = $field

status = empty($skipped) ? 'complete'
       : (empty($restored) ? 'conflict' : 'partial')
```

`values_equal`:
- `robots` → normalize both via the provider's robots normalization (sort), compare.
- scalar → `(string)` compare.

### Existed-vs-empty fidelity

Restore uses the captured **`existed`** flag, not value emptiness:
- prior absent (`existed=false`) → `delete_post_meta` (delete on rollback).
- prior present (`existed=true`) → `update_post_meta` with the exact `prior`, **even if
  `prior` is `''`** (preserve empty-but-existing). This is the fidelity the old
  `(string)`-cast + delete-on-empty path could not provide.

### Idempotency / applied marking (terminal rules)

**Revised per Adversarial Review §B — only `complete` is terminal.**

| status | restored | marks `rollback_applied` | result | `success` |
|---|---|---|---|---|
| complete | all touched, no drift | **true** (terminal) | success result, `restored:true` | true |
| partial | some restored, ≥1 drift | **false** (retryable) | `error: wpcc_rollback_partial` + details | false |
| conflict | none (all drift) | **false** (retryable) | `error: wpcc_rollback_conflict` + details | false |

Rationale:
- **complete** marks applied → second call returns `already_applied` (idempotent guard).
- **partial** and **conflict** are left retryable so the correct multi-step recovery
  remains reachable: roll back the newer shadowing change first (its `after` matches
  live, so it restores cleanly), then retry the older one (now non-drifted). A retry
  never clobbers — drift always skips — so repeated drift-blocked attempts are safe and
  self-converging. This also keeps the SEO record and the Change-History row consistent
  (both remain "not fully reverted" until a clean `complete` restore stamps the change).

### History honesty

The restore result always carries `status`, `restored_fields`, `skipped_fields`,
`conflicts`, and `path` (`delta`|`legacy`). Because partial/conflict return an `error`
envelope, `OperationExecutor::rollback` computes `success=false` (it keys on
`empty($res['error'])`, L428/L450), so **Change-History never records a partial/
conflict rollback as a clean success**. A `complete` delta restore is the only path
that reports success.

---

## 6. Drift policy (false positive / false negative posture)

- **Default safe policy:** on drift, **skip** the field and **report** the conflict.
  Never clobber a value that diverged from what this operation applied.
- **False positives** (flagging a field as drifted when it semantically matches) are
  avoided by comparing normalized values (`robots` sorted; scalars string-cast),
  mirroring exactly how `seo_update` produced `after`.
- **False negatives** (missing a real divergence) are avoided because `after` is the
  literal post-write unified value, so any subsequent write to that field changes the
  live value and trips the compare.

---

## 7. Legacy compatibility

- Records lacking `version`/`fields` (have `before_state`) restore via the **unchanged
  legacy path** (`seo_restore_legacy` for option-based pre-4c rows; the post-meta
  branch gets a legacy sub-branch for `before_state`-only rows). No migration, no
  rewrite of historical records — forward-only.
- The legacy result carries `path => 'legacy'` for history honesty but otherwise keeps
  its current behavior and `restored:true`.

---

## 8. SeoProvider additions (read-only helpers, no behavior change to write)

1. `backing_keys(string $field, string $provider): array` — field → backing meta keys.
2. `read_field(int $post_id, string $field, string $provider): mixed` — single unified
   field read (scalar string or normalized robots array) for drift comparison. Wraps
   the existing private `read_robots`; no new storage semantics.

`SeoProvider::write()` is **unchanged** — the new write helpers are not needed because
restore operates on raw backing keys directly (faithful), and forward writes still go
through the existing unified `write()`.

---

## 9. Files to change

| File | Change |
|---|---|
| `includes/Operations/SeoProvider.php` | add `backing_keys()`, `read_field()` (read-only helpers) |
| `includes/Operations/SeoRuntimeManager.php` | v2 delta store in `seo_update`; delta restore branch in `seo_restore`; legacy branch retained; richer result + audit |
| `tests/test-seo-rollback-delta.sh` (new) | Phase 3 validation suite (all scenarios) |

No changes to: registries, OperationExecutor, ChangeRecorder, ChangeHistory,
REST routes, capabilities, operation map, MCP tool list, DB schema, admin UI.

---

## 10. Requirements traceability

| Design requirement | Where satisfied |
|---|---|
| 1. Delta record (touched fields only) | §3 `fields` map; §4 store |
| 2. Field-scoped restore (no sibling writes) | §5 — only `record['fields']` keys are touched |
| 3. Existed-vs-empty fidelity | §5 existence flag; §3 `keys[*].existed` |
| 4. Drift detection (skip + report, default safe) | §5 drift branch; §6 policy |
| 5. Legacy compatibility (no destructive migration) | §5 branch; §7 |
| 6. History honesty (restored/skipped/conflicts/path/complete) | §5 result; §5 honesty |
| 7. Audit trail (creation, restore, drift, partial, legacy) | §4 audit; §5 result→audit |
| 8. Capability scoping unchanged (34/23/40/40) | §9 — no registry touch |
| 9. No schema change (DB 2.5.0) | §9 — post-meta record shape only |

Proceed to Adversarial Design Review.
