# PROGRAM-4 — Deployment Forensic Report (Phase A)

> **Type:** deployment-readiness forensics (audit-only; no code/merge/deploy). Verified directly from git/source.
> **RC:** `program-4-certification @ efeee24` · **Production:** `main @ a41a9d7`.

---

## 1. RC lineage (verified)
RC tip `efeee24`; last 4 commits: `efeee24` (D2 fix) → `76ee2f3` (cert docs) → `af6500d` (cert remediation: BulkAcfAccessor + plugin/theme honesty) → `89852d5` (merge P4.6). All 13 phase tips (P4.0–P4.10, P4B, P4C.0a) are ancestors of `efeee24` (re-verified). Nothing stranded.

## 2. Merge path into main — **CLEAN / FAST-FORWARDABLE**
- `merge-base(main, RC) = a41a9d7` = **main itself** ⇒ **main is a direct ancestor of the RC.**
- RC is **18 commits ahead** of main, **0 behind**. A merge is a **fast-forward** (no merge commit, no conflict) — or a `--no-ff` merge commit if a marker is preferred. **Zero conflict risk.**

## 3. Deployment path (pull-based — verified from `.ai/DEPLOY.md`)
1. `git push origin main`.
2. Server cron (every minute) runs `~/wpcc-deploy.sh`: `git fetch` → if advanced `git reset --hard origin/main` → **reactivate plugin if active** → `wp cache flush` (idempotent, `flock`-guarded).
3. Live within ~1 min; logged to `~/wpcc-deploy.log`.
- **Migration implication:** reactivation can fire the activation hook, but **`Schema::DB_VERSION` is UNCHANGED (2.5.0)** in the RC ⇒ any version-gated `dbDelta`/migration is a **no-op**. No schema migration runs on this deploy.

## 4. Rollback path (of the deployment)
- **Deploy revert:** `git revert`/reset `main` back to `a41a9d7` + `git push origin main` → the same cron `reset --hard`es production back within ~1 min. Because the merge is a fast-forward of additive commits with **no schema/registry/contract change**, reverting is clean and safe (no data migration to undo).
- **Per-feature data rollback:** independent of the deploy — every certified runtime carries its own field-scoped/atomic rollback (postmeta or option records); these are forward-only additive record formats with legacy dual-read, so a deploy revert does not orphan or corrupt prior rollback data.

## 5. Production invariants
At RC HEAD (verified live): **OPERATION_MAP 34 · capabilities 23 · DB_VERSION 2.5.0**; catalogue 40 / MCP 40 confirmed via passing `operations-registry` (18/0) + `mcp-error-surface` (18/0). Production (`a41a9d7`) already at the same invariants. **The deploy does not move any invariant.**

## 6. Release candidate integrity
- **Deploy surface (main..RC):** **only** `includes/Operations/` (12 runtime managers), `includes/Rollback/` (18 accessor/store/core files), `tests/` (13 suites), `docs/governance/program-4/` (68 docs). **43 code/test files, +4715/−187.**
- **Forbidden-touch scan — ALL CLEAN:** no `Schema.php`/DB_VERSION, no `CapabilityRegistry`/`OperationRegistry`, no `includes/MCP/`, no REST dirs, no `includes/Admin/`/`assets/`, no AI-flag/security/key code. No main plugin file, activation/deactivation hook, cron, composer, or autoloader change.
- **New `includes/Rollback/*` classes** load via the existing PSR-4 `Autoloader` (`WPCommandCenter\Rollback\X` → `includes/Rollback/X.php`) — **no registration step**, no activation dependency.
- **12 runtimes changed** = the 10 certified (SEO, Settings, Media, Content, Comments, Users, Woo Products, Bulk, ACF, Elementor) + Plugin/Theme (BLK-3 honesty flag only). No unexpected runtime.
- Working tree clean of uncommitted code; `main` untouched.

## 7. Forensic verdict
The RC is a **clean, fast-forwardable, migration-free, additive data-runtime deploy** with zero forbidden-surface changes and invariant parity. The only deployment-coupled item not closable off-host is the **production functional verify** (Phase C plan). Forensics: **PASS.**
