#!/usr/bin/env bash
# Step 33 - Health Verification Engine integration suite.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"; source "$PLUGIN_DIR/wpcc-env.sh"
PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }; fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi; }
assert_true(){ if [ "$2" = true ]; then pass "$1"; else fail "$1"; fi; }
api(){ local m="$1" p="$2" b="${3:-}"; if [ -n "$b" ]; then curl -sS -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d "$b" "$WPCC_BASE$p"; else curl -sS -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$p"; fi; }

echo "== 1. Read-only Verification =="
BEFORE=$(wp eval 'global $wpdb; echo wp_json_encode(["posts"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),"options"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}")]);' 2>/dev/null)
VERIFY=$(api POST /health/verify '{}')
VID=$(echo "$VERIFY" | jq -r '.verification_id')
assert_true "verification returns UUID" "$(echo "$VID" | grep -Eq '^[a-f0-9-]{36}$' && echo true || echo false)"
assert_true "verification status valid" "$(echo "$VERIFY" | jq -r '.status | IN("passed","warning","failed")')"
assert_eq "seven checks returned" "7" "$(echo "$VERIFY" | jq -r '.checks|length')"
for check in frontend_health admin_health rest_api_health wpcc_api_health woocommerce_health plugin_integrity theme_integrity; do assert_true "contains $check" "$(echo "$VERIFY" | jq -r --arg id "$check" 'any(.checks[]; .id==$id)')"; done
assert_eq "summary total matches checks" "7" "$(echo "$VERIFY" | jq -r '.summary.total')"
AFTER=$(wp eval 'global $wpdb; echo wp_json_encode(["posts"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),"options"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}")]);' 2>/dev/null)
assert_eq "verification does not modify posts/options" "$BEFORE" "$AFTER"

echo "== 2. Result History & Security =="
RESULTS=$(api GET '/health/results?limit=10')
assert_true "result history contains verification" "$(echo "$RESULTS" | jq -r --arg id "$VID" 'any(.[]; .verification_id==$id)')"
assert_eq "history record preserves status" "$(echo "$VERIFY" | jq -r '.status')" "$(echo "$RESULTS" | jq -r --arg id "$VID" '.[]|select(.verification_id==$id)|.status')"
assert_eq "verify without token is unauthorized" "401" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$WPCC_BASE/health/verify")"

echo "== 3. Context, Manifest, Timeline & Audit =="
CONTEXT=$(api GET /agent/context)
assert_true "context includes recent health verifications" "$(echo "$CONTEXT" | jq -r 'has("recent_health_verifications")')"
MANIFEST=$(api GET /agent/manifest)
assert_eq "manifest health capability" true "$(echo "$MANIFEST" | jq -r '.capabilities.health_verification')"
assert_eq "manifest has two health verification endpoints" 2 "$(echo "$MANIFEST" | jq -r '[.endpoints[]|select(.path=="/health/verify" or .path=="/health/results")]|length')"
TIMELINE=$(api GET '/agent/timeline?limit=200')
assert_true "timeline started event" "$(echo "$TIMELINE" | jq -r 'any(.[];.label=="Health verification started")')"
assert_true "timeline terminal event" "$(echo "$TIMELINE" | jq -r 'any(.[];.label=="Health verification completed" or .label=="Health verification failed")')"
AUDIT=$(wp eval '$u=wp_upload_dir(); echo trailingslashit($u["basedir"])."wpcc-audit/audit.log";' 2>/dev/null)
assert_true "audit started event" "$(grep -q 'health.verification.started' "$AUDIT" && echo true || echo false)"
assert_true "audit terminal event" "$(grep -Eq 'health.verification.(completed|failed)' "$AUDIT" && echo true || echo false)"

echo; echo "== Summary =="; echo "  $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]
