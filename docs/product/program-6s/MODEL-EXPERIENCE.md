# PROGRAM-6S — Model Experience

## From a bare field to guided understanding
`Ai\Platform\Capabilities::model_tags()` provides **honest, declared** tags for the catalogue's preset models, surfaced as colored badges so a non-technical user grasps the trade-off without docs.

| Tag | Meaning | Example |
|---|---|---|
| `recommended` | balanced default | Claude Sonnet 4.6 |
| `fastest` / `cheapest` | speed/cost | Haiku 4.5, GPT-5 mini, Gemini Flash |
| `most-capable` / `reasoning` | hardest tasks | Opus 4.8, GPT-5, Gemini Pro |
| `vision` | image input | Sonnet, GPT-5, Gemini Pro |
| `large-context` | long inputs | Gemini Flash |

- Free-text model entry is preserved (any model id, incl. local/custom), with the provider's recommended default shown as the placeholder.
- The connection card's metadata shows the **active model** and, after a test, the **number of models discovered** at the endpoint (honest, from the test response).

## Future-ready, not faked
Tags are **declared** metadata, clearly framed as such — WPCC does not benchmark models. The structure (tags per model) extends naturally to `deprecated` / `experimental` / per-endpoint model lists when a provider exposes them, without UI rework. No model is labelled with a capability WPCC hasn't honestly sourced from the provider.

## Why not a giant model dropdown per provider
Most OpenAI-compatible/local endpoints expose hundreds of models that change constantly; a curated preset list + free-text + "models discovered: N" is more honest and lower-maintenance than a hardcoded mega-list that would rot.
