# PROGRAM-7 — Performance Review

## The new read model is bounded by design
`Ai\Platform\AiActivity` is built for large sites with deep history:

| Concern | Design |
|---|---|
| **Audit volume** | `AuditLog::tail()` reads newest→oldest across rotated segments and **stops at the limit** (we request ~`max(limit*4, 60)` then filter to ≤12 rows). Old/rotated logs are never fully loaded. |
| **DB load** | one `COUNT(*)` on `wpcc_operation_requests` for pending approvals, guarded by a `SHOW TABLES LIKE` existence check (no error on fresh installs). Same query the admin-bar badge already runs. |
| **Render cost** | ≤12 feed rows + a handful of counters — **O(1)** in history size. No per-row queries. |
| **No N+1** | the feed classifies in-memory; no DB call per event. |
| **No write path** | read-only — zero lock/contention risk. |

## Page-level impact
The Mission Control block adds: 1 audit tail read (file IO, bounded) + 1 guarded `COUNT(*)`. This is comparable to the existing Overview home and admin-bar badge, both of which already do similar reads. No new autoloaded options, no new tables, no new queries on hot paths outside this admin page.

## Scaling notes (honest)
- The audit log is JSONL files (rotated at 50 MB × 5). `tail()` reading the active segment is fast; very high event rates would benefit from an indexed event store — a future concern, not a current one, and out of this experience program's scope.
- A real Job Center / Usage dashboard over millions of jobs would need an indexed table (runtime-scoped program), which is exactly why those are **designed, not shipped** here.

## Verdict
The implemented surface is **bounded and cheap**; it will not degrade on large sites or deep histories. No performance regression introduced.
