# PROGRAM-4C — Revised Rollback Integrity Roadmap

> **Type:** roadmap (no code, no branches). Report-only. Supersedes PROGRAM-4-DESIGN §7 for the *remaining* work (P4.0–P4.6 + P4B are complete).
> **Invariants frozen:** 34 · 23 · 40 · 40 · 2.5.0. **Production stays `a41a9d7`.** Every phase: design → implement → self-audit → validate → independent audit → branch-commit; **deploy is a separate Rule-7 decision.**

---

## 1. Sequencing principles
1. **Stop active harm before improving architecture** — the Bulk corruption + the silent-irreversibility gaps are fixed first (correctness hotfixes), independent of the larger delta migrations.
2. **Build shared infrastructure where it unlocks multiple surfaces** — `PostMetaRollbackStore` and the whole-def+drift-guard mode are written once and reused.
3. **Order delta migrations by Corruption × Likelihood × Blast radius** (Bulk → ACF → Elementor).
4. **Make irreversible-by-nature surfaces honest** before attempting to make them reversible (G2 Tier-1 first).
5. **Defer relational/schema-bearing work** to its own design (Woo orders, A2-1 reaper).
6. **One surface per phase, legacy-compatible, schema-free, validated against the SEO gate.**

---

## 2. The waves

### Wave 0 — Correctness closures (small, high-value, no new architecture)
| Phase | Scope | Why first | Pattern | Complexity |
|---|---|---|---|---|
| **P4C.0a** | **Bulk corruption hotfix** — `bulk_status` restores status (stop title clobber); add capture+restore to `bulk_media`/`bulk_woo`/`bulk_acf`; add the regression tests that assert real reversion | removes **live data corruption** + silent irreversibility | A (legacy snapshot, corrected) | 2 |
| **P4C.0b** | **Woo F-3 closure** — add `store_rollback` to `variation_update` + `coupon_update` | closes silent irreversibility on price/discount | A now, or B via small accessors | 2 |
| **P4C.0c** | **Menu reorder** rollback + **OptionManager cap** + **CPT cap** | close two coverage gaps + bound option growth | A + housekeeping | 1.5 |

> Wave 0 is correctness-only; it intentionally does **not** attempt F-1 closure. It can ship as one small branch series or fold P4C.0b/0c into adjacent phases.

### Wave 1 — Keystone infrastructure + highest-risk delta migrations
| Phase | Scope | Pattern | Dependency | Complexity |
|---|---|---|---|---|
| **P4.7 — `PostMetaRollbackStore` + whole-def+drift-guard mode** | new `RollbackStore` impl (per-entity postmeta, schema-free) + `RollbackDelta` whole-def fingerprint mode | core | none (keystone) | 2 |
| **P4.8 — Bulk delta redesign** | per-item field-scoped delta over existing single-entity accessors; batch index; aggregate result; drift; partial-batch | B (per item) | P4.7 store | 4 |
| **P4.9 — ACF** | values→B (`ACFValueAccessor`, scalar+id-set+option-page); nested values→B whole-field+guard; config/layouts→A′ whole-def+drift-guard+child-orphan report; json_import→honest delist | B + A′ | P4.7 (store + whole-def mode) | 4 |
| **P4.10 — Elementor** | touched-widget-path field delta if feasible, else whole-`_elementor_data`+drift-guard (refuse-on-drift, no clobber) | B or A′ | P4.7 | 3.5 |

> Rationale for ordering inside Wave 1: P4.7 is the keystone (like the original P4.0). Bulk goes first among migrations because of active-harm + blast radius and because it exercises the new store. ACF and Elementor follow as the remaining high-corruption blobs; both reuse the whole-def+drift-guard mode.

### Wave 2 — Honesty + remaining coverage
| Phase | Scope | Pattern | Complexity |
|---|---|---|---|
| **P4.11 — G2 honesty (Tier 1)** | `plugin_update`/`theme_update` → `reversible:false` + DestructiveGuard confirmation + change_log flag | E-visible | 1 |
| **P4.12 — G2 plugin reversibility (Tier 2)** | plugin update artifact capture + restore; **wire the existing plugin-delete backup restore** | C (artifact) | 3 |
| **P4.13 — Forms update + low-risk hardening** | Forms `form_update` capture (CF7 `_form`/`_mail`/`_messages` as fields) or honest no-op→reversible:false; F-1/drift tests for CPT/SiteBuilder/Widgets; Widgets instance-numbering fix; Settings permalink `rewrite_rules` capture-or-recompute | B / E-visible | 2.5 |

### Wave 3 — Deferred (own designs, may trip Rule-7)
| Item | Why deferred | Schema? |
|---|---|---|
| **Woo orders** (order_update/status/note/refund) | relational/custom-table state; status-rollback re-email; HIGH gateway drift → needs a dedicated sub-design (suppress-email-on-rollback, refund reversal semantics) | none expected (HPOS-aware), but **confirm at design** |
| **A2-1 stale-`executing` reaper** | distinguishes dead process from slow handler → needs `claimed_at` column | **YES (schema + DB_VERSION)** — Rule-7 check-in before any code |
| **Theme update real reversibility** | large packages; snapshot-engine (Strategy B) better than ZIP | none |
| **Media byte edge-hardening** | disk-full / concurrent-regen tests; Pattern C already correct | none |

---

## 3. Dependency graph
```
P4.7 (PostMetaRollbackStore + whole-def+drift-guard)   ← keystone
   ├─ P4.8  Bulk delta        (needs per-item store)
   ├─ P4.9  ACF               (needs store + whole-def mode)
   └─ P4.10 Elementor         (needs store + whole-def mode)

Wave 0 (P4C.0a/0b/0c)  — independent of everything; do first
P4.11 G2 honesty       — independent (DestructiveGuard + change_log)
P4.12 G2 plugin artifact — needs P4.11 (honest flag) as predecessor
P4.13 Forms/low-risk   — P4.9 ACF accessor reusable for ACF-bulk; otherwise independent
Wave 3 — independent, separately scheduled
```
Reusable accessors already exist and are leveraged, not rewritten: `ContentFieldAccessor`, `MediaFieldAccessor`, `WooProductAccessor`, `OptionAccessor`, `CommentFieldAccessor`, `UserFieldAccessor` — Bulk fans out over these; ACF adds `ACFValueAccessor`.

---

## 4. Risk-reduction sequence (what each wave buys)
- **After Wave 0:** zero live data corruption; no silently-irreversible Woo price/discount or bulk media/woo/acf op; bounded option growth. *The platform stops actively losing data.*
- **After Wave 1:** F-1 closed for the three highest-blast surfaces (Bulk, ACF, Elementor); shared-option FIFO eviction eliminated for hot surfaces (per-entity postmeta); drift-aware everywhere migrated.
- **After Wave 2:** no false reversibility contract anywhere (G2 honest); plugin update/delete truly reversible; remaining low-risk gaps closed.
- **After Wave 3:** relational Woo orders + the uncatchable-fatal reaper handled under their own schema-aware designs.

---

## 5. Implementation & validation phases (per migration, the SEO gate)
Each phase repeats the proven P4.1–P4.6 shape:
1. **Design note** (affected files, accessor/store, pattern choice, storage-fit/R2 check).
2. **Implement** behavior-preserving + legacy-compatible (dual-read old records).
3. **Self-audit** (scope, invariants, no forbidden drift).
4. **Validate** — adapted **S1–S9** suite + **drift-injection** (the F-1 reproduction proving sibling survival) + coverage-gap negative tests; existing runtime suite stays green at tally; **core 25/0** and **SEO 56/0** unchanged (behavior-preserving proof); invariants 34·23·40·40·2.5.0 re-verified.
5. **Independent audit** (adversarial) → GO/NO-GO.
6. **Branch-commit** (no merge/push/deploy); update RUNNING-STATE + roadmap.

---

## 6. Rule-7 check-in points (where the owner must decide before code)
1. **A2-1 reaper** — `claimed_at` column ⇒ **schema + DB_VERSION**. Deferred to Wave 3; explicit check-in.
2. **Dedicated bulk table** — *only if* per-item postmeta is rejected ⇒ schema. **Not recommended**; postmeta is sufficient at N≤100. Check-in only if revisited.
3. **Woo orders relational store** — confirm HPOS/custom-table reversal needs no new column at its design (expected none, but verify).
4. **Any discovery-index need** on a per-entity rollback store ⇒ schema check-in (not anticipated; postmeta + a small index option suffices).

All other phases (Wave 0, P4.7–P4.13) are **schema-free**: postmeta/usermeta/commentmeta/option storage, plain-array records, new classes behind existing interfaces, new option keys (which do **not** bump DB_VERSION), and response-shape/guard changes. **No phase in Waves 0–2 trips a STOP condition.**

---

## 7. Suggested ordering (single linear sequence)
`P4C.0a (Bulk hotfix)` → `P4C.0b (Woo F-3)` → `P4C.0c (Menu/Option/CPT housekeeping)` → `P4.7 (keystone store + whole-def mode)` → `P4.8 (Bulk delta)` → `P4.9 (ACF)` → `P4.10 (Elementor)` → `P4.11 (G2 honest)` → `P4.12 (G2 plugin artifact)` → `P4.13 (Forms + low-risk)` → **deploy decision (serial T2 + prod verify)** → Wave 3 (Woo orders, A2-1 reaper) under separate designs.

Order is adjustable except: **Wave 0 precedes everything**, **P4.7 precedes P4.8/P4.9/P4.10**, and **P4.11 precedes P4.12**.
