# PROGRAM-5B — Phase D: AI Provider Architecture

## Honest current reality
WPCC has exactly **one** wired outbound AI transport: `Ai\AnthropicClient` (single BYO-key path, errors-as-data, secret-scrubbed). There is **no** OpenAI or Gemini transport. Therefore a multi-provider "active/default provider selection" UI would be **fake functionality** — explicitly forbidden by this program.

## Design: a catalogue-driven, future-proof, honest provider system
Implemented `Admin\ProviderCatalog` (read-only) as the single source of truth for what the UI shows:

```
provider = { id, name, status: supported|planned, configurable: bool, note }
```

- **Anthropic** → `supported`, `configurable: true` (the only wired transport).
- **OpenAI**, **Gemini** → `planned`, `configurable: false` (shown for transparency; **no key field, no fake controls**).

The **AI Setup view iterates the catalogue**: it renders the full key/model/test controls only for `configurable` providers and a clearly-labelled "PLANNED — not yet available" note for the rest.

### Why this is future-proof without faking
When a real OpenAI/Gemini transport ships, the **only** UI change is flipping that entry's `status → supported` / `configurable → true` in `ProviderCatalog` — the view, key form, and status display follow automatically. No dead "active provider" dropdown ships today; a provider-switch UI appears naturally once `ProviderCatalog::single_provider()` becomes false.

## Requirements coverage (honest)
| Requirement | Status |
|---|---|
| Provider management UI | **Done** (catalogue-driven list). |
| Add/edit/remove key | **Done** (Anthropic; 5A controller, masked, audited). |
| Active provider selection | **N/A today, honestly** — one configurable provider; `active_id()` returns `anthropic`. No fake selector. |
| Default provider selection | **N/A today** — same reason; would be a dead control. |
| Model selection | **Done** (Phase E). |
| Provider status | **Done** ("Key configured" / "No key yet" / "PLANNED"). |
| Connection testing | **Done** (5A; minimal non-mutating test). |
| Honest capability messaging | **Done** — "WPCC uses one provider at a time; others appear when their connectors ship; no key is collected for them." |

## What was deliberately NOT built (rejected as fake functionality)
- An "active/default provider" dropdown (only one provider works).
- OpenAI/Gemini key fields (no transport to use them).
- Per-provider model lists for unwired providers.

## STOP-condition check
`ProviderCatalog` is a read-only PHP array helper. No schema, registry, MCP, REST, capability, or transport change. No new runtime behavior. **Clear.**

## Validation
- `php -l` clean (`ProviderCatalog.php`, `ai-setup.php`).
- `test-usability-5b.sh` §4 → catalogue entries, only-supported-configurable, view iterates catalogue, no fake key fields — all green.

**Phase D: GREEN.**
