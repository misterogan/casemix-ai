#!/usr/bin/env bash
# Casemix AI — backup harian database dan berkas klaim. Dipanggil timer casemix-backup.
# Backup di server yang sama BUKAN pengganti backup ke lokasi lain: salin folder DEST
# ke NAS/server backup RS sesuai kebijakan TI.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/casemix-ai}"
DB_NAME="${DB_NAME:-casemix_ai}"
DEST="${DEST:-/var/backups/casemix-ai}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%F-%H%M)"

install -d -m 700 "$DEST"
umask 077

mysqldump --single-transaction --quick --routines "$DB_NAME" | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/berkas-$STAMP.tar.gz" -C "$APP_DIR/storage/app" private
cp "$APP_DIR/.env" "$DEST/env-$STAMP"

find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete
echo "Backup selesai: $DEST (*-$STAMP)"
