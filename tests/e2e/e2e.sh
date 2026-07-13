#!/usr/bin/env bash
#
# End-to-end test harness for AGT Sync for WooCommerce.
#
# Drives the plugin's REAL classes, inside a REAL WordPress, against a REAL
# American Gun Trader. Seeds an FFL dealer on AGT, mints a token, points the
# plugin at AGT, and runs tests/e2e/run-e2e.php.
#
# Usage:
#   AGT_REPO=/path/to/americanguntrader.com \
#   WP_PATH=/path/to/wordpress \
#   AGT_BASE=http://localhost:8082 \
#   tests/e2e/e2e.sh
#
set -euo pipefail

AGT_REPO="${AGT_REPO:-/home/shadow/Source/americanguntrader.com}"
WP_PATH="${WP_PATH:-/tmp/pc-wp}"
AGT_BASE="${AGT_BASE:-http://localhost:8082}"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo "── AGT Sync E2E ──"
echo "  AGT repo : $AGT_REPO"
echo "  WP path  : $WP_PATH"
echo "  AGT base : $AGT_BASE"
echo

# 1. Is AGT actually up?
if ! curl -fsS -o /dev/null --max-time 5 "$AGT_BASE/.well-known/oauth-authorization-server/dealer"; then
	echo "AGT is not answering on $AGT_BASE — start it (php artisan serve --port=8082) first." >&2
	exit 1
fi

# 2. Seed a deterministic FFL dealer + token on AGT.
echo "Seeding dealer on AGT…"
SEED_JSON="$(cd "$AGT_REPO" && php artisan dealer:e2e-seed --reset --token --json)"

CLIENT_ID="$(echo "$SEED_JSON"   | php -r 'echo json_decode(stream_get_contents(STDIN),true)["client_id"];')"
ACCESS="$(echo "$SEED_JSON"      | php -r 'echo json_decode(stream_get_contents(STDIN),true)["access_token"];')"
REFRESH="$(echo "$SEED_JSON"     | php -r 'echo json_decode(stream_get_contents(STDIN),true)["refresh_token"];')"
FIREARMS="$(echo "$SEED_JSON"    | php -r 'echo json_decode(stream_get_contents(STDIN),true)["firearms_category_id"];')"

if [ -z "$ACCESS" ]; then echo "Seed failed: no access token." >&2; exit 1; fi
echo "  dealer + token seeded."
echo

# 3. Sync the working-tree plugin into the WP install (runtime files only).
DEST="$WP_PATH/wp-content/plugins/agt-sync-for-woocommerce"
rm -rf "$DEST"; mkdir -p "$DEST"
cp -r "$PLUGIN_DIR/includes" "$DEST/"
cp "$PLUGIN_DIR/agt-sync-for-woocommerce.php" "$PLUGIN_DIR/uninstall.php" "$DEST/"

# 4. Point the plugin at AGT via wp-config (constant), and run the tests.
# NOTE: a string constant must be set WITHOUT --raw, or wp-config gets an
# unquoted (invalid-PHP) value that breaks the whole install.
wp --path="$WP_PATH" config set AGT_SYNC_API_BASE "$AGT_BASE" --type=constant

# WooCommerce must be active for WC_Product; the plugin must be active so its
# classes autoload inside eval-file.
wp --path="$WP_PATH" plugin activate woocommerce agt-sync-for-woocommerce >/dev/null 2>&1 || true

echo "Running E2E suite…"
echo

AGT_SYNC_API_BASE="$AGT_BASE" \
AGT_E2E_CLIENT_ID="$CLIENT_ID" \
AGT_E2E_ACCESS_TOKEN="$ACCESS" \
AGT_E2E_REFRESH_TOKEN="$REFRESH" \
AGT_E2E_FIREARMS_CATEGORY_ID="$FIREARMS" \
	wp --path="$WP_PATH" eval-file "$PLUGIN_DIR/tests/e2e/run-e2e.php"
