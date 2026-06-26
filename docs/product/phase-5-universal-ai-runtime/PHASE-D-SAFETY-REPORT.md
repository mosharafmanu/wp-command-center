# Phase D Safety & Honesty Cleanup Report

> **Program:** Universal AI Provider Runtime. **Type:** focused safety + honesty cleanup (no new providers, no routing, no runtime redesign). **Date:** 2026-06-26.
> **Invariants held:** OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0.

## Security summary

Closes the SSRF exposure Phase D introduced (admin-supplied OpenAI-compatible endpoints became callable for server-side generation). A new **`AiEndpointGuard`** validates every custom AI endpoint **before** any outbound call:

- **Scheme allowlist:** only `http`/`https` (blocks `ftp`, `file`, etc.).
- **Address policy:** the host is resolved (IPv4 + IPv6; IP literals pass through) and **blocked by default** if any resolved IP is loopback / private / link-local / reserved — using `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. This blocks `127.0.0.1`, `::1`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16` (incl. the `169.254.169.254` cloud-metadata SSRF), and reserved ranges.
- **Declared-local exception:** loopback/private are allowed **only** for providers the catalogue declares local (Ollama / LM Studio / vLLM, `'local' => true`). `openai`, `azure-openai`, `custom-openai`, and all hosted gateways must use public endpoints.
- **Redirect bounce blocked:** the OpenAI-compatible transport now sends with `redirection => 0`, so a public host cannot 3xx-bounce into private space. This is set **per request** — the Anthropic path leaves it unset and is byte-identical.
- **No secret leakage:** the guard reads no options, makes no HTTP call, and its block messages contain no key/endpoint secrets; `Redactor` is preserved on all transport errors. **Single-attempt** HTTP is preserved.
- **Anthropic untouched:** `AnthropicTransport` does not reference the guard and sets no redirect arg; its outbound body/headers/timeout are byte-identical (proven).
- **Engine untouched:** no change to REST, MCP, Operation engine, approval, audit, rollback, capability scoping, or schema.

**Residual (documented):** DNS-rebinding (host resolves public at check, private at connect) is not fully pinned; redirects-off and the resolve-time check cover the common cases. IP pinning is a later hardening step, out of this cleanup's scope.

## Copy / honesty summary

Removed every "generation only runs through Anthropic" claim and replaced it with calm, honest copy in three places:
- **Connect › Providers (feature routing intro)** — now: generation runs through the one connection you set as the **default**; Anthropic and OpenAI-compatible both work once selected; other providers can be saved/tested but only a supported, chosen provider generates; **nothing is selected automatically**; your content is sent **only** to the provider you pick.
- **Design-Partner readiness ("Generation supported")** — now: runs on the provider you set as default (Anthropic or OpenAI-compatible); others connect/test but only the one you select generates.
- **Built-in AI tools partial** — now: generation runs on the provider you select as the default (Anthropic or an OpenAI-compatible provider).

## Files added
- `includes/Ai/Http/AiEndpointGuard.php` — the SSRF endpoint validator.
- `tests/test-universal-ai-runtime-phase-d-safety.sh` — the proof suite.
- `docs/product/phase-5-universal-ai-runtime/PHASE-D-SAFETY-REPORT.md` — this report.

## Files modified
- `includes/Ai/Transport/OpenAiCompatibleTransport.php` — calls the guard before sending (declared-local aware); sets `redirection => 0`; injectable guard for tests.
- `includes/Ai/Http/AiHttpRequest.php` — optional `max_redirects` (null = unchanged Anthropic behaviour).
- `includes/Ai/Http/AiHttpClient.php` — passes `redirection` only when set (Anthropic args unchanged).
- `includes/Ai/Platform/ProviderCatalog.php` — read-only `is_local()` helper.
- `includes/Admin/views/ai-setup.php`, `includes/Admin/DesignPartnerReadiness.php`, `includes/Admin/views/partials/builtin-ai-tools.php` — honest runtime copy.
- `tests/test-universal-ai-runtime-phase-d.sh` (assert guard present; permissive guard for DNS-free dispatch checks), `tests/test-connection-discovery-routing.sh` (copy assertion), `tests/regression-map.tsv`.

## Validation summary
- **Guard blocks unsafe endpoints:** private, loopback (v4+v6), link-local/cloud-metadata, ftp, file — all blocked; public allowed.
- **Declared local providers work:** Ollama on `127.0.0.1`/`192.168.x` allowed end-to-end.
- **Transport enforces it:** a blocked endpoint returns `endpoint_blocked` with **no outbound call**; the message leaks no secret; custom endpoints disable redirects.
- **Anthropic path unchanged:** no `redirection` arg, byte-identical body (proven).
- **OpenAI path works through safe endpoints** (public IP literal).

## Test results
| Suite | Result |
|---|---|
| `test-universal-ai-runtime-phase-d-safety.sh` (new) | **37 / 0** |
| `test-universal-ai-runtime-phase-d` | 35 / 0 |
| `test-universal-ai-runtime-phase-a` (Anthropic identity) | 78 / 0 |
| `test-anthropic-client` · `test-ai-platform-6r` · `test-adoption-readiness` | 47/0 · 38/0 · 44/0 |
| `test-connection-discovery-routing` (copy + guards) | 30 / 0 |

## Invariant status
`OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0` — green.

## Risks remaining
- **DNS rebinding** not fully pinned (mitigated by redirects-off + resolve-time check; IP pinning is a later step).
- **Plaintext provider keys at rest** — unchanged by this cleanup; still a GA-blocker, acceptable for concierge.
- **No external security review yet** — recommended before GA, not before concierge pilots.
- The guard adds a DNS resolution per custom-endpoint generation (negligible vs. the network call it precedes).

## Is the product safe enough for concierge design-partner testing?
**Yes.** The two Phase D issues are closed: custom/OpenAI-compatible endpoints can no longer reach internal infrastructure (SSRF guard + redirects-off, declared-local exception), and the UI no longer claims Anthropic-only generation. Combined with the standing posture (client-safe by default, AI off by default, BYO-key, governed proposal→approval→audit→undo, byte-identical Anthropic path), the product is safe for **hand-held, concierge** design-partner pilots. The remaining items (key encryption, external review, DNS pinning) are GA-stage, not pilot-stage. Nothing was pushed or deployed.
