#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SLUG="${PLUGIN_SLUG:-agt-sync-for-woocommerce}"
OUT="${1:-${RUNNER_TEMP:-/tmp}/${SLUG}}"

mkdir -p "$OUT"
rsync -a --exclude-from="${ROOT}/.distignore" "${ROOT}/" "$OUT/"
bash "${ROOT}/.github/prune-vendor-dev.sh" "$OUT"
test -f "$OUT/vendor/autoload.php"
test -f "$OUT/agt-sync-for-woocommerce.php"
echo "$OUT"
