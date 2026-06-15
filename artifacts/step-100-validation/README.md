# STEP 100 Validation Artifacts (2026-06-15)

Raw evidence for STEP-100-VALIDATION-REPORT.md. Collected via live REST + MCP calls
on the dev site; all seeded content (wpcc-v100-*) torn down after collection.

- env/         environment snapshot, pre-validation store backups, acceptance-suite tails
- scans/       per-attachment media_usage_scan JSON (filename = key-mediaID.json),
               RESULTS.csv (consolidated classification table), unused/orphaned find,
               usage_report aggregate
- writes/      regenerate / webp / optimize raw responses (a1)
- cleanup/     unused_media_cleanup responses (legit, orphan, full-orphan)
- rollback/    rollback + reconciliation evidence
- errors/      read-token rejection, capability/unsupported-mime errors

Key files:
- scans/RESULTS.csv          — 41/41 classification PASS, 0 false positives
- scans/unused_media_find.json — 69 candidates, 0 protected items present
- rollback/a1-rollbacks.txt  — byte-for-byte reversibility proof
