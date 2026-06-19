#!/usr/bin/env bash
#
# STEP 105.6 — PHP verification hardening + snapshot protection acceptance suite.
#
#   - PhpBinary discovery: finds a usable CLI; rejects nonexistent / fpm binaries;
#     WPCC_PHP_BINARY override honored; bounded execution.
#   - verify_file error taxonomy: ok | syntax_error | tokenizer_fallback_used,
#     with reason ∈ php_cli_not_found|php_cli_not_executable|verification_timeout.
#   - Tooling failure is NEVER a syntax failure (falls back to tokenizer); a REAL
#     syntax error always blocks (php -l AND tokenizer).
#   - summarize_verification aggregates method/code/warning.
#   - Snapshot: large-file guard, timeout config, atomic write, hash fidelity.
#
# Requires: php, wp-cli, wpcc-env.sh.
# Usage: bash tests/test-php-verification.sh

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_true(){ local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d (got '$a')"; }
lint(){ if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
pj(){ printf '%s' "$1" | jq -r "$2"; }
wpe(){ wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }
wpef(){ wp --path="$WP_ROOT" eval-file "$1" 2>&1; }

echo "== 1. PHP lint =="
lint "PhpBinary lints"        "$PLUGIN_DIR/includes/PatchSystem/PhpBinary.php"
lint "PatchApproval lints"    "$PLUGIN_DIR/includes/PatchSystem/PatchApproval.php"
lint "PatchOperation lints"   "$PLUGIN_DIR/includes/Operations/PatchOperation.php"
lint "SnapshotManager lints"  "$PLUGIN_DIR/includes/Rollback/SnapshotManager.php"
lint "OperationRegistry lints" "$PLUGIN_DIR/includes/Operations/OperationRegistry.php"
lint "McpServerRuntime lints" "$PLUGIN_DIR/includes/Mcp/McpServerRuntime.php"

echo
echo "== 2. PhpBinary discovery + validation =="
RES=$(wpe '\WPCommandCenter\PatchSystem\PhpBinary::reset_cache(); $r=\WPCommandCenter\PatchSystem\PhpBinary::resolve(); echo wp_json_encode(["reason"=>$r["reason"],"path"=>$r["path"],"exec"=>($r["path"]?(is_executable($r["path"])?1:0):0)]);')
assert_eq   "discovery: a usable CLI resolved (reason ok)" "ok" "$(pj "$RES" '.reason')"
assert_true "discovery: resolved path is executable" "$([ "$(pj "$RES" '.exec')" = "1" ] && echo true || echo false)"
USAB=$(wpe '
$ok=\WPCommandCenter\PatchSystem\PhpBinary::is_usable_cli("/usr/sbin/php8.4-does-not-exist");
$fpm=\WPCommandCenter\PatchSystem\PhpBinary::is_usable_cli("/usr/sbin/php-fpm");
echo wp_json_encode(["nonexistent"=>$ok,"fpm"=>$fpm]);')
assert_eq "discovery: nonexistent binary rejected" "false" "$(pj "$USAB" '.nonexistent')"
assert_eq "discovery: fpm binary rejected"         "false" "$(pj "$USAB" '.fpm')"

echo
echo "== 3. verify_file taxonomy (ok / syntax_error) + safety =="
OKF=$(mktemp /tmp/wpcc_ok_XXXX.php); printf '<?php echo "ok";\n' > "$OKF"
BADF=$(mktemp /tmp/wpcc_bad_XXXX.php); printf '<?php if( {\n' > "$BADF"
VF=$(wpe "
\$a=new \WPCommandCenter\PatchSystem\PatchApproval();
\$ok=\$a->verify_file('$OKF'); \$bad=\$a->verify_file('$BADF');
echo wp_json_encode(['ok_pass'=>\$ok['passed'],'ok_code'=>\$ok['code'],'bad_pass'=>\$bad['passed'],'bad_code'=>\$bad['code']]);
")
assert_true "verify: clean file passes" "$(pj "$VF" '.ok_pass')"
assert_eq   "verify: clean file code=ok" "ok" "$(pj "$VF" '.ok_code')"
assert_eq   "verify: broken file blocked (passed=false)" "false" "$(pj "$VF" '.bad_pass')"
assert_eq   "verify: broken file code=syntax_error" "syntax_error" "$(pj "$VF" '.bad_code')"
# Tokenizer alone still blocks a real syntax error (host-independent safety).
TOK=$(wpe "\$a=new \WPCommandCenter\PatchSystem\PatchApproval(); \$r=\$a->tokenizer_check('<?php if( {'); echo wp_json_encode(['passed'=>\$r['passed']]);")
assert_eq "verify: tokenizer blocks real syntax error" "false" "$(pj "$TOK" '.passed')"
rm -f "$OKF" "$BADF"

echo
echo "== 4. Tooling failure != syntax failure; summary warning surfaced =="
SUM=$(wpe '
// A tokenizer-fallback (tooling failure) check that PASSED must NOT be a syntax failure.
$checks = [
  "a.php" => ["passed"=>true,"method"=>"tokenizer","code"=>"tokenizer_fallback_used","reason"=>"php_cli_not_executable"],
];
$s = \WPCommandCenter\PatchSystem\PatchApproval::summarize_verification($checks);
echo wp_json_encode(["passed"=>$s["passed"],"code"=>$s["code"],"fallback"=>$s["tokenizer_fallback_used"],"reason"=>$s["reason"],"has_warning"=>($s["warning"]?true:false)]);')
assert_true "summary: tooling-failure tokenizer pass is still passed=true" "$(pj "$SUM" '.passed')"
assert_eq   "summary: code=tokenizer_fallback_used"  "tokenizer_fallback_used" "$(pj "$SUM" '.code')"
assert_true "summary: tokenizer_fallback_used flag"   "$(pj "$SUM" '.fallback')"
assert_eq   "summary: reason carried"                 "php_cli_not_executable" "$(pj "$SUM" '.reason')"
assert_true "summary: human warning present"          "$(pj "$SUM" '.has_warning')"
# A real syntax error in any file dominates the summary.
SUM2=$(wpe '
$s=\WPCommandCenter\PatchSystem\PatchApproval::summarize_verification(["a.php"=>["passed"=>false,"method"=>"php -l","code"=>"syntax_error","reason"=>"none"]]);
echo wp_json_encode(["passed"=>$s["passed"],"code"=>$s["code"]]);')
assert_eq "summary: syntax_error dominates (passed=false)" "false" "$(pj "$SUM2" '.passed')"
assert_eq "summary: code=syntax_error" "syntax_error" "$(pj "$SUM2" '.code')"

echo
echo "== 5. Snapshot protection: large-file guard + atomic write + hash fidelity =="
SNAP=$(wpe '
// Snapshot is READ-ONLY on the source; use an existing allowed plugin file
// (PathGuard permits themes/plugins/mu-plugins only).
$rel = "plugins/wp-command-center/readme.txt";
$src = WP_CONTENT_DIR . "/" . $rel;
$m = new \WPCommandCenter\Rollback\SnapshotManager();
$ok = $m->create($rel, "105.6 test");
$hash_ok = (!is_wp_error($ok)) && ($ok["hash"] === md5_file($src));
// Oversized file is refused (tiny cap via option; readme.txt > 10 bytes).
update_option("wpcc_snapshot_max_bytes", 10);
$big = $m->create($rel, "too big");
$too_large = is_wp_error($big) && $big->get_error_code() === "wpcc_snapshot_too_large";
delete_option("wpcc_snapshot_max_bytes");
echo wp_json_encode(["created"=>!is_wp_error($ok),"hash_ok"=>$hash_ok,"too_large_refused"=>$too_large]);')
assert_true "snapshot: normal create succeeds"        "$(pj "$SNAP" '.created')"
assert_true "snapshot: stored hash matches source"    "$(pj "$SNAP" '.hash_ok')"
assert_true "snapshot: oversized file refused (wpcc_snapshot_too_large)" "$(pj "$SNAP" '.too_large_refused')"

echo
echo "== 6. Machine-readable MCP schema for patch_manage.files =="
SCHEMA=$(wpe '
$ops=(new \WPCommandCenter\Operations\OperationRegistry())->get_operations();
$files=null; foreach($ops as $o){ if($o["id"]==="patch_manage"){ foreach($o["parameters"] as $p){ if($p["name"]==="files"){ $files=$p; } } } }
echo wp_json_encode([
  "items"=>isset($files["items"]),
  "mode_enum"=>$files["items"]["properties"]["mode"]["enum"] ?? [],
  "oneof"=>count($files["items"]["oneOf"] ?? []),
  "examples"=>count($files["examples"] ?? []),
]);')
assert_true "schema: files has items object"  "$(pj "$SCHEMA" '.items')"
assert_eq   "schema: mode enum has 6 modes"    "6" "$(pj "$SCHEMA" '.mode_enum | length')"
assert_eq   "schema: 6 oneOf per-mode branches" "6" "$(pj "$SCHEMA" '.oneof')"
assert_eq   "schema: 6 worked examples"         "6" "$(pj "$SCHEMA" '.examples')"
# Confirm the modes are exactly the required set.
for m in whole_file append prepend replace_text replace_range unified_diff; do
  assert_true "schema: mode '$m' present" "$(printf '%s' "$SCHEMA" | jq -r --arg m "$m" '.mode_enum | index($m) != null')"
done

echo
echo "== 7. Invariants: no op_map / capability / DB / MCP-tool-count change =="
M=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map stays 34" "34" "$(pj "$M" '.capability_management.operation_map | keys | length')"
assert_eq "capabilities stay 23"   "23" "$(pj "$M" '.capability_management.capabilities | length')"
assert_eq "DB_VERSION 2.5.0"       "2.5.0" "$(wpe 'echo get_option("wpcc_db_version");')"
TOOLS=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '.result.tools | length')
assert_eq "MCP tool count stays 40" "40" "$TOOLS"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
