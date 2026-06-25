# PROGRAM-6 — Phase 3: Admin Provider Management UI

Rebuilt `views/ai-setup.php` from a single-key screen into a real provider manager driven by `ProviderConfigController` (same-page POST; no REST change).

## UX delivered
- **Empty state:** "No AI providers configured yet" + "Add your first provider below."
- **Add Provider:** type selector (only unconfigured types; "— stored only" suffix for non-runtime types) → optional key → **Add provider**.
- **Per-provider card:** name, type description, **DEFAULT** / **USED BY WPCC** / **STORED ONLY** badges, "Key configured / No key yet" status, last-test result with time.
- **Actions:** update key, remove key, change model (+ custom), **Test connection** (disabled when no key or test unsupported), **Set as default** (runtime-usable + keyed only), **Enable/Disable**, **Delete**.
- **States:** empty / setup (add form) / error + success (notices) / per-action confirms.

## Honesty (the core requirement)
- Runtime-usable provider (Anthropic): "USED BY WPCC".
- Config-only provider: "STORED ONLY" + "Saved, but not used by WPCC runtime yet."
- Test unsupported: button disabled + "Test not available yet."
- No fake key fields: every provider type that is shown is genuinely configurable (stored); the input name is generic (`wpcc_provider_key`), never a per-provider fake.

## Security (verified)
- nonce (`wpcc_ai_setup`) + `manage_options` on every action.
- Inputs sanitized; provider type validated against the catalogue; model validated; name `sanitize_text_field` + `esc_html` on render; type `esc_attr`.
- **Key never echoed**: all key inputs are `type="password"` with no `value`; the view never reads or prints a secret (only a boolean state).
- Audit on add/update/clear/delete/default/enable/test/map — **secret-free** (`{provider, …}`).

## Validation
`test-ai-config-6.sh` §3 (view: no key echo, password inputs, empty state, STORED ONLY / USED BY WPCC / Test-not-available honesty, add flow, feature mapping) + §2 (security contract) — all green.

**Phase 3: GREEN.**
