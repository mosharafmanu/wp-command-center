# Phase 1 — Performance Review

> The change is navigation/labelling only. No hot path gained a query, route, or external call.

## Request-cost analysis

| Concern | Before | After | Delta |
|---|---|---|---|
| New REST routes | — | **none** (`register_rest_route` absent in shell layer) | 0 |
| New DB queries on a section render | `sections()` builds a static array | identical static array, six entries | 0 |
| `sections()` call count per page | render() + `nav_map()` (Assets) | identical (render() + nav_map()) | 0 |
| Legacy-slug redirect cost | one array lookup | `resolve_legacy()` = at most two array lookups (tab map, then standalone map) | negligible, **only** on legacy URLs |
| Admin-bar badge query | one `COUNT(*)` on pending requests | unchanged | 0 |
| New view `api-integrations.php` | n/a | one `AuthTokens::list()` read, **only** when that tab is opened | bounded, off hot path |

## Notes
- **No N+1, no external/network call** was added. The API landing reads the token list once (the same read the token manager already performs) and only on its own page.
- **The redirect path is cheaper or equal** for the common case: current-IA URLs return `null` from `resolve_legacy()` after one-to-two `isset()` checks and render directly (no redirect). Only genuinely legacy URLs incur the single `wp_safe_redirect`.
- **Catalogue/registry hot-path debt is untouched** (that is Phase B's S1/W1 work). This milestone neither worsens nor fixes it.
- **Asset payload unchanged** — no new CSS/JS bundles; the new view uses inline styles consistent with sibling views and the existing CDS classes.

## Verification
The drift guards in `test-ia-phase1.sh` (§7) and `test-experience-layer.sh` assert the shell/menu layer adds **no** `register_rest_route` and **no** `OperationExecutor`/`->run(`/`->execute(` dispatch — i.e. nothing that would introduce engine or query cost on the navigation path.

## Net
No measurable performance regression. The one new read (`AuthTokens::list()` on the API landing) is bounded and page-local. Existing scalability debt (catalogue rebuild/caching) is out of Phase-1 scope and is carried, unchanged, into Phase B.
