# WPCC V1 Final Scope And Release Audit

Date: 2026-08-01  
Scope: report-only source, admin IA, workflow, and WordPress.org release audit. No code, tests, branches, commits, deployment, or product expansion are part of this document.

Fixed V1 goal audited against:

> WP Command Center lets a WordPress site connect easily to an MCP-compatible AI assistant, allows the user to request website changes through natural-language prompts, and keeps every supported change secure through capability scoping, approval, audit history, and rollback.

Authoritative release references used:

- WordPress.org detailed plugin guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- WordPress.org readme rules: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- WordPress.org plugin directory requirements: https://developer.wordpress.org/plugins/wordpress-org/
- WordPress.org common plugin review issues: https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- WordPress.org plugin developer FAQ: https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- WordPress capability checks handbook: https://developer.wordpress.org/plugins/security/checking-user-capabilities/

## 1. Complete Feature Inventory

Works today means "implemented in source and wired to the current admin or API surface." It does not mean the workflow is suitable for a normal client without refinement.

| Item | Source / screen | What it does | Intended user | Works today | Facing | V1 classification |
|---|---|---|---|---|---|---|
| Plugin identity | `wp-command-center.php`, `readme.txt` | Registers WP Command Center `0.2.0-rc.3`; describes an AI operations platform with diagnostics, patching, rollback, and agent gateway. | Site admin, WordPress.org reviewer | Partial: plugin header is complete, readme is too thin and overbroad. | Client-facing | SIMPLIFY FOR V1 |
| Top-level admin menu | `includes/Admin/AdminMenu.php` | Adds "Command Center" plus six submenus. All require `manage_options`. | WordPress admin | Yes | Client-facing | SIMPLIFY FOR V1 |
| Home | `includes/Admin/views/command-home.php` | Dashboard, first-run panel, readiness checklist, attention cards, counts, recent activity, platform invariants. | Client and developer | Yes | Mixed | SIMPLIFY FOR V1 |
| Built-in AI | `includes/Admin/views/ai-setup.php`, `seo-meta.php`, `ai-alt-text.php`, `ai-content.php` | Stores provider credentials and drives built-in SEO, alt text, and content draft workflows. | Advanced client, agency, developer | Partial: provider setup works; runtime is strongest for Anthropic/OpenAI-compatible paths. | Mixed | MERGE FOR V1 |
| Connect | `includes/Admin/views/ai-integrations.php`, `api-integrations.php` | Explains MCP/API connection, generates assistant config snippets, creates access tokens, offers read-only test. | Client, agency, developer | Partial: pieces exist but the flow is fragmented and overly technical. | Client-facing | REQUIRED ADDITION FOR V1 |
| Activity | `approval-center.php`, `operations-center.php`, `proposals.php` | Approval inbox, queue/results, live activity, governed drafts when dev flag is enabled. | Client, developer | Yes | Mixed | SIMPLIFY FOR V1 |
| History | `change-history.php` | Timeline, sessions, reversible changes, detail diff, rollback modal. | Client | Yes for supported changes | Client-facing | KEEP FOR V1 |
| Settings | `settings.php`, `token-capability-manager.php`, `settings-diagnostics.php`, `settings-advanced.php` | Security mode, token/capability manager, diagnostics, search/replace, operations catalogue, file access. | Client, developer | Yes | Mixed | SIMPLIFY FOR V1 |
| Legacy URL redirects | `includes/Admin/AppShell.php`, `AdminMenu.php` | Redirects old slugs/tabs to current sections. | Existing installs/bookmarks | Mostly yes | Internal compatibility | KEEP FOR V1 |
| Stale internal links | `command-home.php`, `approval-center.php` | Some links still point to removed `wpcc_tab=recommendations` and `wpcc_tab=capabilities` paths. | Client | Partial | Client-facing | BLOCKING DEFECT |
| App shell | `includes/Admin/AppShell.php` | Six-section shell, Builder/Engineer toggle, mode pill, search/command affordance. | Client and developer | Yes | Client-facing | SIMPLIFY FOR V1 |
| Admin REST API | `includes/Admin/AdminRestApi.php` | Cookie/nonce-admin endpoints for approvals, history, tokens, operations, dashboard, proposals, alt text, SEO. | WP admin screens | Yes | Internal admin-facing | KEEP FOR V1 |
| WP admin row actions/action panel | `AiActionRegistry.php`, `ActionPanelAssets.php`, `SeoRowActions.php`, `MediaRowActions.php` | Adds contextual AI actions to admin list screens when feature flags and gates allow them. | Content admin | Yes when enabled | Client-facing optional | DEFER AFTER V1 |
| Feature gate seam | `includes/Admin/FeatureGate.php` | Central feature gate, currently returns true by default via filter. | Developer | Yes | Developer-facing | HIDE UNDER ADVANCED |
| Built-in AI feature flags | `WPCC_SEO_META_UI`, `WPCC_ALT_TEXT_UI`, `WPCC_AI_CONTENT_UI`, `WPCC_PROPOSALS_DEV_UI`, filters in `AppShell.php`, `BuiltinAiSettings.php` | Enables optional AI tools and dev proposal UI. | Developer/advanced admin | Yes | Mixed | HIDE UNDER ADVANCED |
| MCP JSON-RPC endpoint | `includes/Mcp/McpRestApi.php`, `McpServerRuntime.php` | Bearer-token MCP server at `/wp-command-center/v1/mcp`; exposes resources, tools, prompts, tool calls. | MCP assistant | Yes | Assistant-facing | KEEP FOR V1 |
| MCP resources | `McpServerRuntime.php` | Exposes manifest, context, capabilities, operations, queue, results, recommendations. | Assistant/developer | Yes | Assistant/developer | HIDE UNDER ADVANCED |
| MCP tool map | `McpServerRuntime.php`, `OperationRegistry.php` | Exposes one tool per registered operation. | Assistant/developer | Yes | Assistant/developer | HIDE UNDER ADVANCED |
| MCP prompts | `McpServerRuntime.php` | Gives canned assistant prompts for inspecting and managing site areas. | Assistant user | Yes | Client-facing through assistant | KEEP FOR V1 |
| Public REST API | `includes/AiAgent/RestApi.php` | Bearer-token API for health, context, files, search, patches, operations, queue, results, clients. | Developer, assistant | Yes | Developer-facing | HIDE UNDER ADVANCED |
| REST route/catalogue alignment | `RestApi.php`, `OperationRegistry.php` | Explicit REST run routes cover most but not every registered operation; MCP generic tool calls expose the full catalogue. | Developer/assistant | Partial | Developer-facing | HIDE UNDER ADVANCED |
| REST client configuration | `api-integrations.php`, `AiAgent/RestApi.php` | Documents REST base URL and bearer token use. | Developer | Yes | Developer-facing | HIDE UNDER ADVANCED |
| Access tokens | `includes/Auth/AuthTokens.php`, `token-capability-manager.php`, `ai-integrations.php` | Creates hashed `wpcc_` bearer tokens, raw token shown once, read-only/full scope, expiry, revoke/delete. | Client/admin | Yes | Client-facing | KEEP FOR V1 |
| Token manifest storage | `AuthTokens.php` | Stores token metadata and hashes in uploads manifest with deny files. | Internal | Yes | Internal | KEEP FOR V1 |
| Raw capability catalogue | `CapabilityRegistry.php`, `token-capability-manager.php` | 23 capability buckets and operation mapping for scoped tokens. | Developer/security admin | Yes | Developer-facing | HIDE UNDER ADVANCED |
| Operation map | `CapabilityRegistry.php`, `operations-explorer.php` | Maps operations to required capability and read-only scope. | Developer/security admin | Partial: some operation registry entries are not mapped. | Developer-facing | HIDE UNDER ADVANCED |
| Security modes | `SecurityModeManager.php`, `settings.php`, `Activator.php` | Developer, Client, Enterprise approval policies; fresh installs seed Client mode. | Client/admin | Yes | Client-facing | KEEP FOR V1 |
| Developer mode | `settings.php`, `SecurityModeManager.php` | Executes without human approval; intended for staging/development. | Developer | Yes | Client-visible today | HIDE UNDER ADVANCED |
| Client mode | `settings.php`, `SecurityModeManager.php` | Medium/high/critical changes require approval; low/diagnostic can run. | Normal V1 user | Yes | Client-facing | KEEP FOR V1 |
| Enterprise mode | `settings.php`, `SecurityModeManager.php` | All non-diagnostic operations require approval. | Compliance/team site | Yes | Client-facing | KEEP FOR V1 |
| Approval system | `OperationExecutor.php`, `OperationManager.php`, `approval-center.php`, `AdminRestApi.php` | Creates pending requests, supports approve/reject, queue, results, destructive confirmations. | Client/admin | Yes | Client-facing | KEEP FOR V1 |
| Admin bar approval badge | `AdminMenu.php` | Adds "AI Requests" badge when approvals are pending in non-developer modes. | Client/admin | Yes | Client-facing | KEEP FOR V1 |
| Activity live view | `operations-center.php`, `OperationsCenterQuery.php` | Live operations, failed queue items, system activity, token/cost placeholders, recent undoable sessions. | Developer | Yes | Mixed | HIDE UNDER ADVANCED |
| Change history | `ChangeRecorder.php`, `ChangeHistoryRuntimeManager.php`, `change-history.php` | Records changes, lists timeline/sessions/reversible items, displays diff and rollback state. | Client/admin | Yes for supported operations | Client-facing | KEEP FOR V1 |
| Rollback | `Rollback/*`, `OperationExecutor.php`, `change-history.php` | Reverts supported changes through registered rollback handlers and snapshots. | Client/admin | Partial by operation | Client-facing | KEEP FOR V1 |
| AI provider catalogue | `Integration/ProviderCatalog.php`, `ai-setup.php` | Lists Anthropic, OpenAI, Gemini, Azure, OpenRouter, Groq, Together, Fireworks, DeepInfra, Mistral, Perplexity, xAI, Ollama, LM Studio, vLLM, custom OpenAI-compatible. | Advanced admin | Partial runtime support | Client-visible today | DEFER AFTER V1 |
| AI model/routing settings | `ConnectionStore.php`, `ai-setup.php`, built-in AI classes | Stores default provider and routes for SEO, alt text, content generation. | Advanced admin | Partial | Client-visible today | HIDE UNDER ADVANCED |
| Built-in SEO workflow | `seo-meta.php`, `Seo/*`, `AdminRestApi.php` | Audits/generates SEO meta proposals and applies through governed change flow. | Content admin | Yes when enabled/provider configured | Client-facing optional | DEFER AFTER V1 |
| Built-in alt text workflow | `ai-alt-text.php`, `AltText/*`, `AdminRestApi.php` | Scans media, generates alt text suggestions, applies through governed change flow. | Content admin | Yes when enabled/provider configured | Client-facing optional | DEFER AFTER V1 |
| Built-in content workflow | `ai-content.php`, `Content/*`, `Proposals/*` | Generates title/excerpt/content proposals and applies through governed change flow. | Content admin | Yes when enabled/provider configured | Developer/client optional | DEFER AFTER V1 |
| Proposal store/dev drafts | `proposals.php`, `Proposals/*` | Developer validation surface for governed drafts. | Developer | Yes when flag enabled | Developer-facing | REMOVE FROM V1 UI |
| Diagnostics | `diagnostics.php`, `settings-diagnostics.php` | Site health, performance/security/WooCommerce checks, debug log viewer. | Developer/support | Yes | Developer-facing | HIDE UNDER ADVANCED |
| Site intelligence/report | `site-intelligence.php`, `report_manage` | Environment/site/plugin/theme/server report. | Developer/support | Yes | Developer-facing | HIDE UNDER ADVANCED |
| Recommendations | `recommendations.php`, `Recommendation/*` | Deterministic findings, scan, dismiss/resolve, approve/reject suggested fixes. | Developer/support | Yes | Mixed | HIDE UNDER ADVANCED |
| Search/replace tool | `tools-search-replace.php`, `Operations/SearchReplace.php` | Governed DB search/replace with dry run and approval. | Developer/support | Yes | Developer-facing | HIDE UNDER ADVANCED |
| File access UI | `file-access.php`, `FileAccessApi.php`, `CodeSearch.php` | Read-only browser/search for themes/plugins/mu-plugins, patch link. | Developer | Yes | Developer-facing | REMOVE FROM V1 UI |
| Patch UI and patch operation | `patches.php`, `PatchSystem/*`, `PatchOperation.php` | Creates, reviews, applies, verifies, and rolls back file patches. | Developer | Yes | Developer-facing | REMOVE FROM V1 UI |
| Database inspection | `database_inspect`, `RestApi.php` | Read-only database structure/data inspection through governed operation. | Developer/assistant | Yes | Developer-facing | HIDE UNDER ADVANCED |
| WP-CLI bridge | `WpCliBridge.php`, `wp_cli_bridge` | Runs allowlisted WP-CLI commands with output/time limits when shell access exists. | Developer | Yes | Developer-facing | REMOVE FROM V1 UI |
| Plugin/theme/update operations | `plugin_manage`, `theme_manage`, `safe_updates` | Governed management of high-risk site code/update operations. | Developer/assistant | Yes | Assistant/developer | HIDE UNDER ADVANCED |
| WooCommerce/ACF/forms/menu/widgets/CPT operations | `OperationRegistry.php`, runtime managers | Domain-specific site changes through operation handlers. | Advanced assistant/admin | Yes/partial by dependency | Assistant-facing | HIDE UNDER ADVANCED |
| Telemetry/cost placeholders | `operations-center.php`, `TelemetryStore.php` | Shows token/cost style placeholders and internal runtime data. | Developer | Partial/not tracked | Client-visible today | REMOVE FROM V1 UI |
| Internal DB version/invariants | `Schema.php`, `DashboardAdminQuery.php`, Home | Shows DB version, operation/capability/MCP counts. | Developer | Yes | Client-visible in parts | HIDE UNDER ADVANCED |

Operation catalogue from `includes/Operations/OperationRegistry.php` contains 42 operation IDs: `system_info`, `content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed`, `safe_search_replace`, `media_import`, `safe_updates`, `capability_manage`, `database_inspect`, `content_manage`, `snapshot_manage`, `theme_manage`, `plugin_manage`, `option_manage`, `wp_cli_bridge`, `user_manage`, `media_manage`, `woocommerce_manage`, `acf_manage`, `term_manage`, `cache_manage`, `forms_manage`, `menu_manage`, `settings_manage`, `approval_manage`, `search_manage`, `bulk_manage`, `workflow_manage`, `comments_manage`, `widgets_manage`, `cpt_manage`, `file_manage`, `code_search`, `patch_manage`, `rollback_manage`, `seo_manage`, `site_builder_manage`, `elementor_manage`, `report_manage`, `media_enhance`, `change_history`.

V1 visibility decision: expose only the plain user outcomes in client UI: connect, token, safety mode, approvals, history, rollback, safe test, and human-readable supported task categories. Keep the broad operation catalogue in backend/MCP only where gated, documented, and not promoted as a client console.

## 2. Admin Information Architecture Audit

Current navigation is partly understandable but too broad for the fixed V1 goal.

| Finding | Evidence | V1 classification |
|---|---|---|
| Six primary plugin items are too many for the V1 journey. | `AdminMenu.php` registers Home, Built-in AI, Connect, Activity, History, Settings. | SIMPLIFY FOR V1 |
| "Built-in AI" and "Connect" are not clearly distinct. | Connect setup tells users to add a provider in Built-in AI, but MCP assistant connection does not require a built-in provider key. | MERGE FOR V1 |
| Activity and History overlap. | Activity Live shows recent reversible sessions while History also owns review/undo. | SIMPLIFY FOR V1 |
| Settings is overloaded. | Security mode, REST endpoint docs, token/capabilities, diagnostics, search/replace, operations catalogue, and file access live under Settings. | SIMPLIFY FOR V1 |
| Developer tools are too prominent. | Operation counts, capability map, file access, patches, diagnostics, queue/runtime details appear in normal admin surfaces. | HIDE UNDER ADVANCED |
| Important V1 workflow is split across multiple screens. | Connect has token creation/test, Settings Access has token/capability management, Activity owns approvals, History owns undo. | REQUIRED ADDITION FOR V1 |
| Home can explain the product quickly, but the message is diluted. | First-run copy is strong; surrounding counters, invariants, operations, and built-in AI readiness add noise. | SIMPLIFY FOR V1 |
| Backward compatibility exists but needs link repair. | Legacy slug redirects are implemented, but some current links point to stale tab values. | BLOCKING DEFECT |

Recommended V1 navigation, maximum five primary items:

1. Home
2. Connect
3. Activity
4. History
5. Settings

Final placement:

| Current surface | V1 placement | Decision |
|---|---|---|
| Home | Home | KEEP FOR V1, simplified |
| Connect > AI Clients | Connect | KEEP FOR V1, rebuilt as guided setup |
| Connect > API & Integrations | Settings > Advanced > API | HIDE UNDER ADVANCED |
| Built-in AI > Providers/SEO/Alt Text/Content | Settings > Built-in AI, hidden or optional | MERGE FOR V1 |
| Activity > Approvals | Activity default tab | KEEP FOR V1 |
| Activity > Live | Activity > Advanced or Settings > Advanced | HIDE UNDER ADVANCED |
| Activity > Drafts (Dev) | Hidden unless dev flag | REMOVE FROM V1 UI |
| History | History | KEEP FOR V1 |
| Settings > Security & Approvals | Settings | KEEP FOR V1 |
| Settings > Access | Connect for simple token creation; Settings > Advanced for capability matrix | MERGE FOR V1 |
| Settings > Diagnostics | Settings > Advanced | HIDE UNDER ADVANCED |
| Settings > Tools/Search Replace | Settings > Advanced | HIDE UNDER ADVANCED |
| Settings > Advanced/Capabilities/File Access | Settings > Advanced, developer-only | HIDE UNDER ADVANCED |

Preserve direct URLs by keeping redirects and by rendering de-emphasized advanced pages when explicitly opened by a capable administrator. The V1 sidebar should not advertise developer surfaces.

## 3. Dashboard Audit

The dashboard should answer only:

1. What is WP Command Center?
2. Is my site ready?
3. Is an assistant connected?
4. Is my site protected?
5. Is anything waiting for my approval?
6. What should I do next?
7. Where can I review or undo a change?

Current Home item decisions:

| Dashboard item | Current behavior | Decision | V1 classification |
|---|---|---|---|
| Product explanation | Explains AI changes, approvals, audit, undo, and limitations. | Remain, shorter and MCP-first. | KEEP FOR V1 |
| First-run setup panel | Checklist plus three doors: Built-in AI, Connect assistant, app/API. | Simplify to one guided V1 path: Connect assistant. | SIMPLIFY FOR V1 |
| Built-in AI readiness | Provider/tool/content readiness dominates first-run. | Move to optional Built-in AI settings. | MERGE FOR V1 |
| Safety promise | "AI proposes, you approve, recorded, undo" is correct. | Remain. | KEEP FOR V1 |
| "What it does / does not do" details | Useful boundary against backup/fleet-manager expectations. | Keep condensed. | KEEP FOR V1 |
| Needs attention | Shows pending approvals, failures, recommendations, setup gaps. | Keep only approvals and setup blockers on Home. | SIMPLIFY FOR V1 |
| Approval count | Shows pending work needing human decision. | Remain. | KEEP FOR V1 |
| Access/token card | Shows tokens and capability/access state. | Replace with "assistant connection" status; move counts to Connect/Advanced. | SIMPLIFY FOR V1 |
| History card | Shows recent changes/undo path. | Remain, show 3-5 recent items. | KEEP FOR V1 |
| Capability counts | Counts capability buckets and available operations. | Move to Advanced. | HIDE UNDER ADVANCED |
| Operation counts | Shows catalogue/available/requires approval counts. | Remove from client dashboard. | HIDE UNDER ADVANCED |
| MCP tool counts | Shows one tool per operation via platform invariants. | Remove from client dashboard. | HIDE UNDER ADVANCED |
| DB version | Shows `Schema::DB_VERSION`. | Remove from client dashboard. | HIDE UNDER ADVANCED |
| Queue failures | Shows queue/system failure state. | Show only if user action is required; otherwise Activity Advanced. | SIMPLIFY FOR V1 |
| Raw operation names | Appears in activity/cards/details. | Replace with human-readable labels. | REQUIRED ADDITION FOR V1 |
| Large historical lists | Home can show recent activity and reversible sessions. | Limit to compact recent changes with History link. | SIMPLIFY FOR V1 |
| Diagnostics data | Recommendations/health/report data appears through Home attention. | Move to Advanced unless it blocks setup/security. | HIDE UNDER ADVANCED |
| Token counts | At-a-glance Access and token manager details. | Replace with "token ready / no token / revoked" status. | SIMPLIFY FOR V1 |
| Platform invariants | Operation map, capability catalogue, MCP count, DB version. | Keep only in Advanced diagnostics. | HIDE UNDER ADVANCED |

Proposed final dashboard wireframe:

```text
WP Command Center
Connect an AI assistant to safely change this WordPress site.

[Setup checklist]
1. Safety mode: Standard protection
2. Access token: Not created / Ready
3. Assistant config: Not copied / Copied
4. Connection test: Not run / Passed / Failed
5. First change: Waiting

[Status strip]
Site protection: Standard protection
Assistant: Not connected / Last tested today
Waiting for approval: 0
Undo available: 3 recent changes

[Next action]
Create token and copy assistant config

[Waiting for approval]
Only appears when pending requests exist.

[Recent changes]
3-5 human-readable items with View and Undo when supported.

[Advanced details]
Collapsed link to diagnostics, capabilities, operation map, and logs.
```

## 4. Connect Experience Audit

Current verdict: PARTIAL.

What exists today:

| Requirement | Current state | V1 classification |
|---|---|---|
| Explain what an AI assistant is | Connect hero and explainer do this. | KEEP FOR V1 |
| Explain why WPCC needs a token | Present, but split between explainer/config/security tabs. | SIMPLIFY FOR V1 |
| Show MCP URL | Present as REST URL to `/wp-command-center/v1/mcp`. | KEEP FOR V1 |
| Generate a token | Present in Connect Configuration and Settings Access. | KEEP FOR V1 |
| Choose scope | Read-only and full scopes exist; capability matrix is too technical. | SIMPLIFY FOR V1 |
| Copy configuration | Present with assistant-specific JSON snippets. | KEEP FOR V1 |
| Support Claude/Codex/ChatGPT/Gemini/Cursor | Registry supports Claude Desktop, Cursor, ChatGPT, Codex, Gemini, Continue, OpenCode, Aider, Roo Code, Windsurf, Command Code. | KEEP FOR V1 |
| Test connection | Read-only test exists after user pastes a token. | KEEP FOR V1 |
| Show live connection status | Current "Connection ready" is setup-derived, not actual assistant connection status. | REQUIRED ADDITION FOR V1 |
| Explain approval after connection | Present in safety notes, but not as one clear sequence. | SIMPLIFY FOR V1 |
| Disconnect/revoke access | Revoke/delete token exists in Access; not obvious in Connect flow. | REQUIRED ADDITION FOR V1 |
| Assistant-specific instructions | Config snippets exist; normal-user step-by-step recipes are insufficient. | REQUIRED ADDITION FOR V1 |

The current flow is too technical and duplicated. It mixes MCP assistant connection, built-in provider keys, generic API documentation, token capability management, certification labels, and operation counts. A normal agency owner can complete the setup if guided, but the product currently assumes too much hidden knowledge about MCP, bearer tokens, and which screens matter.

V1 changes genuinely required:

1. Turn Connect into a single guided setup flow: choose assistant, create/select token, choose scope, copy config, run read-only test.
2. Add assistant-specific plain-language snippets for Claude, Codex, ChatGPT, Gemini, Cursor, plus a generic MCP option.
3. Replace raw token/capability detail with simple scope names: "Read-only setup test" and "Allow governed changes".
4. Add persisted last-test status and last-used/last-tested wording so Home and Connect can say whether an assistant is connected or at least verified.
5. Put revoke/disconnect in the same Connect flow.

## 5. Prompt-To-Change Workflow Audit

Product promise audited: Prompt -> governed request -> approval -> execution -> history -> undo.

| Stage | Current entry point | System state | Failure state | Approval behavior | Audit/history | User wording | Verdict |
|---|---|---|---|---|---|---|---|
| Prompt | External MCP assistant using copied config; no generic in-plugin prompt box for MCP. | Assistant calls `tools/call` with operation/action/payload. | Hidden unless assistant reports MCP error. | Depends on operation risk/mode. | MCP result and operation result recorded when execution reaches executor. | Assistant-facing more than wp-admin-facing. | PARTIAL |
| Governed request | `McpServerRuntime::tools_call()` -> `OperationExecutor::run()`. | Token scope, capability map, destructive guard, idempotency, security mode evaluated. | Returns structured error envelope. | Creates pending approval when required. | Operation request/result tables. | Mostly technical operation/action IDs. | KEEP FOR V1 |
| Approval | Activity > Approvals; admin bar badge. | Pending requests show risk, payload, queue/result, diff/change set when available. | Queue/results/failures visible. | Approve executes or queues; reject requires reason for destructive flows. | Request audit trail exists. | Too many raw IDs for clients. | SIMPLIFY FOR V1 |
| Execution | Admin approve path or queued worker. | Operation handler dispatches and records result. | Failed results recorded and surfaced. | Client/Enterprise modes gate writes first. | Change recorder records applied/failed changes. | Failure details are technical. | KEEP FOR V1 |
| History | History timeline/sessions/reversible. | Change list, detail diff, rollback status. | Unsupported rollback shown as not available. | Rollback may itself require approval outside Developer mode. | Change log and rollback records. | Good concept, needs human labels. | KEEP FOR V1 |
| Undo | History rollback modal and rollback operation. | Reverts supported operations using handlers/snapshots. | Unsupported or failed rollback is shown. | High-risk rollback confirmation exists. | Rollback is audited. | "When supported" copy is honest. | KEEP FOR V1 |

End-to-end verdict: PARTIAL.

The backend governance loop is real: MCP call, token scope, capability validation, security mode, approval request, execution, change recording, and rollback all exist. The client-facing promise is not complete because the first prompt depends on hidden MCP setup knowledge, Connect is fragmented, Home is not MCP-first, operation names are raw, and approval/history views still read like an engineering console.

## 6. Client Mode, Developer Mode, Enterprise Mode

| Mode | Who it is for | What changes | What requires approval | Automatically allowed | Risks | UI explanation | V1 decision |
|---|---|---|---|---|---|---|---|
| Developer | Developers on staging/local sites. | Writes execute immediately. | Nothing by security mode. | All operations allowed if token/capability/destructive checks pass. | Unsafe for normal public users; can bypass the core approval promise. | UI warns it is not for client sites and uses confirmation. | HIDE UNDER ADVANCED |
| Client | Normal V1 users, agencies managing a client site. | Medium/high/critical operations create approval requests. | Medium, high, critical. | Diagnostic and low-risk operations. | Low-risk writes can still run without explicit human approval. | UI calls it recommended. | KEEP FOR V1 |
| Enterprise | Teams/compliance-heavy sites. | Every non-diagnostic operation creates approval request. | Low, medium, high, critical. | Diagnostic only. | More friction and queue volume. | UI is understandable but enterprise framing is heavier than V1 needs. | KEEP FOR V1 |

Plain-language V1 names:

| Current name | V1 name | Description |
|---|---|---|
| Developer | Development - instant changes | For staging/local work. Changes can run without approval. |
| Client | Standard protection - recommended | Safe tests can run; meaningful site changes wait for admin approval. |
| Enterprise | Strict approval | Every non-read-only change waits for admin approval. |

All three can remain in V1 if the primary UI defaults to Standard protection and hides Development under an advanced disclosure. Fresh installs seed Client mode in `Activator.php`, which is correct. However, `SecurityModeManager::DEFAULT_MODE` still falls back to Developer if the option is missing or invalid. That fallback is unsafe for public V1 and should be fixed before release.

Developer mode must not be the default for public users.

Classification: BLOCKING DEFECT for the fallback behavior if a missing/corrupt option can put a public site into instant-change mode.

## 7. Feature Pruning

This section separates backend capability from client-facing visibility. Stable backend capability does not need to be deleted merely because the UI should be hidden.

| Feature | Backend decision | V1 UI decision | V1 classification |
|---|---|---|---|
| Platform invariants | Keep for diagnostics. | Hide under Advanced. | HIDE UNDER ADVANCED |
| Raw capability catalogue | Keep for token enforcement. | Hide from default client flow. | HIDE UNDER ADVANCED |
| Operation map | Keep for MCP/capability enforcement. | Hide from default client flow. | HIDE UNDER ADVANCED |
| Developer diagnostics | Keep. | Move under Advanced. | HIDE UNDER ADVANCED |
| File system browsing | Keep only if necessary for assistant/developer workflows and review-safe. | Remove from public V1 UI. | REMOVE FROM V1 UI |
| Patch tools | Keep backend only if gated, documented, and review-safe. | Remove from public V1 UI. | REMOVE FROM V1 UI |
| Search/replace | Keep governed backend. | Hide under Advanced. | HIDE UNDER ADVANCED |
| Database inspection | Keep read-only backend. | Hide under Advanced. | HIDE UNDER ADVANCED |
| API endpoint documentation | Keep docs for developers. | Move to Advanced/API. | HIDE UNDER ADVANCED |
| Internal job/runtime details | Keep internally. | Hide unless troubleshooting. | HIDE UNDER ADVANCED |
| Queue failures | Keep. | Show on Home only when user action is required. | SIMPLIFY FOR V1 |
| Usage/cost placeholders | Do not show until real. | Remove from public V1 UI. | REMOVE FROM V1 UI |
| Built-in AI provider matrix | Keep optional. | Merge under Settings/Built-in AI and de-emphasize. | MERGE FOR V1 |
| Experimental built-in AI tools | Keep behind flags/options. | Do not make part of first-run V1. | DEFER AFTER V1 |
| Recommendations engine | Keep for support. | Hide under Diagnostics/Advanced. | HIDE UNDER ADVANCED |
| Duplicate onboarding cards | Consolidate. | One first-run path only. | SIMPLIFY FOR V1 |
| Certification/compatibility matrix | Keep as support documentation. | Remove from first setup screen. | SIMPLIFY FOR V1 |
| WP-CLI bridge | Keep only if security posture and review path are settled. | Remove from public V1 UI. | REMOVE FROM V1 UI |
| Plugin/theme/update management | Keep backend guarded. | Hide from default UI. | HIDE UNDER ADVANCED |
| Agent sessions/tasks/plans/actions | Keep API storage if needed. | Hide from client UI. | HIDE UNDER ADVANCED |

## 8. Missing V1 Functionality

Limit: required additions are capped at seven.

| Required addition | Why it is required | Affected surface | V1 classification |
|---|---|---|---|
| Guided Connect setup | The primary journey depends on token/config/test across multiple surfaces today. | Connect, Home | REQUIRED ADDITION FOR V1 |
| Assistant-specific setup recipes | Users need Claude/Codex/ChatGPT/Gemini/Cursor instructions without interpreting MCP JSON. | Connect | REQUIRED ADDITION FOR V1 |
| Live or last-tested connection status | Home must answer whether an assistant is connected or verified. | Home, Connect | REQUIRED ADDITION FOR V1 |
| Simple token scope selection | Normal users should choose read-only test vs governed changes, not inspect capability maps first. | Connect, Settings Access | REQUIRED ADDITION FOR V1 |
| Human-readable activity names | Approval/history cards must say what will change, not raw operation/action IDs. | Activity, History, Home | REQUIRED ADDITION FOR V1 |
| Simple change summary/diff | Approval and History need a plain "what will change/what changed" summary for supported V1 operations. | Activity, History | REQUIRED ADDITION FOR V1 |
| WordPress.org-safe onboarding/disclosures | Public release requires clear external service, API key, data storage, privacy, and uninstall/retention disclosures. | `readme.txt`, settings/help copy | REQUIRED ADDITION FOR V1 |

Deferred after V1: demo/reset state, broad usage/cost tracking, richer assistant analytics, provider marketplace depth, fleet/team management, advanced diagnostics redesign, and expanded operation catalogue UX.

## 9. WordPress.org Public-Release Audit

This section is evidence-based from source and official WordPress.org documentation. The WordPress.org guidelines require clear external service disclosure and consent, GPL-compatible licensing, safe behavior, and package hygiene. The common issues guide specifically calls out third-party-service disclosure and removal of unneeded development/test/demo folders. The developer FAQ warns that new plugins centered on arbitrary code insertion/execution, file managers, or AI tools that generate executable site code may not be accepted.

| Area | Classification | Evidence | Required action |
|---|---|---|---|
| Plugin header | ACCEPTABLE | `wp-command-center.php` includes name, description, version, WP/PHP requirements, license, text domain. | Keep, but simplify description. |
| Product naming/identity | RELEASE MEDIUM | Header/readme say "AI-powered WordPress Operations Platform" and emphasize diagnostics/file patching beyond fixed V1. | Rewrite around MCP assistant connection, approval, audit, rollback. |
| Version/stable tag | RELEASE HIGH | `Version` and `Stable tag` are `0.2.0-rc.3`; public directory release should not look like an RC. | Release as final stable tag/version before submission. |
| Readme completeness | WORDPRESS.ORG BLOCKER | `readme.txt` has minimal description/install/changelog, no FAQ, privacy, external service, token, storage, uninstall, or screenshots info; spec path is wrong. | Rewrite readme to WordPress.org standard. |
| External service disclosure | WORDPRESS.ORG BLOCKER | Built-in provider catalog and tests/generation can call Anthropic, OpenAI, Google Gemini, Azure, OpenRouter, Groq, Together, Fireworks, DeepInfra, Mistral, Perplexity, xAI, local providers. Readme does not disclose services, circumstances, terms/privacy links. | Add service-by-service disclosure and consent language. |
| API key disclosure | WORDPRESS.ORG BLOCKER | `CredentialStore` stores provider API keys in WordPress options; readme does not explain storage or transmission. | Document key storage, transmission, and revocation. |
| Privacy/data storage disclosure | WORDPRESS.ORG BLOCKER | Custom tables/options/files store tokens, audit logs, operation requests/results, queue, sessions/tasks/plans/actions, recommendations, health results, change log, proposals, patches/snapshots. | Add privacy/data retention disclosure and admin-facing summary. |
| Uninstall behavior | WORDPRESS.ORG BLOCKER | `uninstall.php` contains only a TODO. | Implement or explicitly retain with documented policy before release. |
| Package hygiene | WORDPRESS.ORG BLOCKER | Repo contains `tests/`, `docs/`, `artifacts/`, `scripts/`, `examples/`, handoff/program docs, localhost/dev/prod notes, and no observed release allowlist/distignore. | Create a review-safe release package excluding dev/test/artifact docs. |
| Arbitrary code/file-manager concern | WORDPRESS.ORG BLOCKER | Public/admin surfaces include file access and patch tools; operations include `patch_manage`, `file_manage`, `code_search`, `wp_cli_bridge`, plugin/theme/update operations. WordPress.org FAQ warns about file managers and AI/executable-code tools. | Remove from public V1 UI/package or gate/document to satisfy plugin review before submission. |
| Safe defaults | RELEASE HIGH | Fresh activation seeds Client mode, but `SecurityModeManager::DEFAULT_MODE` falls back to Developer when option is missing/invalid. | Make fallback public-safe. |
| Capability checks | ACCEPTABLE | Admin menus and admin REST require `manage_options`; public REST/MCP require bearer tokens. | Keep; run final security review. |
| Nonces | ACCEPTABLE | Forms/admin REST use nonce checks across settings, tokens, AI setup, approvals, patches, recommendations, search/replace. | Keep; final Plugin Check pass still required. |
| Escaping/sanitization | RELEASE MEDIUM | Views generally use `esc_html`, `esc_attr`, `esc_url`, and `wp_json_encode`; some rendered HTML badges/diffs use explicit HTML output. | Run PHPCS/Plugin Check/manual review for all unescaped render paths. |
| Remote requests | RELEASE HIGH | Provider tests/generation and generated MCP relay config use outbound requests or user machine `curl` to site-hosted script. | Ensure consent and disclosure; avoid remote calls until explicitly configured. |
| Third-party branding | RELEASE MEDIUM | Claude, Codex, ChatGPT, Gemini, Cursor, Anthropic, OpenAI, Google and others appear in UI. | Keep nominative use; add no-affiliation wording. |
| Development/debug content | RELEASE HIGH | Older docs/artifacts contain local paths, localhost, dev/prod notes, and program handoffs. | Exclude from WordPress.org package. |
| Hardcoded development URLs | RELEASE HIGH | Dev/docs/artifacts include localhost and local environment references. Runtime mostly uses generated site URLs. | Exclude dev docs/artifacts; audit runtime strings before package. |
| Test/demo data | RELEASE HIGH | Tests/examples/artifacts are present in repo. | Exclude from release package. |
| Admin notices | RELEASE LOW | Approval admin bar badge is scoped and useful; no broad notice abuse found in sampled source. | Keep restrained. |
| Feature flags | RELEASE MEDIUM | FeatureGate is always true by default; dev proposal UI is hidden by constant/filter but gate itself is ungated. | Ensure public build cannot accidentally expose dev-only UI. |
| Screenshots/assets | RELEASE LOW | Readme does not define screenshots/assets. | Add only after final UI is stable. |
| Translation readiness | RELEASE MEDIUM | PHP strings mostly use translation functions; JS inline strings exist and need final extraction review. | Run i18n tooling and generate/update POT. |
| Accessibility | RELEASE MEDIUM | Admin screens were source-audited, not browser/axe-tested. Complex tabs/modals need keyboard/focus validation. | Run admin accessibility pass before public release. |
| Error handling | RELEASE MEDIUM | REST/MCP return structured errors; client UI still exposes technical failures in some views. | Humanize V1 errors on Home/Connect/Activity/History. |
| REST route/catalogue drift | RELEASE MEDIUM | `OperationRegistry.php` registers the authoritative operation catalogue; `AiAgent/RestApi.php` uses explicit run routes plus generic operation lookup, so developer-facing REST docs can drift from MCP tools. | Document MCP as primary V1 path and keep REST docs under Advanced until route coverage is intentionally specified. |
| Secret handling | RELEASE HIGH | Access tokens are hashed; provider API keys are stored plaintext in options. | Keep token hashing; disclose provider-key storage and consider encryption later. |
| Licensing compatibility | ACCEPTABLE | GPL v2 or later header/readme and Composer license are present. | Keep. |
| Directory-review risk | WORDPRESS.ORG BLOCKER | File patching, WP-CLI, plugin/theme/update operations, and AI-generated executable code surfaces could trigger review rejection if public/prominent. | Prune/hide public V1 and prepare explicit reviewer explanation. |

## 10. Simplified V1 Specification

### Product promise

WP Command Center connects one WordPress site to an MCP-compatible AI assistant so requested site changes are scoped, approved, recorded, and undoable when supported.

### Primary user

The V1 user is a WordPress site owner or agency admin with wp-admin access who wants to connect Claude, Codex, ChatGPT, Gemini, Cursor, or another MCP-compatible assistant to one site without giving shell access. They need a simple setup path, safe defaults, clear approvals, understandable history, and honest rollback limits.

### Main navigation

1. Home
2. Connect
3. Activity
4. History
5. Settings

### First-run flow

1. Activate the plugin.
2. Choose Standard protection or Strict approval.
3. Choose an assistant in Connect.
4. Create or select an access token.
5. Copy the MCP configuration.
6. Run a read-only connection test.
7. Ask the assistant for a supported WordPress change and review the approval/history result.

### Core workflow

Prompt -> assistant MCP tool call -> token/capability/security checks -> approval request when needed -> admin approve/reject -> operation executes -> change is recorded -> rollback is offered when supported.

### Included features

- MCP endpoint and assistant configuration.
- Access token creation, read-only/full scopes, revoke/delete.
- Standard and Strict protection modes, with Development hidden under Advanced.
- Approval inbox with approve/reject and destructive confirmation.
- Change history, diff/summary, and rollback for supported operations.
- Safe read-only connection test.
- Minimal Home dashboard focused on readiness, connection, protection, approvals, next action, and recent undoable changes.
- WordPress.org-safe onboarding, disclosure, and uninstall/retention behavior.

### Advanced features

Retained but hidden or de-emphasized: API endpoint docs, diagnostics, recommendations, site report, capability catalogue, operation map, token capability matrix, search/replace, plugin/theme/update operations, database inspection, file/code search, patch backend, WP-CLI bridge, queue internals, agent sessions/tasks/plans, built-in AI provider routing.

### Deferred features

Built-in AI content/SEO/alt text as a primary product path, provider marketplace depth, usage/cost reporting, fleet management, hosting platform features, enterprise observability, demo/reset flows, advanced diagnostics redesign, public file manager experience, public patch/code editing UI, expanded assistant analytics.

### Release acceptance criteria

- Home explains the product and next step in under 30 seconds.
- A new admin can complete Connect without opening Advanced.
- A read-only test can be run from Connect and status appears on Home.
- A governed assistant request can create an approval.
- Approval clearly states what will change in human language.
- Approved supported changes appear in History.
- Supported changes can be undone from History.
- Development mode is not the default or fallback for public users.
- WordPress.org readme discloses external services, API keys, data storage, privacy, uninstall/retention, and third-party brand usage.
- Public release package excludes dev/test/artifact material and review-risk UI is hidden or removed.

## 11. Exact Refinement Backlog

### A. Release blockers

| # | Exact issue | Affected file/screen | Recommended action | Reason | Acceptance test | Size |
|---|---|---|---|---|---|---|
| A1 | Readme lacks required public-release disclosures. | `readme.txt` | Rewrite with product scope, installation, FAQ, external services, API key, privacy, data storage, uninstall/retention, screenshots placeholder, changelog. | WordPress.org review requires clear disclosure and consent for external services/data. | Readme answers what data is stored/sent, to whom, when, and how to revoke/delete. | M |
| A2 | Uninstall behavior is a TODO. | `uninstall.php`, schema/options/uploads | Implement cleanup or explicit retention policy and align readme. | Public users need predictable data removal/retention. | Uninstall path removes or intentionally retains documented WPCC tables/options/files/cron. | M |
| A3 | Public UI exposes file/patch/code-editing surfaces with directory-review risk. | `patches.php`, `file-access.php`, `settings-diagnostics.php`, `settings-advanced.php`, `PatchOperation.php` | Remove from public V1 UI or hide behind developer-only Advanced with review-safe documentation. | WordPress.org FAQ warns about file managers and AI/executable-code tools. | Default V1 admin has no visible patch/file-manager/code-editing entry. | M |
| A4 | Release package would include dev/test/artifact/program material. | `tests/`, `docs/`, `artifacts/`, `scripts/`, `examples/`, handoff docs | Build a release allowlist/dist package that excludes non-runtime material. | WordPress.org common issues call out unneeded dev/test/demo files. | Zip inspection shows only runtime plugin files, assets, readme, license, languages, uninstall. | M |
| A5 | Public version still looks like a release candidate and readme points to wrong spec path. | `wp-command-center.php`, `readme.txt` | Set final public version/stable tag and remove/fix broken canonical spec reference. | WordPress.org listing depends on readme accuracy and stable tag. | `Version` and `Stable tag` match final release; no broken spec reference. | S |
| A6 | External AI provider and MCP relay behavior lacks consent/disclosure. | `ai-setup.php`, `ai-integrations.php`, `ProviderCatalog.php`, `ClaudeIntegration.php` | Add UI/readme disclosure before remote-provider testing/generation and explain local relay config. | Users must know when data/API keys are sent or when local assistant config runs a command. | A fresh user sees disclosure before configuring provider or copying relay command. | M |
| A7 | Missing/invalid security mode falls back to Developer. | `SecurityModeManager.php`, `Activator.php`, Settings | Make fallback Standard protection for public builds. | Instant-change fallback can bypass the V1 approval promise. | Deleting `wpcc_security_mode` still results in Standard protection. | S |
| A8 | Dev-only or internal surfaces can appear if constants/filters are enabled and FeatureGate is always true. | `FeatureGate.php`, `AppShell.php`, `proposals.php` | Ensure public V1 cannot show Drafts (Dev) or internal validation UI by default. | Public UI must not feel like an internal console. | Fresh install with no custom constants shows no dev draft/proposal surface. | S |
| A9 | Provider API key storage is not disclosed. | `CredentialStore.php`, `readme.txt`, Settings help | Document plaintext option storage and deletion behavior. | Secret handling must be transparent. | Readme and UI state where provider keys are stored and how to remove them. | S |
| A10 | WordPress.org Plugin Check/security review has not been completed on final package. | Full release package | Run Plugin Check/PHPCS/security pass after pruning package. | Source spot-check is not a directory-review substitute. | Final package has no blocker-level Plugin Check/PHPCS findings. | M |

### B. V1 usability essentials

| # | Exact issue | Affected file/screen | Recommended action | Reason | Acceptance test | Size |
|---|---|---|---|---|---|---|
| B1 | Primary navigation has six items and over-promotes Built-in AI. | `AdminMenu.php`, `AppShell.php` | Reduce primary nav to Home, Connect, Activity, History, Settings; move Built-in AI under Settings/optional. | The fixed V1 journey is external assistant connection, not a built-in AI suite. | Sidebar shows no more than five WPCC primary items. | M |
| B2 | Connect setup is fragmented across AI Clients, Configuration, Settings Access, and API docs. | `ai-integrations.php`, `token-capability-manager.php`, `api-integrations.php` | Create one guided setup path with assistant, token, config, test, revoke. | Normal users need one clear setup flow. | New admin can complete setup without opening Settings Access. | M |
| B3 | Home dashboard shows engineering counters. | `command-home.php`, `DashboardAdminQuery.php` | Remove/move operation, capability, MCP tool, DB version, token-count, diagnostics data from client Home. | Dashboard should answer readiness and next action only. | Home contains no raw counts except approvals/recent changes. | M |
| B4 | Activity and History use raw operation/action IDs. | `approval-center.php`, `change-history.php`, query formatters | Add human labels and plain summaries for supported operations. | Clients need to know what will change. | Pending request cards use human title plus technical ID only in Advanced detail. | M |
| B5 | Stale links route users to removed tabs. | `command-home.php`, `approval-center.php` | Update current URLs or rely on explicit redirects. | Broken/confusing links damage first-run flow. | All Home/Approval CTAs open an existing rendered screen. | S |
| B6 | Development mode is too visible and named for normal clients. | `settings.php` | Rename modes in plain language and hide Development details under Advanced. | Safety choice must be understandable. | First-run presents Standard and Strict clearly; Development is de-emphasized. | S |
| B7 | Connection status is not real enough. | Home, Connect | Persist last read-only test time/status and show it. | User journey asks "is an assistant connected?" | Passing test changes status on Home and Connect. | M |
| B8 | Approval detail is payload-first for some operations. | `approval-center.php`, operation change-set builders | Show simple "will change" summary before JSON/payload. | Approval should be a decision, not a debugging task. | Supported operation approval cards can be understood without opening JSON. | M |
| B9 | History rollback availability is not prominent enough on Home. | `command-home.php`, `change-history.php` | Show compact recent changes and direct "View/Undo" when supported. | The product promise includes undo. | Home lists recent supported rollback items with a History link. | S |
| B10 | Built-in AI copy conflicts with Connect journey. | `ai-integrations.php`, `ai-setup.php`, Home | Remove provider-key requirement from MCP setup copy; present built-in AI as optional. | Users should not think Claude/Codex MCP requires configuring an AI provider in WordPress. | Connect can be completed with no built-in provider configured. | S |

### C. Post-V1 backlog

| # | Exact issue | Affected file/screen | Recommended action | Reason | Acceptance test | Size |
|---|---|---|---|---|---|---|
| C1 | Built-in SEO/Alt Text/Content are useful but not required for MCP V1. | Built-in AI screens | Keep optional after V1 polish. | Avoid delaying release for secondary workflow. | V1 works with built-in AI disabled. | L |
| C2 | Provider runtime coverage is broader in UI than verified capability. | Provider catalog/setup | Expand and test providers after V1. | V1 does not need a provider marketplace. | Provider matrix has automated smoke tests. | L |
| C3 | Recommendations engine overlaps approval/change guidance. | Recommendations, Home | Redesign as support diagnostics later. | Not required for prompt-to-change. | Hidden in V1; later UX spec exists. | M |
| C4 | Diagnostics/site report are support-heavy. | Diagnostics/Site Intelligence | Keep under Advanced; redesign after V1. | Client dashboard should stay simple. | Diagnostics no longer appears in first-run. | M |
| C5 | Usage/cost placeholders are not real. | Operations Center, telemetry | Remove from V1; add only with real accounting. | Placeholder metrics reduce trust. | No cost/token placeholder appears in V1 UI. | M |
| C6 | File browser could be useful for developers. | File Access | Revisit after directory-review path is settled. | It is not client V1. | Any future UI is developer-only and review-safe. | L |
| C7 | Patch/code editing is powerful but high-risk. | Patch UI/operation | Keep out of public V1; revisit with explicit review strategy. | Directory-review risk is too high. | No V1 public patch UI. | L |
| C8 | WP-CLI bridge is developer power-user functionality. | WP-CLI operation | Keep hidden and review later. | Not required for normal clients. | Not visible in V1 UI. | M |
| C9 | DB inspection is a support tool. | Database inspect operation | Keep hidden. | Not required for first assistant connection. | No default UI link. | S |
| C10 | Fleet/enterprise observability is outside product goal. | Any future IA | Do not build before V1. | Prevents scope expansion. | No fleet/team/hosting nav or copy in V1. | L |
| C11 | Agent sessions/tasks/plans UI is internal. | Agent REST/store | Keep API/internal; no client UI before V1. | Avoid engineering-console feel. | No sessions/tasks/plans page in V1. | M |
| C12 | Operation catalogue UX is too technical. | Operations Explorer | Keep Advanced only; improve later if needed. | Not required for clients. | Operation IDs are hidden by default. | M |
| C13 | Capability management UI is too granular. | Token Capability Manager | Keep Advanced only; maybe add presets later. | V1 only needs simple scopes. | Client token flow exposes only simple scopes. | M |
| C14 | Search/replace UI is advanced. | Tools/Search Replace | Keep Advanced only. | It is high-impact and not primary. | Not visible in primary nav. | S |
| C15 | Plugin/theme/update management needs stricter user education. | Operation handlers | Revisit after V1 with explicit risk copy. | High-risk operations are not needed to prove V1. | Hidden behind advanced/tool calls. | M |
| C16 | Accessibility test coverage is unknown. | All admin screens | Add full keyboard/focus/contrast pass after V1 IA settles. | Testing before IA pruning wastes effort. | Axe/manual pass documented. | M |
| C17 | Screenshots/assets are absent. | WordPress.org assets | Add after final UI. | Screenshots should reflect final IA only. | Directory assets match V1 screens. | S |
| C18 | Demo/reset state could help onboarding. | Home/Connect | Defer unless support demand proves it. | Not required for core promise. | No demo/reset requirement in release criteria. | M |
| C19 | Assistant analytics and certification labels add clutter. | Connect | Move to docs or post-V1 support page. | Setup should be practical, not a compatibility console. | Connect setup has no certification matrix in default view. | S |
| C20 | Advanced operation labels can be richer. | Registry/query formatters | Improve labels beyond V1 core operations later. | V1 needs labels only for visible operations. | Hidden advanced catalogue can still show technical IDs. | M |

## 12. Final Verdict

1. What is WPCC today?  
WPCC today is a broad WordPress AI operations console. It includes MCP and REST assistant gateways, token scoping, security modes, approvals, audit history, rollback, built-in AI provider setup, diagnostics, file/code tools, patching, operation catalogues, and developer/runtime surfaces.

2. What should WPCC V1 be?  
WPCC V1 should be a simple single-site MCP assistant connector with safe token setup, clear safety mode, governed changes, approval inbox, human-readable history, and rollback when supported.

3. What should be removed or hidden?  
Remove from public V1 UI: file access, patch tools, WP-CLI, telemetry/cost placeholders, dev drafts, raw operation/capability catalogues, platform invariants, broad diagnostics, recommendations, and API docs. Keep stable backend capability only where it is gated, documented, and not promoted to normal clients.

4. What must be added?  
Add guided Connect setup, assistant-specific recipes, last-tested connection status, simple scope selection, human-readable approval/history labels, simple change summaries, and WordPress.org-safe disclosures/onboarding.

5. Is the current dashboard suitable for clients?  
No. It contains useful first-run copy, but it mixes client readiness with internal operation counts, capability counts, MCP tool counts, DB version, diagnostics, token counts, queue details, built-in AI readiness, and raw operation names.

6. Is the current product suitable for WordPress.org?  
No. Public submission is blocked by missing readme disclosures, uninstall TODO, package hygiene, external service/API key/privacy disclosure gaps, and directory-review risk around file/patch/code execution surfaces.

7. Is the prompt-to-change promise genuinely functional?  
PARTIAL. The backend governance loop is functional for supported operations, but the client-facing setup and approval/history wording are not ready.

8. What is the smallest path to release?  
Prune the UI to Home, Connect, Activity, History, Settings; make Connect one guided flow; hide developer/advanced surfaces; fix safety fallback and stale links; humanize approvals/history; complete WordPress.org disclosures/uninstall/package cleanup; then run a final MCP smoke test and WordPress.org Plugin Check on the release package.

9. What must not be worked on before release?  
Do not build fleet management, hosting features, enterprise observability, provider marketplace depth, cost analytics, public file-manager features, public patch/code-editing UI, or new broad product programs before V1 release.

10. Final verdict:  
NOT READY - BLOCKERS PRESENT
