# Phase A Report — Neutral Runtime Contract + Shared HttpClient + Anthropic Transport

> **Program:** Universal AI Provider Runtime. **Phase:** A (foundation seam). **Type:** internal plumbing — **zero observable behavior change**. **Date:** 2026-06-26.
> **Invariants held:** OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0. No schema, REST, MCP, UI, or governance change.

## What was implemented

A provider-neutral runtime seam was interposed beneath the existing Anthropic generation path, with the outbound request kept **byte-identical**. After this phase:

- A **neutral runtime contract** (immutable, I/O-free value objects) carries a generation request and its result without any wire specifics.
- A **shared `AiHttpClient`** is the single home for the outbound HTTP POST — one attempt, timeout, transport-error normalization, Redactor scrubbing. No retry, no SSRF/endpoint guard (those are later phases; the client is their future home but ships neither).
- An **`AnthropicTransport`** is now the sole owner of Anthropic wire knowledge (endpoint, headers, body `{model, max_tokens, messages}`, response parse, error codes).
- **`AnthropicClient` is re-roled as a thin back-compat facade**: it resolves key/model exactly as before, translates the legacy Anthropic-shaped `messages` into the neutral contract, delegates to the transport, and translates the result back into the existing legacy return array. No new fields (no usage / finish reason) are surfaced.

The three live feature providers (SEO, Alt Text, Content), `ConnectionTester`, and `AdoptionStatus` continue to call `AnthropicClient` **unchanged** — the facade preserves its entire public surface.

## Changed files

**New (provider-neutral internals):**
- `includes/Ai/Contract/GenerationRequest.php`
- `includes/Ai/Contract/GenerationResult.php`
- `includes/Ai/Contract/GenerationMessage.php`
- `includes/Ai/Contract/GenerationTextPart.php`
- `includes/Ai/Contract/GenerationImagePart.php`
- `includes/Ai/Http/AiHttpClient.php`
- `includes/Ai/Http/AiHttpRequest.php`
- `includes/Ai/Http/AiHttpResponse.php`
- `includes/Ai/Transport/AnthropicTransport.php`
- `tests/test-universal-ai-runtime-phase-a.sh` (the Phase A proof suite)

**Modified:**
- `includes/Ai/AnthropicClient.php` — re-roled to a facade over the transport; **public surface preserved** (`is_configured`, `key_source`, `model`, `send`), key/model resolution chains unchanged, optional injectable transport added (defaults to the real one, so `new AnthropicClient()` is unchanged for all callers).
- `tests/test-anthropic-client.sh` — static wire assertions repointed from the facade to the new transport/HTTP files (the wire was relocated by design); all functional/behavioral assertions unchanged and still green.
- `tests/regression-map.tsv` — the `ai_transport` group now also triggers on the new components and runs the new suite.

**Not touched:** the three feature providers, resolvers, generators; `ConnectionStore`/`CredentialStore`/`ConnectionTester`/`AdoptionStatus`; `ProposalStore`, `OperationExecutor`, `AdminRestApi`; everything under `Operations/`, `Mcp/`, `AiAgent/`; `Core/Schema.php`; all options; all admin views.

## Compatibility proof (byte-identical)

The new Phase A suite asserts, for the **three live call shapes** (SEO text, Content text, Alt Text image+text), that the new path produces the same:

- **URL** — `https://api.anthropic.com/v1/messages`
- **Headers** — `x-api-key`, `anthropic-version: 2023-06-01`, `content-type: application/json`
- **Request body** — **byte-identical** JSON `{model, max_tokens, messages}` (compared string-for-string against the pre-Phase-A `wp_json_encode` output; no `system`/`temperature`/`stop_sequences` introduced)
- **Timeout** — 30s default; `opts['timeout']` override honored identically
- **Return array** — success `{ok, text, model}` with the same `trim()`; failure `{ok:false, code, message, model}`
- **Error codes** — `not_configured`, `request_failed`, `api_error_<status>` mapped identically; Redactor still scrubs (proven: a leaked `sk-ant-…` is replaced with `[REDACTED_SECRET]`)
- **No HTTP on empty key** — `not_configured` returns without any outbound call

The legacy→neutral→wire round-trip reproduces the original body exactly because the transport serializer preserves key order (`role`→`content`; `type`→`text`; `type`→`source`→`type`/`media_type`/`data`).

> **Hotfix (post-`8a9d34d`):** `send()` also accepts a **bare-string** content shape (`'content' => 'ping'`) used by the connection-test pings in `ConnectionTester` and `AiSetupController`. The original transport passed this through verbatim; the initial neutral round-trip dropped it to `content:[]`. Fixed by carrying a *scalar-text* distinction on `GenerationMessage` (string content → single text part flagged scalar; serialized back as bare-string content). Byte-identity for the ping shape is now locked by a regression test. No other behavior changed.

## Tests run

| Suite | Result | Notes |
|---|---|---|
| `test-universal-ai-runtime-phase-a.sh` (new) | **77 / 0** | unit + identity + behavior + invariants, fully offline (injected sender) |
| `test-anthropic-client.sh` (updated) | **46 / 0** | resolution chains + redaction + vision behavior preserved |
| `test-ai-content.sh` | **33 / 0** | facade is a drop-in for the Content provider |
| `test-ai-assist.sh` | **92 / 0** | — |
| `tests/run.sh --tier T1 --changed` | **467 / 2** | the 2 failures are `test-seo-audit.sh` classify checks |

**Pre-existing failures (NOT attributable to Phase A), each proven identical on a clean tree (`git stash -u` → re-run):**
- `test-seo-audit.sh`: 2 (`classify weak (short desc)`, `classify ok`) — documented environmental classify failures.
- `test-seo-generate.sh`: 1 (`prior captures current meta`) — environmental.

**Net-new attributable failures: 0.**

## Invariants

`OPERATION_MAP == 34` · `ALL_CAPABILITIES == 23` · catalogue `== 40` · MCP tools `== 40` · `DB_VERSION == 2.5.0` — all green. Phase A added no operation, capability, MCP tool, or schema, proving the engine is untouched.

## Risks

- **Round-trip drift (primary).** A divergence in the legacy→neutral→wire serialization would break byte-identity. **Mitigated** by the identity tests comparing the exact `wp_json_encode` body for all three live shapes (string equality).
- **Static-test repointing.** `test-anthropic-client.sh`'s wire greps were moved to the transport/HTTP files. This reflects the intended relocation, not a weakening — all behavioral assertions remain and pass.
- **Security.** No new exposure: every error path remains Redactor-wrapped; the HTTP client logs nothing and never returns the key. Explicitly **no** SSRF guard was added (later phase) — no false impression of endpoint validation.
- **Out of scope (by design):** no retry, no endpoint validation, no new request-body fields, no surfaced usage/finish-reason.

## Rollback plan

Pure code refactor with **no persisted-state surface** — no schema, option, contract, or flag change, no data migration. Rollback = revert the commit (and delete the new `includes/Ai/Contract`, `includes/Ai/Http`, `includes/Ai/Transport` directories + the new test). Because the public surface and outbound wire are unchanged, a revert is residue-free and risk-free. Nothing was deployed (no push).

## Confirmation: user-visible behavior did not change

No UI, REST, MCP, schema, DB_VERSION, provider catalog, routing, or feature behavior changed. The SEO / Alt Text / Content features generate, propose, approve, apply, and roll back exactly as before; the outbound Anthropic request and all return shapes are byte/shape-identical. Phase A delivers internal plumbing only and is independently, invisibly deployable.
