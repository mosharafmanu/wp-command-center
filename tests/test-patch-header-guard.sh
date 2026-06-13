#!/usr/bin/env bash
#
# Patch Header Guard regression suite.
#
# Verifies PatchGuard rejects any patch that removes/corrupts/invalidates a
# plugin bootstrap header (Plugin Name) or theme stylesheet header (Theme Name),
# at BOTH create time and apply time, without weakening normal patch behavior.
#
# Background: a patch whose modified content was valid PHP but dropped the
# `Plugin Name:` header applied cleanly (syntax-only verification) and
# deactivated the plugin ("does not have a valid header"). This guard closes
# that gap.
#
# Requires: curl, jq, python3, wpcc-env.sh. Has filesystem access to wp-content.
# Usage: bash tests/test-patch-header-guard.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

WP_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
THEMES_DIR="$WP_ROOT/wp-content/themes"
PATCH_DIR="$WP_ROOT/wp-content/uploads/wpcc-patches"

P_SLUG="wpcc-hdr-test"
T_SLUG="wpcc-hdr-theme"
P_DIR="$PLUGINS_DIR/$P_SLUG"
T_DIR="$THEMES_DIR/$T_SLUG"
REL_PLUGIN="plugins/$P_SLUG/$P_SLUG.php"
REL_PLAIN="plugins/$P_SLUG/helper.php"
REL_THEME="themes/$T_SLUG/style.css"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
pj() { printf '%s' "$1" | jq -r "$2"; }
rest() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }
# Apply with destructive confirmation (plugin main files are dangerous-file gated).
apply_confirm() { rest patch_manage "$(jq -n --arg id "$1" '{action:"patch_apply",patch_id:$id,confirm:true,confirmation_phrase:"APPLY_PATCH",reason:"header guard test"}')"; }

cleanup() { rm -rf "$P_DIR" "$T_DIR" 2>/dev/null; }
trap cleanup EXIT

mkdir -p "$P_DIR" "$T_DIR"
printf '<?php\n/**\n * Plugin Name: WPCC Header Test\n * Version: 1.0.0\n */\n$wpcc_hdr = "a";\n' > "$P_DIR/$P_SLUG.php"
printf '<?php\n$helper = "a";\n' > "$P_DIR/helper.php"
printf '/*\nTheme Name: WPCC Header Theme\nVersion: 1.0.0\n*/\nbody{color:#000}\n' > "$T_DIR/style.css"

# Content variants
P_VALID=$'<?php\n/**\n * Plugin Name: WPCC Header Test\n * Version: 1.0.0\n */\n$wpcc_hdr = "b";\n'      # keeps header
P_VERBUMP=$'<?php\n/**\n * Plugin Name: WPCC Header Test\n * Version: 2.0.0\n */\n$wpcc_hdr = "a";\n'    # keeps header, bumps version
P_NOHEADER=$'<?php\n$wpcc_hdr = "b";\n'                                                                 # valid PHP, NO header
PLAIN_NEW=$'<?php\n$helper = "b";\n'
T_VALID=$'/*\nTheme Name: WPCC Header Theme\nVersion: 2.0.0\n*/\nbody{color:#111}\n'                    # keeps Theme Name
T_NOHEADER=$'/*\nJust a comment\n*/\nbody{color:#111}\n'                                                # no Theme Name

create() { rest patch_manage "$(jq -n --arg p "$1" --arg m "$2" '{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"hdr test"}')"; }

echo "== 1. Invalid: removing Plugin Name header is rejected at CREATE =="
R=$(create "$REL_PLUGIN" "$P_NOHEADER")
assert_eq "create rejects header-removing plugin patch" "wpcc_patch_breaks_header" "$(pj "$R" '.code // "none"')"

echo "== 2. Valid: header-preserving plugin patch is allowed and applies =="
PID=$(pj "$(create "$REL_PLUGIN" "$P_VALID")" '.patch_id // empty')
assert_eq "create accepts header-preserving patch" "true" "$([ -n "$PID" ] && echo true || echo false)"
R=$(apply_confirm "$PID")
assert_eq "valid plugin patch applies" "applied" "$(pj "$R" '.status // "none"')"
assert_eq "plugin header still present after apply" "1" "$(grep -c 'Plugin Name: WPCC Header Test' "$P_DIR/$P_SLUG.php")"
rest rollback_manage "$(jq -n --arg id "$PID" '{action:"rollback_apply",patch_id:$id}')" >/dev/null

echo "== 3. APPLY-level guard: a header-breaking patch that bypassed create is rejected at apply =="
PID2=$(pj "$(create "$REL_PLUGIN" "$P_VALID")" '.patch_id // empty')
# Tamper the stored patch so its modified content drops the header (simulates a
# patch crafted before the guard existed).
python3 - "$PATCH_DIR/$PID2.json" "$P_NOHEADER" <<'PY'
import json,sys
path,mod=sys.argv[1],sys.argv[2]
d=json.load(open(path)); d['files'][0]['modified']=mod
json.dump(d,open(path,'w'))
PY
R=$(apply_confirm "$PID2")
assert_eq "apply rejects tampered header-breaking patch" "wpcc_patch_breaks_header" "$(pj "$R" '.code // "none"')"
assert_eq "plugin file untouched (header intact)" "1" "$(grep -c 'Plugin Name: WPCC Header Test' "$P_DIR/$P_SLUG.php")"
assert_eq "plugin file not overwritten (still v 'a')" "1" "$(grep -c 'wpcc_hdr = \"a\"' "$P_DIR/$P_SLUG.php")"

echo "== 4. Not weakened: legitimate version bump (keeps Plugin Name) is allowed =="
R=$(create "$REL_PLUGIN" "$P_VERBUMP")
assert_eq "version-bump patch accepted" "true" "$([ -n "$(pj "$R" '.patch_id // empty')" ] && echo true || echo false)"

echo "== 5. Theme: removing Theme Name from style.css is rejected =="
R=$(create "$REL_THEME" "$T_NOHEADER")
assert_eq "create rejects header-removing theme patch" "wpcc_patch_breaks_header" "$(pj "$R" '.code // "none"')"

echo "== 6. Theme: header-preserving style.css patch is allowed and applies =="
TPID=$(pj "$(create "$REL_THEME" "$T_VALID")" '.patch_id // empty')
assert_eq "create accepts header-preserving theme patch" "true" "$([ -n "$TPID" ] && echo true || echo false)"
R=$(rest patch_manage "$(jq -n --arg id "$TPID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "valid theme patch applies" "applied" "$(pj "$R" '.status // "none"')"
assert_eq "Theme Name still present after apply" "1" "$(grep -c 'Theme Name: WPCC Header Theme' "$T_DIR/style.css")"
rest rollback_manage "$(jq -n --arg id "$TPID" '{action:"rollback_apply",patch_id:$id}')" >/dev/null

echo "== 7. Not weakened: ordinary (non-bootstrap) file patches still work =="
HPID=$(pj "$(create "$REL_PLAIN" "$PLAIN_NEW")" '.patch_id // empty')
assert_eq "non-bootstrap patch created" "true" "$([ -n "$HPID" ] && echo true || echo false)"
R=$(rest patch_manage "$(jq -n --arg id "$HPID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "non-bootstrap patch applies" "applied" "$(pj "$R" '.status // "none"')"
rest rollback_manage "$(jq -n --arg id "$HPID" '{action:"rollback_apply",patch_id:$id}')" >/dev/null

echo "== 8. preview flags a header-breaking change =="
R=$(rest patch_manage "$(jq -n --arg p "$REL_PLUGIN" --arg m "$P_NOHEADER" '{action:"patch_preview",files:[{path:$p,modified:$m}]}')")
assert_eq "preview: bootstrap_file true" "true" "$(pj "$R" '.files[0].bootstrap_file')"
assert_eq "preview: header_safe false" "false" "$(pj "$R" '.files[0].header_safe')"
assert_eq "preview: overall syntax_ok false (header unsafe)" "false" "$(pj "$R" '.syntax_ok')"

echo
echo "================================================"
echo "  Patch Header Guard: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
