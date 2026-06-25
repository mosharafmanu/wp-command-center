# Provider Metadata Design

`ProviderCatalog::metadata($id)` returns the descriptor the wizard renders from. Pure derivation from the existing catalog row — no execution, no I/O.

## Descriptor
| Key | Type | Meaning | Drives |
|---|---|---|---|
| `id` / `label` / `dialect` | string | identity | provider option |
| `requires_endpoint` | bool | provider needs a custom Base URL | **shows/hides Base URL** |
| `default_endpoint` | string | official URL applied when blank | Base URL placeholder + backend default |
| `supports_discovery` | bool | a model-listing transport exists | **discovery seam** (false today) |
| `recommended_models` | map id→label | curated fallback list | **model dropdown** |
| `default_model` | string | preselected model | dropdown default |
| `supports_custom_model` | bool | allow free-text model id | **"Custom model ID…"** option |
| `supports_search` | bool | list large enough to filter | **search box** (also auto-enabled when options > `SEARCH_THRESHOLD`) |
| `supports_testing` | bool | a connection test is available | test affordance |
| `needs_deployment` | bool | Azure-style deployment name needed | **deployment field** |
| `key_optional` | bool | local/keyless ok | key copy |

## Derivation (no new source of truth)
Every field is computed from the existing catalog definition (`needs_endpoint`, `models`, `default_model`, `allow_custom_model`, `needs_deployment`, dialect capabilities). `SEARCH_THRESHOLD = 8`.

## Values today (representative)
| Provider | requires_endpoint | discovery | recommended | custom | deployment |
|---|---|---|---|---|---|
| Anthropic | false | false | 3 (Claude Sonnet/Opus/Haiku) | yes | no |
| OpenAI | false | false | 2 (GPT-5 / mini) | yes | no |
| Gemini | false | false | 2 | yes | no |
| Groq / OpenRouter / Together / … | false | false | 0 → free text | yes | no |
| Azure OpenAI | **true** | false | 0 → free text | yes | **yes** |
| Ollama / LM Studio / vLLM / Custom | **true** | false | 0 → free text | yes | no |

## Adding a provider
Add one row to `ProviderCatalog::all()` (label, dialect, optional models/default/endpoint flags). `metadata()` derives the descriptor; the wizard renders it. **No view/JS change.** That is requirement #8 satisfied structurally.
