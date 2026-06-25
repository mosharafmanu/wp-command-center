# PROGRAM-6 — Phase 8: Backward Compatibility & Migration

`AnthropicClient` is **unchanged**; the new store maps the Anthropic provider onto the existing options. No migration step runs; old installs "just work."

| Case | Behavior | Verified |
|---|---|---|
| 1. **Anthropic key in wp-config constant** | `has_secret('anthropic')` true; UI shows the Anthropic provider with key read-only ("defined in wp-config.php… cannot be changed here"); runtime keeps using the constant (priority). | Live env runs with `WPCC_VISION_API_KEY` constant (`key_source=vision_constant`) — implicit provider shown, no fatal. |
| 2. **Anthropic key in existing option** | `records()` synthesizes an implicit Anthropic provider; saving via the new UI writes the same `wpcc_anthropic_api_key`; runtime unaffected. | Functional back-compat asserts. |
| 3. **No key configured** | Empty state ("No AI providers configured"); first-run AI step stays optional; no fatal. | Null-safe reads throughout. |
| 4. **Multiple providers configured** | One record per type; each card independent; secrets isolated (Anthropic→legacy option, others→secrets map). | Functional: openai add/delete independent of anthropic. |
| 5. **Default provider deleted** | `recompute_default_after_removal` clears the stored default; `default_type()` falls back to the runtime-usable configured provider, else empty. | Functional: delete removes record + recomputes. |
| 6. **Provider disabled** | Excluded from default/feature selection; if it was the default, default recomputed. | `set_enabled(false)` path. |
| 7. **Invalid model selected** | Controller rejects (catalogue preset or validated custom); existing model preserved on no-op. | `clean_model` validation. |
| 8. **Feature mapped to unavailable provider** | `feature_map()` ignores a mapping whose provider is not runtime-usable/configured and falls back to the default; `set_feature` refuses such a mapping up front. | Functional: config-only feature map blocked. |

## Safety properties (all cases)
- **No fatal** (every option read is array/null-guarded).
- **Clear admin notice** on every action (success/error/warning).
- **Safe fallback** for default/feature when a provider is removed/disabled/invalid.
- **No secret leak**, **no AI auto-enable**, **no security-mode change**.

**Phase 8: GREEN.**
