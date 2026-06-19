#!/usr/bin/env bash
#
# STEP 111 (S2.2.1) — stateless SelectionContract + read-only SelectionResolver
# + the GET /admin/alt-text/selection resolve route.
#
# Asserts: contract shapes (ids/criteria) + cap clamping; resolver is bounded
# (over-cap REFUSES, never truncates), capability-scoped (operation outside the
# allowed set resolves to nothing), read-only (no writes, no schema), and reuses
# the existing ProposalStore source. Also: the route is READABLE-only and fixes
# the criteria to this surface's own governed scope. Plus invariants.
#
# Requires: wp-cli.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }
lacks(){ grep -qF -- "$2" "$3" && fail "$1 (found '$2')" || pass "$1"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
CONTRACT="$PLUGIN_DIR/includes/Admin/SelectionContract.php"
RESOLVER="$PLUGIN_DIR/includes/Admin/SelectionResolver.php"
VIEW="$PLUGIN_DIR/includes/Admin/views/ai-alt-text.php"

echo "STEP 111 (S2.2.1) — Selection resolver"

echo
echo "== 1. Static: classes are read-only, no persistence, no new authority =="
has  "SelectionContract by=ids"          "BY_IDS"            "$CONTRACT"
has  "SelectionContract by=criteria"     "BY_CRITERIA"       "$CONTRACT"
has  "SelectionContract hard cap"        "HARD_CAP"          "$CONTRACT"
has  "Resolver MAX_SELECTION cap"        "MAX_SELECTION"     "$RESOLVER"
has  "Resolver reuses ProposalStore"     "ProposalStore"     "$RESOLVER"
lacks "Resolver: no writes (insert/update_option/wpdb)" "update_option" "$RESOLVER"
lacks "Resolver: no engine dispatch (no instantiation)" "new OperationExecutor" "$RESOLVER"
lacks "Resolver: no apply service (no instantiation)"   "new ProposalApplyService" "$RESOLVER"
lacks "Resolver: no store mutation"      "->create("        "$RESOLVER"
lacks "Resolver: no dismiss/apply call" "->dismiss("       "$RESOLVER"
lacks "Contract: not persisted (no wpdb)" "wpdb"            "$CONTRACT"

echo
echo "== 2. Route: READABLE-only resolve endpoint =="
has  "route: /admin/alt-text/selection" "'/admin/alt-text/selection'" "$RESTAPI"
has  "selection handler present"        "function alt_text_selection" "$RESTAPI"
has  "selection gated by alt-text perm" "check_alt_text_permission"   "$RESTAPI"
# The selection route registration block declares READABLE (no write verbs).
SEL_ROUTE="$(awk '/admin\/alt-text\/selection/{f=1} f{print} f&&/\] \);/{exit}' "$RESTAPI")"
if printf '%s' "$SEL_ROUTE" | grep -qE "CREATABLE|EDITABLE|DELETABLE"; then fail "selection route is read-only"; else pass "selection route is read-only (READABLE)"; fi
# Handler fixes the criteria to media_manage drafts (not caller-supplied).
has  "handler fixes operation to media_manage" "'operation_id' => 'media_manage'" "$RESTAPI"
has  "handler scopes allowed_operations"       "'allowed_operations' => [ 'media_manage' ]" "$RESTAPI"

echo
echo "== 3. Functional: resolver behavior =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$store = new \WPCommandCenter\Proposals\ProposalStore();
		$batch = "selftest-" . substr( wp_generate_uuid4(), 0, 8 );
		$made  = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$p = $store->create([
				"operation_id" => "media_manage",
				"action"       => "media_update",
				"target_type"  => "attachment",
				"target_id"    => (string) ( 900000 + $i ),
				"payload"      => [ "action" => "media_update", "media_id" => 900000 + $i, "alt" => "t" ],
				"batch_id"     => $batch,
			]);
			if ( ! is_wp_error( $p ) ) { $made[] = $p["proposal_id"]; }
		}
		$out = [];
		$R = new \WPCommandCenter\Admin\SelectionResolver();

		// criteria over our unique batch → exactly 3, ids returned, not over cap.
		$c = \WPCommandCenter\Admin\SelectionContract::from_array([
			"by" => "criteria",
			"filters" => [ "operation_id" => "media_manage", "status" => "draft", "batch_id" => $batch ],
			"cap" => 50,
		]);
		$r = $R->resolve( $c, [ "allowed_operations" => [ "media_manage" ] ] );
		$out["crit_total"]  = (int) $r["total_matched"];
		$out["crit_count"]  = (int) $r["count"];
		$out["crit_idcnt"]  = count( $r["ids"] );
		$out["crit_over"]   = $r["over_cap"] ? 1 : 0;
		$out["crit_action"] = (string) $r["action"];

		// over-cap: cap below the match count → REFUSE (empty ids), over_cap true.
		$c2 = \WPCommandCenter\Admin\SelectionContract::from_array([
			"by" => "criteria",
			"filters" => [ "operation_id" => "media_manage", "status" => "draft", "batch_id" => $batch ],
			"cap" => 2,
		]);
		$r2 = $R->resolve( $c2, [ "allowed_operations" => [ "media_manage" ] ] );
		$out["over_flag"]  = $r2["over_cap"] ? 1 : 0;
		$out["over_ids"]   = count( $r2["ids"] );          // must be 0 (refused, not truncated)
		$out["over_total"] = (int) $r2["total_matched"];   // still reports the true count

		// capability scope: operation not allowed → empty.
		$r3 = $R->resolve( $c, [ "allowed_operations" => [ "content_manage" ] ] );
		$out["scope_block"] = ( 0 === $r3["count"] ) ? 1 : 0;

		// by=ids over cap → refuse.
		$c4 = \WPCommandCenter\Admin\SelectionContract::from_array([ "by" => "ids", "ids" => [ "a","b","c" ], "cap" => 2 ]);
		$r4 = $R->resolve( $c4 );
		$out["ids_over"] = ( $r4["over_cap"] && 0 === $r4["count"] ) ? 1 : 0;

		// by=ids within cap → echoed.
		$c5 = \WPCommandCenter\Admin\SelectionContract::from_array([ "by" => "ids", "ids" => [ "a","b" ], "cap" => 10 ]);
		$r5 = $R->resolve( $c5 );
		$out["ids_ok"] = ( 2 === $r5["count"] && ! $r5["over_cap"] ) ? 1 : 0;

		// contract cap clamped to HARD_CAP.
		$c6 = \WPCommandCenter\Admin\SelectionContract::from_array([ "by" => "criteria", "cap" => 99999 ]);
		$out["cap_clamp"] = ( $c6->cap() === \WPCommandCenter\Admin\SelectionContract::HARD_CAP ) ? 1 : 0;

		// invalid by → WP_Error.
		$c7 = \WPCommandCenter\Admin\SelectionContract::from_array([ "by" => "bogus" ]);
		$out["bad_by"] = is_wp_error( $c7 ) ? 1 : 0;

		// arbitrary filter key is ignored (whitelist): inject a junk column.
		$c8 = \WPCommandCenter\Admin\SelectionContract::from_array([
			"by" => "criteria",
			"filters" => [ "operation_id" => "media_manage", "status" => "draft", "batch_id" => $batch, "evil" => "1; DROP" ],
			"cap" => 50,
		]);
		$r8 = $R->resolve( $c8, [ "allowed_operations" => [ "media_manage" ] ] );
		$out["whitelist_ok"] = ( (int) $r8["total_matched"] === (int) $out["crit_total"] ) ? 1 : 0;

		// read-only: count of drafts in our batch unchanged after all resolves.
		$out["readonly"] = ( $store->count([ "operation_id" => "media_manage", "status" => "draft", "batch_id" => $batch ]) === 3 ) ? 1 : 0;

		// cleanup (dismiss our drafts so they do not pollute other suites).
		foreach ( $made as $pid ) { $store->dismiss( $pid ); }

		echo wp_json_encode( $out );
	')"
	getj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }

	assert_eq "criteria resolves exactly 3 matches"      "3" "$(getj crit_total)"
	assert_eq "criteria returns 3 ids"                   "3" "$(getj crit_idcnt)"
	assert_eq "criteria count == ids"                    "3" "$(getj crit_count)"
	assert_eq "criteria not over cap"                    "0" "$(getj crit_over)"
	assert_eq "envelope action = selection_resolve" "selection_resolve" "$(getj crit_action)"
	assert_eq "over-cap REFUSES (over_cap flag)"         "1" "$(getj over_flag)"
	assert_eq "over-cap returns NO ids (no truncation)"  "0" "$(getj over_ids)"
	assert_eq "over-cap still reports true total"        "3" "$(getj over_total)"
	assert_eq "capability scope blocks other operation"  "1" "$(getj scope_block)"
	assert_eq "by=ids over cap refuses"                  "1" "$(getj ids_over)"
	assert_eq "by=ids within cap echoes ids"             "1" "$(getj ids_ok)"
	assert_eq "cap clamped to HARD_CAP"                  "1" "$(getj cap_clamp)"
	assert_eq "invalid by → WP_Error"                    "1" "$(getj bad_by)"
	assert_eq "arbitrary filter key ignored (whitelist)" "1" "$(getj whitelist_ok)"
	assert_eq "resolver performs no writes (read-only)"  "1" "$(getj readonly)"

	echo
	echo "== 4. Functional: REST route end-to-end (admin) =="
	RRES="$(wpe '
		$a = get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$req = new WP_REST_Request("GET","/wp-command-center/v1/admin/alt-text/selection");
		$req->set_param("by","criteria");
		$d = rest_do_request($req)->get_data();
		$keys = ["action","by","total_matched","count","ids","over_cap","cap"];
		$ok = 1; foreach($keys as $k){ if(!array_key_exists($k,$d)) $ok=0; }
		$out = [
			"shape" => $ok,
			"by"    => (string)($d["by"]??""),
			"cap"   => (int)($d["cap"]??-1),
			"ids_is_array" => is_array($d["ids"]??null) ? 1 : 0,
		];
		echo wp_json_encode($out);
	')"
	getr() { printf '%s' "$RRES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "route returns canonical resolve envelope" "1" "$(getr shape)"
	assert_eq "route resolves by=criteria"               "criteria" "$(getr by)"
	assert_eq "route cap = MAX_SELECTION (100)"          "100" "$(getr cap)"
	assert_eq "route ids is an array"                    "1" "$(getr ids_is_array)"
fi

echo
echo "== 5. View: 'Select all matching' wired to the resolver + existing loops =="
has  "select-all-matching control"      "wpcc-at-sg-matchall"          "$VIEW"
has  "calls the resolve endpoint"       "/alt-text/selection"          "$VIEW"
has  "re-resolves at action time"       "resolveMatching"              "$VIEW"
has  "over-cap surfaced to user"        "matchOverCap"                 "$VIEW"
has  "feeds existing per-item apply"    "runApply"                     "$VIEW"
has  "feeds existing per-item dismiss"  "runDismiss"                   "$VIEW"
lacks "no batch endpoint in view"       "/batch"                       "$VIEW"
lacks "no new write op in view"         "OperationExecutor"            "$VIEW"

echo
echo "== 6. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
