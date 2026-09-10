# Action Steward 1.0.2 release evidence

Date: 2026-09-11 (Asia/Dhaka)

## Public identity

- Product: Action Steward
- Positioning: Safe AI operations for WordPress.
- Version: 1.0.2
- Requested WordPress.org slug: `action-steward`
- Package folder and text domain: `action-steward`
- Main file: `action-steward.php`
- Database version: 2.6.0 (unchanged)
- Compatibility identifiers retained: internal `WPCC`/`wpcc_*`, MCP key `wp-command-center`, and REST namespace `wp-command-center/v1`

## Practical name screen

The preferred name, AgentGate, was rejected after current searches found several direct-category AI-agent governance, security, and MCP products using that name. CommandGate, GovernOps, ActionGate, SafeOps, and AgentControl also showed material software-product confusion signals. Exact-name searches for Action Steward across WordPress.org, GitHub, software/product results, AI governance, and security did not find a material direct-category collision. Action Steward was therefore locked for this release.

This was a practical product-name collision screen, not a formal trademark search or legal opinion.

WordPress.org naming guidance checked:

- <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>
- <https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/>

## Regression evidence

- Focused release identity: 20 passed, 0 failed.
- Branding assets: 33 passed, 0 failed.
- T0 release identity: 20 passed, 0 failed; state and source restoration verified.
- T1 release identity: 606 passed, 0 failed across 10/10 suites; state and source restoration verified.
- Broader optional T1 selection: 3,130 passed, 1 transient cross-suite failure across 22/22 suites. The affected approval-center suite immediately passed in isolation at 166/166. No required gate remained red.
- Full T2 was not required: public branding, folder/text-domain, version, documentation, and package identity changed, while MCP, REST, authentication, authorization, governance, database, and execution contracts remained unchanged.

## Environment and package evidence

- Fresh WordPress 7.1 install: activation and reactivation passed; Action Steward 1.0.2 displayed correctly.
- Database: 2.6.0, 15 expected `wpcc_*` tables, no duplicates.
- First-user state: Standard protection (`client`), Built-in AI off, zero tokens/connections/history.
- Six primary admin surfaces: HTTP 200; current brand present; legacy display name absent.
- CSS, JavaScript, and SVG assets: HTTP 200; post-gate debug log: 0 bytes.
- v1.0.1 transition: the same 15-table set and hash were retained, DB version remained 2.6.0, and seeded `wpcc_*` state stayed readable after activation from `action-steward/action-steward.php`.
- Plugin Check: 0 errors, 826 warnings in 16 pre-existing static-analysis categories; no restricted-name, trademark, translator-comment, tested-up-to, compatibility, plugin-header, text-domain, or stable-tag finding.
- Package: `build/action-steward-1.0.2.zip`
- Size: 1,149,225 bytes
- File count: 302
- SHA-256: `e71f272d56472a7c9019ded5fc3bd8ab26d1c80b3339c29585b42b871da1f0e5`
- Extracted-tree SHA-256: `c25679c994a5ca0664dd5d6d967012e2fb54349742cb18e71ce57bd466646352`
- Allowlisted source manifest SHA-256: `c25679c994a5ca0664dd5d6d967012e2fb54349742cb18e71ce57bd466646352`
- Package/source manifest diff: 0 lines.
- PHP lint: 284 files, 0 failures.
- JavaScript/MJS syntax: 0 failures.
- Contamination, legacy display name, legacy text-domain, and developer-path scans: 0 findings.
- Secret-pattern review: no credential material. Two lexical scanner matches were expected implementation code: password input handling and the security redactor's secret-detection patterns.

The old restricted display name is not the plugin display name, readme name, package name, folder, or text domain.
