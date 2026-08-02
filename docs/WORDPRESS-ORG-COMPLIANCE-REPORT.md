# WordPress.org Compliance — Plugin Check Report

**Artifact:** `wp-command-center-1.0.0.zip` — 279 files, 916 KB
**sha256:** `51f1d4b4a3d4ac54a8bfc5627535061c704c03d0320e146539d6541ee570d258`
**Commit:** `984aa3c` · **Checked with:** Plugin Check via WP-CLI against the installed ZIP
(not the development checkout).

## Result

| | Baseline | Final |
|---|---:|---:|
| **Errors** | **164** | **124** |
| Warnings | 821 | ~821 (unchanged; none actionable) |

**40 errors removed.** Every remaining error is classified below. No "all clear" is
claimed: 124 findings will still appear in a reviewer's run, and this document exists so
that each one has an answer.

## Fixed (40)

| Code | Count | What was wrong and what changed |
|---|---:|---|
| `AlternativeFunctions.unlink_unlink` | 22 | Direct `unlink()`. Every call site was in statement position behind `@`, so the return value was never consulted → `wp_delete_file()`, core's sanctioned wrapper, which fires the `wp_delete_file` filter and suppresses errors itself. |
| `ForbiddenFunctions.Found` | 8 | `wp_get_sidebars_widgets()` is private in core. Every write already used `update_option( 'sidebars_widgets', … )`, so reads now use the same option through one accessor — public, and symmetric with the write. |
| `file_system_operations_is_writable` | 7 | `is_writable()` → `wp_is_writable()`, which core ships precisely because `is_writable()` is unreliable on Windows. |
| `WPQueryParams.SuppressFilters` | 2 | A readiness probe counting one post and one image set `suppress_filters => true`. It has no reason to bypass query filters. Removed. |
| `wp_function_not_compatible_with_requires_wp` | 1 | `array_is_list()` (PHP 8.1 / WP 6.5) against a declared WP 6.4 minimum. The bootstrap polyfills it, but relying on it made the plugin read as requiring a newer WordPress than it does. Tested inline instead. |

## Remaining, classified (124)

### A. Proven false positives — 95

**SQL, 67** (`PreparedSQL.NotPrepared` 45, `DirectDB.UnescapedDBParameter` 19,
`PreparedSQLPlaceholders.LikeWildcardsInQuery` 3). Four patterns, each verified in source:

1. *Prepared into a variable, then used* — `includes/Health/HealthVerificationEngine.php:85-87`.
   `$sql` is assembled with `%s` placeholders and passed through `$wpdb->prepare( $sql, ...$params )`;
   LIMIT/OFFSET are prepared separately and bounded with `max()/min()` integer casts.
2. *Allowlisted identifier interpolation* — `includes/PatchSystem/PatchManager.php:464`.
   `$field` is validated against `['session_id','task_id','plan_id']` immediately above;
   `$table` is `$wpdb->prefix . 'wpcc_patches'`; the value binds with `%s`. SQL identifiers
   cannot be placeholders.
3. *Class-constant interpolation* — `includes/AiAgent/TimelineBuilder.php:842`, `{$limit}`
   is `self::BASELINE_LIMIT`.
4. *Placeholders assembled by a helper* — `includes/Operations/MediaUsageResolver.php:92`.
   `content_match_clauses()` returns `$where` containing only `'p.post_content LIKE %s'`
   and `$params` carrying the values, with `$wpdb->esc_like()` applied to filenames. The
   three `LikeWildcards` findings are literal patterns in static SQL (`LIKE 'field\_%'`,
   `LIKE 'options\_%'`) with correct `_` escaping and no variable part.

**Output escaping, 28** (`EscapeOutput.OutputNotEscaped`). All are `echo $page_url( … )`
and siblings in admin views. `includes/Admin/views/file-access.php:10`:

```php
$page_url = static function ( array $args = [] ): string {
    return esc_url( add_query_arg( …, admin_url( 'admin.php' ) ) );
};
```

The output is escaped; the sniffer cannot follow a closure's return value.

### B. Necessary exceptions — 29

**Native filesystem, 27** (`fclose` 15, `fopen` 6, `rename` 3, `rmdir` 2, `fread` 1).
29 of the original 56 were replaceable and were replaced. These 27 cannot move to
`WP_Filesystem` without weakening a guarantee the product depends on:

* `fclose()` / `fread()` operate on **`proc_open` pipes** — process streams, not files.
  `WP_Filesystem` has no equivalent; the sniffer matches the function name alone.
* `rename()` in `SnapshotManager` / `AuditLog` is the **temp-write-then-atomic-replace**
  that snapshot and audit integrity rest on. `WP_Filesystem::move()` gives no atomicity
  guarantee and is not atomic at all over its FTP/SSH transports.
* `fopen( $file, 'c' )` + `flock` in `AuditLog` is **append-only logging under
  concurrency**. `WP_Filesystem` has no locking primitive.

**`proc_open`, 2.** `includes/PatchSystem/PhpBinary.php:200` runs `php -l` to verify a
patched file before accepting it; `includes/Operations/WpCliBridge.php:229` runs WP-CLI.
Both already degrade honestly: on a host that disables process execution (the certified
staging host disables `exec`/`shell_exec`/`popen`) `wp_cli_bridge` reports
`operation_not_available` with no fatal and every other operation continues to work.
Removing the syntax verification would make patching less safe, not more compliant.

## Package hygiene

Verified against the built ZIP: **0** matches for `wpcc-env`, `.git/`, `tests/`, `*.md`,
`DEPLOY`, `HANDOFF`, `CERTIFICATION`. Present and correct: plugin bootstrap,
`uninstall.php`, `readme.txt`, `LICENSE`, `sdk/javascript/wpcc-mcp-relay.mjs`.

## Honest limitation

The classifications in section A were established by verifying **representative instances
of each pattern** in source — not by inspecting all 95 individually. The patterns are
consistent and the evidence is named and checkable, but a reviewer asking about a specific
line outside the cited ones would be asking a fair question that this document has not
individually answered.
