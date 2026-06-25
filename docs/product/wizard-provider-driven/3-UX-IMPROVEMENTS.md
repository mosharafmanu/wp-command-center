# UX Improvements

Mapped to the 8 requirements.

1. **Dynamic model discovery (#1)** — a clean seam: when a provider advertises `supports_discovery` and a `window.wpccDiscoverModels` transport is registered, the wizard requests models and populates the dropdown (with a "Discovering models…" state). No provider advertises it yet, so the seam is dormant — never fabricating a list.
2. **Graceful fallback (#2)** — if discovery is off/unavailable/fails, the dropdown is built from the provider's **curated `recommended_models`**; if there is no curated list, the control becomes **free text**. The user is **never** left with an empty dropdown, and no model is invented.
3. **Custom model (#3)** — every populated dropdown appends **"Custom model ID…"**; selecting it reveals the free-text input and submits as `wpcc_model=custom` + `wpcc_model_custom` (the existing backend contract).
4. **Local / gateway providers (#4)** — Ollama, LM Studio, vLLM, OpenAI-compatible, custom gateways have no curated list → **free-text entry**, with the discovery seam available if a transport is ever registered.
5. **Base URL (#5)** — hidden for cloud providers (their official endpoint is applied automatically), shown only when `requires_endpoint`, with copy explaining *why* ("you only set this for local, Azure, gateway, or custom endpoints").
6. **Advanced options (#6)** — collapsed `<details>` holding **Tags** (explained as internal routing/organization, never sent to the provider) and the **Deployment name** (shown only for Azure via `needs_deployment`). Only *real, backend-consumed* fields are included — no fake "custom headers/timeout" inputs the runtime would ignore.
7. **Provider metadata (#7)** — the wizard renders from `ProviderCatalog::metadata()`; the descriptor exposes discovery/endpoint/recommended/custom/search/testing/deployment.
8. **Future providers (#8)** — a new provider is a catalog row; the wizard adapts with no view change.

## Extra UX touches
- **Search/filter** appears above the dropdown when the list exceeds `SEARCH_THRESHOLD` (8) or `supports_search` — ready for large discovered lists; the "Custom model ID…" option stays reachable while filtering.
- **Recommended-first ordering** — curated lists are authored recommended-first (e.g. "Claude Sonnet 4.6 (recommended)"), so the default selection is the recommended model.

## Progressive enhancement
Without JS: Base URL stays visible, the model is a free-text input, search/select are hidden, Advanced is a native `<details>`. Nothing breaks; the prior behavior is the floor.
