# Phase 1 — Accessibility Review

> Scope: the navigation chrome and the two surfaces with new markup (Home door fork, API & Integrations landing). The shared CDS substrate (focus styles, reduced-motion, contrast tokens) is unchanged and still applies.

## Navigation chrome (`AppShell::render`)
- **Sub-tab bar** is a `<nav>` with `aria-label` set to the section name; the active tab carries `aria-current="page"` and an `is-active` class (not colour-only). Tabs are real `<a href>` — fully keyboard reachable, work without JS.
- **Mode toggle** is a `role="group"` with `aria-label`; buttons expose `aria-pressed`.
- **Security-posture pill** has a `title`; the brand mark and decorative glyph are `aria-hidden="true"`.
- **Empty-section state** (`render_empty_section`) is wrapped in `role="status"` and provides a labelled action.

## Home door fork (new)
- Wrapped in `role="group"` with `aria-label="How do you want to use AI here?"`, with a visible heading carrying the same question.
- Each door is a heading + description + a real `<a>` button (keyboard/AT reachable); the door icon is `aria-hidden="true"`.
- Layout uses CSS grid that reflows to a single column at narrow widths (no horizontal scroll, no fixed pixel traps).

## API & Integrations landing (new)
- Single `<h1>` + section `<h2>`s in order; the connection details use a real `<table>` with `<th scope="row">` row headers.
- The example request is in `<pre><code>` (announced as preformatted); no information is conveyed by colour alone (the dark code block has explanatory prose above it).
- The "you have N active tokens" confirmation pairs the `dashicons-yes` (decorative, `aria-hidden`) with text — never icon-only meaning.

## Preserved from the existing Home (unchanged, re-verified present)
- `role="status"` + `aria-live="polite"` live regions on the dynamic panels (needs-attention, cards, activity).
- `.screen-reader-text` on the checklist done/to-do state.
- `prefers-reduced-motion` and focus-visible handling live in the CDS layer (`wpcc-cds.css`), untouched by this milestone.

## Honest limitations
- This is a **static + structural** review (markup, ARIA, keyboard semantics, contrast-by-token). A full assistive-technology pass (NVDA/VoiceOver) and an automated axe-core sweep across all surfaces are **recommended before GA** and are noted in the GA risk list — not claimed here.
- The advanced Settings tabs host pre-existing views whose internal a11y is unchanged by this milestone (no regression introduced; no new audit performed on them).

## Net
No accessibility regression introduced; the new markup follows the established CDS a11y patterns (labelled landmarks, `aria-current`, live regions, non-colour state, keyboard-native controls). A formal AT/axe pass remains a pre-GA action.
