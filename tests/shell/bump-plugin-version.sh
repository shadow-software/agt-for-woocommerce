#!/usr/bin/env bash
#
# Regression test for .github/scripts/bump-plugin-version.sh, run under real
# bash (not an interactive shell) to match what GitHub Actions' `run:` steps
# actually execute — the exact gap that let a literal "\n"-in-double-quotes
# bug through review once already. No test framework: plain assertions,
# fails loudly with a diff/grep on mismatch.
#
# Usage: bash tests/shell/bump-plugin-version.sh
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="${repo_root}/.github/scripts/bump-plugin-version.sh"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

fail() {
	echo "FAIL: $1" >&2
	exit 1
}

# --- Fixture: a minimal readme.txt + plugin header shaped like the real ones ---
cat >"${work}/readme.txt" <<'EOF'
=== AGT Sync for WooCommerce ===
Stable tag: 1.0.1

== Changelog ==

= 1.0.1 =
* Host allowlist for AGT API / OAuth.

= 1.0.0 =
* Initial release.
EOF

cat >"${work}/plugin.php" <<'EOF'
<?php
/**
 * Plugin Name:       AGT Sync for WooCommerce
 * Version:           1.0.1
 */
define( 'AGT_SYNC_VERSION', '1.0.1' );
EOF

# --- Run ---
new_version="$(bash "$script" "0.3.0" "${work}/readme.txt" "${work}/plugin.php" 2>"${work}/stderr")"

# --- Assertions ---
[ "$new_version" = "1.0.2" ] || fail "expected new version 1.0.2 on stdout, got '${new_version}'"

grep -q "^Stable tag: 1.0.2\$" "${work}/readme.txt" || fail "readme.txt Stable tag was not bumped"
grep -q "^ \* Version:           1.0.2\$" "${work}/plugin.php" || fail "plugin header Version was not bumped"
grep -q "AGT_SYNC_VERSION', '1.0.2'" "${work}/plugin.php" || fail "AGT_SYNC_VERSION constant was not bumped"

# The bug this test exists to catch: a literal backslash-n in the file
# instead of a real line break.
grep -q '\\n' "${work}/readme.txt" && fail "readme.txt contains a literal backslash-n — changelog insertion regressed"

# The new changelog entry must be a real, separately-parseable heading — this
# is what .github/extract-changelog.sh relies on for GitHub Release notes.
notes="$(bash "${repo_root}/.github/extract-changelog.sh" "1.0.2" "${work}/readme.txt")"
[ "$notes" = "* Updated the bundled AGT PHP SDK to 0.3.0." ] || fail "extract-changelog.sh didn't find a clean 1.0.2 entry, got: '${notes}'"

# The existing 1.0.1 entry must survive untouched, with exactly one entry
# between it and the new heading (no doubled/missing blank lines).
old_notes="$(bash "${repo_root}/.github/extract-changelog.sh" "1.0.1" "${work}/readme.txt")"
[ "$old_notes" = "* Host allowlist for AGT API / OAuth." ] || fail "the pre-existing 1.0.1 changelog entry was corrupted, got: '${old_notes}'"

# --- Guard: a malformed Stable tag must fail loudly, not silently misbump ---
cat >"${work}/bad-readme.txt" <<'EOF'
Stable tag: 1.0.1-beta

== Changelog ==

= 1.0.1-beta =
* Beta.
EOF

if bash "$script" "0.3.0" "${work}/bad-readme.txt" "${work}/plugin.php" >/dev/null 2>"${work}/bad-stderr"; then
	fail "expected bump-plugin-version.sh to reject a non-X.Y.Z Stable tag"
fi
grep -q "refusing to auto-bump" "${work}/bad-stderr" || fail "expected a clear error message for the malformed tag, got: $(cat "${work}/bad-stderr")"

echo "OK: bump-plugin-version.sh — all assertions passed"
