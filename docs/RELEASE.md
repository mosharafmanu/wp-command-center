# Release process

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

## Version numbers

Four places must agree:

1. `ai-command-center.php` header `Version:`
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

Output: `build/ai-command-center-<version>.zip`.

Verify no development files leaked:

```bash
unzip -l build/ai-command-center-1.0.0.zip | grep -Ei "/tests/|\.git|node_modules|wpcc-env|\.DS_Store|\.md$"
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

`tests/regression-baseline.tsv` records accepted failures. It must be **empty** for a
release: an assertion that fails is either a real regression or a stale expectation, and
both need fixing rather than recording.

## Compliance

Plugin Check must be run against the **built artifact**, not the checkout — the checkout
contains `build/`, `tests/`, `.git` and `.DS_Store`, none of which ship:

```bash
unzip -q build/ai-command-center-1.0.0.zip -d /tmp/pkg
wp plugin check /tmp/pkg/ai-command-center --format=csv --fields=type,code,file,line
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
