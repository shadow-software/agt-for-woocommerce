#!/usr/bin/env bash
set -euo pipefail

HOST="${SMOKE_SSH_HOST:?}"
WP_PATH="${SMOKE_WP_PATH:?}"
SLUG="${SMOKE_PLUGIN_SLUG:?}"
BACKUP="${SMOKE_ROLLBACK_PATH:?}"

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o BatchMode=yes)
if [[ -n "${SMOKE_SSH_KEY:-}" ]]; then
	SSH_OPTS+=(-i "$SMOKE_SSH_KEY")
fi

PLUGIN_DIR="${WP_PATH}/wp-content/plugins/${SLUG}"

ssh "${SSH_OPTS[@]}" "$HOST" bash -s <<REMOTE
set -euo pipefail
if [[ ! -d '${BACKUP}' ]]; then
	echo "Rollback path missing: ${BACKUP}" >&2
	exit 1
fi
rm -rf '${PLUGIN_DIR}'
cp -a '${BACKUP}' '${PLUGIN_DIR}'
wp --path='${WP_PATH}' --allow-root plugin activate '${SLUG}' --quiet
echo "Rollback complete."
REMOTE
