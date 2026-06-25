# PROGRAM-6R — Independent Adversarial Audit

| Attack | Result | Evidence |
|---|---|---|
| **API key leakage in HTML** | SAFE | key inputs `type="password"`, no `value`; view never echoes a secret (grep clean). |
| **API key leakage in logs** | SAFE | no `error_log`; tester reads only responses; Gemini key in query never logged. |
| **API key leakage in audit** | SAFE | audit contexts are `{connection,…}` / `{feature,connection}` — never the key. |
| **API key leakage via REST** | SAFE | no REST routes added/changed; same-page admin POST only. |
| **API key leakage via JS** | SAFE | no key emitted to JS; inline JS is `confirm()` only. |
| **Nonce bypass** | SAFE | `check_admin_referer('wpcc_ai_setup')` on every action. |
| **Capability bypass** | SAFE | `current_user_can('manage_options')`; page is manage_options-only. |
| **CSRF** | SAFE | nonce on every form. |
| **XSS via name / model / endpoint / tags / error** | SAFE | name/tags `sanitize_text_field`→`esc_html`/`esc_attr`; endpoint `esc_url_raw`; model `^[A-Za-z0-9._:\-]+$`; ids `esc_attr`; tester errors `Redactor`-scrubbed. |
| **Delete default connection edge case** | SAFE | default cleared + recomputed; routes unmapped; sync repoints (functional-tested). |
| **Select non-runtime connection for default/route** | SAFE | `set_default`/`set_route` reject non-runtime; UI excludes them (functional-tested). |
| **Invalid provider type** | SAFE | `ProviderCatalog::is_valid` gate; unknown rejected. |
| **Corrupted connection option** | SAFE | all reads `is_array`-guarded; invalid providers filtered in `all()`. |
| **Provider test expensive call** | SAFE | anthropic `max_tokens=1`; others `GET /models`; no generation. |
| **Test auto-triggering on load** | SAFE | test is a manual POST; nothing runs on render. |
| **Secret stored autoload=yes** | SAFE | every platform option write is `update_option(…, false)` (grep-verified). |
| **Duplicate copies the key** | SAFE | `duplicate()` intentionally omits the secret (functional-tested). |
| **AI accidentally enabled** | SAFE | no `WPCC_*_UI` flag written; adding a key/connection enables nothing. |
| **Connection config changes security mode** | SAFE | no `wpcc_security_mode` write anywhere. |
| **Runtime regression / Program-4** | SAFE | `AnthropicClient` + generators + Rollback untouched; `ai-assist` 92/0; constant priority preserved; sync mirrors only the legacy options the runtime already reads. |
| **MCP / operation / capability drift** | SAFE | invariants 34/23/40/40/2.5.0 held; registries + `Mcp/` untouched. |

## BLOCKER / HIGH
**None.**

## Accepted / documented LOW
- **Plaintext-option key at rest** — masked, autoload=no, audit-free; `CredentialStore` is the single seam to add encryption/Vault later. Encrypted-at-rest = schema-bearing (future).
- **Gemini key in request query** (its API design); never logged.
- **Runtime mirror duplicates the default Anthropic key** into the legacy option (the runtime's required read location) — documented; constant always wins; the bridge is localized to `sync_runtime()`.

## Re-validation
No code change required by the audit. Re-run: 6R 38/0; 5A/5B/5C 44/36/23 /0; ai-assist 92/0; registry/capability/MCP 18/61/18 /0; admin-permissions 51/0; pre-existing env failures only. Net-new attributable = 0.

**No BLOCKER/HIGH open.**
