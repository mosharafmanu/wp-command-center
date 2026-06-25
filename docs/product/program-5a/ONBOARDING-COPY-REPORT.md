# PROGRAM-5A — Phase 7: Onboarding Copy + Design-Partner Readiness

## Where the copy lives
- **Overview first-run panel** — a `<details>` "**What WP Command Center does — and what it doesn't**".
- **AI Setup view** — optional/off-by-default framing + a security note.
- **Security Mode banners** — plain-language posture + recommendation (Phase 6).

## What the copy says (honest, no overclaim)
- **What it does:** lets an AI agent operate *this* site under control — capability limits, approval step, full audit, one-click undo for supported changes.
- **AI is optional and off by default:** "No AI runs until you add a provider key and enable a feature. Adding a key never turns features on by itself." (No AI-auto-enable language anywhere.)
- **Approval protects client sites:** in Client/Enterprise mode, writes wait for review.
- **Undo lives in Audit → Changes:** names the *supported* surfaces (content, SEO meta, media metadata, settings, comments, users, …).
- **Honest limits (explicit):** "Not everything is reversible. **Plugin and theme updates are NOT automatically undoable**, and some surfaces (e.g. WooCommerce orders) have no rollback. WPCC tells you when a change cannot be undone — it does not promise undo everywhere."
- **Not a backup tool or a fleet manager:** "governs individual actions on this one site; it does not take full-site backups or manage many sites."
- **Where to set up:** first-run checklist links to AI Setup, Tokens, Security Mode, and Audit → Changes.

## Overclaim audit (against the program's hard rules)
- **No "audited reversibility everywhere."** Copy explicitly scopes reversibility to *supported* surfaces and calls out the irreversible ones.
- **No outdated Program-4 status / version claims** in user copy (no "certified everywhere" language; the certified-surface list is described in plain user terms, not as a guarantee).
- **No security overpromise** — the AI Setup security note states the realistic option-storage caveat.
- **No AI-auto-enable language** — reinforced in three places.

## Validation
- `php -l` clean.
- `test-adoption-readiness.sh` §3/§6 → honest does/doesn't copy, irreversibility caveat ("NOT automatically"), no-auto-enable language all green.

**Phase 7: GREEN.**
