# WP Command Center — Documentation Index

Complete listing of every document in the repository with its purpose.

---

## Root-Level Documents

| File | Purpose |
|------|---------|
| `AGENTS.md` | Agent instructions for opencode — credentials sourcing, REST API connection, and Patch Engine workflow |
| `CONNECTING.md` | REST API connection guide with curl examples for all endpoints (Site Intelligence, Diagnostics, File Access, Patch Engine) |
| `readme.txt` | WordPress.org plugin listing readme |
| `composer.json` | PHP Composer configuration and namespace map |
| `openapi.json` | OpenAPI 3.0 specification for the REST API |
| `DOCUMENTATION-INDEX.md` | This file — complete document inventory |

---

## docs/product/ — Product Documentation

| File | Purpose |
|------|---------|
| `OVERVIEW.md` | High-level product overview — features, architecture summary, and user personas |
| `INSTALLATION.md` | Step-by-step installation and initial configuration guide |
| `QUICKSTART.md` | Quick-start guide for first-time users |
| `TROUBLESHOOTING.md` | Common issues and their resolutions |
| `CAPABILITIES.md` | Full capabilities reference — what the plugin can do by category |
| `WP-Command-Center-Canonical-Spec.md` | Canonical product specification — consolidated from multiple design drafts |
| `PRODUCT-STRATEGY-REPORT.md` | Product strategy analysis — SaaS founder, agency owner, and business perspectives |
| `Full Plugin Breakdown.md` | Complete plugin component and feature breakdown |
| `WPCC-Remaining-Roadmap-Steps-68-75.md` | Remaining development roadmap covering steps 68 through 75 |

---

## docs/architecture/ — Technical Architecture

| File | Purpose |
|------|---------|
| `ARCHITECTURE.md` | System architecture — component design, data flow, and technical decisions |
| `API.md` | REST API reference — all endpoints, request/response schemas, and authentication |
| `MCP.md` | MCP (Model Context Protocol) integration — tools, resources, context modes, and search pagination |
| `OPERATIONS.md` | Operations reference — per-operation payload formats for the operations API |
| `SECURITY.md` | Security model — token auth, capability scoping, approval gating, and audit trail |
| `AI-INTEGRATIONS.md` | AI client integration guide — supported clients, configuration, and certification |
| `AI-CERTIFICATION.md` | AI client certification criteria, testing methodology, and certification levels |

---

## docs/reports/audits/ — Audit Reports

| File | Purpose |
|------|---------|
| `ADMIN-PARITY-AUDIT.md` | Audit comparing admin-UI parity with REST API capabilities |
| `CLAUDE-AUDIT-REPORT.md` | Claude Desktop integration audit — full report |
| `CLAUDE-AUDIT-SCORECARD.md` | Claude Desktop integration audit — scorecard |
| `CLAUDE-AUDIT-CHANGES.md` | Claude Desktop integration audit — change log |
| `CODEX-AUDIT-CHANGES.md` | Codex integration audit — change log |
| `CODEX-RELEASE-AUDIT.md` | Codex release-readiness audit |
| `CODEX-READINESS-SCORE.md` | Codex integration readiness scorecard |
| `REMEDIATION-REPORT.md` | Issues discovered during audit and their remediation |
| `STEP-49-READINESS-SCORE.md` | Beta readiness scorecard (Step 49) |
| `STEP-50-ARCHITECTURE-REVIEW.md` | Architecture review report (Step 50) |
| `STEP-50-ENTERPRISE-HARDENING.md` | Enterprise hardening assessment (Step 50) |
| `STEP-50-PERFORMANCE-AUDIT.md` | Performance audit report (Step 50) |
| `STEP-50-SECURITY-AUDIT.md` | Security audit report (Step 50) |
| `STEP-50-TECHNICAL-DEBT.md` | Technical debt assessment (Step 50) |

---

## docs/reports/validation/ — Validation Reports

| File | Purpose |
|------|---------|
| `STEP-36-VALIDATION-REPORT.md` | Core architecture validation results (Step 36) |
| `STEP-37-SECURITY-VALIDATION.md` | Security system validation results (Step 37) |
| `STEP-40-THEME-VERIFICATION.md` | Theme runtime verification results (Step 40) |
| `STEP-46-MCP-CLIENT-VALIDATION.md` | MCP client protocol validation results (Step 46) |
| `STEP-46-MCP-SECURITY-VALIDATION.md` | MCP security enforcement validation results (Step 46) |
| `STEP-47-CLAUDE-VERIFICATION.md` | Claude Desktop integration verification (Step 47) |
| `STEP-47.5-UX-VERIFICATION.md` | AI integration UX verification (Step 47.5) |
| `STEP-48-AI-CLIENT-VERIFICATION.md` | AI client integration layer verification (Step 48) |
| `STEP-49-BETA-VALIDATION.md` | Beta release validation results (Step 49) |
| `WPCC-FINAL-VALIDATION.md` | Full final validation report (all suites, all assertions) |

---

## docs/reports/performance/ — Performance Reports

| File | Purpose |
|------|---------|
| `PERFORMANCE-OPTIMIZATION-REPORT.md` | Overall performance optimization findings and improvements |
| `STEP-49-PERFORMANCE-REPORT.md` | Beta performance metrics (Step 49) |
| `TOKEN-EFFICIENCY-REPORT.md` | Token efficiency analysis — payload sizing, context mode comparison |
| `STEP-76-TOKEN-EFFICIENCY.md` | Step 76 token efficiency implementation report |

---

## docs/reports/steps/ — Development Step Reports

| File | Purpose |
|------|---------|
| `STEP-38-OPTION-RUNTIME-REPORT.md` | Option runtime implementation report (Step 38) |
| `STEP-39-PLUGIN-RUNTIME-REPORT.md` | Plugin runtime implementation report (Step 39) |
| `STEP-40-THEME-RUNTIME-REPORT.md` | Theme runtime implementation report (Step 40) |
| `STEP-41-SNAPSHOT-RUNTIME-REPORT.md` | Snapshot runtime implementation report (Step 41) |
| `STEP-42-CONTENT-RUNTIME-REPORT.md` | Content runtime implementation report (Step 42) |
| `STEP-43-DATABASE-INSPECTION-REPORT.md` | Database inspection runtime implementation report (Step 43) |
| `STEP-44-CAPABILITY-RUNTIME-REPORT.md` | Capability runtime implementation report (Step 44) |
| `STEP-45-MCP-RUNTIME-REPORT.md` | MCP server runtime implementation report (Step 45) |
| `STEP-45.5-HARDENING-REPORT.md` | Hardening pass report (Step 45.5) |
| `STEP-46-MCP-COMPATIBILITY-MATRIX.md` | MCP client compatibility matrix (Step 46) |
| `STEP-47-CLAUDE-INTEGRATION.md` | Claude Desktop integration implementation report (Step 47) |
| `STEP-47.5-AI-INTEGRATION-UX.md` | AI integration UX implementation report (Step 47.5) |
| `STEP-48-AI-CLIENT-INTEGRATION-LAYER.md` | AI client integration layer implementation report (Step 48) |
| `STEP-51-DOCUMENTATION-REPORT.md` | Documentation build-out report (Step 51) |
| `STEP-53-AI-CLIENT-CERTIFICATION.md` | AI client certification implementation report (Step 53) |
| `STEP-54-CURSOR-CERTIFICATION.md` | Cursor IDE certification implementation report (Step 54) |

---

## .ai/ — AI Development Artifacts

### .ai/handoffs/

| File | Purpose |
|------|---------|
| `HANDOFF-STEP-76.md` | Step 76 handoff — token efficiency implementation, files modified, test results, remaining work |
| `resume.md` | Development summary / resume of completed work |

### .ai/prompts/

| File | Purpose |
|------|---------|
| `WP-Command-Center-Steps-24-30-Prompts.md` | Structured prompts for AI-assisted development (Steps 24–30) |
| `WP-Command-Center-Steps-32-36.md` | Structured prompts for AI-assisted development (Steps 32–36) |

### .ai/audits/

Reserved for future AI-specific audit artifacts.

---

## File Count Summary

| Location | Files |
|----------|-------|
| Root | 6 |
| docs/product/ | 9 |
| docs/architecture/ | 7 |
| docs/reports/audits/ | 14 |
| docs/reports/validation/ | 10 |
| docs/reports/performance/ | 4 |
| docs/reports/steps/ | 16 |
| .ai/handoffs/ | 2 |
| .ai/prompts/ | 2 |
| README files (docs, reports, .ai) | 3 |
| **Total indexed documents** | **73** |
