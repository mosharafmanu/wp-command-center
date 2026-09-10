# Action Steward — Overview

Action Steward connects an AI assistant to a WordPress site through the
Model Context Protocol (MCP), and puts a governance layer between the assistant and
the database.

**Version:** 1.0.2 · **Requires:** WordPress 6.4+, PHP 8.0+ · **Tested to:** WordPress 7.1

---

## What it is

An assistant that speaks MCP (Claude Desktop, Cursor, or anything else implementing the
protocol) connects to your site and gets 42 tools covering content, media, users,
WooCommerce, ACF, Elementor, SEO, menus, widgets, custom post types, files, patches,
snapshots and reporting.

What makes it different from handing an assistant your admin password:

- **Changes wait for you.** On a fresh install, anything that modifies the site becomes a
  pending approval. You approve or reject from the WordPress admin.
- **Every change is recorded.** Who, what, when, and the values before and after.
- **Supported changes can be undone.** Undo is a first-class operation, not a backup restore.
- **A read-only token stays read-only.** Scope is enforced at the transport, before the
  operation is dispatched.

## What it is not

- Not a backup plugin. Snapshots exist to make specific changes reversible, not to protect
  the whole site.
- Not an autonomous agent. It executes what an assistant asks for, under your governance.
  It never initiates work.
- Not a hosted service. Everything runs on your site; no data is sent anywhere except to
  the AI provider you configure, and only if you configure one.
- Not multisite-capable in 1.0.2. Network activation is refused with an explanation
  rather than half-working. See [ARCHITECTURE.md](ARCHITECTURE.md#multisite).

## The shape of a change

```
assistant → MCP tool call → capability + scope check → risk assessment
   → (Standard/Strict) pending approval → you approve
   → execution → change log entry → undo available
```

In Development mode the approval step is skipped. That mode is for local and staging
sites and says so in the UI.

## Where to go next

| You want to… | Read |
|---|---|
| Install and connect an assistant | [QUICKSTART.md](QUICKSTART.md) |
| Install in detail, including uninstall | [INSTALLATION.md](INSTALLATION.md) |
| Understand the security model | [SECURITY.md](SECURITY.md) |
| Know how the pieces fit | [ARCHITECTURE.md](ARCHITECTURE.md) |
| Use the MCP interface | [MCP.md](MCP.md) |
| Look up an operation | [OPERATIONS.md](OPERATIONS.md) |
| Call the REST API directly | [API.md](API.md) |
| Understand token capabilities | [CAPABILITIES.md](CAPABILITIES.md) |
| Configure a specific AI client | [AI-INTEGRATIONS.md](AI-INTEGRATIONS.md) |
| Fix something that is not working | [TROUBLESHOOTING.md](TROUBLESHOOTING.md) |
| Cut a release | [RELEASE.md](RELEASE.md) |
