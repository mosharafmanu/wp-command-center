# RC-2 — Remaining Risks

After clearing the four RC-1 blockers, these residuals remain. None is a blocker for a **hand-onboarded concierge beta**; each has a mitigation/expectation.

| # | Risk | Severity (concierge beta) | Mitigation / expectation to set |
|---|---|---|---|
| R1 | **Live AI generation not yet confirmed on a keyed site** | Medium | The governed pipeline is proven; first concierge action sets the partner's key + enables one flag + confirms a live generate→apply→undo (Checklist). Low-risk, one-time. |
| R2 | **Telemetry / Operations Center sparse until activity accrues** | Medium | Honest by design ("no data yet" / "not tracked yet"); set the expectation that these populate as the partner works. Real tokens/cost await runtime push-instrumentation (a future program). |
| R3 | **Single-site (no fleet)** | Medium | Concierge beta = a few partners, one site each (or per-site setup). Fleet is out of scope; do not pitch multi-site management. |
| R4 | **Plaintext provider keys at rest** (WP options, masked, autoload-no) | Low–Medium | Advise scoped keys; document. Encryption is a future `CredentialStore`-localized change; acceptable for a hand-held beta, revisit before GA. |
| R5 | **File-based token manifest** in `uploads/` | Low | Protected by `.htaccess`/index; verify deny rule on non-Apache stacks. |
| R6 | **Developer mode still selectable** (no longer default) | Low | Switching requires the Security UI with a blocking confirm + audit; brief partners not to use it on client sites. |
| R7 | **`regression-baseline.tsv` is stale** (omits ~14 known-environmental failures) | Low (QA hygiene) | Acceptance attribution was done against `main` directly (net-new = 0). Refresh the baseline post-RC so the runner's "net-new" reflects reality. |
| R8 | **N=1 / demand unproven** | Strategic, not technical | The concierge beta exists precisely to retire this; not a release defect. |
| R9 | **No external security review / no automated a11y/perf pass** | Medium for GA, Low for concierge | Internal reviews done (Security Review, per-program a11y/perf docs). Schedule external + axe/device passes before GA. |
| R10 | **No distribution/licensing/support-at-scale** (v0.2.0-rc.2) | Low for concierge, High for self-serve | Concierge = manual install + founder support; do **not** attempt a self-serve 10-agency beta. |

## Risks that would BLOCK (and are cleared)
- Not merged / no acceptance gate → **cleared** (integration + T2, net-new attributable 0).
- Insecure-by-default → **cleared** (client-safe seed).
- Ungoverned changes to client sites → **cleared** (client mode requires approval by default).

## Net read
The residual risks are the expected shape of an honest, single-site, BYO-key concierge beta. They are documented, mitigated, and appropriate to set as expectations with 3–5 hand-onboarded partners — not blockers.
