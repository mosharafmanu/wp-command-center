#!/usr/bin/env bash
# Step 34 - environment and guarded cleanup integration suite.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"&&pwd)"; ROOT="$(cd "$DIR/.."&&pwd)"; source "$ROOT/wpcc-env.sh"
P=0; F=0; pass(){ P=$((P+1)); echo "  PASS: $1"; }; fail(){ F=$((F+1)); echo "  FAIL: $1"; }; eq(){ if [ "$2" = "$3" ];then pass "$1";else fail "$1 (expected '$2', got '$3')";fi; }; ok(){ if [ "$2" = true ];then pass "$1";else fail "$1";fi; }
api(){ local m="$1" p="$2" b="${3:-}"; if [ -n "$b" ];then curl -sS -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d "$b" "$WPCC_BASE$p";else curl -sS -X "$m" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$p";fi; }

echo "== 1. Environment Modes =="
ORIGINAL=$(api GET /system/environment|jq -r '.mode')
for mode in development staging production; do eq "set $mode mode" "$mode" "$(api POST /system/environment "{\"mode\":\"$mode\"}"|jq -r '.mode')"; done
eq "invalid mode rejected" wpcc_invalid_environment_mode "$(api POST /system/environment '{"mode":"demo"}'|jq -r '.code')"

echo "== 2. Production Safeguard =="
BLOCKED=$(api POST /system/cleanup '{"dry_run":false,"older_than_days":365,"confirm":"CLEANUP"}')
eq "production live cleanup blocked" wpcc_production_cleanup_blocked "$(echo "$BLOCKED"|jq -r '.code')"
DRY=$(api POST /system/cleanup '{"dry_run":true,"older_than_days":365}')
eq "production dry run allowed" true "$(echo "$DRY"|jq -r '.dry_run')"
ok "dry run returns all resource groups" "$(echo "$DRY"|jq -r '.resources|has("sessions") and has("tasks") and has("actions") and has("plans") and has("queue_items") and has("recommendations")')"

echo "== 3. Development Cleanup =="
api POST /system/environment '{"mode":"development"}' >/dev/null
FIXTURE_ID="cleanup-$(date +%s)"
wp eval "global \$wpdb; \$old=time()-(500*DAY_IN_SECONDS); \$wpdb->insert(\$wpdb->prefix.'wpcc_agent_sessions',['session_id'=>'$FIXTURE_ID','source'=>'api','label'=>'Cleanup fixture','status'=>'closed','created_at'=>\$old,'updated_at'=>\$old,'expires_at'=>\$old]);" >/dev/null
PREVIEW=$(api POST /system/cleanup '{"dry_run":true,"older_than_days":365,"resources":["sessions"]}')
ok "fixture eligible in dry run" "$(echo "$PREVIEW"|jq -r '.resources.sessions.eligible >= 1')"
EXISTS_BEFORE=$(wp eval "global \$wpdb; echo (int)\$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}wpcc_agent_sessions WHERE session_id=%s\",'$FIXTURE_ID'));" 2>/dev/null)
eq "dry run keeps fixture" 1 "$EXISTS_BEFORE"
eq "live cleanup requires confirmation" wpcc_cleanup_confirmation_required "$(api POST /system/cleanup '{"dry_run":false,"older_than_days":365,"resources":["sessions"]}'|jq -r '.code')"
LIVE=$(api POST /system/cleanup '{"dry_run":false,"older_than_days":365,"resources":["sessions"],"confirm":"CLEANUP"}')
ok "live cleanup deletes eligible fixture" "$(echo "$LIVE"|jq -r '.resources.sessions.deleted >= 1')"
EXISTS_AFTER=$(wp eval "global \$wpdb; echo (int)\$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}wpcc_agent_sessions WHERE session_id=%s\",'$FIXTURE_ID'));" 2>/dev/null)
eq "fixture removed" 0 "$EXISTS_AFTER"

echo "== 4. API, Context, Dashboard & Audit =="
eq "cleanup requires full token" 401 "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$WPCC_BASE/system/cleanup")"
CTX=$(api GET /agent/context); eq "context environment mode" development "$(echo "$CTX"|jq -r '.environment_mode')"
MAN=$(api GET /agent/manifest); eq "cleanup capability" true "$(echo "$MAN"|jq -r '.capabilities.cleanup')"; eq "environment capability" true "$(echo "$MAN"|jq -r '.capabilities.environment_management')"
ok "dashboard has production warning" "$(grep -q 'Production environment' "$ROOT/includes/Admin/views/dashboard.php"&&echo true||echo false)"
AUDIT=$(wp eval '$u=wp_upload_dir();echo trailingslashit($u["basedir"])."wpcc-audit/audit.log";' 2>/dev/null)
for e in system.environment.updated system.cleanup.started system.cleanup.completed system.cleanup.blocked;do ok "audit has $e" "$(grep -q "$e" "$AUDIT"&&echo true||echo false)";done
api POST /system/environment "{\"mode\":\"$ORIGINAL\"}" >/dev/null

echo; echo "== Summary =="; echo "  $P passed, $F failed"; [ "$F" -eq 0 ]
