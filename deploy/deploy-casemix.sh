#!/usr/bin/env bash
# Deploy Casemix AI. Disalin ke /usr/local/bin/deploy-casemix.sh dan dipanggil
# GitHub Actions self-hosted runner (label: casemix) setiap push ke main.
# Polanya sama dengan /usr/local/bin/deploy-antrol.sh.
set -euo pipefail
umask 002
PHP=/usr/bin/php8.4
cd /var/www/casemix-ai
OLD=$(git rev-parse HEAD)
git fetch origin main
CHANGED=$(git diff --name-only "$OLD" origin/main)
if echo "$CHANGED" | grep -qE '^(composer\.(json|lock)|database/migrations/)'; then
  echo "Ada perubahan dependensi/migrasi: mode maintenance"
  $PHP artisan down --retry=15 || true
  trap '$PHP artisan up' EXIT
fi
git reset --hard origin/main
$PHP "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction
if [ ! -f public/build/manifest.json ] || echo "$CHANGED" | grep -qE '^(resources/|package(-lock)?\.json|vite\.config\.js)'; then
  echo "Build tampilan (npm)"
  npm ci --no-audit --no-fund
  npm run build
fi
$PHP artisan migrate --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan queue:restart || true
sudo /usr/bin/systemctl reload php8.4-fpm
echo "Deploy selesai: $(git log -1 --format='%h %s')"
