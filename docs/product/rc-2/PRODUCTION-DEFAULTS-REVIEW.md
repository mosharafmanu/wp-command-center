# RC-2 — Production Defaults Review

> Clears RC-1 blocker **W2** (insecure-by-default). Every default that differs between a developer build and the release build is documented below.

## The one default that changed (the fix)
| Default | Developer build (pre-RC-2) | Release build (RC-2) | Mechanism |
|---|---|---|---|
| **Security mode on fresh install** | `developer` (self-approving — AI/agents write immediately) | **`client`** (medium/high/critical writes require human approval) | `Activator::activate()` seeds `wpcc_security_mode = MODE_CLIENT` **only when unset** |

Why this is the correct, minimal fix:
- A real client site is now **governed out of the box** — the product's promise (approval before changes) is ON, not OFF.
- It is **one-time + unset-only**: existing sites that already chose a mode are untouched; an operator can still deliberately choose Developer mode via the Security UI (which carries the 5A/5B confirmation guard + audit).
- It does **not** change `SecurityModeManager::DEFAULT_MODE` or `current()` resolution — so the documented fallback, all security-mode tests, and existing behavior are preserved. The change is isolated to the *fresh-install seed*.

## Defaults reviewed and deliberately LEFT as-is (correct for a client-safe release)
| Default | Value | Verdict |
|---|---|---|
| AI surface flags (`WPCC_ALT_TEXT_UI`/`WPCC_SEO_META_UI`/`WPCC_AI_CONTENT_UI`/`WPCC_PROPOSALS_DEV_UI`) | **OFF** | Correct — AI stays off until a site explicitly enables a feature. A concierge beta enables the chosen slice intentionally (E2E doc). |
| Anthropic API key | **unset** | Correct — BYO key; never shipped/committed. |
| Capability enforcement | **on** (`wpcc_enforce_capabilities` default true) | Correct — least-privilege by default. |
| Token scope on creation | explicit (read_only / full) | Correct — no implicit elevation. |
| Telemetry table | self-provisions on first event; empty otherwise | Correct — additive, no data fabricated. |
| Event Bus subscribers | none in production | Correct — foundation only (P9). |
| Operations Center | read-only; honest empty/"not tracked yet" | Correct — no faked liveness. |

## "Developer convenience" audit — none leak into the release build
- The only developer-convenience default was **developer security mode**; it is now seeded to **client** on fresh installs.
- No debug flags, no verbose logging, no auto-approve, no auto-enabled AI, no seeded keys ship enabled.

## Deployment note (honest)
This RC branch is **not pushed/deployed**. The pull-deploy reactivates the plugin on each deploy; because the seed is **unset-only** (`add_option`/`false === get_option`), it will set `client` only on a site that has never chosen a mode. The current production site relies on the developer *fallback* (no stored option), so the first deploy of this build would seed it to **client** — the intended, safer posture. This is an owner-authorized deploy decision, flagged here, not taken by RC-2.

**Blocker W2: CLEARED** — the release build is client-safe by default.
