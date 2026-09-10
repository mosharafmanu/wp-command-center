#!/usr/bin/env bash
# Byte-level candidate-tree manifests for release-gate source-drift detection.
# Includes every tracked path plus every non-ignored untracked path, while
# naturally excluding ignored/runtime temp files.

WPCC_SOURCE_MANIFEST() {
	local output="$1" root="${2:-$ROOT}" candidate digest
	: > "$output" || return 1
	while IFS= read -r candidate; do
		[ -n "$candidate" ] || continue
		if [ -f "$root/$candidate" ] || [ -L "$root/$candidate" ]; then
			digest="$(shasum -a 256 -- "$root/$candidate" | awk '{print $1}')" || return 1
		else
			digest="MISSING"
		fi
		printf '%s\t%s\n' "$digest" "$candidate" >> "$output" || return 1
	done < <(git -C "$root" ls-files -c -o --exclude-standard --deduplicate | LC_ALL=C sort)
}

WPCC_SOURCE_FINGERPRINT() {
	local manifest="$1"
	shasum -a 256 -- "$manifest" | awk '{print $1}'
}

WPCC_SOURCE_ASSERT_IDENTICAL() {
	local before="$1" after="$2" changed
	if cmp -s "$before" "$after"; then
		return 0
	fi
	changed="$(diff -u "$before" "$after" | awk '
		/^[+-]/ && $0 !~ /^(\+\+\+|---)/ {
			line = substr($0, 2); tab = index(line, "\t");
			if (tab) { path = substr(line, tab + 1); if (!seen[path]++) print path; }
		}' || true)"
	printf '%s\n' 'tracked/untracked candidate source drift detected:' >&2
	printf '  %s\n' "$changed" >&2
	return 1
}
