> # SUPERSEDED — HISTORICAL RECORD ONLY
>
> This document is **not current** and must not be used to verify, build, or ship
> anything. It is kept because it records what was true when it was written.
>
> **The single authoritative engineering document is [`RELEASE_HANDOFF.md`](../../../RELEASE_HANDOFF.md) at the repository root.**
>
> **Still useful for:** the shape of a submission package checklist. Every number in it is superseded.
>
> Superseded on 2026-08-10. Reason: records the 2026-08-03 artifact; both its checksum and its file count are superseded

---

# WordPress.org submission package — WP Command Center 1.0.0

**Artifact:** `build/ai-command-center-1.0.0.zip`
**Certified:** 2026-08-03

---

## Branding decision (frozen)

| | Value |
|---|---|
| Public product name | **WP Command Center** |
| WordPress.org slug | `ai-command-center` |
| Text domain | `ai-command-center` |
| Plugin directory / main file | `ai-command-center/ai-command-center.php` |

Frozen until reviewer feedback. If a reviewer requests a naming change, address it then.

## One thing to do at submission

WordPress.org derives the slug from the `Plugin Name:` header. The header reads
**"WP Command Center"**, so the automated email will propose **`wp-command-center`**.

> "When you submit a plugin, you get an automated email telling you what the slug will be.
> This is populated based on the value of your Plugin Name in your main plugin file."
> — [Plugin Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/)

**Reply to that email immediately and request the slug `ai-command-center`.** The slug can
be corrected while the plugin is in review and never after approval:

> "The slug can be changed while a plugin is in review but we **cannot** change it once
> your plugin is approved."
> — [Make WordPress Plugins](https://make.wordpress.org/plugins/2018/11/21/reminder-we-cant-rename-plugins-post-approval/)

The package is already built as `ai-command-center` throughout — directory, main file and
text domain — so no code change is needed once the slug is granted.

## Known reviewer-visible warnings

Plugin Check reports **0 errors** and 814 warnings. Two are `trademarked_term`, both on the
display name "WP Command Center" (plugin header and `readme.txt`). They are warnings, not
errors. WordPress has stated the abbreviation is not covered by their trademark:

> "The WordPress trademark does not cover the abbreviation 'WP,' and you are free to use it
> in any way you see fit."

Every other warning family is accounted for with evidence in
[WORDPRESS-ORG-COMPLIANCE-REPORT.md](../certifications/WORDPRESS-ORG-COMPLIANCE-REPORT.md).

## Certification summary

| Area | Result |
|---|---|
| Plugin Check (artifact) | **0 errors**, 814 warnings, 2 trademark (display name) |
| Regression | **6,361 passed / 0 failed**, 181 suites |
| Lifecycle (clean WP 6.9.5) | **24 / 24** |
| Invariants | 42 tools · 42 catalogue · 34 map · 23 capabilities · DB 2.6.0 |
| Artifact | 284 files, 944 KB, no dev-file leakage, relay included |

**Verified on a clean install from the ZIP only:** MCP handshake (`2024-11-05`, 42 tools),
all 7 discovery resources, read-only scope refused a write (`wpcc_token_read_only`), all
three protection modes gate correctly (diagnostic reads never gated), a Standard-mode write
returned `pending_approval` and wrote nothing, approve → worker → executed, undo restored
content and a second undo was refused (`wpcc_already_rolled_back`), and every runtime
answers in its own error contract.

**Verified on the customer staging site:** WooCommerce (79 products), ACF (11 field
groups), ACF Local JSON (0 drift), Contact Form 7 (6 forms), Yoast (provider detected),
Elementor (reachable), health check **passed**. Staging restored: 139/139 theme files
byte-identical, all content counts at baseline.

## Post-submission

The slug is permanent once approved. Everything else — display name, description,
screenshots — can be changed afterwards.
