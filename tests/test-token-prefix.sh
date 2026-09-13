#!/usr/bin/env bash
# SiteRadian public token-prefix and legacy-credential compatibility contract.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_ROOT="${WPCC_TEST_WP_ROOT:-$(cd "$ROOT/../../.." && pwd)}"
PHP_BIN="$(command -v php || echo /Applications/AMPPS/apps/php82/bin/php)"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

echo "Token identity — new SiteRadian prefix with legacy compatibility"

assert_eq "AuthTokens PHP syntax" "ok" "$( "$PHP_BIN" -l "$ROOT/includes/Security/AuthTokens.php" >/dev/null 2>&1 && echo ok || echo bad )"
assert_eq "new generation prefix is siteradian_" "yes" "$( rg -q "TOKEN_PREFIX\s*=\s*'siteradian_'" "$ROOT/includes/Security/AuthTokens.php" && echo yes || echo no )"
assert_eq "validation has no prefix allow/deny gate" "0" "$( sed -n '/function validate/,/private function update/p' "$ROOT/includes/Security/AuthTokens.php" | rg -c 'TOKEN_PREFIX|str_starts_with|preg_match' || echo 0 )"

if ! command -v wp >/dev/null 2>&1 || [ ! -f "$WP_ROOT/wp-config.php" ]; then
	echo "  SKIP: wp-cli test site unavailable — static contract only."
else
	RESULT="$(wp --path="$WP_ROOT" eval '
		$tokens = new \WPCommandCenter\Security\AuthTokens();
		$new = $tokens->create("SiteRadian prefix compatibility probe", "read_only", time()+300, 1);
		if ( is_wp_error($new) ) { echo "create_error"; return; }
		$new_raw = $new["token"];
		$new_id  = $new["record"]["id"];

		$legacy_raw = "wpcc_" . wp_generate_password(64, false);
		$ref = new ReflectionClass($tokens);
		$get_dir = $ref->getMethod("get_storage_dir"); $get_dir->setAccessible(true);
		$hash = $ref->getMethod("hash_token"); $hash->setAccessible(true);
		$read = $ref->getMethod("read_manifest"); $read->setAccessible(true);
		$write = $ref->getMethod("write_manifest"); $write->setAccessible(true);
		$dir = $get_dir->invoke($tokens);
		$manifest = $read->invoke($tokens, $dir);
		$legacy_id = wp_generate_uuid4();
		$manifest[] = [
			"id"=>$legacy_id,"label"=>"Legacy prefix compatibility probe",
			"token_hash"=>$hash->invoke($tokens,$legacy_raw),
			"token_preview"=>substr($legacy_raw,0,12),"scope"=>"read_only",
			"status"=>"active","user_id"=>1,"created_at"=>time(),
			"expires_at"=>time()+300,"last_used_at"=>null,
		];
		$write->invoke($tokens,$dir,$manifest);
		$legacy_check = $tokens->validate($legacy_raw);
		$stored = wp_json_encode($tokens->list());
		$out = [
			"new_prefix"=>str_starts_with($new_raw,"siteradian_"),
			"old_prefix_new"=>str_starts_with($new_raw,"wpcc_"),
			"legacy_valid"=>!is_wp_error($legacy_check) && ($legacy_check["id"]??"")===$legacy_id,
			"new_valid"=>!is_wp_error($tokens->validate($new_raw)),
			"raw_not_stored"=>false===strpos($stored,$new_raw) && false===strpos($stored,$legacy_raw),
		];
		$tokens->delete($new_id); $tokens->delete($legacy_id);
		echo wp_json_encode($out);
	' 2>/dev/null)"
	get(){ printf '%s' "$RESULT" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo !empty($j[$argv[1]])?"yes":"no";' "$1"; }
	assert_eq "fresh token starts with siteradian_" "yes" "$(get new_prefix)"
	assert_eq "fresh token no longer starts with wpcc_" "no" "$(get old_prefix_new)"
	assert_eq "legacy wpcc_ token still authenticates" "yes" "$(get legacy_valid)"
	assert_eq "fresh siteradian_ token authenticates" "yes" "$(get new_valid)"
	assert_eq "neither raw credential is stored" "yes" "$(get raw_not_stored)"
fi

echo
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
