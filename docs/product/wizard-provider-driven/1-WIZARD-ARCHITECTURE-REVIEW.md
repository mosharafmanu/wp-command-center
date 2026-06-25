# Provider-Driven Wizard — Architecture Review

## Before
The wizard branched on provider-specific facts inline (curated model lists hard-coded into the view's JS; ad-hoc Base URL copy). Adding/altering a provider meant editing the view. Not scalable.

## After
The wizard renders entirely from a **single per-provider descriptor** — `ProviderCatalog::metadata($id)`. The view emits `ProviderCatalog::metadata_all()` as one JSON map and the JS reads only that. There are **no provider-specific conditionals** left in the view; one renderer handles all 16 providers, and any future provider is driven by its catalog row.

```
ProviderCatalog::all()            (provider definitions — unchanged)
        │  derive (read-only, no execution)
        ▼
ProviderCatalog::metadata($id)    (NEW: UI descriptor)
        │  metadata_all() → JSON
        ▼
ai-setup.php wizard JS            (one metadata-driven renderer)
   • Base URL field      ← requires_endpoint
   • Deployment field    ← needs_deployment
   • Model control       ← recommended_models / supports_custom_model / supports_search
   • Discovery seam      ← supports_discovery (+ optional window.wpccDiscoverModels)
```

## Boundary discipline (what did NOT change)
- **Provider execution / runtime / API contracts:** untouched. `ConnectionController`, `ConnectionStore`, `Dialect`, `ConnectionTester` are **byte-identical to `main`** (asserted by tests).
- **Key storage / security:** untouched.
- `ProviderCatalog` changed **additively only** (new `metadata()`/`metadata_all()`/`SEARCH_THRESHOLD`; no existing line removed/changed — asserted).
- The submitted field contract is unchanged (`wpcc_provider/name/endpoint/key/model/model_custom/tags/deployment`) — the backend already consumed all of these.

## The discovery question (honest)
Live model discovery requires a **browser-facing model-listing endpoint**. WPCC does not have one — the connection test only *counts* models server-side inside a full-page POST. Building a JS-callable discovery endpoint would be **new backend capability**, which the brief forbids. So discovery is implemented as a **gated seam**: `supports_discovery` is `false` for every provider today, and the renderer falls back to the curated list / free text. A future discovery endpoint sets `supports_discovery=true` and registers `window.wpccDiscoverModels` — **with zero wizard changes**. Nothing is fabricated.

## Net
The wizard is now **provider-driven, scalable, and future-proof**: provider definitions drive the UI, discovery is ready-but-honest, and runtime behavior is unchanged.
