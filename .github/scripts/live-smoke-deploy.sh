#!/usr/bin/env bash
set -euo pipefail

HOST="${SMOKE_SSH_HOST:?SMOKE_SSH_HOST required}"
WP_PATH="${SMOKE_WP_PATH:?SMOKE_WP_PATH required}"
SLUG="${SMOKE_PLUGIN_SLUG:?SMOKE_PLUGIN_SLUG required}"
ZIP="${SMOKE_ZIP_FILE:?SMOKE_ZIP_FILE required}"
ROLLBACK="${SMOKE_ROLLBACK_ROOT:-/var/www/_plugin_rollbacks}"

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o BatchMode=yes)
if [[ -n "${SMOKE_SSH_KEY:-}" ]]; then
	SSH_OPTS+=(-i "$SMOKE_SSH_KEY")
fi

REMOTE_ZIP="/tmp/${SLUG}-deploy-$$.zip"
PLUGIN_DIR="${WP_PATH}/wp-content/plugins/${SLUG}"
TS="$(date +%Y%m%d-%H%M%S)"
BACKUP="${ROLLBACK}/${SLUG}-${TS}"

echo "── Deploy ${SLUG} → ${HOST}:${PLUGIN_DIR} ──"

scp "${SSH_OPTS[@]}" "$ZIP" "${HOST}:${REMOTE_ZIP}"

SMOKE_ROLLBACK_PATH="$(
	ssh "${SSH_OPTS[@]}" "$HOST" bash -s <<REMOTE
set -euo pipefail
WP_PATH='${WP_PATH}'
SLUG='${SLUG}'
REMOTE_ZIP='${REMOTE_ZIP}'
PLUGIN_DIR='${PLUGIN_DIR}'
BACKUP='${BACKUP}'
ROLLBACK='${ROLLBACK}'

rollback() {
	if [[ -d "\$BACKUP" ]]; then
		echo "ROLLBACK: restoring \$BACKUP" >&2
		rm -rf "\$PLUGIN_DIR"
		cp -a "\$BACKUP" "\$PLUGIN_DIR"
		wp --path="\$WP_PATH" --allow-root plugin activate "\$SLUG" --quiet 2>/dev/null || true
	fi
}
trap rollback ERR

mkdir -p "\$ROLLBACK"
if [[ -d "\$PLUGIN_DIR" ]]; then
	cp -a "\$PLUGIN_DIR" "\$BACKUP"
	echo "Backed up to \$BACKUP"
fi

STAGE="\${REMOTE_ZIP%.zip}-stage"
rm -rf "\$STAGE" "\$PLUGIN_DIR"
mkdir -p "\$STAGE"
unzip -q "\$REMOTE_ZIP" -d "\$STAGE"

if [[ -d "\$STAGE/\$SLUG" ]]; then
	mkdir -p "\$PLUGIN_DIR"
	shopt -s dotglob
	mv "\$STAGE/\$SLUG"/* "\$PLUGIN_DIR/"
else
	mv "\$STAGE" "\$PLUGIN_DIR"
fi
rm -rf "\$STAGE" "\$REMOTE_ZIP"

wp --path="\$WP_PATH" --allow-root plugin activate "\$SLUG" --quiet
echo "\$BACKUP"
REMOTE
)"

echo "Deploy OK. Rollback snapshot: ${SMOKE_ROLLBACK_PATH}"
if [[ -n "${GITHUB_ENV:-}" ]]; then
	echo "SMOKE_ROLLBACK_PATH=${SMOKE_ROLLBACK_PATH}" >>"$GITHUB_ENV"
fi
