# RC-2 — Concierge Beta Checklist

The build is ready; this is the **operational** checklist to run a safe concierge beta with 3–5 hand-onboarded partners. Items marked ⚙️ are human/owner actions, not code.

## Pre-beta (owner, once)
- [ ] ⚙️ **Decide to deploy the RC build.** Merge `rc-2-release-candidate` → `main` (or tag it) and pull-deploy. *(Owner-authorized; RC-2 did not push/deploy.)*
- [ ] ⚙️ Confirm post-deploy invariants on prod (34/23/40/40/2.5.0) and a clean smoke (homepage 200, REST root 200, `/v1/health` 401).
- [ ] ⚙️ Refresh `tests/regression-baseline.tsv` to record the known-environmental failures (so future net-new is accurate). *(QA hygiene; optional before beta.)*

## Per partner (concierge onboarding, ~30 min each)
- [ ] ⚙️ Install the plugin on the partner's site. **Verify Security Mode = Client** (the fresh-install default) — do **not** use Developer mode on a client site.
- [ ] ⚙️ Connect a provider: add the partner's **Anthropic** key in AI Setup; **Test connection** (expect "Connection succeeded").
- [ ] ⚙️ **Enable ONE AI feature flag** for the slice (alt-text or SEO meta) — `define( 'WPCC_ALT_TEXT_UI', true )` or the filter.
- [ ] ⚙️ Create a scoped access token (if connecting an agent) OR use the governed-drafts UI.
- [ ] ⚙️ **Confirm the live workflow once:** generate → review → **approve** → apply → verify in Change History → **undo one** change. (This is the live-AI step the BYO-key boundary prevented in CI — confirm it here.)
- [ ] ⚙️ Walk the partner through the **Operations Center** (Operate → Operations Center): needs-attention, timeline, review & undo. Set the expectation that telemetry/cost populate as they work and that cost is "not tracked yet".

## Expectations to set with every partner
- Single site (no fleet) for this beta.
- AI is BYO-key; they pay their provider directly; cost isn't metered in-product yet.
- Approval is ON by default (Client mode) — changes wait for their review; that is the point.
- This is a hand-held beta on a pre-1.0 build (`0.2.0-rc.2`); direct line to the founder for issues; no SLA.

## Stop conditions (pull a partner / pause the beta)
- Any ungoverned change reaching a client site (should be impossible in Client mode — investigate immediately if seen).
- Any secret appearing in UI/logs/REST (none expected; tests assert otherwise).
- A rollback that fails to restore a certified surface.

## Success signals (per the product's own PMF criteria)
- Partner completes a real governed AI run on a real client site, hits a value moment, uses review/undo, and would pay. (See PMF Discovery / Design-Partner program.)
