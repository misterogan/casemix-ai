#!/bin/sh
set -e
# .env dari bind mount → config:cache per kontainer. route:cache & view:cache sudah dibuat saat build image.
if [ -f .env ]; then
  php artisan config:cache >/dev/null
fi
exec "$@"
