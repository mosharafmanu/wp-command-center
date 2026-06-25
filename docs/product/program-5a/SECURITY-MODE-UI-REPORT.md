# PROGRAM-5A — Phase 6: Security Mode UI

## Existing baseline
`Access → Security Mode` (`settings.php`) already had a working 3-mode chooser (developer / client / enterprise) with descriptions, same-page POST + `check_admin_referer('wpcc_settings')`, writing `wpcc_security_mode`. The page is reachable only under `manage_options` (menu capability). Modes are audited from `SecurityModeManager` (`MODES`, `DEFAULT_MODE = developer`).

## Enhancements added (clarity + safety + traceability — no logic change)
1. **Plain-language posture banner.** Developer mode → a **warning** ("AI can change this site with no approval; switch to Client before a live client site; audit + undo stay on"). Client/Enterprise → a **success** banner ("human-approval mode active — recommended for client sites").
2. **Recommended-mode guidance.** Client mode now carries a **RECOMMENDED** badge — the safe default for design partners.
3. **Confirmation before self-approve.** Selecting **Developer** and saving triggers a `window.confirm()` ("Developer mode lets AI change this site with no approval step… switch anyway?"). The user must explicitly confirm; cancelling aborts the submit.
4. **Audit on change.** A real mode change now records `security.mode.changed {from, to, self_approve, actor: admin_ui}` (only when the value actually changes). No secret.

## Safety invariants preserved
- **Does not silently change mode** — only writes on explicit submit, exactly as before.
- **Does not auto-change production posture** — default remains `developer`; this program never sets the option itself.
- **Existing security-mode logic untouched** — `SecurityModeManager` not modified.
- **Authorization** — mode change remains gated by the page's `manage_options` requirement (unchanged); the new audit/clarity adds no new write path.

## Validation
- `php -l` clean (`settings.php`).
- `test-security-modes.sh` → **28/0** · `test-security-mode-validation.sh` → **27/0** (existing behavior intact, including `wp eval` live-mode switching + fallback).
- `test-adoption-readiness.sh` §7 → audit event, RECOMMENDED, confirm guard, developer warning all green.

**Phase 6: GREEN.**
