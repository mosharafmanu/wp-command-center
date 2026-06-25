# PROGRAM-6 — Phase 6: Connection Testing

`Admin\ProviderConnectionTester` — minimal, manual, secret-safe; errors as data.

| Provider | Test | Call |
|---|---|---|
| **Anthropic** | live | reuse `AnthropicClient::send([ping], max_tokens=1, timeout=10)` — reads its own key (key never extracted) |
| **OpenAI** | live | `GET https://api.openai.com/v1/models` with `Authorization: Bearer <key>`, 10s — no generation, no cost |
| **Gemini** | live | `GET …/v1beta/models?key=<key>`, 10s — no generation, no cost |
| OpenRouter / Azure / Mistral / Perplexity / xAI | **not implemented** | returns `test_unsupported` → UI "Test not available yet." **Never faked.** |

## Rules honored
- Manual only (button); short timeout (10s); no expensive generation; **no site mutation, no proposal/draft**; last status saved (`last_test {ok,code,time}`); audit `ai.provider.test {provider, result, model}` — **no secret**.
- Handled: missing key (`not_configured`, no network call), invalid key (`api_error_401`), timeout/offline (`request_failed`), provider error (`api_error_<code>`). Every message **Redactor-scrubbed**.

## Test-environment honesty (what is / isn't exercised here)
- No provider keys are set in CI (and must not be), so live success paths are not run here. Verified statically + by code path: no-key short-circuit (no network), nonce+cap, minimal payloads, audit redaction, result persistence, disabled-without-key UI, error mapping.
- The Anthropic path is the unchanged 5A transport; OpenAI/Gemini are simple authenticated GETs. A user with a real key exercises them on first use.
- **Gemini note:** Gemini's API takes the key as a query param; WPCC builds the URL but **never logs it** (only the response is read; errors are Redactor-scrubbed). Documented minor, not a leak.

## Validation
`test-ai-config-6.sh` §4 (anthropic reuse, openai/gemini endpoints present, unsupported not faked, Redactor, 10s timeout) — all green.

**Phase 6: GREEN.**
