> # SUPERSEDED — HISTORICAL RECORD ONLY
>
> This document is **not current** and must not be used to verify, build, or ship
> anything. It is kept because it records what was true when it was written.
>
> **The single authoritative engineering document is [`RELEASE_HANDOFF.md`](../../../RELEASE_HANDOFF.md) at the repository root.**
>
> **Still useful for:** what a clean local environment rebuild involved, if you ever have to do it again.
>
> Superseded on 2026-08-10. Reason: record of a one-off local environment reset

---

# WPCC Reset to First-Install State — record & restore instructions

**Performed:** 2026-08-08 · **Result:** WPCC is at a faithful first-install state.
No release code changed. No WordPress content touched.

---

## Where your data went

Everything removed was backed up **first**, and verified, to:

```
build/reset-backup-20260808-124645/     (148 MB, git-ignored, durable)
```

| File | Contents |
|---|---|
| `data-<table>.ndjson` × 16 | every row of every `wpcc_*` table (newline-delimited JSON) |
| `schema-<table>.sql` × 16 | `SHOW CREATE TABLE` for each |
| `options.json` | all 39 `wpcc_*` options **including your Anthropic API key** |
| `usermeta.json` | 8 `wpcc_*` user-meta rows |
| `uploads/` | all six `wpcc-*` upload dirs (tokens, patches, 76 MB audit log, snapshots) |
| `manifest.json` | row counts + provenance |

**Backup integrity was verified before deletion:** 15 of 16 tables matched exactly;
`telemetry` differed by +1 row because it is written continuously and grew *during* the
backup. The API key was verified by SHA-256 comparison against the live value.

### Your Anthropic API key

It was deleted, because a first install has no key and you asked to perform "First
Built-in AI setup" yourself. It exists in **two** places:

1. `build/reset-backup-20260808-124645/options.json` → `wpcc_anthropic_api_key`
2. `wpcc-env.sh` (unchanged by this task)

To read it back without printing it to a terminal:

```bash
wp eval '$o=json_decode(file_get_contents("build/reset-backup-20260808-124645/options.json"),true);
foreach($o as $r){ if($r["option_name"]==="wpcc_anthropic_api_key"){ echo $r["option_value"]."\n"; } }'
```

### Restoring everything (if you ever need to)

The backup is plain JSON, so a restore is a scripted re-insert rather than a single
command. Restore options first, then table rows via `$wpdb->insert()` per line, then copy
`uploads/*` back into `wp-content/uploads/`. Nothing is compressed or encoded.

---

## What was removed

**Database — 86,791 rows across all 16 tables** (emptied with `TRUNCATE`, never dropped):

| Table | Rows | | Table | Rows |
|---|---:|---|---|---:|
| telemetry | 56,149 | | agent_actions | 199 |
| operation_results | 16,378 | | agent_sessions | 183 |
| change_log | 11,087 | | agent_plan_steps | 156 |
| operation_requests | 781 | | agent_tasks | 126 |
| snapshots | 650 | | health_verifications | 122 |
| patches | 387 | | agent_plans | 117 |
| operation_queue | 311 | | idempotency | 79 |
| proposals | 54 | | recommendations | 12 |

**Options — 44 removed.** All lazily-created runtime state: every `*_rollbacks` payload
(content, option, media, woo, theme, user, widgets, menu, comments, cpt, forms, acf, bulk,
plugin, settings, sitebuilder, seo), `wpcc_workflow_history`, `wpcc_workflows`,
`wpcc_plugin_backups`, `wpcc_capability_assignments`, `wpcc_ai_connections`,
`wpcc_ai_credentials`, `wpcc_ai_routes`, `wpcc_ai_usage`, `wpcc_anthropic_api_key`,
`wpcc_anthropic_model`, `wpcc_builtin_ai_tools`, `wpcc_environment_mode`,
`wpcc_enforce_capabilities`, `wpcc_cpt_configs`, `wpcc_media_file_snapshots`, and the
site-intelligence transient pair.

**User meta — 8 rows** (`wpcc_suspended`).

**Files — 1,078** across all six upload dirs, including the 76 MB audit log, 3 token
manifests, 385 patch files and 646 snapshots.

---

## What was preserved, and why

| Kept | Reason |
|---|---|
| All 16 tables (structure) | Schema and migration history must survive; `TRUNCATE`, not `DROP` |
| `wpcc_db_version` = 2.6.0 | Written by `Schema::install()`. A fresh install has it |
| `wpcc_migrated_v1` = 1 | Migration guard. `Schema::install()` sets it on a brand-new site — the migration finds nothing to do and records that it ran. Deleting it would make the next load re-run migrations against already-current tables |
| `wpcc_changelog_backfilled` = 1 | Same reasoning |
| `wpcc_security_mode` = `client` | Reset to the release default `Activator` seeds (Standard protection) |
| Cron `wpcc_process_operation_queue` | A fresh install schedules it. An unscheduled worker is a queue that silently never drains |
| Plugin code, capabilities, roles | Untouched |

### On the "long-term audit model" question

You asked whether any historical WPCC data is intentionally permanent and should survive.
It is — but **not on a first install**, which is the distinction that matters here.

`uninstall.php` states the policy explicitly: WPCC's tables hold an audit trail of who
changed what, and *deleting the plugin is not consent to erase it* — so uninstall
**retains** by default and purges only when the operator opts in. That protects a real
customer's history.

It does not apply to this task. That policy is about **not destroying a history that
belongs to someone**; the 86,791 rows here were generated by regression suites and this
session's testing, and a genuine first-time customer has **zero** of them. Keeping any
would have been the thing that revealed the site was heavily tested. So the audit model is
correct and unchanged — the reset simply returns it to the empty state every real install
starts from.

Two options were deleted whose *effective* values are unchanged, because a fresh install
has no row for either: `wpcc_enforce_capabilities` (code default `true`) and
`wpcc_environment_mode` (falls back to `wp_get_environment_type()` → `production`).

---

## Final state

```
Protection mode   Standard protection (client)
Built-in AI       SEO=off  Alt Text=off  Content=off
AI provider key   none
AI connections    0
Access tokens     0
Assistant state   no_token — "Not connected", never connected
Home              renders the SETUP flow, "Step 1 of 2"
DB version        2.6.0    Tables 16/16 present, all empty
Cron              scheduled
```

WordPress content untouched: **519 posts · 7 pages · 171 media · 881 users**, and all 17
non-WPCC upload folders (Elementor, WooCommerce, Rank Math, …).

---

## One thing to know before you run the test suites again

`tests/test-change-history.sh` (and other token-dependent suites) read a **full-scope
`WPCC_TOKEN` from `wpcc-env.sh`**. That token's record was deleted with all the others, so
it no longer validates — the suites will fail with 24 assertions until you point them at a
live token.

This is not a defect and not fixable while the site stays fresh: **a first-install state
and a token-dependent harness are mutually exclusive.** After you create your first token
during manual validation, paste it into `wpcc-env.sh`:

```bash
WPCC_TOKEN="wpcc_…"
```

`wpcc-env.sh` was left **byte-for-byte unchanged** (SHA-256 verified) — it still contains
the old, now-dead token.

---

## Helper scripts left behind

`scripts/wpcc-reset-backup.php` and `scripts/wpcc-reset-to-fresh.php` are reusable — run
the reset again after your manual validation if you want to hand the site to someone else
fresh. Both are excluded from the release ZIP (`build-release.sh` uses an allowlist that
does not include `scripts/`), so neither ships.

They exist because `wp db export` / `mysqldump` cannot authenticate under AMPPS on this
machine (`ERROR 1045` for `root@localhost`), so the normal "take a dump first" step was
unavailable; `$wpdb` authenticates fine and the backup was taken through it.
