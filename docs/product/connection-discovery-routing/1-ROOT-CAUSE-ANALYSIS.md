# Root Cause Analysis — Discovered Models & Feature Routing

## Issue 1 — OpenAI discovers 118 models, but the selector shows only the curated list

**Root cause:** the connection test already fetches `GET {base}/models` (118 entries for OpenAI), but `ConnectionTester::from_http()` only **counted** them (`$models = count($body['data'])`) and **discarded the IDs**. `record_test()` persisted only the integer count (`'models'`), so no model IDs ever reached storage or the UI. The Edit selector therefore had nothing but the curated list to show.

- Evidence: `from_http()` returned `res(true,'ok','',$count)`; `record_test` whitelist was `['latency_ms','models']`. Model IDs existed transiently in the HTTP response and were dropped.

**Verdict:** discovered models *should* become available — they are **real** provider data (not invented), and the user explicitly wants them. The fix is to capture the IDs from the response already fetched, persist them (bounded, sanitised), and offer them in the editor alongside the recommended list.

## Issue 2 — Feature Routing lists only Anthropic although OpenAI is healthy

**Root cause (intentional, but unexplained):** routing only offers connections where `runtime_usable()` is true, and `runtime_usable` = `Dialect::runtime_supported(dialect)`, which is **true only for the Anthropic dialect**. WPCC's runtime can currently *execute generation* through Anthropic only; OpenAI is **testable and storable but not executable**. So a healthy OpenAI connection is correctly excluded — but the UI never said *why*, so its absence looked like a bug.

- Evidence: `ConnectionStore::routes()` / `default_id()` filter on `runtime_usable()`; the routing UI built `$wpcc_runtime_conns` from runtime-usable connections only, with no mention of the excluded healthy ones.

**Verdict:** this is correct behavior (runtime capability is genuinely Anthropic-only). The fix is **clarity, not capability** — surface the healthy-but-ineligible connections with an explicit reason, and never fake OpenAI runtime support.

## Constraint that shaped both fixes
No change to provider **execution/generation**, security, or key storage. Confirmed: `AnthropicClient`, `Dialect`, `CredentialStore` remain **byte-identical to `main`**. The changes are data capture + UI only.
