# PROGRAM-6S — Accessibility Report

Target: WCAG 2.1 AA intent, keyboard + screen-reader usable, no docs required.

| Area | Implementation |
|---|---|
| **Notices** | `role="alert"` (errors) / `role="status"` (warnings) so changes are announced. |
| **Wizard toggle** | `aria-expanded` on the "New connection" button + `aria-controls` to the wizard. |
| **Wizard focus mgmt** | each step's heading gets `tabindex=-1` + `.focus()` on advance — keyboard/SR users land on the new step. |
| **Routing selectors** | each has a `screen-reader-text` `<label for>`; the visual arrow is `aria-hidden`. |
| **Form labels** | every input has an associated `<label for>` (wizard + edit forms). |
| **Readiness score** | `aria-label="Setup readiness N percent"` (the ring is decorative). |
| **Avatars / dots / arrows** | decorative elements marked `aria-hidden`. |
| **Buttons** | real `<button>`/`<a>` elements; native focus rings preserved (no `outline:none`); destructive actions use `confirm()`. |
| **Color is not the only signal** | health uses a dot **and** a text label; badges carry text, not just color. |
| **Contrast** | hero text on dark gradient, body copy `#50575e`/`#1d2327` on white, badges on tinted backgrounds — all ≥ AA for their size. |
| **Keyboard** | all actions are standard form submits / buttons; wizard Next/Back/Cancel are buttons; no mouse-only interactions. |
| **Progressive enhancement** | without JS the wizard degrades to a fully labelled form; nothing is JS-only. |

## Known limitations (honest)
- The wizard's progress bar is decorative (`aria-hidden`); step position is conveyed by the focused heading text ("Step 3 — Credentials"), which is sufficient but could add `aria-current` in a future pass.
- `<details>` disclosures rely on native semantics (well-supported) for capabilities/edit.

No automated axe run was performed in this environment; the above is a manual structural review. A live axe/screen-reader pass is recommended before GA.
