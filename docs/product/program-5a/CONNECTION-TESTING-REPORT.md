# PROGRAM-5A — Phase 5: Connection Testing

## What was built
A "**Test connection**" action in **Connect → AI Setup** (same-page POST, nonce + `manage_options`) that verifies the provider key works via the **existing single transport** `AnthropicClient::send()` — no new HTTP path.

## The test request (minimal, non-mutating)
- One user message `{role:user, content:'ping'}`, **`max_tokens = 1`**, **`timeout = 10s`**.
- **No expensive generation, no site mutation, no proposal, no operation execution.** It only confirms the key authenticates.
- Button is **disabled until a key is configured**.

## Failure handling (all returned as data — the transport never throws)
| Condition | Result |
|---|---|
| Missing key | Short-circuits in `send()` (`not_configured`) with **no network call**; UI says "Add a key first." |
| Invalid key | Provider HTTP 401 → `api_error_401`, surfaced as a redacted message. |
| Timeout / offline / network | `is_wp_error` → `request_failed`, surfaced safely. |
| Provider error | `api_error_<code>` surfaced (message scrubbed by the transport's `Redactor`). |

## Persistence & audit
- Last result stored non-secret in `wpcc_anthropic_last_test` `{ok, code, time}` (autoload=no); shown as "Last test: succeeded/failed (code) — N ago".
- Audit event `ai.provider.test {provider, result, model}` — **never the key**. (`result` is a status code like `ok` / `api_error_401` / `not_configured`.)

## Test-environment honesty (what is / isn't verified here)
WPCC's test suites are static (lint + `rg`) and this environment has **no provider key set** (and must not, per program rules). Therefore:
- **Verified statically/by code path:** the no-key short-circuit (no outbound call), nonce + cap gating, `max_tokens=1` minimal payload, audit redaction, result persistence, disabled-without-key UI, and graceful error mapping (the transport already returns every failure mode as data).
- **NOT exercised live here:** an actual successful 200 from Anthropic (requires a real key + network). This path is the unchanged, already-shipped `AnthropicClient::send()` used by the alt-text/SEO/content providers — Program-5A adds no new outbound logic, only a 1-token caller. A design partner with a real key exercises it on first use.

No mock was needed: the real transport's no-key branch is the safe, deterministic path in a keyless environment.

## Validation
- `php -l` clean.
- `test-adoption-readiness.sh` §2 → minimal-payload + test-audit checks green.

**Phase 5: GREEN.**
