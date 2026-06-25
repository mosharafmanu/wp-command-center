# PROGRAM-8 — Storage Design

## Table: `{prefix}wpcc_telemetry` (self-provisioned, decoupled from DB_VERSION)
Created lazily via `CREATE TABLE IF NOT EXISTS` (per-request guarded). **Not tied to `Schema::DB_VERSION`** — the long-held 2.5.0 invariant is untouched, and telemetry can never conflict with core schema versioning. Additive + non-destructive.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| job_id | VARCHAR(64) | correlation id (`job_…`), indexed |
| kind | VARCHAR(32) | operation / ai_generation / connection_test / change / rollback |
| operation | VARCHAR(64) | indexed |
| capability | VARCHAR(64) | |
| provider | VARCHAR(64) | free string — any provider; indexed |
| model | VARCHAR(128) | free string |
| status | VARCHAR(24) | running/completed/failed/cancelled/unknown; indexed |
| started_at / completed_at | INT UNSIGNED NULL | NULL = unknown |
| duration_ms / queue_ms / exec_ms / approval_wait_ms | INT UNSIGNED NULL | NULL = unknown |
| tokens_input / tokens_output | INT UNSIGNED NULL | NULL = unknown (not 0-faked) |
| estimated_cost_micros | BIGINT UNSIGNED NULL | micro-USD; NULL = unknown |
| currency | VARCHAR(8) | default USD |
| error_code | VARCHAR(64) | |
| retry_count | INT UNSIGNED | default 0 |
| cancelled | TINYINT(1) | |
| rollback_available | TINYINT(1) NULL | |
| actor_type | VARCHAR(24) | system/user/token |
| created_at | INT UNSIGNED | indexed (window queries + prune) |

## Indexing
`job_id, status, operation, provider, kind, created_at` — covers Job Center (by status/recency), Usage & Cost (by provider/window), and timeline (created_at) without table scans.

## Growth & cleanup
- **One row per terminal event** (operations are user/agent-paced, not per-request) → low write rate.
- **`prune($days=90)`** deletes rows older than the retention window (callable from a scheduled task; not auto-scheduled here to avoid adding a background job — owner can wire wp-cron later).
- Row size is small (no payloads/blobs — facts only), so even years of activity stay modest and indexed.

## Why a table (not options/JSONL)
Usage/cost/job analytics need **queryable, indexed, aggregatable** storage — options can't aggregate and JSONL can't index. A table is the right primitive for "Datadog-for-WordPress" reporting, and it is the storage the program explicitly asked to design.

## Secret safety
No secret columns; the recorder/subscriber whitelist non-secret facts only (provider/model/operation/status/duration/actor). Audit context is already redacted by callers before the hook fires.
