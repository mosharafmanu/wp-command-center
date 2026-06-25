# Concierge Beta — Phase 3: End-to-End AI Evidence

> Phase 3 asks for a complete workflow **using a real provider key**. I do **not** have a real key and **must not fabricate one, set one on a live site, or invent generation output**. Below is exactly what is proven vs what requires the owner's key.

## Step status
| Step | Status | Evidence |
|---|---|---|
| **Connect** | ✅ Proven | `ConnectionStore::all()` live-OK; `test-ai-platform-6r.sh` 38/0 (create connection, dialect, secret isolation, routing). |
| **Generate (live AI inference)** | ⛔ **Cannot perform — no key** | Requires a real Anthropic key (BYO; unset by design). Fabricating a key/output would violate the project's honesty discipline. Runtime present + unit-covered (`test-ai-assist.sh` 92/0). |
| **Review** | ✅ Proven | ProposalStore / Governed Drafts; proposal suites green in T2. |
| **Approve** | ✅ Proven | Approval queue + human-approver enforcement; approval suites green in T2. |
| **Apply** | ✅ Proven (live) | Governed `OperationExecutor::run` via certified rollback suites (settings/content/media/comments/users/acf/bulk) — green in T2. |
| **Verify** | ✅ Proven | `wpcc_change_log` + audit + telemetry capture; `test-change-history-admin` 119/0, `test-telemetry-8` 21/0. |
| **Rollback** | ✅ Proven (live) | Certified field-scoped, drift-aware restore; rollback suites assert real restoration. |

## What this means honestly
- **The governed safety pipeline (Connect → Review → Approve → Apply → Verify → Rollback) is fully proven** on real reversible operations with real recording and restoration.
- **The single AI-inference step is not demonstrable here** because it needs a real key on a real site. This is identical to the boundary documented since Programs 5C/6R/8/RC-2 — a property of the BYO-key model, not a defect.

## Where it WILL be demonstrated (with evidence capture)
The first concierge onboarding (Checklist) is the correct place to run the live AI step: on the partner's keyed site, enable one flag, run **generate → review → approve → apply → verify → undo**, and capture screenshots/IDs. That is an owner action on a keyed site, not a CI action.

## Required input to complete Phase 3
A real Anthropic API key **on a non-production test site** (or the first partner's site), provided/entered by the owner. With that, this exact workflow can be run and evidence captured. Without it, Phase 3's AI-inference step remains **proven-by-pipeline, pending live confirmation** — stated honestly rather than faked.
