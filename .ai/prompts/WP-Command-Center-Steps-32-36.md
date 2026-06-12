# WP Command Center
# Phase 5 Roadmap
# Steps 32–36

## Step 32 — Recommendation Workflow Engine
Goal: Complete the recommendation lifecycle.

Flow:
Recommendation → Action → Plan → Approval → Execution → Resolution

Requirements:
- Store recommendation_id, action_id, plan_id relationships.
- POST /recommendations/{id}/create-plan
- Recommendation statuses:
  - open
  - converted_to_action
  - plan_created
  - approved
  - executing
  - resolved
  - dismissed
- Timeline events:
  - recommendation.action_created
  - recommendation.plan_created
  - recommendation.approved
  - recommendation.executing
  - recommendation.resolved
- Dashboard cards:
  - Open Recommendations
  - Awaiting Plan
  - Awaiting Approval
  - In Progress
  - Resolved
- Add recommendation summaries to /agent/context.
- Create tests/test-recommendation-workflow.sh

Constraints:
- No auto patch creation.
- No auto execution.
- Human approval required.

---

## Step 33 — Health Verification Engine

Goal: Verify site health after risky operations.

Checks:
- Frontend health
- wp-admin health
- REST API health
- WPCC API health
- WooCommerce health
- Plugin integrity
- Theme integrity

Endpoints:
- POST /health/verify
- GET /health/results

Timeline:
- health.verification.started
- health.verification.completed
- health.verification.failed

Tests:
- tests/test-health-verification.sh

Constraints:
- Read-only.
- No modifications.

---

## Step 34 — Development Cleanup & Environment Management

Goal: Manage runtime clutter.

Requirements:
- Cleanup utility for sessions, tasks, actions, plans, queue items, and recommendations.
- Environment modes:
  - development
  - staging
  - production
- Dashboard environment warnings.
- POST /system/cleanup
- Full audit logging.

Tests:
- tests/test-cleanup.sh

Constraints:
- Never delete production data accidentally.
- Require full permissions.

---

## Step 35 — V1 Admin UX Polish

Goal: Improve usability.

Requirements:
- Better dashboard cards
- Better empty states
- Timeline filtering
- Timeline pagination
- Severity badges
- Recommendation badges
- Queue status indicators
- Result links
- Runtime hierarchy visualization

Constraints:
- UI only.
- No architecture changes.

---

## Step 36 — Real Site Validation

Goal: Validate WP Command Center on a real WordPress site.

Test Stack:
- WordPress
- WooCommerce
- ACF
- Contact Form 7

Validation Flow:
1. Run diagnostics
2. Generate recommendations
3. Create actions
4. Create plans
5. Approve plans
6. Execute operations
7. Verify queue
8. Verify results
9. Verify timeline
10. Verify rollback
11. Verify health checks

Deliverables:
- Validation report
- Screenshots
- Bugs found
- Fixes applied
- Final pass/fail report

Success Criteria:
WP Command Center runs end-to-end without manual database intervention.

---

Expected Outcome After Step 36

WP Command Center V1 Beta

Capabilities:
- Site Intelligence
- Diagnostics
- Recommendations
- Actions
- Plans
- Human Approval
- Operations
- Queue
- Results
- Timeline
- Health Verification
- Rollback
- Audit Trail

Not Included Yet:
- Claude integration
- Codex integration
- Gemini integration
- MCP
- Autonomous agents
- Multi-site management
- SaaS layer

These belong to Phase 6.
