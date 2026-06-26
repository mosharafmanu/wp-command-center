# Phase D Report — OpenAI-compatible execution backend

> **Program:** Universal AI Provider Runtime. **Phase:** D (first non-Anthropic execution backend). **Type:** execution expansion — governance, proposals, REST/MCP, schema unchanged. **Date:** 2026-06-26.
> **Invariants held:** OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0.

## Architecture summary
Phase D adds a second execution backend behind the neutral runtime. Providers still build only neutral `GenerationRequest`s and read only `GenerationResult`s; the new transport owns all OpenAI-compatible wire knowledge. The runtime dispatches by the **explicitly configured default connection's dialect**:

- default = **keyed openai-compatible** connection → OpenAI-compatible transport (with that connection's key/endpoint/model/deployment);
- everything else (Anthropic default, no default, unkeyed, disabled, any other dialect) → the **unchanged Anthropic path** (byte-identical).

This is not routing, selection, or fallback: the runtime honours one explicit admin choice and nothing more.

## Runtime summary
`AiRuntime` became dialect-aware. It reads `ConnectionStore::OPT_DEFAULT` **directly** (the admin's deliberate `set_default` choice) and resolves an openai-compatible target only when that explicit default is an enabled openai-compatible connection with a stored key. It deliberately does **not** call `ConnectionStore::default_id()`, whose first-usable fallback would amount to auto-selection (a constraint violation — see Risks). `is_configured()`, `model()`, and `generate()` all resolve the same memoized target; for the Anthropic path they delegate to the unchanged `AnthropicClient`.

## Transport summary
`OpenAiCompatibleTransport` is the sole owner of OpenAI-compatible (Chat Completions) wire knowledge. It builds the URL (standard `{endpoint}/chat/completions`; Azure `{endpoint}/openai/deployments/{deployment}/chat/completions?api-version=…`), the auth header (Bearer, or Azure `api-key`), and the body via the codec; runs the **single** HTTP attempt through the shared Phase A `AiHttpClient`; and maps results to neutral `GenerationResult`s (`api_error_<status>` / `request_failed` / `not_configured`). It reads no options, performs no retry, adds no endpoint/SSRF policy, logs nothing, and routes every error through `Redactor`.

## Compatibility profile summary
`OpenAiCompatProfiles` is pure data: a standard OpenAI profile (`auth=bearer`, `chat_path=/chat/completions`, `token_param=max_tokens`) plus genuine per-provider overrides — currently Azure OpenAI (`auth=api-key`, deployment-in-path, `api-version`). The 14 standard openai-compatible providers (OpenAI, OpenRouter, Groq, Together, Fireworks, DeepInfra, Mistral, Perplexity, xAI, Ollama, LM Studio, vLLM, custom, DeepSeek-style) use the default profile unchanged. `OpenAiCompatibleCodec` maps neutral messages to OpenAI messages (text-only → string content; image present → `image_url` data-URI + text parts) and reads `choices[0].message.content`.

## Files added
- `includes/Ai/Transport/OpenAiCompatibleTransport.php` — the wire transport.
- `includes/Ai/Transport/OpenAiCompatibleCodec.php` — neutral⇄OpenAI translation.
- `includes/Ai/Transport/OpenAiCompatProfiles.php` — per-provider compat profiles.
- `tests/test-universal-ai-runtime-phase-d.sh` — proof suite.
- `docs/product/phase-5-universal-ai-runtime/PHASE-D-REPORT.md` — this report.

## Files modified
- `includes/Ai/AiRuntime.php` — explicit-default dialect dispatch (Anthropic + OpenAI-compatible); constructor gained an optional injectable OpenAI transport.
- `includes/Ai/Platform/Dialect.php` — `runtime_supported` flipped to `true` for the openai-compatible dialect (the registration) + docblock.
- `tests/test-ai-platform-6r.sh`, `tests/test-connection-discovery-routing.sh` — six assertions that encoded "openai not runtime-supported" updated to the new reality.
- `tests/regression-map.tsv` — `ai_transport` group triggers on the OpenAI components + Phase D suite.

**Untouched (verified):** the three providers, the neutral contract, `AnthropicClient`, `AnthropicTransport`, `AiHttpClient`, `JsonObjectExtractor`, `CapabilityGate`, the resolvers, `ProposalStore`, `OperationExecutor`, `CapabilityRegistry`, `OperationRegistry`, MCP, REST, `Core/Schema.php`. `CostModel` already carried OpenAI pricing (no change needed). No new option, UI file, interface, routing, fallback, managed key, model/capability override, streaming, async, or function calling was added.

## Validation summary
- **OpenAI executes end-to-end (offline):** a keyed openai default connection → `AiRuntime` resolves model from the connection (`gpt-5`, not the provider's Anthropic default), reports configured, and dispatches a correct Chat Completions request (verified URL, Bearer auth, body, and parsed response through the real transport with an injected sender).
- **Anthropic path byte-identical:** with no explicit openai default, the runtime takes the unchanged Anthropic path — proven by the Phase A/B/C suites and all feature suites.
- **Codec/transport/profiles** proven in isolation incl. image messages, Azure URL/auth, error mapping, redaction, and `not_configured`.

## Test results
| Suite | Result |
|---|---|
| `test-universal-ai-runtime-phase-d.sh` (new) | **35 / 0** |
| `test-universal-ai-runtime-phase-a/b/c` | 78/0 · 64/0 · 34/0 |
| `test-anthropic-client` · `test-ai-content` · `test-ai-assist` | 47/0 · 33/0 · 92/0 |
| `test-ai-platform-6r` (assertions updated) | 38/0 |
| `test-adoption-readiness` | 44/0 |

## Regression summary
Behaviour-neutral for every Anthropic setup (OpenAI dormant unless an admin explicitly sets a keyed openai default). The only feature-suite failure is the pre-existing `test-seo-generate` (`prior captures current meta`) environmental failure, proven on a clean tree in prior phases. **Net-new attributable failures: 0.** (T1 `--changed` summary appended on completion.)

## Invariant status
`OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0` — verified green. Phase D adds no operation, capability, MCP tool, or schema.

## Security observations
- **SSRF is NOT addressed (deferred to its own phase, per directive).** Phase D makes admin-supplied custom endpoints (Ollama/Azure/self-hosted/custom gateways) **callable for generation** for the first time. The transport reuses the Phase A `AiHttpClient` with no endpoint validation, no private-IP blocking, and no redirect policy. This is a real, knowingly-deferred exposure: an admin-configured endpoint triggers a server-side request from the WP host. It must be closed before broad/untrusted use. Mitigations in place: `manage_options`-gated configuration, single attempt (no retry amplification), and `Redactor` on every error.
- **Keys:** never read from options by the transport (handed in by the runtime), never logged, never returned; errors are redacted. The OpenAI-compatible key rides in the `Authorization`/`api-key` header only.
- **Data path:** when an openai default is active, generated content/images are sent to the configured endpoint — a deliberate admin choice, but a different data path than Anthropic.

## Risks discovered during implementation
1. **Auto-selection flaw caught by regression (fixed).** The first implementation resolved the target via `ConnectionStore::default_id()`, whose first-usable fallback silently auto-selected an *existing* keyed openai connection even when no explicit default was set — observed live on the dev box (a pre-existing gpt-5-mini connection rerouted generation, failing the Phase B suite 45/19). This violated the "no auto-selection" constraint. **Resolved** by reading `OPT_DEFAULT` directly (explicit admin choice only); Phase B returned to 64/0. This is the single most important finding: dialect dispatch must key off the *explicit* default, never an inferred one.
2. **Honesty debt — stale connect-screen copy.** Flipping `runtime_supported` makes the connect view's hardcoded intro ("only run AI tasks through Anthropic") inaccurate. The view was **not** edited (UI is out of Phase D scope); the copy is now stale and should be corrected by a UI follow-up. The asserting test still passes (the string is present).
3. **Untested against live OpenAI endpoints.** Validation is offline (injected sender). The compat profile knobs (token param, Azure deployment/api-version) are designed but unverified against real providers; newer OpenAI models that require `max_completion_tokens` would need a profile entry.

## Honest architectural assessment
Solid and appropriately bounded for an execution-only expansion. The wire lives entirely in the new transport; the codec/profiles are pure data; providers and governance are untouched; the Anthropic path is byte-identical and the OpenAI path is dormant until an admin explicitly opts in. The auto-selection flaw was a genuine design error, caught by regression and fixed toward the *more* constraint-respecting behavior (explicit default only). Remaining honesty debt (the stale copy) and the knowingly-deferred SSRF exposure are real and clearly surfaced rather than hidden. The `AiRuntime → AnthropicClient` dependency noted in earlier reviews is now joined by a second concrete dispatch branch; this is acceptable at two dialects but is the seam a future transport-registry abstraction would formalize.

## Whether Phase E planning may begin
Yes. The runtime now has two working execution backends behind one neutral seam, with the engine, governance, REST/MCP, and invariants intact. The two items a responsible next step must weigh — the deferred SSRF exposure and the stale connect-screen copy — are documented and non-blocking to *planning*. Phase E planning may begin.
