#!/usr/bin/env bash
# Backup harian database dan berkas klaim Casemix AI -> /usr/local/bin/backup-casemix.sh
# Backup di server yang sama BUKAN pengganti salinan di lokasi lain (NAS/server backup RS).
set -euo pipefail
APP_DIR=/var/www/casemix-ai
DEST=/var/backups/casemix-ai
KEEP_DAYS=14
STAMP="$(date +%F-%H%M)"
install -d -m 700 "$DEST"
umask 077
mysqldump --single-transaction --quick --routines casemix_ai | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/berkas-$STAMP.tar.gz" -C "$APP_DIR/storage/app" private
cp "$APP_DIR/.env" "$DEST/env-$STAMP"
find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete
echo "Backup selesai: $DEST (*-$STAMP)"
