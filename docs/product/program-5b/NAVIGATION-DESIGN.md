# PROGRAM-5B — Phase B: Navigation Design

## Goal
A non-technical agency owner understands WPCC within 5 minutes.

## Evaluation of the proposed concepts (keep / merge / move / rename / remove)
| Concept | Decision | Rationale |
|---|---|---|
| **Overview** | **Keep** | Best entry point; now carries a one-line section description. |
| **Operate** | **Keep + describe** | Hosts the human Review surface (Approvals) + the Operations catalogue. Added desc: "Review and approve the work AI wants to do…" |
| **Review** | **Merge into Operate (Approvals)** | "Review" = the Approvals surface, already under Operate. Surfaced via the section description rather than a new top-level menu (avoids re-flattening + legacy-redirect churn). |
| **Audit** | **Keep + describe** | "See every change, and undo the ones that can be reversed." |
| **Settings / Security** | **Keep as Access → Security Mode** | Security mode is the key "setting"; lives in Access alongside Tokens. |
| **AI Setup** | **Keep, prominent** | First tab under Connect (from 5A); the provider/key/model/test home. |
| **Access Tokens** | **Keep as Access → Tokens & Capabilities** | Stale "arrives later" copy corrected (create/revoke ship). |
| **Connect** | **Keep + describe + disambiguate** | "AI Integrations" renamed **"Connect an AI Agent"** to separate it from "AI Setup". |

## Why NOT re-flatten to 8 top-level menus
The mission lists 8 concepts; all already exist within the proven **5-C IA** (Overview · Operate · Audit · Access · Connect) with a working legacy-slug redirect system. Re-flattening would:
- fragment a coherent operator mental model into a long sidebar,
- fight `AppShell::legacy_map()` / `AdminMenu::redirect_legacy_slugs()` (every old bookmark redirects through the 5-C),
- constitute a **broad admin UI rewrite** (an explicit STOP risk).

The highest-value, lowest-risk navigation rebuild is therefore **clarity within the 5-C**: plain-language section descriptions, disambiguated labels, de-emphasis of engineer surfaces — not a structural teardown.

## Changes implemented
1. **Section descriptions** (new `desc` per section in `AppShell::sections()`, rendered as `.wpcc-shell__desc`): one plain-language line orienting a newcomer at the top of every section.
2. **Label disambiguation:** `AI Integrations → "Connect an AI Agent"` (vs "AI Setup" = provider keys).
3. **De-emphasis:** `Runtime → "Runtime (advanced)"` (the legacy engineer dashboard; signals it's optional/advanced).
4. **Order preserved** with AI Setup first under Connect (from 5A).

## Backward compatibility
- All section/tab **slugs unchanged** (`wpcc-operate`, `wpcc-connect`, `?wpcc_tab=…`). Only **labels/descriptions** changed.
- `AppShell::legacy_map()` entries (incl. the 5A `wpcc-ai-setup`) intact → every legacy/bookmarked URL still redirects correctly.
- No callbacks, routes, or capabilities changed.

## Validation
- `php -l` clean (`AppShell.php`).
- `test-usability-5b.sh` §2 → all green (descriptions render, labels renamed, 5-C intact, AI Setup present).
- `test-admin-permissions.sh` 51/0; `test-admin-ux.sh` pre-existing 1 fail only.

**Phase B: GREEN.**
