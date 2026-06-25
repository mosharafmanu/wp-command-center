# PROGRAM-6 — Phase 7: Runtime Integration Decision

## Decision: **Option B/C** — multi-provider configuration + connection testing now; **runtime stays Anthropic-only**, behind a clean abstraction, with honest UI.

## Why not full multi-provider runtime (Option A)
WPCC's outbound runtime is three per-feature provider resolvers (`AltText\ProviderResolver`, `Seo\SeoMetaProviderResolver`, `Content\ContentFieldProviderResolver`), each registering only an **Anthropic** provider over the single `AnthropicClient` transport. Wiring OpenAI/Gemini to the runtime means implementing the provider interface **per feature × per provider** (vision, SEO, content), each with its own transport, prompt shaping, response normalization, and error handling — **generation logic with no existing non-Anthropic test coverage**. That is precisely the "broad rewrite of AI runtime logic without tests" the STOP rules guard against, and it risks the existing, working Anthropic path.

## What was built instead (the clean abstraction)
- `ProviderCatalog` (types + honest runtime/test flags), `ProviderStore` (records/secrets/default/feature-map), `ProviderConnectionTester` (live tests for the 3 majors).
- **Only Anthropic is `runtime = supported`.** The store/UI **refuse** to make a non-runtime provider the default or a feature provider. The label is explicit ("STORED ONLY", "not used by WPCC runtime yet").
- Adding a real runtime later is now a **localized** change: implement the feature-provider for the new type + flip its catalogue `runtime` flag → it becomes selectable. No UI rewrite needed.

## Preserved (verified)
- Existing Anthropic behavior (transport + key/model resolution + constant priority) — `AnthropicClient` **unmodified**.
- Existing feature flags (all OFF), proposal flow, approval/rollback/audit flow — untouched.
- No AI auto-enable.

## Honesty outcome
The UI is a real configuration product (add/edit/delete/test/default/map across 8 provider types) that **never claims a provider is used by the runtime when it isn't**. It solves the "planned placeholder" problem (providers are genuinely configurable + testable) without faking generation.

**Phase 7: GREEN.**
