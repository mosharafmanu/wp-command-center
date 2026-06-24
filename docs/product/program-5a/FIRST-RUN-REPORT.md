# PROGRAM-5A — Phase 2: First-Run Experience

## What was built
A **server-rendered first-run / adoption-readiness panel** on the Overview home (`command-home.php`), backed by a read-only `AdoptionStatus` helper. It is not a wizard, not a modal, makes no external calls, and changes no state on render.

### Panel contents (the required first-run guidance)
A 4-step checklist with live status + deep links:
1. **Choose a safety mode** — "done" when NOT self-approving (i.e. Client/Enterprise). Warns when Developer mode is active.
2. **Add an AI provider key (optional)** — "done" when a key is configured; explicit "AI stays off until you add one" copy.
3. **Create an access token for your AI agent** — "done" when ≥1 active token; links to Tokens.
4. **Know where to review & undo** — informational; links to Approvals + Audit → Changes.

Plus a `<details>` "**What WP Command Center does — and what it doesn't**" honesty block (Phase 7), and the live "X of N ready" progress count.

### Behavior / safety
- **Shows when setup is incomplete** (`AdoptionStatus::setup_incomplete()` = self-approving OR zero active tokens) — and then **cannot be dismissed**, so a new partner is never left without guidance.
- **Dismissible only when setup is complete** (per-user meta `wpcc_firstrun_dismissed`), via a nonce-protected same-page POST. **Reversible:** a "Show setup guide" link reopens it.
- **No AI auto-enable, no mode change, no external API call** on display. The AI-key step is explicitly *optional* and does not block completion.
- Degrades safely if options/tokens are absent (all reads are null-safe; counts default to 0).
- Accessible: real `<ol>/<li>`, `screen-reader-text` done/to-do markers, semantic `<section aria-labelledby>`, links not div-clicks.

## Status source
`AdoptionStatus` (new, read-only): `ai_configured()`, `ai_key_source()`, `ai_key_is_constant()`, `ai_model()`, `security_mode()/label()`, `is_self_approving()`, `token_count()/active_token_count()`, `any_ai_surface_enabled()`, `checklist()`, `setup_incomplete()`. No writes; never returns the key.

## Validation
- `php -l` clean (`command-home.php`, `AdoptionStatus.php`).
- `test-adoption-readiness.sh` §6 → all green (checklist rendered, nonce present, dismiss-only-when-complete, honest copy, no auto-enable language).
- Renders without an AI key (key step simply shows "not done", optional).
- No fatal when no tokens/options exist (null-safe reads).

**Phase 2: GREEN.**
