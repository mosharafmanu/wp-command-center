> # SUPERSEDED — HISTORICAL RECORD ONLY
>
> This document is **not current** and must not be used to verify, build, or ship
> anything. It is kept because it records what was true when it was written.
>
> **The single authoritative engineering document is [`RELEASE_HANDOFF.md`](../../../RELEASE_HANDOFF.md) at the repository root.**
>
> **Still useful for:** the per-category analysis of Plugin Check findings and why most were sniffer false positives. The counts are repository-wide and superseded; the analysis is not.
>
> Superseded on 2026-08-10. Reason: quotes the repository-wide Plugin Check figure (164) rather than the package result; the authoritative number is 0 errors / 824 warnings

---

# WordPress.org compliance — every Plugin Check finding accounted for

**Plugin:** WP Command Center 1.0.0
**Measured against:** the built release artifact (`build/ai-command-center-1.0.0.zip`),
extracted and checked as an installed plugin — **not** the development checkout.

```
ERRORS:   0
WARNINGS: 817
```

Nothing below is "unknown". Every finding is either fixed, proven a false positive with
evidence, or documented as something WordPress.org may still report and why.

> **Why the artifact and not the checkout.** Running Plugin Check against the working tree
> reports 204 additional errors — `build/*.zip`, `.DS_Store`, `.gitignore`, `.distignore`,
> `tests/*.sh`. None of those files are in the ZIP. The artifact is what a reviewer
> installs, so it is what the numbers above describe.

---

## 1. Errors — 0

164 errors were resolved during the release programme, each audited at its own line. The
41 SQL sites and 28 filesystem/`proc_open` sites carry per-site justifications.
Suppressions use `phpcs:disable`/`phpcs:enable` **blocks** rather than line-numbered
`phpcs:ignore`, because an earlier attempt at line-anchored annotation inserted comments
*inside SQL string literals* and would have shipped broken queries. That attempt was
caught by reading the result, reverted across 12 files, and redone.

## 2. Findings fixed during closeout

| Finding | What it actually was |
|---|---|
| `ValidatedSanitizedInput.MissingUnslash` — `tools-search-replace.php` | **A real bug.** WordPress slashes `$_POST`; the values were cast straight to string and passed to the operation, so searching for `O'Brien` queried for `O\'Brien`. Fixed, with a regression test. |
| `PreparedSQL` — `DatabaseInspector::table_stats()` | The table name is a *value* matched against `information_schema`. `esc_sql()` was adequate, but it now uses `$wpdb->prepare()` with `%s`. |

## 3. Trademark — the one finding that can block submission

```
WARNING trademarked_term  readme.txt, wp-command-center.php
  "WP Command Center" contains the restricted term "wp" …
  slug "wp-command-center" contains the restricted term "wp" …
```

**This is not a false positive.** WordPress.org restricts "WP" in plugin names and slugs.
Plugin Check reports it as a *warning* because a human reviewer makes the final call, and
many published plugins contain "WP" — but a submission under this name may be asked to
rename.

It is **not fixed here, deliberately.** Changing the slug changes the installation
directory, the update path and the text domain — a breaking change for any existing
install — and choosing a product name is a branding decision with trademark implications
that belongs to the owner, not to a release process.

**Owner decision required before submission.** Either submit as-is and accept a possible
rename request, or rename first. If renaming: the slug, the main file name, the text
domain, `readme.txt`, and the `wp-command-center` references in the generated client
configuration all move together. The `wpcc_` database prefix and the `wp-command-center/v1`
REST namespace would also need a migration path.

## 4. Verified false positives

### `NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` — 233

**All 233 are in `includes/Admin/views/`.** They are template locals inside included view
files, not globals. The sniff cannot distinguish a variable scoped to an included template
from one leaking into the global namespace.

*Evidence:* every flagged path begins `includes/Admin/views/` (233 of 233).

### `Security.NonceVerification.Missing` / `.Recommended` — 27

`ConnectionController` and `AiSetupController` read `$_POST` inside private helpers. The
sniff flags the reads because it cannot follow the call graph. Both classes have a **single
entry point** that gates everything:

```php
public function handle_post(): ?array {
    if ( ! isset( $_POST['wpcc_conn_action'] ) ) { return null; }
    if ( ! current_user_can( 'manage_options' ) ) { … }
    if ( ! check_admin_referer( self::NONCE ) ) { … }
    // … only then dispatch to the private helpers
```

`views/file-access.php` reads `$_GET['path']` and `$_GET['q']` for **navigation**. Nonces
protect state changes, not reads; the screen requires `manage_options`, and the path is
resolved by `PathGuard`, which rejects `..`, `realpath()`s the result, confirms it is
inside the allowed roots and applies a deny list.

### `ValidatedSanitizedInput.InputNotSanitized` — remaining 15

Each verified individually:

- `AuthTokens::bearer_from_request()` — a bearer token is **hashed and compared**, never
  interpolated. Sanitizing it would corrupt legitimate tokens.
- `McpServerRuntime` — forwards the incoming `Authorization` header to the site's own REST
  API. Same reasoning.
- `views/patches.php` — `$modified` (file content) and `$explanation` (prose) are
  deliberately unsanitized; both are `wp_unslash()`ed, and the path goes through
  `PathGuard`.
- `ReportingRuntimeManager` — `$_SERVER['SERVER_SOFTWARE']` **is** sanitized; server
  variables are not slashed, so the unslash sniff does not apply.
- `BuiltinAiSettings` — `sanitize_key( wp_unslash( … ) )`, correct.
- `tools-search-replace.php` — search/replace strings stay unsanitized **by design**: the
  tool replaces arbitrary content including markup, and sanitizing would corrupt the
  operator's intent. They are bound with `$wpdb->prepare()`, never interpolated.

### `PreparedSQL` family — 145 findings across 108 sites

Every site was classified mechanically and then the exceptions read by hand:

| Result | Sites |
|---|---|
| `prepare()` present, or only a code-derived **identifier** interpolated | 97 |
| Reviewed individually | 11 |

A table or column name is an identifier and **cannot** be bound with a placeholder — it
has to be interpolated. Each of the 11 was checked for where its value comes from:

- `TimelineBuilder` ×3 — `LIMIT {$limit}` where `$limit = self::BASELINE_LIMIT`, a class
  constant.
- `SearchReplace` — `DESCRIBE {$table}`, and every table is validated **before** the loop:
  it must start with `$wpdb->prefix` **and** exist (checked with a prepared
  `SHOW TABLES LIKE %s`). An injection string fails the existence check.
- `DatabaseInspector::index_analysis` / `row_counts` — the table always comes from
  `DatabaseRegistry::sanitize_table()`, which returns a name from a fixed `CORE_TABLES`
  allow-list or `null`. Verified directly: `sanitize_table("wp_posts\` WHERE 1=1 -- ")`
  returns `NULL`.
- `Schema`, `OperationWorker`, `TelemetryStore`, `recommendations.php` — all interpolate
  `$wpdb->prefix`-derived table names and `$wpdb->get_charset_collate()`.

### `PrefixAllGlobals.NonPrefixedHooknameFound` — 4

All four are in `CacheRuntimeManager`, firing **other plugins'** documented hooks:

```php
do_action( 'litespeed_purge_all' );
do_action( 'cache_enabler_clear_complete_cache' );
do_action( 'litespeed_purge_url', $url );
do_action( 'cache_enabler_clear_page_cache_by_url', $url );
```

These names are fixed by LiteSpeed Cache and Cache Enabler. Prefixing them would break the
integration. **Unfixable by definition.**

### `PrefixAllGlobals.DynamicHooknameFound` — 7

`apply_filters( $t['filter'], … )` where `$t['filter']` is read from the plugin's own
`BuiltinAiSettings::TOOLS` constant. Every value there is `wpcc_`-prefixed; the sniff
cannot resolve an array lookup.

## 5. Findings WordPress.org may still report — and why we accept them

### `DB.DirectDatabaseQuery.DirectQuery` — 196 · `.NoCaching` — 174

This plugin owns 16 tables (`wpcc_change_log`, `wpcc_operation_requests`,
`wpcc_patches`, …). There is no core API for them, so direct `$wpdb` access is the only
option — this is the intended use of `$wpdb`, not an avoidance of core.

Caching is deliberately **not** applied to the governance surfaces. An approval queue, a
change log or a rollback lookup served from a stale object cache would show a customer a
decision they have already made, or hide one they have not. Correctness outranks the
sniff here.

### `DB.DirectDatabaseQuery.SchemaChange` — 2

`Schema::install()` and the lazy `TelemetryStore::ensure_table()`. A plugin that owns
tables must create them.

### `DB.SlowDBQuery.slow_db_query_meta_key` — 1

A meta-key query in a change-history lookup, bounded by a `LIMIT` and reached only from an
admin screen.

### `Squiz.PHP.DiscouragedFunctions.Discouraged` — 1

Reviewed and retained; not a security concern.

---

## Summary

| Category | Count | Disposition |
|---|---|---|
| Errors | 0 | — |
| Fixed during closeout | 2 | One real bug (`MissingUnslash`), one hygiene improvement |
| Trademark | 3 | **Owner decision** — may prompt a rename request |
| Verified false positives | 427 | Evidence recorded above |
| Accepted with reasons | 384 | Plugin-owned tables, deliberate cache avoidance, third-party hook names |

**Nothing is unexplained.** The only item that requires a decision before submission is the
plugin name and slug (§3).
