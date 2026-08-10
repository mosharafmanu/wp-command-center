# Final RC Certification — WP Command Center 1.0.0

> **SUPERSEDED.** This report records an earlier stage of the V1 release
> programme and is kept as a record. It is not current. See
> [V1-CLOSEOUT-CERTIFICATION.md](V1-CLOSEOUT-CERTIFICATION.md) for the current
> state, and [README.md](README.md) for the product documentation. Where this
> report disagrees with those, they are correct.

**Commit:** `984aa3c` on `release/v1-finalization`
**Artifact:** `build/wp-command-center-1.0.0.zip` — 279 files, 916 KB
**sha256:** `51f1d4b4a3d4ac54a8bfc5627535061c704c03d0320e146539d6541ee570d258`
**Site:** `purple-surgical.mosdev.site` — real customer site (custom theme, ACF Pro,
WooCommerce, Elementor Pro, CF7, Yoast), WP 6.9.5 / PHP 8.3.30, host disables
`exec`/`shell_exec`/`popen`.

---

## Invariants — verified live on staging at close

| Invariant | Required | Actual |
|---|---|---|
| MCP tools | 42 | **42** (compact **and** standard) |
| Catalogue operations | 42 | **42** |
| Mapped operations | 34 | **34** |
| Capabilities | 23 | **23** |
| Protection modes | 3 | **3** |
| DB version | 2.6.0 | **2.6.0** |

## Release artifact

**Included and verified present:** plugin bootstrap, `uninstall.php`, `readme.txt`,
`LICENSE`, `sdk/javascript/wpcc-mcp-relay.mjs`, `includes/`, `assets/`, `languages/`.

**Verified absent:** `wpcc-env.sh`, `.git/`, `tests/`, any `*.md`, `DEPLOY*`, `HANDOFF*`,
`CERTIFICATION*`, build artifacts, credentials. (0 matches each.)

The build refuses to produce a package without the relay — the guard added after the
release ZIP was found to omit it — and that guard was re-proven by removing the file and
watching the build exit 1.

## Connector handshake — run as Claude Desktop runs it

```
relay fetch                 http=200
node --check                OK
initialize  → serverInfo    {"name":"WP Command Center","version":"1.0.0"}
tools/list  →               42 tools
```

Driven through a long-lived child process over stdio with stdin held open, from the
configuration the plugin generates, against the deployed ZIP.

## Certified in this program

| Area | Evidence |
|---|---|
| ACF value reads | 4 distinct honest outcomes (no object / no field / not found / empty) |
| ACF field counts | `total 2, nested 2, nodes 4` for 1 text + 1 repeater(2) — was `total 0` |
| ACF group reads under local JSON | fixed; read paths resolved the JSON copy (ID 0) and returned nothing |
| SEO analysis messages | 10 checks, 4 failing, **0 contradictory** against real Yoast data |
| Malformed requests | 6 operations refused pre-gate; pending approvals unchanged at 0 |
| No false rejections | 33-operation read sweep, **0** wrongly refused |
| Undo naming | `Undo a change — Update price — #1185` / `— Edit a post or page — “PH4…”` |
| Boundaries in default context mode | `agent_note` survives compaction on 3 operations |
| Multisite | network activation refused; notice for already-network-active |
| Plugin Check | 164 → **124**, all classified |

## Regression

**Net-new attributable failures: 0.**

253 assertions across 6 suites at 0 failures; three suites carry pre-existing failures
(`media-runtime` 70/10, `snapshot-runtime` 57/1, `mcp-error-surface` 17/1) measured
identically with this program's changes stashed.

Four assertions were updated because this program deliberately changed the contracts they
encoded; each carries a comment saying why, and one was made fixture-independent
(44/0 on both local and staging).

## Staging restored exactly

Posts 32, pages 23, products 79, attachments 716, users 5, ACF groups 11, `acf-json` 18
files, 0 orphaned ACF fields, custom theme **byte-identical** across all 139 files, mode
`client`, theme `purple-surgical`. Front 200, admin 302, product page 200, relay 200.

Residues found and corrected during restoration: page 8's title (revisions proved the
original was "Products"), price meta on product 1185 that had no such rows originally, and
14 ACF objects created by suites that had been pointed at staging.

## Not certified in this program

Deliberately listed rather than implied:

* **Lifecycle:** clean-install on a fresh WordPress, deactivate/reactivate, upgrade from a
  previous build, uninstall retain-vs-purge, reinstall, reconnect. **Not run.**
* **ACF local JSON** still goes stale after field changes; `acf_json_sync` returns
  `synced_count: 0`.
* **Rollback routing**, **parameter vocabulary/aliases**, **full describe coverage** and
  **undo click-cost** — unchanged from the previously certified build.
* **124 Plugin Check findings** classified from representative instances per pattern, not
  line-by-line for all 124.

## Verdict

# B. NOT READY — OBJECTIVE BLOCKERS REMAIN

The blockers are scope, not defects. The build is functionally better than the one
certified in `FINAL-CERTIFICATION-2026-08-02.md` and nothing regressed, but this program
required every listed phase to be completed or proven unnecessary, and Phases 1, 3, 5, 10
were not started, Phase 6 is partial, and Phases 14/15/16 are partial. See
`docs/V1-FINALIZATION-REPORT.md` §9 for the itemised list.
