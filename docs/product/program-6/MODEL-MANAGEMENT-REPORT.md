# PROGRAM-6 — Phase 4: Model Management

## Per-provider model selection
- Each provider card renders a **model `<select>`** built from `ProviderCatalog::types()[type].models`, plus a **Custom…** option when `allow_custom_model` is true (progressive-disclosure text field).
- Saving a model writes `ProviderStore::save_record(type, name, model)`; for Anthropic it **mirrors to the legacy `wpcc_anthropic_model` option** so the runtime uses the choice.
- **No API call on model save** (pure option write). **No hidden auto-switching** (explicit submit only).

## Validation & honesty
- Custom model validated in the controller: `^[A-Za-z0-9._-]+$`, ≤100 chars; rejected otherwise with a clear message. Preset must exist in the catalogue.
- **Active model** shown on each card / mirrored line; resolves to the catalogue default when unset.
- **Recommended vs faster vs higher-capability** explained in the "About models" disclosure (Sonnet=balanced/recommended, Opus=higher capability, Haiku=faster/cheaper) — plus the non-destructive reassurance: "Switching a model only changes which model handles future AI requests… it does not change your key or anything already on your site."
- Audit `ai.provider.model.updated {provider, model}` — model id is not a secret.

## Validation
- Functional: model mirror verified (back-compat test); `test-usability-5b.sh` §5 (explainer + recommended framing + non-destructive copy) re-pointed and green; `test-ai-config-6.sh` green.

**Phase 4: GREEN.**
