#!/bin/sh
set -e
# .env dari bind mount; cache konfigurasi per kontainer (bootstrap/cache ada di image, bukan bind mount).
if [ -f .env ]; then
  php artisan config:cache >/dev/null
  php artisan route:cache >/dev/null
  php artisan view:cache >/dev/null || true
fi
exec "$@"
