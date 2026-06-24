# PROGRAM-5A — Phase 9: Independent (Adversarial) Audit

Adversarial review of the Program-5A changes. Each attack was checked against the code; result + evidence below. **No confirmed blocker.**

| # | Attack | Result | Evidence |
|---|---|---|---|
| 1 | **API key leakage in UI** | **SAFE** | `ai-setup.php` key input is `type="password"` with **no `value`**; placeholder is dots; grep for `echo …(api_key|->key())` → none. View never reads the key option. |
| 2 | **Key visible in page source** | **SAFE** | No key is rendered server-side; only a boolean "configured" state. The user's own POST is the only place the key transits (standard). |
| 3 | **Key logged in audit** | **SAFE** | `AiSetupController::audit()` contexts are `{provider}` / `{provider,model}` / `{provider,result,model}` — never the key. Grep `record(.*wpcc_api_key)` → none. |
| 4 | **Key exposed via REST** | **SAFE** | No REST routes added or changed (`git diff` shows no `Mcp/`/REST files; all settings use same-page admin POST). |
| 5 | **Nonce bypass** | **SAFE** | `AiSetupController::handle_post` requires `check_admin_referer('wpcc_ai_setup')`; first-run + settings POST each require their nonce. |
| 6 | **Capability bypass** | **SAFE** | Controller requires `manage_options`; first-run handler requires it; settings page is `manage_options`-gated at the menu. |
| 7 | **CSRF** | **SAFE** | Nonce on every state-changing form (`wp_nonce_field`) + referer check. |
| 8 | **XSS in settings fields** | **SAFE** | All output escaped (`esc_html*`/`esc_attr*`/`esc_url`); the four `echo $var` sites emit pre-escaped i18n strings or a fixed hex color from a boolean. Stored values (model/key) validated to `^[A-Za-z0-9._-]+$`. |
| 9 | **Unsafe model input** | **SAFE** | Custom model validated `^[A-Za-z0-9._-]+$`, ≤100 chars; invalid rejected. |
| 10 | **Provider test → expensive calls** | **SAFE** | One request, `max_tokens=1`, `timeout=10`; disabled without a key; no-key short-circuits with no network call. No proposal/operation/mutation. |
| 11 | **AI auto-enabled accidentally** | **SAFE** | No code writes any `WPCC_*_UI` flag; `AdoptionStatus` only *reads* flags. Adding a key does not enable any surface. |
| 12 | **Developer mode enabled accidentally** | **SAFE** | Mode only changes on explicit submit; new `confirm()` guards *against* accidental Developer; default unchanged; program never sets the option itself. |
| 13 | **Admin route broken** | **SAFE** | `AppShell` change is additive (one tab); render path unchanged; `php -l` clean; `test-admin-permissions.sh` 51/0. |
| 14 | **Direct URL broken** | **SAFE** | Legacy map extended (added `wpcc-ai-setup`), redirect logic untouched; old slugs still resolve. |
| 15 | **Menu callback broken** | **SAFE** | `AdminMenu` not modified. |
| 16 | **Program-4 rollback regression** | **SAFE** | No `Rollback/` / `RollbackDelta` / accessor files touched (`git diff` confirms); capability/registry/security suites green. |
| 17 | **MCP / operation / capability drift** | **SAFE** | Invariants 34/23/40/40/2.5.0 re-verified; registries + `Mcp/` untouched. |
| 18 | **Documentation overclaim** | **SAFE** | Onboarding copy explicitly states updates are NOT auto-reversible, Woo orders have no rollback, and WPCC is not a backup/fleet tool; no "audited reversibility everywhere." |

## Minor observations (non-blocking, accepted)
- **Key at rest is a plaintext option.** Pre-existing pattern the transport already used; this UI adds masking/no-echo/non-autoload + a candid security note. Encrypted-at-rest storage is a separate schema-bearing decision (out of scope). Not a STOP (masking present).
- **Security-mode POST relies on page-level `manage_options`** rather than an inline `current_user_can` (unchanged from the original `settings.php`). Acceptable: the page is unreachable without the capability. Could be hardened inline in a future pass; not introduced or worsened by this program.

## Re-validation after audit
No code change was required by the audit. Validation suite remains: adoption-readiness 44/0; security 28/0 + 27/0; admin-permissions 51/0; registry/capability/MCP 18/61/18 /0; invariants held.

**Phase 9: GREEN — no blocker.**
