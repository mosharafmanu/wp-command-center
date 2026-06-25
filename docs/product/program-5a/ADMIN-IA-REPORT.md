# PROGRAM-5A — Phase 1: Admin IA + Navigation Cleanup

## Decision: keep the 5-C IA; add, don't restructure
The admin is already consolidated (historical ~12 submenus → **5 sections**: Overview · Operate · Audit · Access · Connect), each rendered by `AppShell` with a working legacy-slug redirect system (`AppShell::legacy_map()` + `AdminMenu::redirect_legacy_slugs()` at `admin_menu` priority 0). Re-flattening to the prompt's suggested 9-item menu would fragment a coherent model, fight the redirect system, and constitute a **broad admin UI rewrite (a STOP condition)**. The smallest safe, highest-value change is therefore *additive*: surface the missing **AI Setup** entry and improve clarity, not restructure.

## Changes made
1. **Added a dedicated "AI Setup" tab** under **Connect** (`AppShell::sections()`), placed **first** so the provider-key/model/test surface is the prominent AI entry (ahead of the existing "AI Integrations" MCP-client surface). View stem: `ai-setup`.
2. **Added a legacy slug** `wpcc-ai-setup → [wpcc-connect, setup]` so a direct/bookmarked URL resolves into the new tab via the existing redirect path.

No other navigation changes. No labels removed, no callbacks changed, no functionality removed.

## Why this resolves the adoption gap
Before: a design partner had **no admin path** to configure the AI provider key/model — it required defining a PHP constant or hand-setting an option (developer-only). After: a clearly-labelled **Connect → AI Setup** tab exists, discoverable from the menu and from the first-run checklist.

## Page inventory (post-change)
| Section | Tabs |
|---|---|
| Overview | Home (`command-home`) |
| Operate | Approvals, Operations, Runtime (+ flag-gated AI surfaces, OFF) |
| Audit | Changes, Patches, Diagnostics, Site Intelligence |
| Access | Tokens & Capabilities, Security Mode |
| **Connect** | **AI Setup (new)**, AI Integrations, File Access |

## Validation
- `php -l` clean on `AppShell.php`.
- `test-admin-permissions.sh` → **51/0**.
- `test-admin-ux.sh` → 22/1 (the 1 failure is **pre-existing on `main`**, identical — not attributable).
- `test-ai-integration-ux.sh` → 51/3 (all 3 **pre-existing on `main`**, identical — environment config, not attributable).
- Legacy redirects preserved (map extended, logic untouched); direct old URLs still resolve.
- Invariants unchanged (no routes/ops/caps/tools/schema touched).

**Phase 1: GREEN.**
