# PROGRAM-5C — Phase J: Independent Adversarial Audit

Attacked from four viewpoints: confused user, skeptical agency owner, first-time partner, non-technical operator. Severity: BLOCKER / HIGH / MEDIUM / LOW.

| Viewpoint | Finding | Severity | Disposition |
|---|---|---|---|
| **Confused user** | "I added a key and a token but nothing does AI work." AI screens flag-OFF + external agent needed. | **BLOCKER (structural)** | **Out of scope** — product/validation decision (enable a slice / human action path). Now *honestly signposted* (after-key guide + agent explainer) instead of a silent dead-end. Documented, not implemented (no flag-flip, no new runtime). |
| **Confused user** | "What is an AI agent?" never answered. | **was HIGH** | **FIXED** — `AgentExplainer` FAQ + flow line on the Connect screen. |
| **Skeptical agency owner** | "Can I trust AI on a client site? Can I undo?" | MEDIUM | **Mitigated** — approval/undo discoverable from first screen + admin-bar; "NOT FOR CLIENT SITES" on Developer; honest limits copy. |
| **Skeptical owner** | "Is this just dev tooling?" jargon Connect screen. | **was HIGH** | **FIXED** — plain-language rewrite; quick no-AI win proves value without setup. |
| **First-time partner** | No confidence-building first step. | **was HIGH** | **FIXED** — "Run a site report — no AI or setup needed" 2-minute win. |
| **First-time partner** | "AI Setup vs Connect an AI Agent — which?" | MEDIUM | **Fixed** — naming (5B) + setup order + cross-links (5C). |
| **Non-technical operator** | Must operate a third-party assistant app. | MEDIUM | **Inherent** — outside WPCC; explainer + config provided; concierge bridges. |
| **All** | XSS via new copy? | — | SAFE — all static i18n via `esc_html_e`/`esc_html__`; explainer values `esc_html`'d; no user input rendered. |
| **All** | Key/secret leakage in changed screens? | — | SAFE — no key handling added; AI Setup secret contract unchanged (still masked, never echoed). |
| **All** | Architecture drift? | — | SAFE — only `Admin/` views + a read-only helper changed; invariants 34/23/40/40/2.5.0 held; `git diff` confirms no schema/registry/MCP/REST/capability/rollback file. |

## BLOCKER / HIGH disposition
- **All HIGH findings FIXED** (agent confusion, jargon Connect screen, no confidence-building first step).
- **The one BLOCKER (F1)** is structural (flag-off AI + external-agent requirement) and is explicitly a **product/validation** decision outside this UX program. Per the STOP rules and scope, it is **documented, not implemented** — and importantly, it is now *honestly surfaced* (the user is told what to do and what's gating them) rather than presenting as a broken/empty product. No BLOCKER is left in a *misleading* state.

## MEDIUM / LOW (accepted, documented)
- Third-party assistant operation (inherent); Woo/Elementor dormant (postpone); plaintext-option key (masked; encrypted = schema STOP).

## Re-validation
No code change required beyond the HIGH fixes (already applied + tested). Re-run: first-value-5c 23/0; usability-5b 36/0; adoption-readiness 44/0; admin-permissions 51/0; pre-existing-only failures elsewhere. Net-new attributable = 0.

**Phase J: GREEN — all BLOCKER/HIGH that are in scope are fixed; the structural BLOCKER is honestly surfaced + documented, not silently broken.**
