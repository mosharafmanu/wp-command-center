# PROGRAM-5A — Phase 4: Model Selection UI

## What was built
A model selector in **Connect → AI Setup**, separate from the API key, writing to the existing `wpcc_anthropic_model` option (`update_option(…, false)`).

## Models (project naming conventions)
Presets use the canonical model ids already hardcoded as the providers' defaults (`AnthropicVisionProvider`/`AnthropicSeoProvider`/`AnthropicContentProvider` all default to `claude-sonnet-4-6`):
- `claude-sonnet-4-6` — **recommended / balanced** (the project default; preselected).
- `claude-opus-4-8` — highest capability.
- `claude-haiku-4-5-20251001` — fastest / lowest cost.
- **Custom…** — free-text exact model id.

The active model line reflects the resolved value, falling back to the project default `claude-sonnet-4-6` when unset (matching `AnthropicClient::model($default)`).

## Behavior / safety
- **Separate from the key** — saving a model never touches the key and is allowed with no key present.
- **No provider call on save** — pure option write.
- **No hidden auto-switching** — the model only changes on explicit submit.
- **Custom input validated** — `^[A-Za-z0-9._-]+$`, ≤100 chars; invalid ids rejected with a clear message (prevents unsafe/garbage values).
- **Audit** — `ai.provider.model.updated {provider, model}` (model id is not a secret).
- Constant `WPCC_ANTHROPIC_MODEL` still takes priority in resolution (transport unchanged); the UI manages the option tier.

## Validation
- `php -l` clean.
- Save preset → persisted; switch to Custom → input revealed (progressive disclosure), validated on save; invalid value rejected safely.
- Active provider/model displayed correctly; no key required to choose a model; no runtime regression (registry/capability/MCP suites green).

**Phase 4: GREEN.**
