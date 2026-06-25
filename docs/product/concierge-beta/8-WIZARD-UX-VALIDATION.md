# Connection Wizard — UX Cleanup Validation (pre-deployment)

> UX-only. **No runtime behavior, no new provider execution, no key-storage, no security change.** The backend was already applying provider defaults; this only changes what the wizard *shows*.

## Issues fixed

### 1. Base URL no longer confuses cloud users
- **Before:** every provider showed a free "Base URL" field with an Ollama placeholder.
- **After:** the Base URL field (`#wpcc-w-endpoint-field`) is **shown only for providers that need it** (`needs_endpoint` = Azure, Ollama, LM Studio, vLLM, Custom). For cloud providers (Anthropic, OpenAI, Gemini, Groq, OpenRouter, …) it is **hidden and the official default URL is applied automatically** by the backend (`ConnectionController::endpoint_value` → `ProviderCatalog::default_endpoint`). OpenAI default = `https://api.openai.com/v1` (verified).
- Copy: *“Cloud providers use their official URL automatically — you only set this for local, Azure, gateway, or custom endpoints.”*

### 2. Model is a provider-aware dropdown (with custom fallback)
- **Before:** a blank `model-id` text box for everyone.
- **After:** providers with a model list render a **dropdown** (`#wpcc-w-model-select`) plus a **“Custom model ID…”** option that reveals the free-text field:
  - **OpenAI** → GPT-5, GPT-5 mini.
  - **Anthropic** → Claude Sonnet 4.6 (recommended), Opus 4.8, Haiku 4.5 — the runtime-supported Claude set.
  - **Gemini** → 2.5 Pro / 2.5 Flash.
  - **Local / gateway / custom** (no fixed list) → **free text**, as required.
- Wiring uses the existing backend contract unchanged: the dropdown sets `wpcc_model` to the chosen model id, or `'custom'` + `wpcc_model_custom` for free text (exactly what `ConnectionController::model_value` already accepts).

### 3. Tags moved out of the default path
- **Before:** a "Tags" box sat in Step 4 next to Model — confusing for normal users.
- **After:** Tags live inside an **“Advanced options”** `<details>` (collapsed by default), explained as *“Internal labels to organize and route your connections … used only inside WP Command Center — never sent to the provider.”*

## Validation
| Check | Result |
|---|---|
| `test-wizard-ux-cleanup.sh` (new) | **26 / 0** (incl. 15 functional provider-meta checks) |
| Backend byte-identical to `main` | ✅ ConnectionController / ConnectionStore / ProviderCatalog / Dialect **unchanged** |
| `test-ai-platform-ux-6s.sh` | 44 / 0 |
| `test-ai-platform-6r.sh` | 38 / 0 |
| `test-ai-activity-7.sh` / `test-mission-control-polish-7-5.sh` | 15 / 0 · 29 / 0 |
| Lint / boot | clean; field names (`wpcc_provider/endpoint/key/model/model_custom/tags`) preserved |

## Graceful degradation
Progressive enhancement only: without JS, the Base URL field stays visible and the model is free text (the prior behavior) — nothing breaks. With JS, the wizard hides Base URL for cloud and shows the model dropdown.

## Scope honored
View file `includes/Admin/views/ai-setup.php` only. No runtime, provider-execution, key-storage, capability, or security change.
