# PROGRAM-6X — Enterprise Readiness

## 4. The agency stress test
Scenario: an agency with **100 websites · 20 AI environments · multiple API keys · multiple providers · multiple teams.**

### Would Program-6 survive? — **No.** Here is precisely where it breaks, in order:

| Requirement | Program-6 reality | Breaks because |
|---|---|---|
| **20 AI environments** | One record **per provider type** | Type-keying allows ~8 records total (one per brand), not 20 environments. **First wall, immediately.** |
| **Multiple keys per provider** | One key per type (Anthropic→legacy option, others→one slot each) | Cannot store a prod key + a cheap key + a client key for the same provider. |
| **Prod / Test / Cheap / Premium** | No tags, no environments | No way to label or separate a "test" connection from "production." A test run could hit the prod/billed key. |
| **100 websites** | Single-site `wp_options` | No fleet/control plane. 100 sites = 100 hand-edited option blobs. Per-site routing has no home. |
| **Multiple teams** | One global config, `manage_options` only | No per-team scoping, no least-privilege over connections, no "team A uses key A." (W3 from the Master Plan is unaddressed here too.) |
| **Secret governance** | Plaintext keys in `wp_options` (autoload=no, masked UI) | Enterprise security review rejects DB-stored plaintext secrets; demands encryption-at-rest / Vault / env injection / rotation. No secret-provider abstraction. |
| **Routing / failover / cost** | Flat `feature→type`, single default | No failover, no cost/latency policy, no per-site/per-user routing. |
| **Audit of AI usage/cost** | Secret-free action audit only | No usage/cost/latency observability per connection — enterprise wants spend attribution + reports. |

### The honest enterprise verdict
Program-6 is an **SMB/single-operator** configuration tool. Against the 100-site/20-env/multi-team profile it fails at the **first** requirement (more than one environment) and then at nearly every subsequent one. **This is acceptable** — the product is pre-PMF and its real near-term buyer is the AI-forward freelancer/boutique (Reality Audit + PMF Discovery), **not** the enterprise. The danger is not that Program-6 is SMB-shaped; it's that its **identity model would force a rewrite** the day an enterprise (or even a 5-site agency) shows up — and the model is changeable for free *right now*.

### What enterprise readiness will require (future layers — not now)
1. **Connections as first-class resources** with opaque ids, tags (prod/test/cheap/premium), and scopes (global/site/feature/user/team).
2. **A Fleet control plane** — one pane managing connections + routing across N sites (network storage or an external WPCC service; ties to the Master Plan's fleet/multisite ambition).
3. **A Secret-provider abstraction** — `wp_options` (default), encrypted option, env/constant, and external manager (Vault/AWS/GCP) behind one interface; rotation + never-at-rest-plaintext for enterprise.
4. **Routing policy** — ordered connections + strategy (failover/cost/latency), scoped.
5. **Least-privilege over connections** — which team/role may view/use/edit which connection (extends W3).
6. **Usage/cost/latency observability** — per-connection metering + reports (already a Master Plan §2.3 gap).

None of these must ship now. **All of them assume a connection-centric model.** Shipping the type-keyed model and then bolting these on = the forced rewrite this review exists to prevent.

### The one enterprise-relevant thing Program-6 got RIGHT
Governance (approval/audit/rollback/capability scoping) is provider-agnostic and is the actual enterprise differentiator — no competitor (AI Engine, OpenRouter, LibreChat) governs *actions on the site*. The provider layer must be rebuilt **without touching** that moat. Program-6 correctly kept the two separate (it changed no rollback/approval/audit/capability code), which means the future provider rework is contained to the provider layer. That containment is the program's best architectural property.
