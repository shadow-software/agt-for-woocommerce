#!/usr/bin/env bash
# Publish the current, verified plugin release to WordPress.org SVN from a laptop.
# Credentials come from SVN_USERNAME/SVN_PASSWORD or SVN_CREDENTIALS_FILE.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="agt-sync-for-woocommerce"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
VERSION="${1:-$(awk '/^Stable tag:/{print $3; exit}' "$ROOT/readme.txt")}"
DRY_RUN="${DRY_RUN:-0}"
CREDS_FILE="${SVN_CREDENTIALS_FILE:-$ROOT/../000-creds/.env.wordpress.org}"

if [[ -z "${SVN_USERNAME:-}" || -z "${SVN_PASSWORD:-}" ]]; then
	if [[ ! -r "$CREDS_FILE" ]]; then
		echo "Missing SVN credentials. Set SVN_USERNAME/SVN_PASSWORD or SVN_CREDENTIALS_FILE." >&2
		exit 2
	fi
	SVN_USERNAME="$(awk -F= '$1=="USERNAME"{print substr($0,index($0,"=")+1)}' "$CREDS_FILE")"
	SVN_PASSWORD="$(awk -F= '$1=="PASSWORD"{print substr($0,index($0,"=")+1)}' "$CREDS_FILE")"
fi

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Invalid release version: $VERSION" >&2; exit 2; }
header="$(grep -oE '^\s*\*\s*Version:\s*\S+' "$ROOT/agt-sync-for-woocommerce.php" | awk '{print $NF}')"
stable="$(awk '/^Stable tag:/{print $3; exit}' "$ROOT/readme.txt")"
[[ "$header" == "$VERSION" && "$stable" == "$VERSION" ]] || {
	echo "Version mismatch: header=$header stable=$stable requested=$VERSION" >&2
	exit 1
}

tmp="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.svn.XXXXXX")"
cleanup() { rm -rf "$tmp"; }
trap cleanup EXIT

echo "Building release layout for ${SLUG} ${VERSION}..."
composer install --no-dev --prefer-dist --no-progress --no-interaction --working-dir="$ROOT"
layout="$tmp/$SLUG"
bash "$ROOT/.github/scripts/build-release-layout.sh" "$layout" >/dev/null
test -f "$layout/agt-sync-for-woocommerce.php"
test -f "$layout/readme.txt"
if find "$layout" -type f \( -name 'git_push.sh' -o -path '*/.openapi-generator/*' \) | grep -q .; then
	echo "Release layout contains forbidden generator artifacts." >&2
	exit 1
fi

svn_config="$tmp/svn-config"
mkdir -m 700 "$svn_config"
# Let SVN read the password from stdin once and cache it only inside the
# throwaway config directory. Passing --password would expose it in `ps`.
printf '%s\n' "$SVN_PASSWORD" | svn --config-dir "$svn_config" --username "$SVN_USERNAME" --trust-server-cert checkout "$SVN_URL" "$tmp/wc" >/dev/null
auth=(--config-dir "$svn_config" --username "$SVN_USERNAME" --non-interactive --trust-server-cert)
mkdir -p "$tmp/wc/trunk" "$tmp/wc/assets" "$tmp/wc/tags"
rsync -a --delete --exclude='.svn' "$layout/" "$tmp/wc/trunk/"
rsync -a --delete --exclude='.svn' "$ROOT/.wordpress-org/" "$tmp/wc/assets/"
svn add --force "$tmp/wc/trunk" "$tmp/wc/assets" "$tmp/wc/tags" >/dev/null 2>&1 || true
svn status "$tmp/wc"

if [[ "$DRY_RUN" == 1 ]]; then
	echo "DRY RUN: no SVN commit performed."
	exit 0
fi

if svn list "${auth[@]}" "$SVN_URL/tags/$VERSION" >/dev/null 2>&1; then
	echo "SVN tag already exists: $VERSION (refusing to overwrite)." >&2
	exit 1
fi

svn commit "${auth[@]}" "$tmp/wc" -m "Release ${SLUG} ${VERSION}" >/dev/null
svn copy "${auth[@]}" "$SVN_URL/trunk" "$SVN_URL/tags/$VERSION" -m "Tag ${SLUG} ${VERSION}" >/dev/null
echo "Published ${SLUG} ${VERSION} to ${SVN_URL} (trunk + tags/${VERSION} + assets)."
