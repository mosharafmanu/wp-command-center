# PROGRAM-6 — Phase 0: AI System Baseline Audit

> **Branch:** `program-6-ai-configuration-system`, stacked on Program-5C `27b5c69` (main untouched at `94a716c`).

## Current AI-related code
- **Transport:** `Ai\AnthropicClient` — the ONLY outbound AI client. BYO key, errors-as-data, `Redactor`-scrubbed. Reads key/model from constants→options (canonical `wpcc_anthropic_api_key` / `wpcc_anthropic_model`, legacy vision names).
- **Feature generators + per-feature provider resolvers (clean abstraction already exists):**
  - Alt text: `AltText\ProviderResolver` → registers `AnthropicVisionProvider` (default model `claude-sonnet-4-6`).
  - SEO: `Seo\SeoMetaProviderResolver` → registers `AnthropicSeoProvider`.
  - Content: `Content\ContentFieldProviderResolver` → registers `AnthropicContentProvider`.
  - Each resolver: picks the first `is_configured()` provider; provider id overridable via a filter; **NO outbound call** in resolution. **Only Anthropic providers are registered.**
- **Admin config (5A/5B):** `Admin\AiSetupController` (writes `wpcc_anthropic_api_key`/`_model`, masked, audited), `Admin\ProviderCatalog` (5B: anthropic supported, openai/gemini "planned" static), `Admin\AdoptionStatus` (read-only status), view `ai-setup.php`.
- **Feature flags:** `WPCC_ALT_TEXT_UI` / `WPCC_SEO_META_UI` / `WPCC_AI_CONTENT_UI` / `WPCC_PROPOSALS_DEV_UI` — all OFF by default (constant-or-filter).
- **REST:** no provider-config REST routes; admin config uses same-page POST (5A pattern). MCP/REST agent surface is separate.
- **Audit:** `ai.provider.key.updated/.cleared/.model.updated/.test` (5A), secret-free.
- **Tests:** `test-adoption-readiness.sh` (5A), `test-usability-5b.sh` (5B), `test-first-value-5c.sh` (5C).

## The 8 audit questions
1. **Where is provider hardcoded?** In each resolver's `$registry` (only Anthropic registered) and in the generators' default-model constants. `ProviderCatalog` (5B) hardcodes anthropic=supported, others=planned.
2. **Where is Anthropic assumed?** Everywhere outbound: the single transport is `AnthropicClient`; all three feature providers wrap it.
3. **Where is the API key loaded from?** `AnthropicClient::key()`: `WPCC_ANTHROPIC_API_KEY` constant → `wpcc_anthropic_api_key` option → legacy vision constant/option.
4. **Where is the model loaded from?** `AnthropicClient::model($default)`: `WPCC_ANTHROPIC_MODEL` → `wpcc_anthropic_model` → legacy → caller default (`claude-sonnet-4-6`).
5. **Which features can use AI today?** Alt text, SEO meta, content (title/excerpt) — all via Anthropic, all behind OFF-by-default flags.
6. **Which features are flag-gated?** All three AI UIs (flags above) + proposals dev UI.
7. **Which providers can realistically be RUNTIME-supported now?** **Only Anthropic.** OpenAI/Gemini/others have no provider implementation in any resolver and no transport. Wiring them = a per-feature × per-provider transport build (broad).
8. **What must remain honest / not supported?** OpenAI/Gemini/OpenRouter/Azure/Mistral/Perplexity/xAI are **not runtime-usable**. They can be **configured** (stored) and (for OpenAI/Gemini) **connection-tested**; they must be labelled "Stored — not used by WPCC runtime yet."

## Runtime integration decision (Phase 7, decided here)
**Option B/C — multi-provider CONFIGURATION + connection-testing now; runtime stays Anthropic-only.** Adding OpenAI/Gemini runtime clients across all three feature resolvers + transports is a broad change touching generation logic with no test coverage for non-Anthropic prompts/responses — out of safe scope and adjacent to the "broad rewrite of AI runtime logic without tests" STOP. We build a clean provider abstraction (`ProviderCatalog` types + `ProviderStore` records + `ProviderConnectionTester`), wire **only Anthropic to runtime**, and make the UI honest about it. The abstraction makes a future runtime addition a localized change.

## Backward-compatibility anchor (critical)
The Anthropic **runtime key/model must remain** `wpcc_anthropic_api_key` / `wpcc_anthropic_model` (constant priority) so `AnthropicClient` + all generators keep working untouched. Design: the new `ProviderStore` stores the **Anthropic provider's secret IN that same legacy option** (constant still wins), and stores **other providers' secrets** in a new non-autoloaded `wpcc_ai_provider_secrets` map used ONLY for connection testing. `AnthropicClient` is **not modified**.

## STOP-condition pre-clearance
Plan = new read-only catalogue/store/tester helpers + a provider-config controller (same-page POST) + a rebuilt AI Setup view + WP options (no schema). No DB schema/version, no operation/capability/MCP/REST-contract change, no rollback/security change, no AI auto-enable, no real key. **Clear.** (Adding non-Anthropic *runtime* clients is explicitly NOT done — it would approach the broad-rewrite STOP.)
