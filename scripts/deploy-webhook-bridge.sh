#!/usr/bin/env bash
set -euo pipefail

HOST="${1:-}"
WP_PATH="${2:-}"

if [[ -z "$HOST" || -z "$WP_PATH" ]]; then
  echo "Usage: $0 <ssh-host> <wp-path-on-server>"
  echo "Example: $0 julia /var/www/site/public"
  exit 1
fi

PLUGIN_DIR="wp-plugins/webhook-bridge"
REMOTE_PLUGIN_PATH="${WP_PATH}/wp-content/plugins/webhook-bridge"

ssh "$HOST" "sudo mkdir -p '$REMOTE_PLUGIN_PATH' && sudo chown -R \$(whoami):\$(whoami) '$WP_PATH/wp-content/plugins' || true"

rsync -avz --delete "${PLUGIN_DIR}/" "${HOST}:${REMOTE_PLUGIN_PATH}/"

echo "Deployed to: ${HOST}:${REMOTE_PLUGIN_PATH}"
echo "Next: activate plugin in WP admin or via wp-cli."
