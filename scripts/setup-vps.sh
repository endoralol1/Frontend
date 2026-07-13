#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/p-stream}"
REPO_URL="${REPO_URL:-https://github.com/endoralol1/Frontend.git}"
BRANCH="${BRANCH:-production}"
DEPLOY_KEY="/root/.ssh/github_actions_deploy"

echo "==> Installing dependencies..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git docker.io docker-compose-plugin curl

systemctl enable docker
systemctl start docker

echo "==> Cloning repository..."
mkdir -p "$(dirname "$APP_DIR")"
if [ ! -d "$APP_DIR/.git" ]; then
  git clone -b "$BRANCH" "$REPO_URL" "$APP_DIR"
else
  echo "Repository already exists at $APP_DIR"
fi

chmod +x "$APP_DIR"/scripts/*.sh

echo "==> First deploy..."
APP_DIR="$APP_DIR" BRANCH="$BRANCH" bash "$APP_DIR/scripts/deploy.sh"

echo "==> Setting up GitHub Actions SSH key..."
mkdir -p /root/.ssh
chmod 700 /root/.ssh

if [ ! -f "$DEPLOY_KEY" ]; then
  ssh-keygen -t ed25519 -f "$DEPLOY_KEY" -N "" -C "github-actions-deploy"
fi

if ! grep -qF "$(cat "${DEPLOY_KEY}.pub")" /root/.ssh/authorized_keys 2>/dev/null; then
  cat "${DEPLOY_KEY}.pub" >> /root/.ssh/authorized_keys
  chmod 600 /root/.ssh/authorized_keys
fi

VPS_IP="$(curl -s --max-time 5 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')"

echo ""
echo "============================================"
echo "  VPS setup done. One last step on GitHub:"
echo "============================================"
echo ""
echo "Go to: https://github.com/endoralol1/Frontend/settings/secrets/actions"
echo ""
echo "Add these 3 secrets:"
echo ""
echo "  VPS_HOST     = ${VPS_IP}"
echo "  VPS_USER     = root"
echo "  VPS_SSH_KEY  = (copy the private key below)"
echo ""
echo "----- BEGIN PRIVATE KEY -----"
cat "$DEPLOY_KEY"
echo "----- END PRIVATE KEY -----"
echo ""
echo "Then enable auto-deploy:"
echo "  GitHub → Settings → Secrets and variables → Actions → Variables"
echo "  Add: VPS_DEPLOY_ENABLED = true"
echo ""
echo "After that, every push to production auto-deploys."
echo "Manual update: cd $APP_DIR && ./scripts/deploy.sh"
echo ""
