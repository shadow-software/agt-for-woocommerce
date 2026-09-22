#!/usr/bin/env bash
set -euo pipefail

HOST="${SMOKE_SSH_HOST:?}"
WP_PATH="${SMOKE_WP_PATH:?}"
SCRIPT="${1:?path to run-smoke.php}"

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o BatchMode=yes)
if [[ -n "${SMOKE_SSH_KEY:-}" ]]; then
	SSH_OPTS+=(-i "$SMOKE_SSH_KEY")
fi

REMOTE="/tmp/live-smoke-$$.php"
scp "${SSH_OPTS[@]}" "$SCRIPT" "${HOST}:${REMOTE}"

ssh "${SSH_OPTS[@]}" "$HOST" \
	"wp --path='${WP_PATH}' --allow-root eval-file '${REMOTE}' && rm -f '${REMOTE}'"
