# Phase B Report — Provider-Neutral Generation (re-point onto the runtime)

> **Program:** Universal AI Provider Runtime. **Phase:** B (provider decoupling). **Type:** behaviour-preserving refactor — generation output, proposals, governance, REST/MCP, schema all unchanged. **Date:** 2026-06-26.
> **Invariants held:** OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0.

## 1. Architecture summary

**Before (Phase A):** the three generation providers (`AnthropicSeoProvider`, `AnthropicVisionProvider`, `AnthropicContentProvider`) each `new AnthropicClient()`, hand-built Anthropic content-block `messages`, called `send()`, and parsed the returned `['ok'/'text'/'code']` array. The neutral seam existed but only the facade used it.

**After (Phase B):** the three providers are **provider-neutral prompt builders**. Each builds a neutral `GenerationRequest` (text/image parts) and reads a neutral `GenerationResult` through a new neutral facade, **`AiRuntime`**. They no longer reference `AnthropicClient`, construct wire messages, or parse transport arrays. Execution flows:

```
Provider (neutral prompt) ─► AiRuntime.generate(GenerationRequest)
                               └─► AnthropicClient.generate()  [key resolution]
                                     └─► AnthropicTransport     [sole wire owner]
                                           └─► AiHttpClient      [single HTTP attempt]
```

`AiRuntime` is the seam future phases extend (provider selection, capability gating) **without touching feature code**. For Phase B it runs on Anthropic only — no routing, no selection.

## 2. Files changed

**New:**
- `includes/Ai/AiRuntime.php` — neutral execution facade (`is_configured`/`model`/`generate`). The providers' only runtime dependency.
- `includes/Ai/JsonObjectExtractor.php` — shared tolerant-JSON decoder (extracted from the duplicated SEO/Content decode).
- `tests/test-universal-ai-runtime-phase-b.sh` — Phase B proof suite (static decoupling + offline byte-level request/parse verification).

**Modified:**
- `includes/Seo/AnthropicSeoProvider.php` · `includes/AltText/AnthropicVisionProvider.php` · `includes/Content/AnthropicContentProvider.php` — re-pointed to `AiRuntime` + neutral contract; deleted wire/array code; prompts unchanged verbatim.
- `includes/Ai/AnthropicClient.php` — added neutral `generate(GenerationRequest)`; `send()` now routes through it (kills the internal duplication). Legacy ping/diagnostic surface unchanged.
- `includes/Ai/Contract/GenerationRequest.php` — `timeout` gained a default (`DEFAULT_TIMEOUT = 30`) so feature code needn't know the HTTP timeout. Additive; facade still passes explicit timeouts.
- `tests/test-anthropic-client.sh` · `tests/test-seo-generate.sh` — repoint provider assertions from `AnthropicClient` to `AiRuntime`.
- `tests/test-alt-text.sh` — corrected a `wp_remote_*` location assertion that had been stale since Phase A (the call moved to `AiHttpClient`).
- `tests/regression-map.tsv` — `ai_transport` group triggers on the new components and runs the Phase B suite.

**Untouched (verified):** Operations/`OperationExecutor`/`CapabilityRegistry`, MCP, `AiAgent` REST, `ProposalStore`, `Core/Schema.php`, all options, all views, the resolvers, the generators, and the result types.

## 3. Anthropic coupling removed

- **3× hand-built Anthropic content-block `messages`** (text and image+text) in the providers → **deleted**, replaced by neutral `GenerationRequest`/`GenerationMessage`/`GenerationTextPart`/`GenerationImagePart`.
- **3× transport-array parsing** (`$res['ok']`/`['text']`/`['code']`/`['message']`) → **deleted**, replaced by `GenerationResult` accessors.
- **3× `new AnthropicClient()`** dependencies in feature code → **deleted**, replaced by `AiRuntime`.
- Providers no longer import `WPCommandCenter\Ai\AnthropicClient` at all (grep-verified).

## 4. Remaining Anthropic assumptions (honest)

- The provider **class names** (`Anthropic*Provider`), their `DEFAULT_MODEL = 'claude-sonnet-4-6'`, and the `not_configured` copy ("No Anthropic API key configured.") are still Anthropic-specific. They are registered under the `'anthropic'` key and are correct for an Anthropic-only runtime; a rename + neutral defaults is deferred (future-phase concern, avoided here to prevent churn and because Phase B is Anthropic-only).
- **`AiRuntime` executes on Anthropic only** — by design. Provider selection/capability logic is explicitly out of Phase B scope.
- `AnthropicTransport` remains the **sole** owner of Anthropic wire (endpoint/headers/body/parse/errors) — grep-confirmed absent from providers, `AiRuntime`, and the facade's feature path.
- The legacy `AnthropicClient` facade still serves the non-generation consumers (connection-test pings, `AdoptionStatus`, `CredentialStore`, `ConnectionStore`) unchanged. `CostModel` pricing remains Anthropic-only (off the generation path).

## 5. Technical debt retired

- **Duplicated wire construction** across three providers → gone (single neutral contract).
- **Duplicated transport-array parsing** across three providers → gone (single `GenerationResult`).
- **Duplicated tolerant-JSON decode** in SEO + Content → unified in `JsonObjectExtractor` (the public `extract_meta`/`extract_field` keep their key-shape validation).
- **`send()` vs transport-call duplication** in the facade → `send()` now delegates to `generate()`.
- **Stale test assertion** (alt-text `wp_remote_*` home) → corrected to the real owner (`AiHttpClient`).

## 6–9. Validation, tests, regression, invariants

| Suite | Result |
|---|---|
| `test-universal-ai-runtime-phase-b.sh` (new) | **64 / 0** — static decoupling + byte-level request structure + result parse + error/invalid-JSON propagation |
| `test-universal-ai-runtime-phase-a.sh` | 78 / 0 (incl. ping identity) |
| `test-anthropic-client.sh` | 47 / 0 |
| `test-ai-content` · `test-ai-content-builder` · `test-ai-assist` | 33/0 · 81/0 · 92/0 |
| `test-seo-review` · `test-seo-apply` · `test-seo-undo` | 36/0 · 76/0 · 33/0 |
| `test-proposal-store` · `test-approval-center` · `test-approval-enforcement` | 161/0 · 127/0 · 16/0 |
| `test-change-history` · `test-operations-registry` · `test-capability-runtime` · `test-mcp-error-surface` | 31/0 · 18/0 · 61/0 · 18/0 |
| `test-adoption-readiness` | 44/0 |
| `tests/run.sh --tier T1 --changed` | 639 passed, 7 failed |

**The 7 T1 failures are all pre-existing environmental, each proven identical on a clean tree (`git stash -u` → re-run):**
- `test-seo-audit.sh`: 2 (classify) — environmental.
- `test-seo-generate.sh`: 1 (`prior captures current meta`) — environmental.
- `test-seo-runtime-step91.sh`: 4 (expects Yoast; dev box runs Rank Math) — environmental.

**Net-new attributable failures: 0.** Invariants `34 / 23 / 40 / 40 / 2.5.0` verified green.

## 10. Risks discovered

- **No new correctness bug** was found in Phase A while implementing Phase B (the string-ping bug was already fixed in the hotfix).
- A **stale `wp_remote_*` assertion** in `test-alt-text.sh` (wrong since Phase A, but in a network-quarantined suite so never executed) — corrected here.
- The connection-layer **file-stability guard** (`test-connection-discovery-routing.sh`) compares the working tree to live `main`; it flags `AnthropicClient.php` while changes are uncommitted and clears once committed. Fragile but correct; not redesigned (out of scope).

## 11. Honest assessment of Phase B quality

High. The required outcomes are met and grep-proven: the three providers build neutral requests, parse neutral results, and contain zero Anthropic wire/parse/`AnthropicClient` references. Behaviour is byte-identical on the wire (verified by capturing the HTTP body through the real transport) and result-identical (verified end-to-end). Real duplication was deleted, not wrapped (wire/array ×3, JSON decode ×2, facade send/generate). Two thin-but-load-bearing abstractions were added (`AiRuntime`, `JsonObjectExtractor`), both justified by decoupling/dedup; no interfaces were introduced. The honest residue is cosmetic (Anthropic class names/default model/copy) and deferred deliberately.

## 12. Ready for Phase C?

**Yes.** The neutral seam is complete end-to-end: feature code is provider-agnostic, `AiRuntime` is the single execution entry where capability/selection logic will attach, and `AnthropicTransport` cleanly owns the wire. Phase C can proceed without redesigning A or B.
