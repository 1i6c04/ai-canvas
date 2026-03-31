#!/usr/bin/env bash
# =============================================================================
# deploy.sh — Post-deploy script, runs on EC2 after every rsync
# Called automatically by GitHub Actions via SSH
# =============================================================================
set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-/var/www/ai-canvas}"
DEPLOY_USER="${DEPLOY_USER:-ubuntu}"

cd "$DEPLOY_PATH"

echo "==> Fixing ownership and permissions..."
# Directories: owner can rwx, www-data can rwx (for SQLite writes)
sudo chown -R "$DEPLOY_USER":www-data .
sudo find . -type d -exec chmod 775 {} +
# Files: owner can rw, www-data can rw
sudo find . -type f -exec chmod 664 {} +
# Shell scripts must be executable
sudo chmod +x cicd/*.sh

echo "==> Ensuring SQLite database is writable..."
if [ -f canvas.sqlite ]; then
    sudo chmod 664 canvas.sqlite
    sudo chown "$DEPLOY_USER":www-data canvas.sqlite
else
    # Create empty file now so www-data can write to it later
    touch canvas.sqlite
    sudo chmod 664 canvas.sqlite
    sudo chown "$DEPLOY_USER":www-data canvas.sqlite
    echo "    Created empty canvas.sqlite (will be initialised on first request)"
fi

echo "==> Testing Nginx config..."
sudo nginx -t

echo "==> Reloading Nginx..."
sudo systemctl reload nginx

echo ""
echo "Deploy complete."
