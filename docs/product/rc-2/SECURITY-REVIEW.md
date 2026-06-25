# RC-2 — Security Review (merged release candidate)

Review of the integrated `rc-2-release-candidate` surface across the security-relevant subsystems.

| Area | Posture in the RC build | Verdict |
|---|---|---|
| **Approval model** | Fresh installs now seed **Client mode** → medium/high/critical writes require a human WordPress admin to approve; API tokens cannot self-approve in client/enterprise. | **Strong (fixed by RC-2).** The governance promise is ON by default. |
| **Capabilities** | Enforced at the single `OperationExecutor` chokepoint; `wpcc_enforce_capabilities` defaults true; 23-capability least-privilege; `system.admin` unlock explicit. | **Strong.** |
| **Tokens** | Bearer, HMAC-SHA256 hashed, file manifest in `uploads/wpcc-tokens/` (`.htaccess` Deny + `index.php`); read_only/full scope; raw shown once; revoke immediate. | **Adequate; one residual** — file-based manifest in a web-served dir (mitigated, but enterprise will flag). |
| **Secrets (provider keys)** | Stored as **plaintext WordPress options** (autoload=no), masked in UI, **never echoed/logged/REST-exposed** (asserted by 6R/6S tests); `Redactor` scrubs transport errors; telemetry/events carry no key. | **Adequate for SMB; residual** — plaintext at rest (TD3). `CredentialStore` is the seam for future encryption. |
| **Audit trail** | Append-only JSONL (rotated) + `wpcc_change_log`; actor attribution; the P8/P9 observation hook fires **after** the durable write (behavior-neutral). | **Strong.** |
| **Rollback** | Program-4 certified (field-scoped, drift-aware); restore is governed via `OperationExecutor::run` (no bypass), inheriting capability + approval + audit. | **Strong (production-proven).** |
| **Patch safety** | `PatchGuard` header protection, PHP `-l` verify (+ tokenizer fallback), pre-write snapshot + auto-revert, `DestructiveGuard` phrase+reason handshake. | **Strong.** |
| **New surfaces (6R/6S/7/7.5/8/9/10)** | All are read-only reads or governed same-page POST (nonce + `manage_options`); **no new REST/MCP routes, no new capabilities**; telemetry/event-bus are behavior-neutral observers; Operations Center is read-only. | **Strong.** |
| **WP-CLI / DB inspect** | WP-CLI bridge is a 14-command allowlist (shell-metachar blocked, hard blocklist); DB inspect read-only (write-keyword blocked, secret-redacted). | **Strong (unchanged).** |

## XSS / injection (new code)
- All new admin views escape output (`esc_html`/`esc_attr`/`esc_url`); identifiers `rawurlencode`; numbers cast to int. Telemetry/connection writes use `$wpdb->insert/update`/`prepare`. No raw echo of user/DB strings found.

## Remaining security risks (honest, none are RC blockers for a concierge beta)
1. **Plaintext provider keys at rest** (Medium) — masked + autoload-no + never surfaced; encryption is a future, `CredentialStore`-localized change. Set expectation with partners; advise scoped keys.
2. **File-based token manifest** (Low–Medium) — protected by `.htaccess`/index; non-Apache stacks should verify the deny rule.
3. **Developer mode still selectable** (Low) — no longer the default; switching to it requires the Security UI with a blocking `confirm()` + an audit event. Acceptable; document for partners.
4. **No external/third-party security review of the merged surface** (Medium) — RC-2 performed an internal review only; recommend an external pass before any GA (not before a hand-held concierge beta).

## Conclusion
The RC build's security posture is **materially improved** by the client-safe default and is sound for a concierge beta on real client sites: governed-by-default, least-privilege, audited, reversible, with no secret leakage in the new surfaces. The residuals (plaintext-at-rest, file manifest) are documented, mitigated, and appropriate to revisit before GA — not blockers for a hand-onboarded beta.
