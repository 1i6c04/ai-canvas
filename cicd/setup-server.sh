#!/usr/bin/env bash
# =============================================================================
# setup-server.sh — Run ONCE on a fresh EC2 Ubuntu 22.04 instance as root/sudo
# =============================================================================
set -euo pipefail

export NEEDRESTART_MODE=a
export DEBIAN_FRONTEND=noninteractive

DEPLOY_USER="${1:-ubuntu}"
DEPLOY_PATH="${2:-/var/www/ai-canvas}"

echo "==> [1/6] Updating system packages..."
apt-get update -y
apt-get upgrade -y

echo "==> [2/6] Installing PHP 8.1 + extensions..."
apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
  php8.1 \
  php8.1-fpm \
  php8.1-sqlite3 \
  php8.1-curl \
  php8.1-mbstring \
  php8.1-xml

echo "==> [3/6] Installing Nginx and Certbot..."
apt-get install -y nginx certbot python3-certbot-nginx

echo "==> [4/6] Creating deploy directory..."
mkdir -p "$DEPLOY_PATH"
chown -R "$DEPLOY_USER":www-data "$DEPLOY_PATH"
chmod 775 "$DEPLOY_PATH"

echo "==> [5/6] Configuring Nginx site..."
cp "$(dirname "$0")/nginx.conf" /etc/nginx/sites-available/ai-canvas
ln -sf /etc/nginx/sites-available/ai-canvas /etc/nginx/sites-enabled/ai-canvas
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl enable nginx
systemctl restart nginx

echo "==> [6/6] Enabling PHP-FPM..."
systemctl enable php8.1-fpm
systemctl start php8.1-fpm

echo ""
echo "============================================================"
echo "  Server setup complete!"
echo "============================================================"
echo ""
echo "Next steps:"
echo "  1. Upload your .env file to: $DEPLOY_PATH/.env"
echo "  2. Edit /etc/nginx/sites-available/ai-canvas — replace YOUR_DOMAIN"
echo "  3. Run: sudo certbot --nginx -d yourdomain.com"
echo "  4. Push to GitHub main branch to trigger first deploy"
echo ""
