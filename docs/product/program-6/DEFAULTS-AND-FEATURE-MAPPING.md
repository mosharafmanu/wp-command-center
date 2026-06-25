# PROGRAM-6 — Phase 5: Default Provider + Feature Mapping

## Default provider (honest routing)
- `ProviderStore::default_type()` resolves to a provider that is **configured + enabled + runtime-usable**. Today that is **Anthropic**.
- `set_default($type)` **returns false** for any provider WPCC cannot actually call (`runtime_usable=false`) — you literally **cannot** make OpenAI/Gemini the default while the runtime can't use them. The UI only shows "Set as default" on runtime-usable, keyed providers.
- Deleting/disabling the default safely recomputes it (falls back to the runtime-usable configured provider, else empty).

## Feature mapping (honest)
- Features: **SEO meta · Alt text · AI content** (`ProviderStore::FEATURES`).
- `set_feature($feature, $type)` **only accepts a runtime-usable type**. The mapping `<select>` is populated **exclusively** from runtime-usable, configured, enabled providers — so a user **cannot select a provider WPCC can't use** for a feature. Unmapped features fall back to the default.
- If no runtime-usable provider is configured, the UI says "Add a key for Anthropic above to choose feature providers" rather than offering a dead control.

## "Available for this feature" vs "Stored only"
The UI is explicit: runtime-usable providers carry **USED BY WPCC** and appear in the feature selector; config-only providers carry **STORED ONLY** and are absent from the selector. The page states plainly: "WP Command Center can only route a feature through a provider it is able to call. Today that is Anthropic… this is shown honestly, not hidden."

## Validation
- Functional (wp eval-file): `set_default('openai')===false` (config-only blocked), `set_feature('seo_meta','openai')===false` (config-only blocked), `set_feature('seo_meta','anthropic')===true` (runtime-usable allowed). All green.

**Phase 5: GREEN.**
