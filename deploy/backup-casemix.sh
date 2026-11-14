#!/usr/bin/env bash
# Backup harian database dan berkas klaim Casemix AI -> /usr/local/bin/backup-casemix.sh
# Backup di server yang sama BUKAN pengganti salinan di lokasi lain (NAS/server backup RS).
#
# Enkripsi: isi BACKUP_GPG_RECIPIENT dengan ID kunci publik GPG milik pengelola backup RS.
# Kunci privatnya disimpan di luar server ini. Tanpa itu, siapa pun yang mendapat file backup
# bisa membaca rekam medis, dan .env (APP_KEY) membuka API key penyedia AI di database.
#   gpg --import kunci-publik-backup.asc
#   echo 'BACKUP_GPG_RECIPIENT=backup@rs.local' | sudo tee /etc/default/casemix-backup
set -euo pipefail
[ -f /etc/default/casemix-backup ] && . /etc/default/casemix-backup
APP_DIR=/var/www/casemix-ai
DEST=/var/backups/casemix-ai
KEEP_DAYS=14
STAMP="$(date +%F-%H%M)"
RECIPIENT="${BACKUP_GPG_RECIPIENT:-}"
install -d -m 700 "$DEST"
umask 077

# Tulis stdin ke file; dienkripsi GPG bila penerima diatur.
simpan() {
  if [ -n "$RECIPIENT" ]; then
    gpg --batch --yes --trust-model always --encrypt --recipient "$RECIPIENT" --output "$1.gpg"
  else
    cat > "$1"
  fi
}

if [ -z "$RECIPIENT" ]; then
  echo "PERINGATAN: BACKUP_GPG_RECIPIENT belum diatur, backup disimpan TANPA enkripsi." >&2
fi

mysqldump --single-transaction --quick --routines casemix_ai | gzip | simpan "$DEST/db-$STAMP.sql.gz"
tar -czf - -C "$APP_DIR/storage/app" private | simpan "$DEST/berkas-$STAMP.tar.gz"
simpan "$DEST/env-$STAMP" < "$APP_DIR/.env"
find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete
echo "Backup selesai: $DEST (*-$STAMP)"
