#!/usr/bin/env bash
#
# Bumps the plugin's own version (patch) and inserts a changelog entry noting
# the agt-php-sdk version that triggered it. Pure text manipulation, no
# network calls — kept as a standalone script (rather than inline in the
# bump-sdk.yml workflow step) so it can be exercised by
# tests/shell/bump-plugin-version.sh without needing Composer or GitHub
# Actions.
#
# Usage: bump-plugin-version.sh <sdk_version> [readme.txt] [plugin.php]
# Prints the new plugin version on stdout; everything else goes to stderr.
set -euo pipefail

sdk_version="${1:?usage: bump-plugin-version.sh <sdk_version> [readme.txt] [plugin.php]}"
readme="${2:-readme.txt}"
plugin_php="${3:-agt-sync-for-woocommerce.php}"

current="$(grep -oE '^Stable tag:[[:space:]]*\S+' "$readme" | awk '{print $NF}')"

# Guards the sed/awk below, which all assume a plain X.Y.Z — the only shape
# this plugin has ever shipped (see readme.txt's changelog). A prerelease
# suffix or malformed tag would otherwise silently produce a garbage version
# rather than failing the workflow loudly.
if [[ ! "$current" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "::error::Stable tag '${current}' in ${readme} isn't a plain X.Y.Z version — refusing to auto-bump." >&2
	exit 1
fi

IFS='.' read -r major minor patch <<<"$current"
new="${major}.${minor}.$((patch + 1))"

echo "Bumping plugin ${current} -> ${new} (agt-php-sdk ${sdk_version})" >&2

# Capture-group replace so the header's existing column alignment survives —
# functionally the padding doesn't matter (release.yml's version check trims
# whitespace before comparing), but there's no reason to reformat the file.
sed -i -E "s/^( \* Version:[[:space:]]*).*/\1${new}/" "$plugin_php"
sed -i "s/AGT_SYNC_VERSION', '[^']*'/AGT_SYNC_VERSION', '${new}'/" "$plugin_php"
sed -i "s/^Stable tag:.*/Stable tag: ${new}/" "$readme"

# Discrete awk `print` statements, each producing its own line — NOT a
# variable holding embedded "\n" text passed through printf. Under the bash
# that GitHub Actions steps actually run (not an interactive zsh session),
# `"\n"` in a double-quoted string is the two literal characters backslash-n,
# not a newline; building the entry that way silently wrote literal "\n"
# into readme.txt and broke .github/extract-changelog.sh's line-based parse.
awk -v new="$new" -v sdkver="$sdk_version" '
	{ print }
	/^== Changelog ==$/ && !done {
		print ""
		print "= " new " ="
		print "* Updated the bundled AGT PHP SDK to " sdkver "."
		done = 1
	}
' "$readme" >"${readme}.new"
mv "${readme}.new" "$readme"

echo "$new"
