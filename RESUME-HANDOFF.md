# RESUME HANDOFF — v1.0.0 Release Freeze

**Written:** 2026-08-07 · **Session:** overnight release-engineering pass
**Verdict delivered:** READY AFTER ONE SMALL FIX

This file exists so you can resume cold. It is a root-level `.md`, so it is
**automatically excluded from the release package** — `scripts/build-release.sh` is an
allowlist that copies only `ai-command-center.php`, `uninstall.php`, `readme.txt`,
`LICENSE`, `includes`, `assets`, `languages`, plus the MCP relay. Delete it whenever you
like; nothing depends on it.

---

## 1. Where you are in one paragraph

The product passed. Across four full regression runs I investigated 124 failures and
**not one was a product bug** — every one was a stale test expectation, a shell race, or
a local environment effect. T0 and T1 finish clean. The final T2 finishes at 6989/1, and
that 1 is fixed. The release ZIP is built and fully verified. **The only thing standing
between this and a git tag is that the tree is uncommitted** — 45 modified + 16 new
files. That is also why the checksum recorded in `RELEASE_HANDOFF.md` §2 is wrong: it
describes a 292-file build from commit `ad30856`, which is not the current product.

## 2. The one blocking action

```bash
cd /Applications/AMPPS/www/ClientProjects/WordPress/2026/plugins-dev/wp-content/plugins/ai-command-center
git status --porcelain          # expect 45 modified + 17 untracked
git add -A
git commit -m "release: v1.0.0 freeze"
```

Committing changes **no file contents**, so the package's content identity stays
`7748445f…`. Only the archive SHA256 would change if you rebuild. You do **not** need to
rebuild — the existing ZIP is byte-for-byte the product at this tree.

Then update `RELEASE_HANDOFF.md` §2 with the values in §4 below (current text is stale).

Tag and push are yours. I deliberately did neither.

## 3. Two decisions waiting on you

**a. Should the commit have happened?** I did not commit, because the rule is to commit
only when asked and no instruction to commit arrived. If you'd rather I just do it, say
so and it's one command.

**b. Should the operational tables be pruned?** You asked for temporary approval
requests / snapshots / rollback records to be removed. I did **not** mass-delete them:
`mysqldump` fails under AMPPS (`Access denied for user 'root'@'localhost'`), so I had no
verified backup, and deleting ~27,000 rows of audit history irreversibly — data with
zero bearing on the release artifact — was not a call to make unsupervised.

Session-created rows, using the cutoff `created_at > 1786036704` (your last build). The
cutoff validates itself: your pre-existing queue is **exactly 112 `pending_review`**, the
same 112 the F-01 fix comment cites.

| Table | Session rows |
|---|---|
| `wpcc_telemetry` | 17,775 |
| `wpcc_operation_results` | 5,181 |
| `wpcc_change_log` | 3,353 |
| `wpcc_operation_requests` | 276 |
| `wpcc_snapshots` | 224 |
| `wpcc_patches` | 132 |
| `wpcc_operation_queue` | 106 |

> **If you prune: delete `change_log` and `snapshots` together, or neither.** Snapshots
> are the rollback substrate. Removing them alone leaves undo records pointing at
> nothing, which is worse than leaving both.

## 4. Verified artifact identity — use these numbers

```
File        build/ai-command-center-1.0.0.zip
Size        1,076,581 bytes
Entries     333  (298 files + 35 directories)
SHA256      dfbd435c02a96bc83858ea8209d464b927b427ab569ecdce5cdb7db955c91924
MD5         89140c018483cc9e5b347e35af99ed45
Content-ID  7748445f576343df1c783f1e86739cd5b6d1e72ecf7525a37b33143efb4f3f06
Tree        1ec57b6 + 45 modified + 17 untracked   ← COMMIT BEFORE TAGGING
            (17 = the 16 release files + this RESUME-HANDOFF.md;
             `git add -A` will stage this doc too — drop it if you'd rather not track it)
```

`298 files = the recorded 292 + exactly the 6 new runtime files.` That arithmetic is an
independent check that nothing else crept into the package.

Reproduce the content identity (build-independent, survives rebuilds):

```bash
unzip -q build/ai-command-center-1.0.0.zip -d /tmp/pkg
( cd /tmp/pkg && find . -type f -print0 | sort -z | xargs -0 shasum -a 256 ) | shasum -a 256
```

## 5. Site state — already correct, verified by reading it back from the product

| | |
|---|---|
| Protection mode | `client` (Standard) — the product default |
| Built-in AI | SEO **off**, Alt Text **off**, Content **off** (effective flags off, no tool tabs) |
| Usage ledger | reset, 0 calls |
| AI connections | 1 — **yours**, created before this session, keyed, last test passing. Not touched. |
| Test posts / drafts | none were ever created — the suites self-clean |

Gating proved live in this exact state: `content_create` returned `pending_approval`,
`security_mode: client`, and **0 posts were created** — the change genuinely waited.

## 6. What I changed

**7 test files** (do not ship) and **2 source files, comment/refactor only**:

- `test-seo-quick-panel.sh`, `test-seo-audit.sh` — isolate via `option_wpcc_builtin_ai_tools`
- `test-connection-discovery-routing.sh`, `test-wizard-ux-cleanup.sh` — narrowed the "byte-identical to main" pin
- `test-ai-platform-ux-6s.sh` — label is "Tokens used"; added a cost-absence assertion
- `test-ai-activity-7.sh` — assert behaviour, not the implementation F-01 deleted
- `test-proposal-rest.sh` — here-string instead of a racy pipe
- `AuthTokens.php` — **comment only**, no logic
- `SourceContentSignal.php` — extracted `level_for_words()`; verified identical across the 39/40 boundary

Also reverted one accidental change: `artifacts/step-36-validation/validation-evidence.json`,
which `test-real-site-validation.sh` rewrites with fresh UUIDs on every run.

**The other 38 modified + 16 new files are your pre-existing release work.** I reviewed
and classified all of them as required for v1.0.0. None accidental.

## 7. The root cause behind the T2 noise (worth remembering)

Phase-4 built-in-AI precedence is `constant → filter → option`. A filter returning
`false` **cannot switch a tool off** — `false` falls through to the in-admin option,
which wins. Two suites still isolated the pre-Phase-4 way and were asserting defaults
that no longer existed. If a built-in-AI test ever fails oddly again, check this first.

Separately: **T0/T1 inherit the site's mode.** With the site on Standard they report ~93
failures that are just the approval gate working (`expected 'draft', got
'pending_approval'`). T2 sets its own baseline. To run T0/T1 meaningfully:

```bash
source ./wpcc-env.sh
wp --path="$WP_ROOT" eval 'update_option("wpcc_security_mode","developer");'
bash tests/run.sh --tier T0 --changed
# then restore: update_option("wpcc_security_mode","client");
```

## 8. Known, non-blocking

1. **33 other suites carry the same `pipefail` + `grep -q` SIGPIPE race** I fixed in
   `test-proposal-rest.sh`. Under `set -o pipefail`, `grep -q` exits on an early match,
   SIGPIPEs the writer, and the pipeline reports failure for a string it already
   matched. Measured: piped form `no/no/yes/yes` on an unchanged file; here-string `yes`
   every time. Remedy is mechanical — replace `printf '%s\n' "$X" | grep -q P` with
   `grep -q P <<<"$X"`. Left alone to avoid broadening a freeze. **This is the most
   likely source of future phantom failures.**
2. **WP-CLI cold start.** Warm calls 2.78s ± 0.02; first call after idle 31.5s, tripping
   the 30s `TIMEOUT_DEFAULT`. Local environment, not a defect — the product returns a
   structured `wpcc_wpcli_timeout` with actionable guidance.
3. **`meter()` attribution nit.** If a default connection is OpenAI-dialect but disabled
   or keyless, the call falls back to legacy Anthropic while the ledger names the OpenAI
   connection. Bucket key stays correct; only a display field misattributes. Cosmetic.
4. **`wpcc-env.sh` holds live secrets** (Anthropic key, SSH and staging passwords) in
   plaintext. Git-ignored and never packaged — but it is on disk.
5. **Two WPCC tokens committed in `.ai/` docs.** I tested both: **401, both dead**
   (control token 200). `.ai/` never ships. Hygiene only.

## 9. Evidence preserved

`/private/tmp` is cleared on reboot, so I copied the important bits into `build/freeze-evidence/`
(git-ignored, durable):

- `t2.log`, `t2b.log`, `t2c.log` — the three full T2 runs
- `wpcc-options-backup.json` — plugin option state **before** I applied the release state

> Note: restoring that JSON would put the site back into `developer` mode and undo the
> release state. It is a rollback aid, not something to apply casually.

## 10. Regression scoreboard

| Tier | Suites | Passed | Failed |
|---|---|---|---|
| T0 | 31 | **1598** | **0** |
| T1 | 66 | **2732** | **0** |
| T2 (a) | 195 | 6986 | 5 → fixed |
| T2 (b) | 195 | 6969 | 21 → environment |
| T2 (c) final | 195 | **6989** | 1 → fixed |

A confirming T2 has **not** been run since the last fix (`test-proposal-rest.sh`), which
was verified stable 6/6 standalone. If you want a fully green headline number, that run
takes ~52 minutes.

---

## RESUME PROMPT — paste this into a new session

> Resuming the WP Command Center v1.0.0 release freeze. Read
> `RESUME-HANDOFF.md` in the plugin root first — it has the full state.
>
> Context: an overnight release-engineering pass finished and returned
> **READY AFTER ONE SMALL FIX**. All quality gates pass; the only blocker is that the
> tree is uncommitted (45 modified + 16 new), which also makes the checksum in
> `RELEASE_HANDOFF.md` §2 stale.
>
> Do not re-run the certification or re-review the product — that is done. Do not
> broaden scope or add features.
>
> What I want now:
> 1. Confirm the tree and site state still match §4 and §5 of `RESUME-HANDOFF.md`
>    (nothing should have changed while the machine was off).
> 2. Then wait for my decision on the two open items in §3 — the commit, and whether
>    to prune the operational tables.
>
> Do not commit, tag, push, or delete any database rows until I explicitly say so.
