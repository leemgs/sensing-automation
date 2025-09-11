#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html/sensing-app"
SENSING_DIR="/var/www/html/sensing"

echo "==> Updating packages & installing PHP extensions"
sudo apt update -y
sudo apt install -y unzip curl ca-certificates \
  php php-cli php-fpm php-imap php-curl php-mbstring php-xml php-zip

echo "==> Enabling PHP extensions (imap, mbstring)"
sudo phpenmod imap mbstring || true

echo "==> Restarting web server"
if systemctl is-active --quiet apache2; then
  sudo systemctl restart apache2
elif systemctl is-active --quiet php8.3-fpm; then
  sudo systemctl restart php8.3-fpm
fi

echo "==> Creating sensing output directories"
sudo mkdir -p "$SENSING_DIR"
sudo chown -R www-data:www-data "$SENSING_DIR"
sudo chmod -R 775 "$SENSING_DIR"

echo "==> Deploying app to $APP_DIR"
sudo mkdir -p "$APP_DIR"
# If running inside the unzipped folder, copy everything (except .git/venv/tmp)
sudo rsync -a --delete --exclude='.git' --exclude='venv' --exclude='*.zip' ./ "$APP_DIR/"
sudo chown -R www-data:www-data "$APP_DIR"
sudo chmod -R 775 "$APP_DIR"

echo "==> Basic checks"
php -r "echo 'PHP version: '.PHP_VERSION.PHP_EOL;"
php -m | grep -E '^imap$' >/dev/null && echo '[OK] php-imap enabled' || echo '[WARN] php-imap not visible (check php-fpm vs apache SAPI)'
if grep -q 'AAH_API_KEY=' /etc/environment; then
  echo "[OK] /etc/environment has AAH_API_KEY"
else
  echo "[WARN] /etc/environment missing AAH_API_KEY"
  echo "      -> Add:  sudo bash -c 'echo AAH_API_KEY=Base64EncodedBasicTokenHere >> /etc/environment'"
  echo "      -> Then: source /etc/environment && sudo systemctl restart apache2 || sudo systemctl restart php8.3-fpm"
fi

cat <<'NOTE'

Next steps:
 1) Edit IMAP credentials in:  sudo nano /var/www/html/sensing-app/config.php
 2) Ensure AAH_API_KEY is present in /etc/environment (Authorization: Basic <token>)
 3) Open:  http://<SERVER>/sensing-app/index.php
 4) Click a mail → select article links → "선택 링크 분석 & 저장"

Troubleshooting:
  - If /var/www/html/sensing is not writable, the app saves to /var/www/html/sensing-app/sensing_out
  - Check logs: /var/www/html/sensing-app/sensing.log
NOTE

echo "==> Done."
