# PROGRAM-5B — Phase E: Model Management

## Architecture (honest)
Model resolution lives in `AnthropicClient::model($default)`: canonical constant/option → legacy constant/option → caller default. 5A added the option-tier UI (`wpcc_anthropic_model`). 5B adds the plain-language layer.

| Requirement | Status |
|---|---|
| **Active model** | Done — shown as "Active: `<id>`"; resolves with the project default `claude-sonnet-4-6` when unset. |
| **Recommended model** | Done — `claude-sonnet-4-6` labelled "recommended — balanced" and preselected. |
| **Fallback model support** | **Not implemented — honestly.** The single transport (`AnthropicClient::send`) does **not** implement a fallback chain; adding one would be new runtime behavior (out of scope + STOP-adjacent). Documented as a future enhancement, not faked. |
| **Clear user explanations** | Done — new "Why this model? What changes if I switch?" explainer. |

## The non-technical explanation (new)
A `<details>` explainer answers the two questions a non-technical user actually asks:
- **Why this model?** — Sonnet (balanced, recommended), Opus (highest capability, slower/pricier), Haiku (fastest/cheapest), each in one plain sentence.
- **What changes if I switch?** — "Switching only changes which model handles *future* AI requests — it does not change your key, your saved work, or anything already on your site. You can change it any time." This removes the fear that switching is destructive.

## Why not fake fallback
A "fallback model" control implies the transport will auto-retry on a secondary model — which it does not. Shipping that control would mislead the user into believing a resilience feature exists. Per "do NOT create fake functionality," it is documented as a future option only.

## Validation
- `php -l` clean.
- `test-usability-5b.sh` §5 → explainer present, recommended framing, "does not change your key" reassurance — all green.

**Phase E: GREEN.**
