# PROGRAM-6S — Feature Routing UX

## From hidden table to a visual map
Routing is now a labelled section with **"Feature → Connection"** rows: the feature name, a visual arrow, and a connection selector — so the user instantly reads:

```
SEO meta   →  [ Production Claude ▾ ]
Alt text   →  [ Production Claude ▾ ]
AI content →  [ Production Claude ▾ ]
```

## Honest by construction
- The selector lists **only runtime-usable, configured, enabled connections** (Anthropic today). A stored-only/testable connection (OpenAI, Ollama, gateway) **cannot** be chosen — you can never route a feature to something WPCC can't actually run.
- When no runtime-usable connection exists: a plain prompt ("Add a key to an Anthropic connection to choose feature routing") instead of a dead control.

## Future-natural
The "Feature → Connection" mental model already reads like the future examples (SEO → Claude Production, Alt Text → OpenAI Cheap): when more dialects become runtime-usable, those connections simply appear in the selector — and the same row is where **failover / cost routing** policy will surface (per ROUTING-ARCHITECTURE, the data primitive already references connection-ids). No redesign needed.

## Accessibility
Each selector has a screen-reader label ("Connection for SEO meta"); the visual arrow is `aria-hidden`.
