#!/usr/bin/env bash
# Backup harian CERMAT (Docker): dump DB kontainer + tar storage/.env/mysql.env ke /srv/cermat/backup, simpan 14 hari.
# Crontab oem (crontab -e):
#   30 1 * * * /var/www/casemix-ai/scripts/backup-cermat.sh >> /srv/cermat/backup/backup.log 2>&1
set -euo pipefail
umask 077
CERMAT_DIR="${CERMAT_DIR:-/srv/cermat}"
DEST="$CERMAT_DIR/backup"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%F-%H%M)"
COMPOSE="$(dirname "$(readlink -f "$0")")/cermat-compose.sh"
mkdir -p "$DEST"

db="$DEST/cermat-db-$STAMP.sql.gz"
"$COMPOSE" exec -T mysql sh -c \
  'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines --triggers "$MYSQL_DATABASE"' \
  | gzip > "$db.tmp"
mv "$db.tmp" "$db"

files="$DEST/cermat-files-$STAMP.tgz"
tar czf "$files.tmp" -C "$CERMAT_DIR" \
  --exclude='storage/framework/cache/data' --exclude='storage/framework/views' --exclude='storage/framework/sessions' \
  storage .env mysql.env
mv "$files.tmp" "$files"

chmod 600 "$db" "$files"
find "$DEST" -maxdepth 1 -type f \( -name 'cermat-db-*.sql.gz' -o -name 'cermat-files-*.tgz' \) -mtime +$((KEEP_DAYS - 1)) -delete
echo "$(date '+%F %T') OK $(du -h "$db" | cut -f1) $(du -h "$files" | cut -f1)"
