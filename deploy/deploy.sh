#!/usr/bin/env bash
# =============================================================================
# Casemix AI — pembaruan aplikasi dari Git
#   sudo bash deploy/deploy.sh            pasang bila ada versi baru
#   sudo bash deploy/deploy.sh --force    pasang ulang penuh walau tidak ada versi baru
#   sudo bash deploy/deploy.sh --reload   hanya muat ulang .env dan worker (setelah mengubah .env)
#   (timer systemd memanggil dengan --auto setiap 5 menit, tanpa output bila tidak ada perubahan)
# =============================================================================
set -Eeuo pipefail

# Jalankan dari salinan sementara: git reset di bawah bisa mengubah file skrip ini saat sedang berjalan.
if [[ "${CASEMIX_DEPLOY_COPY:-}" != "1" ]]; then
  tmp="$(mktemp /tmp/casemix-deploy.XXXXXX)"; cp "$0" "$tmp"
  CASEMIX_DEPLOY_COPY=1 exec bash "$tmp" "$@"
fi
trap 'rm -f "$0"' EXIT

APP_DIR="${APP_DIR:-/var/www/casemix-ai}"
APP_USER="${APP_USER:-casemix}"
BRANCH="${BRANCH:-main}"
MODE="${1:-}"
LOG=/var/log/casemix-deploy.log

[[ $EUID -eq 0 ]] || { echo "Jalankan dengan sudo."; exit 1; }
exec 9>/var/lock/casemix-deploy.lock
flock -n 9 || { echo "Deploy lain sedang berjalan."; exit 0; }

as_app() { runuser -u "$APP_USER" -- "$@"; }
as_web() { runuser -u www-data -- "$@"; }
log()    { echo "[$(date '+%F %T')] $*" | tee -a "$LOG"; }
PHP_BIN="${PHP_BIN:-/usr/bin/php8.3}"
COMPOSER="${COMPOSER:-/usr/local/bin/composer}"
artisan() { as_web "$PHP_BIN" "$APP_DIR/artisan" "$@"; }

cd "$APP_DIR"

if [[ "$MODE" == "--reload" ]]; then
  artisan optimize:clear >/dev/null
  artisan optimize
  artisan queue:restart
  log "Konfigurasi dimuat ulang."
  exit 0
fi

as_app git fetch --quiet origin "$BRANCH"
LOCAL="$(as_app git rev-parse HEAD)"
REMOTE="$(as_app git rev-parse "origin/$BRANCH")"

if [[ "$LOCAL" == "$REMOTE" && "$MODE" != "--force" ]]; then
  [[ "$MODE" == "--auto" ]] || log "Sudah versi terbaru ($(as_app git log -1 --format='%h %s'))."
  exit 0
fi

log "Mulai deploy ${LOCAL:0:7} -> ${REMOTE:0:7}"
on_error() {
  log "GAGAL pada baris $1. Aplikasi dihidupkan kembali. Periksa pesan di atas dan $LOG."
  artisan up >/dev/null 2>&1 || true
}
trap 'on_error $LINENO' ERR

artisan down --retry=30 >/dev/null 2>&1 || true

as_app git reset --hard --quiet "origin/$BRANCH"

as_app "$PHP_BIN" "$COMPOSER" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --no-progress
chown -R www-data:www-data storage bootstrap/cache
chmod -R o-rwx storage
artisan package:discover --ansi >/dev/null

# Build tampilan hanya bila Blade/CSS/JS/paket npm berubah, atau belum pernah dibuild
CHANGED_UI="$(as_app git diff --name-only "$LOCAL" "$REMOTE" -- resources package.json package-lock.json vite.config.js | wc -l)"
if [[ "$CHANGED_UI" -gt 0 || ! -f public/build/manifest.json || "$MODE" == "--force" ]]; then
  log "Build tampilan (npm)"
  if [[ -f package-lock.json ]]; then as_app npm ci --no-audit --no-fund; else as_app npm install --no-audit --no-fund; fi
  as_app npm run build
fi

artisan migrate --force
artisan optimize:clear >/dev/null
artisan optimize
artisan queue:restart
artisan up

log "Selesai: $(as_app git log -1 --format='%h %s (%an, %ar)')"
