# Phase C Report — Capability Gate (behaviour-neutral validation)

> **Program:** Universal AI Provider Runtime. **Phase:** C (capability gate). **Type:** additive validation layer — generation output, proposals, governance, REST/MCP, schema unchanged. **Date:** 2026-06-26.
> **Invariants held:** OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0.

## Architecture summary

Phase C adds one component — `CapabilityGate` — and a behaviour-neutral gate call in the three generators. The gate validates, **after** the active provider is resolved and **before** generation, that the provider supports the capability the feature requires. It is pure validation: it never selects, ranks, or routes providers, performs no I/O, and reads no options.

- **Data vs resolver split:** the declared capability **data** lives in the existing `Ai\Platform\Capabilities` (per-dialect baseline + per-provider overrides). `CapabilityGate` is the **resolver**: it owns the feature→requirement map (`seo_meta → []`, `alt_text → [vision]`, `ai_content → []`) and matches it against the declared data.
- **Conservative matching:** a required capability declared `yes` → satisfied; `no` → **blocked** (the only thing that closes the gate); `model` / `sep` / missing → **allowed** (uncertainty never blocks).
- **Placement:** the gate is invoked in each generator at the resolution decision point, mirroring the existing `no_provider` short-circuit (skip-all + the generator's existing envelope, with a `capability_unsupported` reason). The providers, `AiRuntime`, the transport, and the neutral contract are untouched.

## Files added
- `includes/Ai/CapabilityGate.php` — the gate (resolver + feature-needs taxonomy).
- `tests/test-universal-ai-runtime-phase-c.sh` — proof suite (static wiring + gate decision matrix + invariants).
- `docs/product/phase-5-universal-ai-runtime/PHASE-C-REPORT.md` — this report.

## Files modified
- `includes/Seo/SeoMetaGenerator.php` · `includes/AltText/AltTextGenerator.php` · `includes/Content/ContentFieldGenerator.php` — added the behaviour-neutral gate check after provider resolution (+ a `use` import each).
- `includes/Ai/Platform/Capabilities.php` — **docblock only**: note that the gate now reads it (read-only); no logic change.
- `tests/regression-map.tsv` — `ai_transport` group triggers on `CapabilityGate` and runs the Phase C suite.

**Untouched (verified):** `AiRuntime`, `AnthropicClient`, `AnthropicTransport`, `AiHttpClient`, the neutral contract, `JsonObjectExtractor`, the three providers, the resolvers and their filter seams, `ProposalStore`, `OperationExecutor`, `CapabilityRegistry`, `OperationRegistry`, MCP, REST, `Core/Schema.php`. No new option, UI, interface, provider selection, routing, fallback, model logic, per-model logic, or capability override was added.

## Validation summary
- **Behaviour-neutral, empirically:** the gate is OPEN for **every one of the 16 declared providers** across all three features (vision is `yes` or `model`→unknown→allow everywhere). Generation is identical to Phase B.
- **Matching primitives proven:** `supports()` returns `yes` (anthropic vision), `no` (anthropic audio/embeddings — the closing condition's input), and `unknown` (ollama vision `model`, missing capability, and the `model`/`sep` collapse).
- **Closing logic:** the gate closes **iff** a required capability is declared `no`. That condition is proven satisfiable and correctly detected (`supports('anthropic','audio') === 'no'`).
- **Taxonomy:** every feature requirement is a subset of `Capabilities::keys()`; `VERSION` is set.

## Test results
| Suite | Result |
|---|---|
| `test-universal-ai-runtime-phase-c.sh` (new) | **34 / 0** |
| `test-ai-content` · `test-ai-content-builder` · `test-ai-assist` | 33/0 · 81/0 · 92/0 |
| `test-seo-review` · `test-seo-apply` · `test-seo-undo` | 36/0 · 76/0 · 33/0 |
| `test-proposal-store` · `test-approval-center` · `test-change-history` | 161/0 · 127/0 · 31/0 |
| `test-operations-registry` · `test-capability-runtime` · `test-mcp-error-surface` | 18/0 · 61/0 · 18/0 |

## Regression summary
The OPEN path (every real provider) is exercised end-to-end by the unchanged feature suites, all green. The single `test-seo-generate` failure (`prior captures current meta`) is the pre-existing environmental failure, proven identical on a clean tree in earlier phases. **Net-new attributable failures: 0.**

## Invariant status
`OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0` — verified green. Phase C adds no operation, capability, MCP tool, or schema.

## Risks discovered during implementation
- **The gate's CLOSED end-to-end path is currently unreachable (forward-looking guard).** Empirically, no declared provider has `vision = 'no'` — every provider declares `yes` or `model` — so the gate never closes for any provider today, not just Anthropic. The closing *mechanism* is unit-proven at the primitive level (`supports()` correctly returns `no` for declared-`no` capabilities such as audio/embeddings), but the full generator-block path cannot be exercised with current declared data, and Phase C deliberately adds no synthetic data, per-model logic, or capability override to force it. This is correct behaviour-neutral behaviour and an honest coverage boundary — the gate is a guard that becomes active only when a provider/model is known to lack a required capability.
- No correctness bug was found in Phase A or B during implementation.

## Honest assessment
High and appropriately small. The gate is exactly the additive validation the specification called for: it is wired at the right decision point, is provider-neutral and I/O-free, splits cleanly into existing data + new resolver, and is provably inert for every declared provider — so Phase B behaviour is preserved byte-for-byte. The one honest limitation (the closed path is forward-looking and unreachable with today's declared data) is a property of the data, not a defect of the gate, and is documented rather than papered over. No scope was expanded; no Phase A/B code was redesigned.

## Whether Phase D planning may begin
Yes. Phase C delivers the capability-validation seam (a feature can state its needs and have them checked against a provider before generation) without disturbing the runtime, the engine, the governance pipeline, or the invariants. The previously-noted, non-blocking debt is unchanged (provider-level gating only; the `AiRuntime → AnthropicClient` structural dependency; cosmetic Anthropic residue). None of it blocks Phase D planning.
