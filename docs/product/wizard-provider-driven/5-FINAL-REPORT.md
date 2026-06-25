# Provider-Driven Wizard — Final Report

## Outcome
The connection wizard is now **provider-driven, scalable, and future-proof** — it renders from a single per-provider descriptor (`ProviderCatalog::metadata()`) with **no provider-specific conditionals** in the view. Runtime behavior is unchanged.

## What shipped (UX/architecture only)
- **Metadata descriptor** (`ProviderCatalog::metadata()` / `metadata_all()`): requires_endpoint · default_endpoint · supports_discovery · recommended_models · default_model · supports_custom_model · supports_search · supports_testing · needs_deployment. Additive, read-only.
- **One metadata-driven renderer** in `ai-setup.php`: Base URL (conditional), Deployment (conditional, Azure), Model control, search, custom-always.
- **Honest discovery seam**: gated by `supports_discovery` + an optional `window.wpccDiscoverModels` transport; off today (no listing endpoint exists), curated/free-text fallback active, nothing fabricated.
- **Search/filter** for large lists; **"Custom model ID…"** on every populated dropdown; **free text** for local/gateway/custom.
- **Advanced options** holds only real, backend-consumed fields (Tags, Deployment) — no fake inputs.

## Boundaries honored
- `ConnectionController` / `ConnectionStore` / `Dialect` / `ConnectionTester` **byte-identical to `main`** (asserted).
- `ProviderCatalog` changed **additively only**.
- No provider execution / runtime / security / key-storage / API-contract change.
- Submitted field contract unchanged; the backend already consumed every field.

## Validation
- **test-wizard-provider-metadata.sh 29/0** (16 functional) + **test-wizard-ux-cleanup.sh 27/0**.
- No regression: ai-platform-ux-6s 44/0, 6r 38/0, activity-7 15/0, polish-7-5 29/0, **ai-assist 92/0**.

## Future-proofing (the point)
- **New provider** = one catalog row → wizard adapts, zero view change.
- **Real discovery later** = implement a listing endpoint, register `window.wpccDiscoverModels`, flip `supports_discovery=true` in metadata → the dropdown auto-populates from live models, search engages for large lists — **zero wizard change**, and the curated list remains the fallback.

## Where I stopped
The wizard is provider-driven and scalable without any runtime change, and live model discovery is architected but honestly gated behind a backend capability that does not yet exist (and was not invented). Building that listing endpoint is a separate, backend-scoped task — out of bounds here.

## Staging
Committed to the `ux-wizard-cleanup` branch and folded into local `main` (the staged, **unpushed** release). Production remains at Program-4; nothing pushed or deployed.
