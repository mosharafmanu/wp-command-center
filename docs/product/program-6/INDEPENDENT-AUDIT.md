# PROGRAM-6 — Phase 10: Independent Adversarial Audit

| Attack | Result | Evidence |
|---|---|---|
| **API key leakage in HTML** | SAFE | All key inputs `type="password"`, no `value`; view never echoes a secret (grep clean: no `echo …(provider_key\|->secret\|api_key)`). |
| **API key leakage in logs** | SAFE | No `error_log`; tester reads only responses; Gemini URL (key in query) never logged. |
| **API key leakage in audit** | SAFE | Audit contexts are `{provider,…}` / `{feature,provider}` — the word "key" appears only in event *names*, never the secret. |
| **API key leakage through REST** | SAFE | No REST routes added/changed; all config is same-page admin POST. |
| **API key leakage through JS** | SAFE | No key is emitted to JS; the only inline JS is `confirm()`/`onchange` toggles with no secret. |
| **Nonce bypass** | SAFE | `check_admin_referer('wpcc_ai_setup')` on every action. |
| **Capability bypass** | SAFE | `current_user_can('manage_options')` gate; page is `manage_options`-only. |
| **CSRF** | SAFE | Nonce on every form. |
| **XSS via provider name / model / custom model / error** | SAFE | name `sanitize_text_field`→`esc_html`; type `esc_attr`; model validated `^[A-Za-z0-9._-]+$`; tester errors `Redactor`-scrubbed + `sanitize_text_field`. |
| **Delete default provider edge case** | SAFE | `recompute_default_after_removal` clears it; `default_type()` falls back safely (functional-tested). |
| **Select unsupported provider for a runtime feature** | SAFE | `set_default`/`set_feature` return false for non-runtime types; selector only lists runtime-usable providers (functional-tested). |
| **Invalid provider type** | SAFE | `ProviderCatalog::is_valid_type` gate in the controller; unknown → "Unknown provider." |
| **Corrupted provider option** | SAFE | All reads `is_array`-guarded; invalid types filtered out in `records()`. |
| **Provider test expensive call** | SAFE | `max_tokens=1` (Anthropic) / GET models list (OpenAI/Gemini); no generation. |
| **Test auto-triggering on page load** | SAFE | Test is a manual POST button; nothing runs on render. |
| **Key stored with autoload=yes** | SAFE | Every secret/option write is `update_option(…, false)` (grep-verified). |
| **AI accidentally enabled** | SAFE | No `WPCC_*_UI` flag is written; adding a key does not enable any surface. |
| **Provider config changing security mode** | SAFE | No `wpcc_security_mode` write anywhere in Program-6 code. |
| **Program-4 rollback regression** | SAFE | No `Rollback/` / `RollbackDelta` / accessor / `AnthropicClient` file touched. |
| **MCP / operation / capability drift** | SAFE | Invariants 34/23/40/40/2.5.0 held; registries + `Mcp/` untouched. |

## BLOCKER / HIGH
**None.** Every confirmed vector is SAFE.

## Accepted / documented minors (LOW)
- **Plaintext-option key at rest** — pre-existing WP pattern; masked UI, autoload=no, secret-free audit, candid security note. Encrypted-at-rest = schema-bearing (out of scope / STOP).
- **Gemini key travels in the request query string** (its API design); WPCC never logs the URL; errors scrubbed. Documented.
- **Multiple keys per provider type** (environments) not supported — one record per type. Documented future extension.

## Re-validation
No code change required by the audit. Suite re-run: ai-config-6 28/0; 5A/5B/5C 44/36/23 /0; registry/capability/MCP 18/61/18 /0; admin-permissions 51/0; pre-existing-only env failures elsewhere. Net-new attributable = 0.

**Phase 10: GREEN — no BLOCKER/HIGH.**
