# RC-2 — End-to-End Workflow Evidence

> RC-1 blocker #4: demonstrate Connect → Generate → Review → Approve → Apply → Verify → Rollback. Per the brief, steps that cannot be completed are explained with evidence.

## Step-by-step evidence

| Step | Status | Evidence |
|---|---|---|
| **Connect provider** | ✅ Demonstrated | `test-ai-platform-6r.sh` (38/0): functionally creates a connection (opaque id, dialect, endpoint), stores/isolates the secret, sets default, routes a feature — all green in the T2 run. |
| **Generate (AI inference)** | ⚠️ **Boundary — requires a real key** | The generation runtime (`AnthropicVisionProvider`/`AnthropicSeoProvider`/`AnthropicContentProvider` + `AnthropicClient`) is present and unit-covered (`test-ai-assist.sh` 92/0). A **live** generation needs a real Anthropic key, which is **BYO and intentionally unset** — and **must not be set or committed** in this review. It is confirmed at concierge-onboarding time on the partner's keyed site (Checklist step 4). Not CI-demonstrable by design, not by defect. |
| **Review** | ✅ Demonstrated | ProposalStore + Governed Drafts; `test-proposal-store` / `test-proposal-admin` pass in T2 (not in the failing list). |
| **Approve** | ✅ Demonstrated | Approval queue + human-approver enforcement; `test-approval-center` / `test-operation-requests*` pass in T2. With the RC client-safe default, gated writes require approval. |
| **Apply** | ✅ Demonstrated (live) | Governed `OperationExecutor::run` exercised by the **certified rollback suites** (settings/content/media/comments/users/acf/bulk) — all green in T2 — which apply real reversible operations. |
| **Verify** | ✅ Demonstrated | Those operations record a `wpcc_change_log` row + an audit event; the P8 telemetry subscriber captures a telemetry row (behavior-neutral). `test-change-history-admin` (119/0) + `test-telemetry-8` (21/0). |
| **Rollback** | ✅ Demonstrated (live) | Certified field-scoped, drift-aware restore via `OperationExecutor::rollback` / `change_history` — the rollback suites assert real restoration (Program-4 production-proven). Green in T2. |

## What is proven end-to-end
The **governed safety pipeline** — Connect → Review → Approve → **Apply → Verify → Rollback** — is demonstrated live and green within the full acceptance run, on real reversible operations, with real change-log + telemetry recording and real restoration. This is the safety-critical chain a design partner is trusting.

## The one step that is a boundary, not a gap
**Live AI Generation** is the single step not exercised in CI, because it requires a real provider key (BYO; unset; must not be committed). This is the same honest boundary documented since Program-5C/6R/8: the AI runtime is present and unit-covered, but a live inference needs a keyed site. The concierge-onboarding procedure (Checklist) sets the partner's key, enables one feature flag, and confirms a real generate→review→approve→apply→undo on that site — a one-time, low-risk human step.

## Honest conclusion
Every step **except live AI inference** is demonstrated end-to-end with evidence; live inference is verifiable only on a keyed site and is scheduled as the first concierge-onboarding action. The governed pipeline that makes AI *safe* is fully proven; what remains is to flip on AI *generation* with a real key on the first partner site.
