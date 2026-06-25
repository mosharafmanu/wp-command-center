# Phase 1 — Navigation Report

> Backward-compatibility contract for the IA migration. **Every pre-existing URL keeps working** — no bookmark, deep link, admin-bar link, or in-product link 404s or 403s.

## Top-level menu (what the user now sees)
```
Command Center
├── Home          → Mission Control (what needs me, what changed, where to start)
├── Built-in AI   → Providers · SEO · Alt Text · Content        (Door 1)
├── Connect       → AI Clients · API & Integrations             (Doors 2 & 3)
├── Activity      → Live · Approvals (· Drafts dev)
├── History       → Changes (review + undo)
└── Settings      → Security & Approvals · Access · File Access · Diagnostics ·
                    Patches · Site Report · Capabilities · Runtime
```
Architecture words (Runtime, Event Bus, Registry, Telemetry, Routing, MCP, REST, dialect) appear in **no** menu label or section title. They surface only inside views or under the existing Engineer/Developer disclosure.

## Backward-compatibility: how legacy URLs survive
Redirects run in `AdminMenu::redirect_legacy_slugs()` on `admin_menu` **priority 0** — *before* core's `user_can_access_admin_page()` 403 in `menu.php` — so even now-unregistered slugs resolve instead of dying. Resolution is centralized in `AppShell::resolve_legacy($page, $tab)` and is **tab-aware**: it consults the incoming `?wpcc_tab=` for the retired 5-C *section* slugs, so a deep link lands on its exact new home. All other query args (`session_id`, `tab`, `client`, …) pass through untouched.

### A. Retired standalone slugs (pre-5-C bookmarks) → new home
| Legacy `?page=` | New destination |
|---|---|
| `wpcc-dashboard-overview` | Home |
| `wpcc-approval-center`, `wpcc-approvals` | Activity › Approvals |
| `wpcc-operations-center` | Activity › Live |
| `wpcc-operations` | Settings › Capabilities |
| `wpcc-change-history`, `wpcc-rollback` | History › Changes |
| `wpcc-patches` | Settings › Patches |
| `wpcc-diagnostics` | Settings › Diagnostics |
| `wpcc-site-intelligence` | Settings › Site Report |
| `wpcc-tokens` | Settings › Access |
| `wpcc-settings` | Settings › Security & Approvals |
| `wpcc-settings` (+ `section=tokens`) | Settings › Access *(special-case preserved)* |
| `wpcc-ai-setup` | Built-in AI › Providers |
| `wpcc-ai-integrations` | Connect › AI Clients |
| `wpcc-file-access` | Settings › File Access |
| `wpcc-proposals` | Activity › Drafts |
| `wpcc-alt-text` / `wpcc-seo` / `wpcc-ai-content` | Built-in AI › Alt Text / SEO / Content |

### B. Retired 5-C *section* slugs (deep links with a tab) → new home
| Legacy `?page=&wpcc_tab=` | New destination |
|---|---|
| `wpcc-operate` · center / approvals / operations / runtime / drafts / alt_text / seo / ai_content | Activity › Live / Activity › Approvals / Settings › Capabilities / Settings › Runtime / Activity › Drafts / Built-in AI › Alt Text / SEO / Content |
| `wpcc-audit` · changes / patches / diagnostics / intelligence | History › Changes / Settings › Patches / Diagnostics / Site Report |
| `wpcc-access` · tokens / security | Settings › Access / Security |
| `wpcc-connect` · setup / integrations / files | Built-in AI › Providers / Connect › AI Clients / Settings › File Access |

`wpcc-connect` remains a live slug; only its **old** tab keys (setup/integrations/files) redirect — its new tabs (clients/api) render directly with no hop.

## Internal links repointed (no redirect hops in-product)
All in-product links now target canonical new URLs (verified: zero retired `page=wpcc-operate|wpcc-audit|wpcc-access|wpcc-ai-integrations` links remain in `includes/Admin/views`). The **admin-bar "AI Requests" badge** points at `Activity › Approvals`. The **Home quick-win** ("Run a site report") points at `Settings › Diagnostics`. The **dashboard/Runtime** self-links and timeline form target `Settings › Runtime`.

## Verification
- **Static:** `test-ia-phase1.sh` §3 asserts every standalone + tab-aware mapping above.
- **Functional (live wp-cli):** `test-token-capability-admin.sh` exercises the real redirect — `…?page=wpcc-settings&wpcc_tab=access&section=tokens` confirmed for the positive case, `…wpcc_tab=security` for the negative (plain Settings) case.
- **No stale submenus:** the retired per-view submenus (change-history, tokens, operations, approval-center, rollback) are absent — asserted by `lacks` guards.
