# PROGRAM-6S — Microcopy Review

Every user-facing string was challenged for clarity, confidence, humanity, honesty — no buzzwords, no needless jargon.

## Before → After (representative)
| Before | After |
|---|---|
| "AI Setup" (page) | "AI Connections" + "Your AI control panel." |
| "No AI connections yet." (terse) | "A connection links an AI provider to this site so WP Command Center can do work for you — safely, with your approval and one-click undo. Add a connection to get started." |
| raw test code | "Authentication failed — the key was rejected. Paste a new key and test again." |
| "STORED ONLY" alone | + "Saved, not used by WPCC runtime yet." |
| "Configuration" | "Setup readiness", "Default environment", "AI status" |
| dropdown with no help | "Leave blank to use the provider's recommended default. You can change it any time." |

## Principles enforced
- **Plain verbs, no jargon:** "connection," "key," "test," "healthy," "default" — not "transport," "credential ref," "dialect" (dialect shown only as a small technical subtitle, never required to act).
- **Confident, not hedgy:** states are definite ("Healthy," "Authentication failed") with one clear next action.
- **Honest:** "declared, not live-tested," "stored, not used by runtime yet," "adding a key does not turn AI features on by itself," "a wp-config constant always wins."
- **Benefit-first empty/help states:** explain *why* before *how*.
- **No marketing fluff:** no "powerful," "seamless," "revolutionary."

## Warnings
Re-cast from technical to actionable: "N connections need attention. Open them below for the recommended fix." Each unhealthy card carries the specific fix (auth/endpoint/rate-limit), never a bare error.
