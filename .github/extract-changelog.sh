#!/usr/bin/env bash
#
# Pull one version's notes out of readme.txt's == Changelog == section.
#
# The WordPress.org changelog is the single source of truth for what shipped in a
# release, so the GitHub Release notes are generated FROM it rather than written
# twice and left to drift. Given a version, this prints the bullet list under its
# "= <version> =" heading, stopping at the next "= ..." heading or the next
# top-level "== ..." section.
#
# Usage: extract-changelog.sh <version> [readme.txt]
#
set -euo pipefail

version="${1:?usage: extract-changelog.sh <version> [readme.txt]}"
readme="${2:-readme.txt}"

awk -v ver="$version" '
	# Enter the changelog section.
	/^== Changelog ==/ { in_changelog = 1; next }
	# Leave it at the next top-level section.
	in_changelog && /^== / { exit }

	# A version heading like "= 1.2.0 =".
	in_changelog && /^= / {
		# Strip the "= " and " =" to get the bare version.
		line = $0
		gsub(/^= +| +=$/, "", line)
		printing = (line == ver)
		next
	}

	printing { print }
' "$readme" | sed '/^[[:space:]]*$/d'
