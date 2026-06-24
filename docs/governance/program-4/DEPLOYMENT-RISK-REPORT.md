# PROGRAM-4 — Deployment Risk Report (Phase B)

> **Type:** failure analysis (audit-only). Assume the deploy fails; enumerate how. Grounded in the Phase-A forensics.

---

## 1. Merge risks
| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `main` advances before merge (another commit lands) → no longer fast-forward | LOW | conflict / non-ff | Re-verify `merge-base(main,RC)==main` immediately before merge; if advanced, rebase RC or use `--no-ff` and re-run battery |
| Wrong branch/commit merged | LOW | wrong code live | Pin the merge to `efeee24`; verify HEAD after merge |
| **Net merge risk** | **LOW** | — | fast-forward, 0 conflicts today (verified) |

## 2. Deploy risks (pull-based: `reset --hard origin/main` + reactivate + cache flush)
| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| New `includes/Rollback/*` class missing/typo at load → fatal | VERY LOW | site fatal | All files committed + `php -l` clean; PSR-4 `is_readable` guards; no registration needed |
| Reactivation fires a migration | NONE | — | `DB_VERSION` unchanged (2.5.0) ⇒ version-gated dbDelta is a no-op |
| `reset --hard` discards a hand-edit on the server | LOW | lost local edit | Pre-existing deploy-model behavior; server is a deploy target, not an edit host |
| Cron not running / `flock` stuck → deploy doesn't apply | LOW | stale prod (not broken) | Check `~/wpcc-deploy.log`; manual `bash ~/wpcc-deploy.sh` fallback |
| Opcode cache serves stale class | LOW | mixed old/new | `wp cache flush` in the script; OPcache typically resets on file mtime change |
| **Net deploy risk** | **LOW** | — | additive, migration-free |

## 3. Rollback (feature) risks — production behavior of the shipped rollbacks
| Risk | Likelihood | Impact | Severity |
|---|---|---|---|
| **Option-tier FIFO eviction** (Settings/Media/Comments/Users + shared Woo/ACF-def) — a surfaced `rollback_id` evicted on a busy store → `rollback_not_found` | MED on high-volume sites | a real rollback can't be applied (no corruption) | **HIGH residual** (carried; non-blocking) |
| Drift conflicts more frequent on a LIVE site (real concurrent edits via Elementor/ACF UI/WP admin) → rollback returns `conflict`/`reversible:false` | MED | operator surprise; **correct behavior** (refuse-not-clobber) | MED (expectation, not defect) |
| Large real `_elementor_data` / Woo product / ACF nested value through `wp_slash`+JSON round-trip | LOW | restore mismatch | LOW (atomic whole-value; tested) |
| **Net rollback risk** | — | — | no corruption path; reliability + expectation only |

## 4. Production-only risks (cannot be fully closed off-host)
| Risk | Why production-only | Mitigation |
|---|---|---|
| **Plugin version drift** — prod ACF/Elementor/Woo versions differ from dev (dev: Elementor 4.1.3, ACF + Woo active) | accessors use public APIs (`get_field`/`update_field`, `_elementor_data`, WC CRUD) but version behavior is unverified on prod | Phase-C per-surface functional verify on prod confirms each accessor round-trips |
| **Plugin inactive on prod** (ACF/Elementor/Woo) | those rollback paths become inert | runtimes guard with `function_exists`/`class_exists` → graceful no-op, **not a fatal**; verify which are active on prod |
| **Pre-existing legacy rollback records** in prod `wpcc_*_rollbacks` options | dual-read legacy path must restore them | legacy-compat tested per phase; confirm a legacy restore on prod if any records exist |
| **Live HEAD identity** — HTTP can't reveal a commit hash | can't independently confirm prod == a41a9d7 from here | rely on the pull-deploy invariant + `~/wpcc-deploy.log`; confirm post-deploy via the deploy log/SSH |
| Prod scale: postmeta rollback-record growth | per-entity, GC with entity, not autoloaded | bounded; monitor `wp_postmeta` size trend |

## 5. Migration risks
**MINIMAL — no schema change.** No new table/column, no `DB_VERSION` bump, so no `dbDelta` runs on deploy/reactivation. The only "migration" is **data-format coexistence**: new v2/postmeta records vs pre-existing legacy `before_state` option records — every migrated runtime dual-reads (verified). No data transformation, no destructive migration, no backfill.

## 6. Hidden assumptions (made explicit)
1. **The deploy-coupled acceptance gate (serial T2 + prod token-gated functional verify) will pass** — NOT yet run on the deploy host. **This is the one open gate (BLK-2).**
2. Prod is genuinely at `a41a9d7` and the live tree matches (pull-deploy invariant; not SSH-verified here).
3. Prod AI flags remain **OFF**, Anthropic key **unset**, security mode **`developer`** — unchanged by this RC (verified: no flag/security/key code touched), but assumed unchanged by other operators.
4. No other change is queued to `main` concurrently.
5. The `test-alt-text` 4-red environmental (dormant AI, key unset) remains the documented non-attributable baseline — not introduced by Program-4.

## 7. Failure-analysis verdict
No deploy-failure mode rises to a corruption or site-fatal probability that blocks deployment **given the gate is run**. The dominant residual is **reliability** (option-tier FIFO eviction) and **operator expectation** (refuse-on-drift), both non-blocking. The single hard prerequisite is executing the **production validation plan (Phase C)** + serial T2 on the deploy host.
