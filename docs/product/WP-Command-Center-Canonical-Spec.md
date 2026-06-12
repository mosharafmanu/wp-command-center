# WP Command Center
## The AI Agent Gateway & Operations Layer for WordPress

> Canonical product specification — consolidated from `AI-Agent-Gateway`, `Product-Bible`, `Master-Product-Bible-combine`, and `Workflow` drafts.

---

## 1. Tagline

Connect Claude, Codex, GPT, and future AI agents to WordPress websites using only WordPress Admin access.

---

## 2. Executive Summary

WP Command Center is an AI-powered WordPress Operations Platform combining:

- AI Agent Gateway
- WordPress Diagnostics
- Developer Operations
- Safe File Patching
- Rollback Protection
- Site Intelligence

Goal: eliminate the dependency on SSH / WP-CLI / root access for AI-assisted WordPress development, troubleshooting, and maintenance.

---

## 3. Problem Statement

Most WordPress developers and agencies receive:

- WordPress Admin Access
- FTP/SFTP Access
- Database Access (sometimes)

But rarely receive:

- SSH Access
- WP-CLI Access
- Root / Server Access

This gap blocks modern AI-assisted development workflows — AI coding agents (Claude Code, Codex CLI, etc.) need file system and diagnostic access that most client environments don't grant.

---

## 4. Vision & Core Mission

**Vision:** Build the infrastructure layer between WordPress and AI agents. Not a backup plugin, not a maintenance plugin, not another MainWP/ManageWP/InfiniteWP clone.

**Core Mission:** Enable AI agents and developers to securely understand, inspect, diagnose, modify, and operate WordPress websites using only WordPress Admin access — providing SSH-like operational capability from inside wp-admin while maintaining security and reliability.

---

## 5. Product Positioning

| Not | Instead |
|---|---|
| MainWP | AI Agent Gateway |
| ManageWP | WordPress AI Infrastructure |
| InfiniteWP | AI Development / Operations Bridge |
| Backup Plugin | Developer Operations Platform |
| Monitoring Tool | AI-Powered WordPress Control Center |

Focus areas: Developer Operations, AI Diagnostics & Debugging, Safe Code Patching, WordPress Troubleshooting, Operational Automation.

---

## 6. How It Works (High-Level Flow)

```
Claude / Codex / GPT / MCP Agent
        ↓
WP Command Center
        ↓
WordPress API Layer
        ↓
Files, Logs, Diagnostics, Site Context
```

End-to-end loop:

```
Client Site → WP Command Center → AI Agent → Patch Approval → Safe Deployment
```

---

## 7. Architecture

### Layer 1 — Site Intelligence Engine

Collects and exposes:

- WordPress Version
- PHP Version
- Active Theme
- Active Plugins
- WooCommerce Status
- Site Health
- Cache Configuration
- Server Capabilities (`shell_exec`, `proc_open`, WP-CLI availability, etc.)
- Debug Logs / Debug Status
- File Permissions

Purpose: give the AI complete understanding of the site *before* it suggests or makes changes. Also covers native WP management surfaces — plugin/theme/user management.

### Layer 2 — Diagnostics & Operations Engine

**Site Scanner**
- Site Health
- Plugin Scanner
- Theme Scanner
- Configuration Checks

**Diagnostics Suite**
- Performance: Cache Analysis, Memory Usage, Plugin Impact Analysis
- Security: File Permissions, Debug Status, Configuration Checks
- WooCommerce: Scheduled Actions, Payment Status, Template Overrides
- General: Cache Diagnosis, Performance Analysis, Debug Log Viewer

**Operations**
- Search & Replace (database)
- Database Export
- Safe Updates (plugins/themes)
- Rollback Points

**WP-CLI Bridge** (sub-component — see §9 for availability conditions)
- `wp plugin list`
- `wp cache flush`
- `wp db export`
- Export / Optimize / Repair Database
- Update Plugins / Themes
- Safe Search & Replace

### Layer 3 — AI Agent Engine

Supports: Claude, Codex, GPT, MCP Agents, future AI systems.

AI can:
- Read files
- Search code
- Analyze architecture
- Generate patches
- Suggest fixes
- Run AI Site Audit / AI Debugging / AI WooCommerce Troubleshooting / AI Recommendations

---

## 8. Core Features

### 8.1 AI Context Engine

Provides AI with a structured snapshot before it acts:

- Active Theme
- Active Plugins
- WooCommerce Data / Status
- Site Health
- Cache Configuration / Data
- PHP Version
- WordPress Version
- Debug Logs

### 8.2 File Access API

AI can:
- Read files
- Search files
- List directories
- Analyze code

| Allowed | Blocked |
|---|---|
| `wp-content/themes/` | `wp-config.php` |
| `wp-content/plugins/` | `.htaccess` |
| `wp-content/mu-plugins/` | Core WordPress files |

### 8.3 AI Terminal (Virtual, Not SSH)

A virtual terminal experience for natural-language code search, e.g.:

- "Find WooCommerce checkout customization"
- "Search for all custom order meta fields"
- "Locate Elementor overrides"
- "Analyze plugin conflicts"

AI performs code search, file inspection, and analysis only — **not** a real shell.

### 8.4 AI Diagnostics

Answers questions like:
- "Why is the site slow?"
- "Why is checkout broken?"
- "Why aren't my changes visible?"
- "Why is WooCommerce failing?"

Plugin gathers relevant context (WooCommerce status, recent errors, active plugins, theme info, etc.) and hands it to the AI. AI returns: Root Cause Analysis, Suggested Fix, Risk Assessment. **No file changes occur** during diagnostics.

### 8.5 Patch System (AI Coding Bridge)

AI never edits files directly. Full workflow:

1. AI requests file access
2. Plugin validates permissions
3. File contents returned
4. AI reads file(s) and generates a patch + explanation
5. Plugin displays: file name, diff preview, risk level
6. Developer reviews diff / explanation / impact → **Approve / Reject / Edit**
7. Backup created (file snapshot + rollback point)
8. Patch applied
9. Syntax validation + health verification runs (site loads, admin loads, no fatal errors)
10. Rollback available if anything fails

### 8.6 Rollback Engine

- Creates file snapshots before every patch
- Stores patch history / version history
- Creates rollback points
- One-click rollback restores the original file and patch state, instantly
- Only affected files are backed up — no full-site backups for patches

### 8.7 Backup Strategy

| Operation | Backup Scope |
|---|---|
| File Edit (patch) | Modified files only |
| Search & Replace | Database backup only |
| Plugin/Theme Updates | Plugin snapshot + database snapshot |
| Large Sites | Integrate with UpdraftPlus, BlogVault, JetBackup, or hosting-native backups |

### 8.8 Safe Update System

**Before update:**
- Create rollback point
- Create plugin/theme snapshot
- Create database snapshot when needed

**After update:**
- Verify site health
- Verify admin access
- Check for fatal errors
- Clear cache
- Generate report

---

## 9. WP-CLI Bridge (Optional)

Available **only if**:
- `shell_exec` enabled
- `proc_open` enabled
- WP-CLI installed

If unavailable, automatically disabled — not a core/required feature.

Example commands: `wp plugin list`, `wp cache flush`, `wp db export`, database optimize/repair, safe search-replace, plugin/theme updates.

---

## 10. Security Model

- Administrator-only access
- Capability checks (WP roles/permissions)
- Nonce verification
- API tokens with expiration (temporary tokens)
- Activity / audit logging
- Patch approval workflow (human-in-the-loop, mandatory)
- Backup before any change
- Rollback system

---

## 11. End-to-End User Workflows

### Workflow 1 — Initial Installation
1. Client provides WordPress Admin access (+ optional FTP/SFTP)
2. Developer installs WP Command Center
3. Plugin runs a Site Intelligence Scan (WP version, PHP version, active theme/plugins, WooCommerce status, cache config, debug status, file permissions)
4. Plugin generates an API Endpoint, Site ID, and Access Token → site becomes "AI-ready"

### Workflow 2 — AI Diagnostics
Developer asks a question (e.g. "Why is checkout broken?") → plugin collects WooCommerce status, recent errors, active plugins, theme info → AI returns root cause, suggested fix, risk assessment. No file changes.

### Workflow 3 — File Investigation
Developer asks (e.g. "Find all checkout customizations") → plugin searches allowed directories, returns matching files → AI identifies relevant files, hooks, and potential conflicts. No changes.

### Workflow 4 — Patch Generation
Developer asks for a fix → AI reads target files, generates a patch + explanation → plugin displays file name, diff preview, risk level. No changes applied yet.

### Workflow 5 — Patch Approval
Developer reviews diff, explanation, and impact → chooses Approve / Reject / Edit. Only approved patches proceed.

### Workflow 6 — Backup Creation
Before applying an approved patch, plugin creates a file snapshot + rollback point for the affected file(s) only. No full-site backup.

### Workflow 7 — Patch Deployment
Plugin applies the patch, runs syntax validation, and performs health checks (site loads, admin loads, no fatal errors).

### Workflow 8 — Rollback
If an issue is detected, developer clicks Rollback → plugin instantly restores the original file and patch state.

### Workflow 9 — AI Terminal
Developer enters natural-language queries (e.g. "Find Elementor overrides", "Search WooCommerce payment customizations") → AI performs code search, file inspection, and analysis. Virtual terminal, no SSH.

### Workflow 10 — Optional WP-CLI
If the server supports `shell_exec` + `proc_open` + WP-CLI, plugin enables CLI commands (`wp plugin list`, `wp cache flush`, `wp db export`, etc.). Otherwise disabled automatically.

### Final User Experience
Developer has only WP Admin access → installs plugin → connects AI → AI understands the site → AI investigates files → AI proposes fixes → developer approves → plugin applies changes safely. **No SSH required at any point.**

---

## 12. Product Roadmap

| Stage | Scope |
|---|---|
| **MVP (v1)** | Site Intelligence Engine, Site Scanner, File Read API, Code Search, Debug Log Viewer, Cache Diagnosis, AI Diagnostics, Patch Preview, Patch Approval, Rollback |
| **v2** | Search & Replace, Database Export, Safe Updates, WooCommerce Intelligence, Advanced Diagnostics, AI Audit Reports |
| **v3** | Claude Integration, Codex Integration, MCP Support, Remote Agent Connections |
| **v4** | Team Workspaces, Multi-Site Support, White Label, Agency Dashboard, Scheduled Reports |

> Note: an earlier draft phased this differently (Phase 1 = read-only diagnostics only; Patch System + Rollback + WP-CLI deferred to Phase 3). See §14, item 2.

---

## 13. Monetization

| Tier | Includes |
|---|---|
| **Free** | Site Scanner, Site Health, Cache Detection, Basic Diagnostics |
| **Pro** | AI Diagnostics, File Access API, Patch System, Rollback, WooCommerce Intelligence, Search & Replace, Safe Updates, Backup Tools |
| **Agency** | Multi-Site, Team Access, White Label, AI Agent / AI Coding Bridge Connections |

---

## 14. Open Decisions Before Development

These ambiguities exist across the source drafts and should be resolved before implementation begins:

1. **AI execution model for v1.** "AI Diagnostics" is in the MVP, but "Claude/Codex/MCP Integration" isn't until v3. For v1, does the plugin call out to an LLM API directly (whose key, who pays for tokens?), or does it just package context for a human to hand to an external AI agent? This determines a large chunk of MVP engineering scope.

2. **MVP scope size.** The roadmap above bundles Site Intelligence + Diagnostics + the full Patch/Approval/Rollback engine into v1. An earlier draft's Phase 1 was read-only only (scanner, health, debug log, cache diagnosis, AI audit), with Patch System/Rollback/WP-CLI pushed to Phase 3. Recommendation: ship the read-only intelligence/diagnostics/code-search slice first as a usable v1, and treat the patch/rollback engine as its own milestone given its risk profile.

3. **File Access API allow-list vs. secrets.** `wp-content/plugins/` and `mu-plugins/` commonly contain `.env` files, license keys, and credentials even though the directories are "allowed." Need a deny-pattern/redaction layer on top of the path allow-list (e.g., block `*.env`, anything matching common credential filenames, regardless of directory).

4. **Data sent to third-party AI APIs.** Debug logs and WooCommerce order meta can contain customer PII. Need a redaction/sanitization pass before context leaves the site, particularly for Agency-tier clients with compliance obligations.

5. **Health verification mechanism.** "Site loads / Admin loads / No fatal errors" needs a concrete implementation approach (e.g., loopback HTTP requests, WP Recovery Mode hooks, output buffering for fatal error capture).

6. **Branding/trademark.** Marketing language references "Claude," "Codex," and "GPT" by name throughout. Confirm this doesn't imply an official partnership/endorsement that hasn't been secured.

---

## 15. Long-Term Vision & Category

Install one plugin → connect Claude, Codex, or GPT → AI can understand the site, read files, analyze code, diagnose issues, generate patches, and apply approved changes — without SSH access.

**Category created:** *AI-Powered WordPress Operations Platform* — "The Infrastructure Layer Between WordPress and AI Agents." Not "WordPress Maintenance Plugin," not "Another MainWP."
