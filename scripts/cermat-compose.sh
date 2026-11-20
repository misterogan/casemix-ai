#!/usr/bin/env bash
# Pembungkus `docker compose` untuk CERMAT: isi CERMAT_UID/GID (user oem) dan CERMAT_PORT (dari /srv/cermat/.env).
# Contoh: scripts/cermat-compose.sh ps | up -d | exec -T app php artisan migrate --force
set -euo pipefail
cd "$(dirname "$0")/.."
export CERMAT_DIR="${CERMAT_DIR:-/srv/cermat}"
export CERMAT_UID="${CERMAT_UID:-$(id -u oem)}" CERMAT_GID="${CERMAT_GID:-$(id -g oem)}"
if [ -z "${CERMAT_PORT:-}" ] && [ -r "$CERMAT_DIR/.env" ]; then
  CERMAT_PORT="$(sed -n 's/^CERMAT_PORT=//p' "$CERMAT_DIR/.env" | tail -n1 | tr -d "\r\"' ")"
fi
export CERMAT_PORT="${CERMAT_PORT:-8310}"
exec docker compose "$@"
