# PROGRAM-4C — G2 Reversibility Track (Plugin / Theme Update)

> **Type:** architecture recommendation (no code). Report-only. **Targets:** `includes/Operations/PluginManager.php`, `includes/Operations/ThemeManager.php`, `includes/Security/DestructiveGuard.php`.
> **Family:** this is a **coverage gap (silent irreversibility) + a contract mismatch**, not an F-1 corruption.

---

## 1. Verified current state
- **`plugin_update` (`PluginManager.php:243`) and `theme_update` (`ThemeManager.php:185`) call no `store_rollback`.** They capture only version strings for audit; responses omit `rollback_id`; the rollback dispatchers have **no `update` case**. Updates are therefore **irreversible**.
- **Yet both are registered `high` risk** (`OperationRegistry`), and the approval gate treats approved high-risk ops as reversible. So an operator approves a "reversible" update that cannot be undone — a **false contract**.
- **Plugin delete** captures a real pre-delete ZIP (`create_plugin_backup` → `wpcc-plugin-backups/{id}.zip`, hash + size, option `wpcc_plugin_backups`) and surfaces `backup_id`, but the **restore path is unimplemented** (rollback-of-delete returns an error). The artifact exists; the reversal does not.
- **Theme delete** has **no backup** and no restore (`DestructiveGuard` honestly marks theme delete `backup_capable=false`).
- **DestructiveGuard** already enforces confirm + phrase + reason + target for deletes (plugin phrase `DELETE_PLUGIN`, theme `DELETE_THEME`).

---

## 2. Why reversibility is incomplete
A package update **replaces files on disk** via WP's `Plugin_Upgrader`/`Theme_Upgrader`. Nothing field-scoped exists to delta; the only faithful reversals are **binary**: restore the prior files (Pattern C) or re-install the prior version. Today neither pre-state is captured, so there is simply nothing to restore — and, worse, the system does not *say* so.

## 3. What "honest rollback" means here
Two distinct bars:
1. **Honest (minimum):** the system never claims an update is reversible when it is not. The operation returns `reversible:false` with a visible notice, the approval card shows it, and change-history marks it non-reversible. **No silent irreversibility.**
2. **Reversible (full):** a faithful pre-update artifact is captured and a working restore path exists, so the update can actually be undone.

The program must reach bar (1) everywhere immediately; bar (2) is worthwhile where the artifact is cheap and the restore is safe (plugins), and deferrable where it is heavy/risky (themes).

---

## 4. Strategy options

| Strategy | Mechanism | Reuses | Cost | Reversibility | Risk |
|---|---|---|---|---|---|
| **A — Artifact capture (ZIP)** | pre-update: ZIP the plugin/theme folder; on rollback: deactivate→delete new→unzip prior→reactivate | **existing** `create_plugin_backup` + `wpcc-plugin-backups/` dir + `WP_Filesystem` + `ZipArchive` | medium (disk: full pre-update copy per update; needs prune policy) | **full** | unzip/permission failures; disk pressure |
| **B — Filesystem snapshot** | pre-update: `SnapshotManager` snapshot of the slug path; rollback: snapshot restore | existing `snapshot_manage` engine | medium-high | full | per-slug snapshot scoping is new; same disk cost |
| **C — Visibility-only** | classify update as irreversible: `reversible:false` + DestructiveGuard confirmation + visible notice; no capture | existing DestructiveGuard + change_log `reversible` flag | **very low** | none (honest) | none |

---

## 5. Recommendation — two-tier, ship C first

### Tier 1 (immediate, both plugin + theme): **Strategy C — make it honest**
- Add `plugin_update`/`theme_update` to `DestructiveGuard::classify()` as **irreversible** (no `backup_capable`), requiring confirm + reason (reuse the existing handshake; no new phrase needed, or a soft `UPDATE_*` acknowledgement).
- Add response fields `reversible:false` + `reversible_note` ("Updates are not automatically reversible; capture a snapshot before updating").
- Ensure the approval card and `wpcc_change_log` row carry the non-reversible flag (the table already has a `reversible` column — **no schema change**).
- **Outcome:** the false contract is closed; operators see the truth before approving. ~very low effort, zero infra, zero disk.

### Tier 2 (plugin only, after Tier 1): **Strategy A — real plugin-update reversibility**
- Generalize `create_plugin_backup` into `backup_plugin_folder()`; call it **pre-update**; store the artifact reference + prior version in a **new option** `wpcc_plugin_update_backups` (new option key, **no DB_VERSION bump**) and surface a `rollback_id`.
- Implement the restore path (the inverse of the STEP-84 delete-backup flow): deactivate → remove new files → unzip prior → reactivate if it was active. Verify by hash.
- **Also wire the already-captured plugin *delete* backup** to a working restore at the same time (it exists but is unreachable today) — high-value, same machinery.
- Add a **prune/size policy** (cap count + max bytes; skip-with-`log` if a package exceeds the size bound rather than silently not capturing).
- **Outcome:** plugin update + plugin delete become truly reversible artifacts.

### Theme update: stay **Tier 1 (visibility-only)** for now
Themes are larger and theme delete is already unrecoverable; a faithful theme-update restore is better served by the snapshot engine (Strategy B) as a later, separate effort. Mark theme update honestly irreversible now; defer real reversibility.

---

## 6. Complexity scores
| Item | Strategy | Complexity |
|---|---|---|
| plugin_update honest flag | C | 1 |
| theme_update honest flag | C | 1 |
| plugin_update artifact + restore | A | 3 |
| wire plugin_delete restore (existing backup) | A | 2 |
| theme_update real reversibility | B (deferred) | 4 |

---

## 7. Interaction with DestructiveGuard & approval
- The confirmation handshake is the natural carrier for "you are about to do something hard to undo." Tier 1 routes plugin/theme update through it, consistent with delete and `patch_apply`.
- No capability/operation/MCP/security-mode change: this is a response-shape + guard-classification change (Tier 1) and an additive backup/restore path (Tier 2). The action set and registries are untouched.

---

## 8. Validation must-haves
- **Honest (Tier 1):** `plugin_update`/`theme_update` return `reversible:false` + notice; approval card/change_log reflect it; **no `rollback_id` implying reversibility**.
- **Plugin artifact (Tier 2):** update v1→v2, rollback → files + active state back at v1 (hash-verified); oversize package → capture **skipped with a visible log**, and the op is flagged non-reversible for that run (never silently "reversible").
- **Plugin delete restore:** delete → restore-from-backup recreates files + prior active state.
- **Failure modes:** unzip failure / missing artifact → structured error, no partial half-installed state left unreported.

---

## 9. Rule-7 / STOP check
- Tier 1 and Tier 2 are **schema-free** (reuse `wpcc_change_log.reversible`, existing backup dir, a new option key). No DB_VERSION, capability, operation-registry, MCP, or security-mode change. **No STOP condition triggered.**
- If a future decision wants update backups indexed in a dedicated table for discovery, that is a Rule-7 schema check-in — **not** required by this recommendation.
