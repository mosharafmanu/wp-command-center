# Action Steward release process

This is the current release procedure for v1.0.2. The root `RELEASE_HANDOFF.md` records
the immutable v1.0.1 release and remains historical evidence.

---

## Invariants

These must hold in every release. They are asserted by the suite and verified from the
**installed artifact**, not the working tree.

| Invariant | Value |
|---|---|
| MCP tools | 42 |
| Catalogue operations | 42 |
| Mapped operations (`OPERATION_MAP`) | 34 |
| Capabilities | 23 |
| Protection modes | 3 |
| `DB_VERSION` | 2.6.0 |

Changing any of them is a deliberate act that needs a documented reason and a schema
migration where applicable.

## Slug and product name

The public name, folder, main file, and text domain are deliberately aligned for the first
WordPress.org release.

| | Value | Why |
|---|---|---|
| WordPress.org slug | `action-steward` | The slug is derived from the plugin at submission and **cannot be changed after approval**. A `wp-` prefixed slug is flagged by WordPress.org's automated checks — not because "WP" is trademarked (it is not) but to close a rename loophole. Spending a rename before submission was cheaper than discovering it in review. |
| Product / display name | **Action Steward** | It is the brand, and it appears in the plugin header, `readme.txt`, and every customer-facing surface. |
| Text domain | `action-steward` | Must equal the slug, or wordpress.org language packs will not load. |

The name does not begin with `WP` or `WordPress`, and it is not based on another software
product's brand. Plugin Check must report no restricted-name error.

**Never change with the slug** — these are contracts, not branding:

- REST namespace `wp-command-center/v1` — changing it breaks every client config in use.
- MCP server key `wp-command-center` — a certified legacy protocol identifier used in client configurations.
- Admin menu slug `HOME_SLUG` — an existing admin URL.
- PHP namespaces `WPCommandCenter\` and DB prefix `wpcc_` — internal; renaming them would
  force a data migration for no gain.

Tests must **derive** the slug, text domain and main-file name rather than hardcode them.
A rename previously broke 27 assertions across 11 suites, every one a hardcoded literal.

## Version numbers

Four places must agree:

1. `action-steward.php` header `Version:`
2. `WPCC_VERSION` constant
3. `readme.txt` `Stable tag:`
4. the changelog entry

`test-final-validation` and `test-production-validation` derive the expected version from
the plugin header, so a bump does not make them stale.

## Build

```bash
bash scripts/build-release.sh
```

The build is an **allowlist**, not a blocklist — anything not explicitly included stays
out. It asserts `sdk/javascript/wpcc-mcp-relay.mjs` is present and **exits 1** if it is
not; without the relay every generated client configuration would point at a 404.

Output: `build/action-steward-<version>.zip`.

Verify no development files leaked:

```bash
unzip -l build/action-steward-1.0.2.zip | grep -Ei "/tests/|\.git|node_modules|wpcc-env|\.DS_Store|\.md$"
```

Expect no matches. `docs/` and `tests/` do not ship.

## Test

```bash
bash tests/run.sh --tier T0          # lint + primary suites, fast
bash tests/run.sh --tier T1          # the touched runtime, plus core registry
bash tests/run.sh --tier T2          # everything — required before release
```

Run T2 **standalone**. Several suites switch protection mode globally, so a concurrent
run against the same database produces meaningless failures.

`tests/regression-baseline.tsv` records the explicitly reviewed historical baseline. The
release gate must report **zero net-new failures**, and rename-related suites must pass
without relying on that baseline.

## Compliance

Plugin Check must be run against the **built artifact**, not the checkout — the checkout
contains `build/`, `tests/`, `.git` and `.DS_Store`, none of which ship:

```bash
unzip -q build/action-steward-1.0.2.zip -d /tmp/pkg
wp plugin check /tmp/pkg/action-steward --format=csv --fields=type,code,file,line
```

Required: **0 errors**.

## Lifecycle certification

Against a clean WordPress, installing **only the ZIP**:

clean install → activate (defaults to Standard protection, 15 tables, `DB_VERSION`
2.6.0) → relay reachable → deactivate → reactivate → upgrade in place → uninstall
(data retained) → reinstall → uninstall with `wpcc_delete_data_on_uninstall` (0 tables,
0 options).

Then connect over MCP and confirm the handshake, 42 tools, a real read, and that a write
in Standard protection returns `pending_approval` **and writes nothing** until approved.

## Ship

1. Confirm the four version numbers agree.
2. Update the `readme.txt` changelog.
3. Build, verify contents, run Plugin Check on the artifact.
4. Run T2 standalone; expect zero failures.
5. Certify the lifecycle on a clean install.
6. Tag the release.
7. Submit the ZIP to WordPress.org.

`main` auto-deploys. Release work happens on a release branch and is merged deliberately.
