# SiteRadian AI v1.0.0 — Final WordPress.org Release Readiness

Date: 2026-09-13 (Asia/Dhaka)

## Decision

SiteRadian AI 1.0.0 is the first public release under the final product identity. The package is ready for WordPress.org submission. This report does not claim formal legal trademark clearance.

Public identity:

- Product: SiteRadian AI
- Version / stable tag: 1.0.0
- Requested WordPress.org slug: `siteradian`
- Folder: `siteradian/`
- Main file: `siteradian.php`
- Text domain: `siteradian`
- Tagline: Safe, governed AI operations for WordPress.
- DB version: 2.6.0
- Requires WordPress: 6.4
- Tested up to: 7.1
- Requires PHP: 8.0

Certified internal contracts intentionally remain `WPCC_*`, `wpcc_*`, MCP server key `wp-command-center`, and REST namespace `wp-command-center/v1`.

## Baseline

- Branch: `release/v1-security-recut`
- Starting HEAD: `172ee290f4b5d0c75f3a4c362d59612b6dca8390`
- Starting remote release-branch SHA: `172ee290f4b5d0c75f3a4c362d59612b6dca8390`
- Immutable tags preserved: `v1.0.0`, `v1.0.1`, `v1.0.2`
- Tracked-tree fingerprint: `6d496169982ed3a88bbdd8259e6b001bcf130f14f5ab959e6d4e6914903f6a4f`
- Production fingerprint: `f9d3f812d9255bd2f2f847937fb5d3d8e3a96a36b8dbcfa63b49cddfed6d359e`
- Tests/harness fingerprint: `819685ad160eba055e25ad838ccfa194bcdeb770b3fcb10df6d9a14b891c32b0`
- Documentation fingerprint: `3c618a17a4886188d958ffd1aa025c080e6cb67b2d0b915cb7b1a2afc97f06f6`
- Historical-evidence fingerprint: `65ba651cd942ec60dd09faa0595ea552c038618b5c8563ad251e73f3c4484b18`
- Retained Action Steward 1.0.2 ZIP: `build/action-steward-1.0.2.zip`
- Retained Action Steward ZIP SHA-256: `e71f272d56472a7c9019ded5fc3bd8ab26d1c80b3339c29585b42b871da1f0e5`

Existing local historical evidence and pre-existing validation artifacts were preserved and excluded from the release commit.

## Branding and package migration

Active/current source was classified before replacement:

- Customer-facing and package identity: migrated to SiteRadian AI / `siteradian`.
- Internal technical and compatibility identifiers: retained as `WPCC_*` and `wpcc_*`.
- Protocol identifiers: retained as `wp-command-center` and `wp-command-center/v1`.
- Current test assertions and documentation: updated to the final public identity.
- Historical evidence/archive: preserved as historical evidence.
- Negative regression assertions: retained or updated to prohibit stale public identity.

Final shipping-source scan:

- `siteradian` text-domain literals: 3,665
- `action-steward` current text-domain literals: 0
- `ai-command-center` current text-domain literals: 0
- Stale Action Steward / AI Command Center / WP Command Center public-brand files: 0
- Certified `wp-command-center` server-key occurrences: 66
- Certified REST namespace occurrences: 35
- Internal `WPCC_*` identifier occurrences: 172

Naming compliance:

- No leading WP/WordPress display branding.
- SiteRadian AI is the current plugin display name.
- Action Steward is not the current public display/package identity.
- WP Command Center is not the current public display/package identity.
- No mixed shipping text domain.
- No restricted-name Plugin Check error.
- No new trademark/name warning requiring a release stop.

## Codex onboarding remediation

The primary Recommended setup now visibly states:

- “Important: Keep this terminal open.”
- “Run Steps 1–3 in this same terminal window.”
- “Do not open a different Terminal/Warp tab for this step.”
- Step 3: “Start Codex in this same terminal.”

Troubleshooting for `Environment variable WPCC_TOKEN is not set` now explains that MCP registration persists while the terminal environment value does not. It directs the user to return to the original terminal or set a valid token again in a new terminal before starting Codex, without recreating the MCP registration.

The numbered flow remains primary. A combined secret-bearing copy action was not added because it would increase accidental token exposure without improving the clear same-terminal flow. Token values remain process-only, are not written to Codex configuration or shell profiles, and cannot be reconstructed after reload. Windows instructions now set `$env:WPCC_TOKEN` in the current PowerShell session instead of using `setx`.

Rendered visual QA in the fresh WordPress 7.1 install confirmed:

- Same-terminal warning visible in Recommended setup, outside Advanced.
- Step 3 explicitly references the same terminal.
- Advanced sections remain separately collapsed.
- Troubleshooting text accurately distinguishes registration from environment state.
- Reload retains no raw token.
- Secret-dependent copy remains unavailable until a new token is created or a saved token is supplied.
- Approval launch remains `on-request`.

## Regression

- T0: 4,197 passed, 0 failed; 66/66 suites; state restoration verified; source restoration verified.
- Focused post-harness T0: 22 passed, 0 failed; state restoration verified; source restoration verified.
- T1: 9,157 passed, 0 failed; 139/139 suites; state restoration verified; source restoration verified.
- Codex credential UX: 123 passed, 0 failed.
- Branding assets: 33 passed, 0 failed.
- Release identity: 23 passed, 0 failed.
- Representative content runtime: 98 passed, 0 failed.
- Command Code setup UX: 48 passed, 0 failed.
- Media Replace: 20 passed, 0 failed.
- WooCommerce Product: 19 passed, 0 failed.

T2 was not required: the release changes public branding, folder/main basename, text domain, version metadata, documentation, onboarding copy, and release-test fixtures. MCP runtime, authentication, authorization, REST execution, governance, DB schema, and operation execution contracts were not changed.

## Plugin Check 2.0.0

Run against the extracted final ZIP in new-plugin mode with slug `siteradian`:

- Errors: 0
- Warnings: 826
- Restricted-name findings: 0
- Trademark/name findings: 0
- Text-domain findings: 0
- Translator-comment findings: 0
- Tested-up-to findings: 0
- New release-significant warnings: 0

The warning total matches the retained Action Steward 1.0.2 package manifest. Findings are existing static-analysis warnings dominated by prefixed-global, direct-database/caching, prepared-SQL, nonce, and sanitization diagnostics; no rename-related or new release-blocking warning was introduced.

## Fresh WordPress 7.1 install

The exact final ZIP was installed into a new WordPress 7.1 tree and a new temporary database.

- Plugins screen: SiteRadian AI
- Version: 1.0.0
- Folder: `siteradian/`
- Basename: `siteradian/siteradian.php`
- Activation: PASS
- Deactivation/reactivation: PASS
- DB version: 2.6.0
- Expected WPCC tables: 15
- Duplicate tables: 0
- Admin menu: SiteRadian AI
- Home / Connections / Approvals / Changes / Built-in AI / Protection: HTTP 200
- Sampled CSS and JS assets: HTTP 200
- Runtime warnings/notices: 0

Fresh-user state:

- Tokens: 0
- AI connections: 0
- Routes: 0
- Requests: 0
- Pending approvals: 0
- Queue: 0
- Results: 0
- Changes: 0
- Recommendations: 0
- Snapshots: 0
- Patches: 0
- Built-in AI: OFF for all three tools
- Protection: Standard / client
- Capability enforcement: ON

## Action Steward 1.0.2 transition

A second disposable WordPress 7.1 install was activated first with the retained Action Steward 1.0.2 package. Representative `wpcc_*` option state and an operational recommendation row were seeded before deactivating the old basename and activating SiteRadian AI 1.0.0.

- Existing WPCC tables reused: PASS
- Before/after table-universe fingerprint: `29a465035300867e9c8c2350f36ef69be5067c3a2326792b8ce309f0131a835f`
- DB version remained 2.6.0: PASS
- Seeded `wpcc_*` option readable: PASS
- Seeded operational row readable: PASS
- Duplicate tables: 0
- Old basename inactive: PASS
- New basename active: PASS

## Final package

- Exact path: `/Applications/AMPPS/www/ClientProjects/WordPress/2026/plugins-dev/wp-content/plugins/ai-command-center/build/siteradian-1.0.0.zip`
- Filename: `siteradian-1.0.0.zip`
- Size: 1,146,044 bytes
- File count: 302
- SHA-256: `b8c9ea873549a003b5dbd79ca1abe0c6e03dbcefbf86b166027d1280027bb16d`
- Extracted-tree fingerprint: `ea9db9d5fe44587b28362587850f4b1b631f651db962e2dcc58ffd12f826fb15`
- Expected top-level: `siteradian/`
- Archive integrity: PASS
- Production allowlist: PASS
- Source/package missing: 0
- Source/package extra: 0
- Source/package content mismatch: 0
- Secret scan: 0 findings
- Contamination scan: 0 findings
- Private developer paths: 0 findings
- PHP lint failures: 0
- JS/MJS syntax failures: 0

Package contents exclude tests, internal validation evidence, artifacts, `.git`, `.env`, `wpcc-env.sh`, credentials, runtime PrivateStore data, backups, database dumps, logs, developer-only metadata, and absolute private developer paths.

## Git release identity

This report is part of the release commit named `release: SiteRadian AI v1.0.0`. The collision-free annotated release tag is `siteradian-v1.0.0` with message `SiteRadian AI v1.0.0`. The immutable historical tags `v1.0.0`, `v1.0.1`, and `v1.0.2` remain unchanged. The tag target and exact release commit SHA are recorded in the final Codex handoff after the non-force push to `release/v1-security-recut`.

## WordPress.org submission identity

- Product: SiteRadian AI
- Version: 1.0.0
- Requested slug: `siteradian`
- ZIP: `/Applications/AMPPS/www/ClientProjects/WordPress/2026/plugins-dev/wp-content/plugins/ai-command-center/build/siteradian-1.0.0.zip`
- SHA-256: `b8c9ea873549a003b5dbd79ca1abe0c6e03dbcefbf86b166027d1280027bb16d`

SiteRadian AI is the current display name. WP Command Center and Action Steward are not public display names. The package folder and text domain are both `siteradian`. The prior naming blocker is resolved.

No WordPress.org upload or GitHub Release publication was performed.
