# WP Command Center - Token Efficiency Report

**Measured:** June 12, 2026  
**Token estimate:** JSON bytes / 4

## Measured Payloads

| Runtime/resource | Standard bytes | Compact bytes | Reduction |
|---|---:|---:|---:|
| Agent context | 144,666 | 476 | 99.7% |
| Agent manifest | 65,888 | 412 | 99.4% |
| Content Runtime | 4,005 | 1,101 | 72.5% |
| User Runtime | 4,299 | 1,129 | 73.7% |
| Media Runtime | 7,123 | 1,838 | 74.2% |
| WooCommerce Runtime | 7,131 | 1,835 | 74.3% |
| ACF Runtime | 4,166 | 594 | 85.7% |
| Forms Runtime | 364 | 364 | 0.0% |
| Menu Runtime | 284 | 284 | 0.0% |
| Site Settings Runtime | 243 | 243 | 0.0% |
| Search Runtime | 2,037 | 627 | 69.2% |
| Workflow Runtime | 52 | 52 | 0.0% |
| CPT Runtime | 7,184 | 1,971 | 72.6% |
| Comments Runtime | 70 | 70 | 0.0% |

Already-small summary or empty-list responses are intentionally left unchanged. Compact mode does not add wrapper overhead when no meaningful reduction is available.

## Aggregate Savings

The measured cross-section totals:

- Standard: **247,512 bytes**, approximately **61,878 tokens**.
- Compact: **10,996 bytes**, approximately **2,749 tokens**.
- Weighted reduction: **95.6%**.

MCP tool discovery changed from a 14,783-byte pre-Step-76 baseline to 9,040 bytes compact, a **38.9% reduction**, despite adding `context_mode`, search cursor, and result-limit schema fields.

## Latency

Five-request local averages:

| Resource | Standard | Compact | Reduction |
|---|---:|---:|---:|
| `wpcc://context` | 1,016 ms | 524 ms | 48.4% |
| `wpcc://manifest` | 913 ms | 423 ms | 53.6% |

The compact builders avoid constructing and serializing the full REST payload. Small operation responses are expected to show lower network/serialization savings because WordPress bootstrap remains the dominant cost.

## Largest Payload Sources

1. Full agent context: repeated operations, diagnostics, queues, results, audit entries, registries, and site scan data.
2. Full manifest: endpoint catalog, error catalog, operation schemas, risk metadata, and duplicated registry summaries.
3. CPT, WooCommerce, media, ACF, user, and content list payloads.
4. MCP tool schemas with repeated descriptions.

## Workflow Impact

For the common agent sequence of context + manifest + discovery + one list operation, compact mode typically removes tens of thousands of prompt tokens before the model makes its first decision. Detail remains available through standard/verbose mode and cursor pagination.

## Remaining Opportunities

- Add compact native summaries for queue, results, recommendations, and capability resources instead of generic recursive compaction.
- Add cursor pagination to non-search list operations at the runtime query layer.
- Cache compact manifest metadata until registry or plugin version changes.
- Replace full audit-log reads with bounded reverse-tail access.
- Add real model-tokenizer measurement alongside the conservative bytes/4 estimate.
