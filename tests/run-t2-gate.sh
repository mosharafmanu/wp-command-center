#!/usr/bin/env bash
# Create one disposable, long-lived release-gate credential, run T2 in its
# process scope, and delete it on every exit path. The seven-day expiry is a
# failsafe; clock discontinuity detection aborts the gate long before expiry.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"
ENV_FILE="$ROOT/wpcc-env.sh"
GATE_ID=""; GATE_TOKEN=""; ENV_SNAPSHOT=""
MODE="run"
[ "${1:-}" = "--preflight-only" ] && { MODE="preflight"; shift; }
[ "$#" -eq 0 ] || { echo 'usage: tests/run-t2-gate.sh [--preflight-only]' >&2; exit 2; }

if [ "${WPCC_TOKEN+x}" = x ]; then
	echo 'run-t2-gate: refusing inherited WPCC_TOKEN; start from a clean process.' >&2
	exit 2
fi

cleanup() {
	local incoming_rc=$? cleanup_rc=0
	trap - EXIT INT TERM HUP
	if [ -n "$GATE_ID" ]; then
		env WPCC_GATE_DELETE_ID="$GATE_ID" wp --path="$WP_ROOT" eval '
			$id=(string)getenv("WPCC_GATE_DELETE_ID");$auth=new \WPCommandCenter\Security\AuthTokens();
			$result=$auth->delete($id);if(is_wp_error($result)){WP_CLI::error("Gate credential cleanup failed.");}
		' >/dev/null 2>&1 || cleanup_rc=1
	fi
	GATE_TOKEN=""; unset WPCC_TOKEN
	if [ -n "$ENV_SNAPSHOT" ]; then
		env WPCC_GATE_ENV_SNAPSHOT="$ENV_SNAPSHOT" php -r '
			$file=$argv[1];$bytes=base64_decode((string)getenv("WPCC_GATE_ENV_SNAPSHOT"),true);if($bytes===false)exit(2);
			$tmp=tempnam(dirname($file),".wpcc-env-");if($tmp===false)exit(3);chmod($tmp,fileperms($file)&0777);
			if(file_put_contents($tmp,$bytes)!==strlen($bytes)||!rename($tmp,$file)){@unlink($tmp);exit(4);}
		' "$ENV_FILE" || cleanup_rc=1
	fi
	if [ "$cleanup_rc" -eq 0 ]; then echo 'gate credential cleanup: verified'; else echo 'gate credential cleanup: FAILED' >&2; fi
	if [ "$incoming_rc" -ne 0 ]; then exit "$incoming_rc"; fi
	exit "$cleanup_rc"
}
trap cleanup EXIT INT TERM HUP

[ -f "$ENV_FILE" ] || { echo 'run-t2-gate: wpcc-env.sh compatibility file is missing.' >&2; exit 2; }
ENV_SNAPSHOT="$(base64 < "$ENV_FILE" | tr -d '\n')"
GATE_JSON="$(env WPCC_GATE_PREFLIGHT_ONLY="$([ "$MODE" = "preflight" ] && echo 1 || echo 0)" wp --path="$WP_ROOT" eval '
	$auth=new \WPCommandCenter\Security\AuthTokens();
	$prefix=getenv("WPCC_GATE_PREFLIGHT_ONLY")==="1" ? "WPCC T2 Gate Wrapper Focused " : "WPCC Final T2 Gate ";
	$result=$auth->create($prefix . wp_generate_uuid4(), \WPCommandCenter\Security\AuthTokens::SCOPE_FULL, time() + 7 * DAY_IN_SECONDS, 1);
	if(is_wp_error($result)){WP_CLI::error("Unable to create gate credential.");}
	echo wp_json_encode(["token"=>$result["token"],"id"=>$result["record"]["id"]]);
' 2>/dev/null)" || exit 2
GATE_TOKEN="$(printf '%s' "$GATE_JSON" | jq -r '.token // empty')"
GATE_ID="$(printf '%s' "$GATE_JSON" | jq -r '.id // empty')"
[ -n "$GATE_TOKEN" ] && [ -n "$GATE_ID" ] || { echo 'run-t2-gate: gate credential creation returned no usable record.' >&2; exit 2; }

# Legacy suites source this ignored compatibility file. Populate it only for
# this wrapper's lifetime and restore its exact original bytes in cleanup().
env WPCC_GATE_TOKEN="$GATE_TOKEN" php -r '
	$file=$argv[1];$data=file_get_contents($file);if($data===false)exit(2);
	$line="export WPCC_TOKEN=".escapeshellarg((string)getenv("WPCC_GATE_TOKEN"));
	$updated=preg_replace("/^export WPCC_TOKEN=.*$/m",$line,$data,1,$count);if($updated===null||$count!==1)exit(3);
	$tmp=tempnam(dirname($file),".wpcc-env-");if($tmp===false)exit(4);chmod($tmp,fileperms($file)&0777);
	if(file_put_contents($tmp,$updated)!==strlen($updated)||!rename($tmp,$file)){@unlink($tmp);exit(5);}
' "$ENV_FILE" || exit 2

WPCC_BASE="$(wp --path="$WP_ROOT" eval 'echo untrailingslashit(rest_url("wp-command-center/v1"));' 2>/dev/null)"
[ -n "$WPCC_BASE" ] || { echo 'run-t2-gate: unable to resolve WPCC endpoint.' >&2; exit 2; }
export WPCC_TOKEN="$GATE_TOKEN" WPCC_BASE
echo 'gate credential: created with seven-day expiry and isolated to this gate process'
if [ "$MODE" = "preflight" ]; then
	source "$ROOT/tests/lib/gate-liveness.sh"
	WPCC_GATE_CLOCK_INIT || exit 1
	WPCC_GATE_AUTH_PREFLIGHT
	exit $?
fi
bash "$ROOT/tests/run.sh" --tier T2
