# Rebuild preparation — what must change once the ZIP is rebuilt

**Status: PREPARED, NOT APPLIED.** Nothing in this file has been written into the
release documents yet. The rebuild has deliberately not been run. Apply everything
below *after* the build phase, using the numbers that build actually produces —
never the numbers recalled from here.

---

## 1. Why the current artifact cannot be shipped

`build/ai-command-center-1.0.0.zip` is **26 commits stale**.

| | |
|---|---|
| Artifact SHA256 | `06ac6188d6f7683b3a57e13d6b6fe6b025a1f3d76a826ce4593c79e9373f62e3` |
| Artifact built | 2026-08-05 03:03:29 |
| Current HEAD | `74a69cd` (plus this sweep's commits) |
| Commits after the build | **26** |

The checksum **matches `RELEASE_HANDOFF.md` §2 exactly**, which is precisely the
trap: verifying it as documented confirms the wrong file. §2's own guard
("nothing built before 2026-08-04") passes this artifact while it is stale,
because it was built on the 5th. A date is not a commit binding.

Hard evidence the artifact is not the product:

```
views/ai-integrations.php     shipped 1106 lines   |   current 1512 lines
grep -c tokenmake             shipped 0            |   current 59
```

The entire token-creation dialog — required name, read-only default, expiry
choice, duplicate detection, one-time secret, long-lived-credential warning — is
absent from the artifact. It ships a single `generate_full` button. Commit
`67bf29d fix(security): tokens expire after 30 days unless you say otherwise` is
not in it.

**Do not upload this file. Do not re-verify it against §2 and conclude it is fine.**

---

## 2. Documents that reference the stale artifact

Only two files carry build-bound facts. Both must be updated in the same pass as
the rebuild, from the real output.

### `RELEASE_HANDOFF.md`

| Line | Currently says | Action |
|---|---|---|
| 16 | Commit `7ae4a32def…` | replace with the rebuild's HEAD |
| 47 | `Size 998,938 bytes (976 KB)` | re-measure |
| 49 | `SHA256 06ac6188…` | re-measure |
| 50 | `MD5 7aaa1249…` | re-measure |
| 51 | `Built 2026-08-04 from 7ae4a32` | re-record date **and commit** |
| 57 | Content identity `7f86aaec…` | re-measure (build-independent, but re-derive) |
| 20 | "23 commits unpushed" | now **0** — already pushed |
| 18 | "87 commits ahead of main" | now **114** + this sweep |
| 195 | `T2: 182 suites — 6,488 passed, 0 failed (3,763s)` | latest measured full run: **6,659 passed / 1 failed (3,790s)**; that 1 was a stale assertion, fixed in this sweep — re-run to confirm 0 |
| 520 | "expect 6,361 / 0" | stale from two passes ago; align with the new number |
| 40, 220 | "Plugin Check reported 0 errors across every file the package ships" | true again as of this sweep (measured 0 on shipped paths) — **re-measure against the ARTIFACT** after rebuild |
| 231 | "Lifecycle 24 / 24" | unverified in this pass; re-run or mark as inherited |
| 538 | §10.1 "`git rev-parse HEAD` must be `b6c46ec…`" | stale; update to the rebuild commit |

### `docs/SUBMISSION-PACKAGE.md`

| Line | Currently says | Action |
|---|---|---|
| 13 | SHA256 `06ac6188…` | re-measure |
| 14 | MD5 `7aaa1249…` | re-measure |
| 15 | Size `998,938 bytes` | re-measure |
| 16 | `Entries 327 files` | re-measure — note 327 = 292 files + 35 directory entries; the build script's own count is **292 files**, so state which is meant |
| 17 | Content identity `7f86aaec…` | re-measure |
| 18 | `Built 2026-08-04 from 7ae4a32` | re-record |
| 24 | `# must print 06ac6188…` | re-record |
| 90 | `Plugin Check (artifact) 0 errors · 814 warnings` | re-measure against the new artifact |

---

## 3. Add a commit binding, so this cannot recur

The failure was structural, not clerical: nothing tied the artifact to a commit,
so a stale ZIP passed every documented check. Recommended for the build phase:

- Record **SHA256 + HEAD commit + `git status --porcelain` empty** together, as one
  block, in both documents. A checksum without a commit is not a release identity.
- Consider having `scripts/build-release.sh` write a `BUILD-INFO.txt` into
  `build/` containing the commit, the tree-clean assertion and the timestamp, so
  the artifact carries its own provenance.

---

## 4. Pre-rebuild state (measured this sweep, before any rebuild)

| Check | Result |
|---|---|
| Plugin Check errors on **shipped paths** | **0** |
| Plugin Check errors elsewhere (tests/, sdk/php/, examples/, scripts/, build/, dotfiles — none packaged) | 208 |
| PHP lint, all shipped files | 275/275 clean |
| Targeted regression (T1, change-signal selected) | see the sweep report |
| `tests/regression-baseline.tsv` | still empty, per §4.4 |

Everything needed for the rebuild is in place. The rebuild itself, the fresh
install and the full T2 are deliberately **not** part of this sweep.
