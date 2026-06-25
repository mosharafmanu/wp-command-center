# PROGRAM-5A — Phase 3: AI Provider Settings UI

## What was built
**Connect → AI Setup** (`views/ai-setup.php` + `AiSetupController`): an admin UI to add/update/remove the AI provider API key, with masked state, capability + nonce gating, and secret-free audit events.

## Providers — honest scope
WPCC's only wired outbound transport is `Ai\AnthropicClient` (single BYO-key path). Therefore:
- **Anthropic (Claude): SUPPORTED** — fully manageable here.
- **OpenAI · Google Gemini: PLANNED** — shown, clearly labelled "not yet supported," **no key field offered**. Collecting keys for providers with no runtime would be fake, misleading functionality. This honours "implement only providers that fit existing architecture."

## Storage
- Key is saved to the **existing** option the transport already reads: `wpcc_anthropic_api_key`, via `update_option( …, false )` (**autoload = no** for a secret).
- This UI introduces **no new storage pattern** — it is a managed front-end over the option `AnthropicClient::key()` already resolved before this program. Constant-defined keys (`WPCC_ANTHROPIC_API_KEY`) take priority and are shown as read-only ("remove the constant to manage here") — the UI refuses to overwrite or "clear" a constant.

## Security contract (verified)
- **Nonce** (`wpcc_ai_setup`) + **`manage_options`** on every action (`save_key`, `clear_key`, `save_model`, `test_connection`).
- **Key never exposed after save:** input is `type="password"` with **no `value`**, placeholder shows only dots; the view never echoes the key, never reads the key option, and only renders a boolean "Key configured / No key yet" state.
- **Key never logged:** audit events (`ai.provider.key.updated` / `.cleared`) carry only `{provider: anthropic}` — never the secret. Adversarial grep confirmed no key output, no `record(...key...)`.
- **Input sanitized:** trimmed, `sanitize_text_field`, structural validation `^[A-Za-z0-9._-]+$` and min length (rejects obvious garbage / injection without validating against the provider — that's the Test job).
- **No key in page source** beyond the user's own submitted request (standard form POST).

## Documented limitation (not a STOP)
The key is stored as a **plaintext WordPress option** — the same at-rest model used before this UI and the standard WordPress secret pattern. This program **does not weaken** it; it adds masking + no-echo + non-autoload + a security note ("anyone who can edit plugins could read stored options; use a scoped key"). Encrypted-at-rest secret storage is a separate, schema-bearing decision, intentionally out of scope for adoption readiness. The STOP condition ("plaintext keys *without* encryption *or masking*") is **not** triggered: masking + no-echo are present.

## Validation
- `php -l` clean (view + controller).
- `test-adoption-readiness.sh` §2/§3 → all green (nonce, cap, non-autoload write, audit without secret, constant refusal, no key echo, password input, no prefilled value, planned providers).

**Phase 3: GREEN.**
