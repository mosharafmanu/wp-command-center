# Phase 3B — Positioning & GTM (durable decisions)

> Review-only. Strategy/positioning, not implementation. Captures the lasting messaging and go-to-market decisions.

## One-sentence positioning (chosen)
**"The safe way to let AI change your WordPress site — approve, watch, and undo everything it does."**
Lead with *pain/outcome*, never with architecture. "Three Doors, One Engine" is an **internal/technical** frame — not a buyer hook. The category line for technical buyers: *"the governed action layer for AI on WordPress."*

## Messaging rules
- Lead with the **fear** (AI broke a client site, no undo, no accountability) → relief (undo). Never open on the dashboard/console.
- Externalize "governance" as **"control / safe / approval"**; reserve "governance" for technical/enterprise pages.
- Keep customer language: Approvals, History, Connect, Built-in AI, Recommendations. Avoid: runtime, operation, registry, telemetry, capability (outside advanced/dev).
- **Name risk (flagged, not changed):** "Command Center" signals *dashboard/monitoring*, not *AI safety*. Worth testing the category line; renaming is high-cost/uncertain.

## ICP (ranked)
1. Agencies/operators running AI on client sites (Door 1 pain) — *the wedge candidate*.
2. AI-forward teams wiring MCP agents (Door 2 pain) — possibly **faster/more defensible**; run in parallel.
3. Technical owners of high-value sites.
**Not:** hobby/solo bloggers; enterprises expecting SSO/fleet today.

## Moats (ranked; only the defensible ones)
1. Uniform governance across all three doors. 2. Real field-scoped, drift-aware rollback. 3. Append-only audit with human/agent provenance. 4. Self-hosted + BYO-key (no vendor in the data path). 5. Capability/token scoping for agents. *(Registry-as-contract and IA polish are execution edges, not durable moats.)*

## Competitive frame
- **AI Engine / Jetpack AI / Rank Math AI** *add* AI; WPCC *governs* any AI action (incl. agents/apps), uniformly — complement, not clone.
- **Claude Desktop / Cursor / Codex** are **channels** (Door 2), not competitors.
- **The real competitor is "do-it-yourself, ungoverned"** (raw MCP/API on a client site). WPCC is the gate.

## Pricing shape (directional, not exact — validate with partners)
- **Free** (1 site, core approve/audit/undo) → **Pro** (per-site annual) → **Agency** (multi-site license — *the revenue wedge*) → **Enterprise** (custom, post-PMF).
- BYO-key ⇒ price the **governance value**, not tokens ("less than one broken client site"). **No lifetime deals** (a control plane needs ongoing maintenance vs provider/MCP churn). Do not publish prices during the design-partner stage.

## GTM sequence (and why)
**Design Partners → PMF → Public Beta → Commercial Launch → Enterprise.** Each gate unlocks the next build; do not build pricing before PMF or the enterprise envelope before agency revenue. **Sell, then build.**

## Biggest strategic mistake named
**A platform was built before a product was validated** (40 ops, all runtimes, MCP gateway, rollback engine at N=1 demand). The corrective: stop adding capability; validate the wedge with real partners.
