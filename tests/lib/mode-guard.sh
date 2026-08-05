#!/usr/bin/env bash
#
# Protection-mode guard — a test suite must leave the site as it found it.
#
# WHY THIS EXISTS
#
# Roughly twenty suites flip `wpcc_security_mode` to exercise the approval
# gates, and several of them "restored" it by writing back a hardcoded
# `developer`. That is not a restore: on a validation site running Standard
# protection, every one of those runs ended with the site UNPROTECTED — the
# exact state the product's own safety copy exists to warn about. It was found
# twice in one session, by hand, after suite runs silently changed the mode.
#
# The other failure mode is subtler: a suite that restores correctly on its last
# line restores nothing at all if it is interrupted, or if an assertion exits
# early under `set -e`. Cleanup that only runs on the happy path is not cleanup.
#
# WHAT IT DOES
#
#   wpcc_mode_guard_init "<wp --path value>"
#
# captures the mode ONCE, before the suite touches anything, and installs an
# EXIT trap that writes exactly that value back — on success, on failure, on
# Ctrl-C, and on an early exit. It never assumes a default: whatever the site
# was in is what it goes back to.
#
# It also exports WPCC_ORIG_MODE so a suite can assert against the real starting
# mode instead of a hardcoded one ("mode restored to developer" is only true if
# the site happened to start in developer).
#
# TRAP CHAINING. Several suites already install their own EXIT trap for their
# own fixtures. A second `trap ... EXIT` would silently replace the first, so
# this reads the existing handler and composes rather than clobbers.
#
# Usage (after WP_PATH / WP_ROOT is known):
#   source "$SCRIPT_DIR/lib/mode-guard.sh"
#   wpcc_mode_guard_init "$WP_PATH"

# Guard against double-sourcing when a suite is included twice.
if [ -n "${WPCC_MODE_GUARD_LOADED:-}" ]; then
	return 0 2>/dev/null || true
fi
WPCC_MODE_GUARD_LOADED=1

WPCC_MODE_GUARD_PATH=""
WPCC_ORIG_MODE=""

# Restore the captured mode. Idempotent, and silent unless it actually changes
# something — a suite that never touched the mode should print nothing.
wpcc_mode_guard_restore() {
	[ -n "$WPCC_ORIG_MODE" ] || return 0
	[ -n "$WPCC_MODE_GUARD_PATH" ] || return 0

	local now
	now="$( wp --path="$WPCC_MODE_GUARD_PATH" eval \
		'echo get_option( "wpcc_security_mode", "" );' 2>/dev/null )"

	if [ "$now" != "$WPCC_ORIG_MODE" ]; then
		wp --path="$WPCC_MODE_GUARD_PATH" eval \
			"update_option( 'wpcc_security_mode', '${WPCC_ORIG_MODE}' );" >/dev/null 2>&1
		echo "  [mode-guard] protection mode restored: ${now:-unset} -> ${WPCC_ORIG_MODE}"
	fi
}

# Capture the current mode and arm the restore on every exit path.
wpcc_mode_guard_init() {
	WPCC_MODE_GUARD_PATH="${1:-}"
	[ -n "$WPCC_MODE_GUARD_PATH" ] || return 0

	WPCC_ORIG_MODE="$( wp --path="$WPCC_MODE_GUARD_PATH" eval \
		'echo get_option( "wpcc_security_mode", "" );' 2>/dev/null )"

	# Nothing stored yet: there is no original to put back, and inventing one
	# would be the same mistake this guard exists to fix.
	[ -n "$WPCC_ORIG_MODE" ] || return 0
	export WPCC_ORIG_MODE

	# Compose with whatever the suite already installed rather than replacing it.
	local existing
	existing="$( builtin trap -p EXIT | sed -E "s/^trap -- '(.*)' EXIT\$/\1/" )"
	if [ -n "$existing" ]; then
		builtin trap "${existing}; wpcc_mode_guard_restore" EXIT
	else
		builtin trap 'wpcc_mode_guard_restore' EXIT
	fi

	# Interrupts exit through EXIT once these are re-raised.
	builtin trap 'exit 130' INT
	builtin trap 'exit 143' TERM

	# ── Survive a LATER `trap ... EXIT` ─────────────────────────────────────
	#
	# Eight suites install their own EXIT trap well after this point — for their
	# own fixtures — and a second `trap ... EXIT` REPLACES the first rather than
	# adding to it. So the guard was being silently disarmed by the very suites
	# that most needed it, which a full sweep caught: test-change-history-rollback
	# leaked client -> developer despite being wired up.
	#
	# Shadowing the builtin means a suite can keep writing `trap cleanup EXIT`
	# exactly as before and still keep the restore. Everything that is not an
	# EXIT installation passes straight through untouched.
	trap() {
		local arg is_exit=0 handler=""
		for arg in "$@"; do
			[ "$arg" = "EXIT" ] && is_exit=1
		done
		# -p / -l are queries, not installations.
		case "${1:-}" in
			-p|-l|--) builtin trap "$@"; return $? ;;
		esac
		if [ "$is_exit" -eq 1 ]; then
			handler="${1:-}"
			builtin trap "${handler}; wpcc_mode_guard_restore" EXIT
			return 0
		fi
		builtin trap "$@"
	}
}
