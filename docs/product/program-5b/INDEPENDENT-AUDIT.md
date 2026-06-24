# PROGRAM-5B — Phase J: Independent (Adversarial) Audit

Fresh adversarial review of the 5B changes (onboarding, IA, usability, discoverability, AI setup, provider management, navigation, design-partner readiness). Severity: BLOCKER / HIGH / MEDIUM / LOW.

| Attack surface | Finding | Severity | Disposition |
|---|---|---|---|
| **AI setup — key leakage** | Provider section refactored to a catalogue loop; key still `type=password`, no `value`, never echoed (grep clean). | — | SAFE |
| **Provider management — fake functionality** | OpenAI/Gemini render as PLANNED with no key field; only `configurable` providers get controls; `ProviderCatalog` is the single honest source. | — | SAFE |
| **Navigation — broken routes/links** | Only labels/descriptions changed; slugs + `legacy_map` intact; `admin-permissions` 51/0. Section `desc` is `esc_html`-escaped. | — | SAFE |
| **Discoverability — stale copy** | Found + fixed two **honesty bugs** (Changes "restore arrives later", Tokens "create/revoke arrives later") that hid shipped features. Verified the features actually render (Restore modal + route; create/revoke UI + route). | **was HIGH** | **FIXED** |
| **Onboarding — overclaim** | First-run/how-it-works copy is accurate; "does/doesn't" still states updates not auto-reversible + not a backup/fleet tool. No "audited reversibility everywhere." | — | SAFE |
| **Onboarding — XSS via copy** | All new copy is static i18n via `esc_html_e`/`esc_html__`; section desc `esc_html`'d; no user input rendered. | — | SAFE |
| **Safety UX — accidental self-approval** | Developer mode now red "NOT FOR CLIENT SITES" + plain consequence + retained `confirm()` + audit. Harder to enable accidentally, not easier. | — | SAFE (improved) |
| **IA — engineer surfaces confusing newcomers** | Runtime relabelled "(advanced)"; section descriptions orient. Operations/Site Intelligence/Patches/File Access remain advanced but de-emphasized (not in onboarding path). | LOW | ACCEPTED (out of scope to rewrite) |
| **Model management — fake fallback** | No fallback control shipped (transport has no fallback); documented as future, not faked. | — | SAFE |
| **Architecture drift** | No schema/registry/MCP/REST/capability/rollback file touched (`git diff` vs 5A tip). Invariants 34/23/40/40/2.5.0 held. | — | SAFE |

## BLOCKER / HIGH findings
- **HIGH (fixed):** stale "arrives later" copy on Changes + Tokens hid the product's two most important trust features (undo, agent tokens). **Both corrected and tested** (change-history-admin 119/0, token-capability-admin 155/0). No BLOCKER or HIGH remains.

## MEDIUM / LOW (accepted, documented)
- **LOW:** several engineer surfaces (Operations Explorer, Site Intelligence, File Access, Patches, Runtime) remain jargon-y. De-emphasized via labels/descriptions; a full plain-language rewrite of advanced surfaces is out of an adoption-readiness program's scope.
- **LOW (carried from 5A):** plaintext-option key at rest (masked UI); encrypted storage is schema-bearing (STOP). Documented.

## Re-validation after audit
No code change required by the audit beyond the HIGH stale-copy fixes (already applied + tested). Suite re-run: usability-5b 36/0; adoption-readiness 44/0; change-history-admin 119/0; token-capability-admin 155/0; security 28/0 + 27/0; admin-permissions 51/0. Net-new attributable = 0.

**Phase J: GREEN — no BLOCKER/HIGH open.**
