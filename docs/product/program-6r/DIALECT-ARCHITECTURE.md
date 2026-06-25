# PROGRAM-6R — Dialect Architecture

## The core idea: design around DIALECTS, not providers
A **dialect** is an API wire protocol. Most providers speak one of three. A single transport/tester per dialect serves unlimited providers, so adding a provider is a **catalogue entry**, never new transport code.

| Dialect | Wire shape | Auth | Endpoint editable | Test | Runtime (today) | Providers served |
|---|---|---|---|---|---|---|
| `anthropic` | Anthropic Messages | `x-api-key` | no | ✅ | **✅ (only)** | Anthropic |
| `openai-compatible` | OpenAI Chat Completions | Bearer | **yes (base_url)** | ✅ | ❌ (config+test) | OpenAI, Azure OpenAI, OpenRouter, Groq, Together, Fireworks, DeepInfra, Mistral, Perplexity, xAI, **Ollama, LM Studio, vLLM, custom gateways** |
| `gemini` | Google Generative Language | query key | no | ✅ | ❌ (config+test) | Google Gemini |

## Why this is the anti-rewrite lever
- **15 providers in the catalogue today, 3 dialects.** The 12 OpenAI-compatible providers (including all local/self-hosted) share **one** transport adapter and **one** tester.
- Adding "the 30th provider" = one `ProviderCatalog` row pointing at a dialect + a default endpoint. **Zero** new dialect/transport/tester code.
- The future runtime needs **~3 transport adapters**, not O(providers). The Anthropic adapter already exists (`AnthropicClient`); openai-compatible + gemini adapters are localized future additions.

## Honesty enforced at the dialect layer
`Dialect::runtime_supported()` is the single source of "can WPCC's AI features use this?" — true only for `anthropic` today. Every provider on a non-runtime dialect is labelled **TESTABLE** or **STORED ONLY**, never **USED BY RUNTIME**. `Dialect::test_supported()` gates whether a real test runs; all three dialects have testers, so nearly every provider is genuinely connection-testable (a big honesty upgrade over Program-6, where only 3 of 8 were testable).

## Connection testing by dialect (implemented, minimal, secret-safe)
- `anthropic` → `AnthropicClient::send(ping, max_tokens=1)` (reads its own key; never extracted).
- `openai-compatible` → `GET {base_url}/models` (Bearer key; no auth header for keyless local). Covers OpenRouter/Groq/Ollama/LM Studio/custom in one path.
- `gemini` → `GET {endpoint}/models?key=…` (key never logged).
All errors-as-data, Redactor-scrubbed, 10s timeout, no generation/mutation.
