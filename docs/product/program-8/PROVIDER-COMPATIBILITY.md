# PROGRAM-8 — Provider Compatibility Review

## Zero-redesign for new providers — by construction
Telemetry is **provider-agnostic**: `provider` and `model` are free strings, and cost comes from a **filterable price table keyed by model**. Adding a provider requires **no schema or telemetry change** — only (optionally) a price-table entry.

| Provider | Telemetry today | Cost today | To enable measured tokens/cost |
|---|---|---|---|
| **Anthropic** | observed (status/duration/provider/model) | priced (Sonnet/Opus/Haiku) | push instrumentation records `usage` from the response |
| **OpenAI** | observed | priced (GPT-5 / mini, reference) | same |
| **Gemini** | observed | priced (Pro/Flash, reference) | same |
| **OpenRouter / Groq / Together / Fireworks** | observed | add a price row (or rely on response-reported cost later) | same |
| **Local (Ollama / LM Studio / vLLM)** | observed | cost = effectively $0 / unpriced → NULL (honest) | push records tokens; cost stays NULL/0 |
| **Gateway / Custom / future** | observed | unpriced → NULL until a price is added | same |

## Why it holds
- **No per-provider columns** — one flat fact row fits every provider.
- **`wpcc_telemetry_prices` filter** — hosts/agencies can set their own negotiated prices, or future code can populate from provider-reported cost, without touching the store.
- **NULL-is-honest** — an unpriced/local provider reports NULL cost rather than a fake figure; coverage counts (`cost_known`) make this visible in dashboards.
- **Dialect-aligned** — the 6R dialect model already classifies providers; telemetry simply records whatever `provider`/`model` the connection used.

## Conclusion
The success criterion "future providers require zero telemetry redesign" is met: the data model never needs to change to add a provider; at most a one-line price entry improves cost estimation, and even that is optional (NULL is the honest default).
