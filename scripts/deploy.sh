#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/p-stream}"
BRANCH="${BRANCH:-production}"

if [ ! -d "$APP_DIR/.git" ]; then
  echo "Error: $APP_DIR is not a git repo. Run scripts/setup-vps.sh first."
  exit 1
fi

cd "$APP_DIR"

echo "==> Pulling latest $BRANCH..."
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

echo "==> Building and starting containers..."
docker compose up -d --build

echo "==> Cleaning old images..."
docker image prune -f

IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo ""
echo "Deploy complete."
echo "Site: http://${IP:-localhost}"
