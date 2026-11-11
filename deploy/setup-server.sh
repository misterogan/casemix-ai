#!/usr/bin/env bash
# =============================================================================
# Casemix AI — persiapan server (sekali jalan) untuk Ubuntu 24.04 LTS
#
# Syarat: kode sudah di-clone ke $APP_DIR oleh user $APP_USER (lihat panduan).
# Jalankan:  cd /var/www/casemix-ai && sudo SERVER_NAME=192.168.x.x bash deploy/setup-server.sh
# Aman dijalankan ulang: langkah yang sudah selesai tidak diulang secara merusak.
# =============================================================================
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/casemix-ai}"
APP_USER="${APP_USER:-casemix}"
BRANCH="${BRANCH:-main}"
SERVER_NAME="${SERVER_NAME:-$(hostname -I | awk '{print $1}')}"
PHP_VER="8.3"
DB_NAME="casemix_ai"
DB_USER="casemix"
ENABLE_UFW="${ENABLE_UFW:-0}"   # 1 = aktifkan firewall ufw (cek dulu kebijakan TI RS)

step() { echo -e "\n\033[1;32m==> $*\033[0m"; }
warn() { echo -e "\033[1;33m!!  $*\033[0m"; }
die()  { echo -e "\033[1;31mXX  $*\033[0m"; exit 1; }

[[ $EUID -eq 0 ]] || die "Jalankan dengan sudo."
[[ -f "$APP_DIR/artisan" ]] || die "Kode aplikasi belum ada di $APP_DIR. Clone repositori dulu (lihat panduan)."
id "$APP_USER" >/dev/null 2>&1 || die "User $APP_USER belum dibuat (lihat panduan)."
. /etc/os-release
[[ "${VERSION_ID:-}" == "24.04" ]] || warn "Skrip ini disiapkan untuk Ubuntu 24.04. Server ini: ${PRETTY_NAME:-tidak diketahui}."

# Port 80 dipakai aplikasi lain (mis. Apache)? Hentikan agar tidak merusak layanan yang sudah ada.
if ss -ltnp 'sport = :80' 2>/dev/null | grep -q LISTEN && ! ss -ltnp 'sport = :80' | grep -q nginx; then
  ss -ltnp 'sport = :80'
  die "Port 80 sudah dipakai program lain. Diskusikan dengan TI RS sebelum melanjutkan."
fi

# -----------------------------------------------------------------------------
step "1/9 Memasang paket sistem"
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  nginx mysql-server redis-server git unzip curl ca-certificates poppler-utils composer \
  php$PHP_VER-fpm php$PHP_VER-cli php$PHP_VER-common php$PHP_VER-mysql php$PHP_VER-mbstring \
  php$PHP_VER-xml php$PHP_VER-curl php$PHP_VER-zip php$PHP_VER-intl php$PHP_VER-bcmath php-redis

if ! command -v node >/dev/null || (( $(node -v | tr -d v | cut -d. -f1) < 20 )); then
  step "Memasang Node.js 22 LTS (untuk build tampilan)"
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi

for ext in ftp redis intl pdo_mysql zip mbstring fileinfo; do
  php -m | grep -qix "$ext" || die "Ekstensi PHP '$ext' tidak aktif."
done

# -----------------------------------------------------------------------------
step "2/9 Mengatur PHP"
for sapi in fpm cli; do
  cat > /etc/php/$PHP_VER/$sapi/conf.d/99-casemix.ini <<INI
upload_max_filesize = 25M
post_max_size = 200M
memory_limit = 512M
max_execution_time = 120
expose_php = Off
date.timezone = Asia/Jakarta
INI
done

# -----------------------------------------------------------------------------
step "3/9 Menyiapkan database MySQL"
if [[ ! -f /root/.casemix-db ]]; then
  DB_PASS="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | cut -c1-28)"
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
  install -m 600 /dev/null /root/.casemix-db
  echo "$DB_PASS" > /root/.casemix-db
fi
DB_PASS="$(cat /root/.casemix-db)"

# -----------------------------------------------------------------------------
step "4/9 Menyiapkan file .env"
ENV="$APP_DIR/.env"
[[ -f "$ENV" ]] || cp "$APP_DIR/.env.example" "$ENV"

set_env() {   # set_env KUNCI NILAI — ganti baris (termasuk yang dikomentari) atau tambahkan
  local k="$1" v="$2"
  if grep -qE "^#?[[:space:]]*$k=" "$ENV"; then
    sed -i -E "s|^#?[[:space:]]*$k=.*|$k=$v|" "$ENV"
  else
    echo "$k=$v" >> "$ENV"
  fi
}
add_env() {   # add_env KUNCI NILAI — hanya bila belum ada (tidak menimpa isian manual)
  grep -qE "^$1=" "$ENV" || echo "$1=$2" >> "$ENV"
}

grep -qE '^APP_KEY=base64:' "$ENV" || set_env APP_KEY "base64:$(openssl rand -base64 32)"
set_env APP_NAME '"Casemix AI"'
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "http://$SERVER_NAME"
set_env APP_LOCALE id
set_env APP_FALLBACK_LOCALE id
set_env APP_FAKER_LOCALE id_ID
set_env APP_TIMEZONE Asia/Jakarta
set_env LOG_CHANNEL daily
set_env LOG_LEVEL warning
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
set_env REDIS_CLIENT phpredis
set_env REDIS_HOST 127.0.0.1
set_env QUEUE_CONNECTION redis
set_env REDIS_QUEUE_RETRY_AFTER 1900
set_env CACHE_STORE redis
set_env SESSION_DRIVER redis
set_env SESSION_ENCRYPT true
set_env PDFTOPPM_BINARY /usr/bin/pdftoppm
add_env CASEMIX_APP_NAME '"Casemix AI"'
add_env CASEMIX_HOSPITAL_NAME '"Rumah Sakit"'
add_env CASEMIX_AI_QUEUE ai
add_env SIMRS_FTP_HOST ""
add_env SIMRS_FTP_PORT 21
add_env SIMRS_FTP_USERNAME ""
add_env SIMRS_FTP_PASSWORD ""
add_env SIMRS_FTP_SSL false
add_env SIMRS_FTP_PASSIVE true
add_env SIMRS_FTP_PATH /ftp/data/
add_env SIMRS_FTP_PATH_RIWAYAT /ftp/riwayat_pasien/
add_env SIMRS_FTP_MAX_FILES 60

# -----------------------------------------------------------------------------
step "5/9 Mengatur kepemilikan file"
chown -R "$APP_USER":www-data "$APP_DIR"
chown "$APP_USER":www-data "$ENV"; chmod 640 "$ENV"
# User deploy masuk grup www-data agar git bisa membaca storage; user lain di server tidak bisa
# membuka berkas pasien (folder storage 2770, tanpa akses "others").
usermod -aG www-data "$APP_USER"
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R ug+rwX,o-rwx "$APP_DIR/storage"
find "$APP_DIR/storage" -type d -exec chmod 2770 {} +
chmod -R ug+rwX "$APP_DIR/bootstrap/cache"
runuser -u "$APP_USER" -- git -C "$APP_DIR" config core.fileMode false

# -----------------------------------------------------------------------------
step "6/9 Build pertama (composer, npm, migrasi)"
APP_DIR="$APP_DIR" APP_USER="$APP_USER" BRANCH="$BRANCH" bash "$APP_DIR/deploy/deploy.sh" --force

USERS="$(mysql -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.users" 2>/dev/null || echo 0)"
if [[ "$USERS" == "0" ]]; then
  step "Membuat peran dan akun admin pertama"
  runuser -u www-data -- php "$APP_DIR/artisan" db:seed --force
fi

# -----------------------------------------------------------------------------
step "7/9 Mengatur nginx"
sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__SERVER_NAME__|$SERVER_NAME|g" -e "s|__PHP_VER__|$PHP_VER|g" \
  "$APP_DIR/deploy/nginx/casemix-ai.conf" > /etc/nginx/sites-available/casemix-ai
ln -sf /etc/nginx/sites-available/casemix-ai /etc/nginx/sites-enabled/casemix-ai
# Hapus situs bawaan nginx hanya bila masih file bawaan (bukan situs lain milik RS)
if [[ -L /etc/nginx/sites-enabled/default ]] && grep -q "Welcome to nginx\|default_server" /etc/nginx/sites-available/default 2>/dev/null; then
  rm -f /etc/nginx/sites-enabled/default
fi
nginx -t

# -----------------------------------------------------------------------------
step "8/9 Mendaftarkan layanan systemd (jalan otomatis saat boot dan saat mati)"
for f in "$APP_DIR"/deploy/systemd/*.service "$APP_DIR"/deploy/systemd/*.timer; do
  sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__APP_USER__|$APP_USER|g" -e "s|__BRANCH__|$BRANCH|g" \
    "$f" > "/etc/systemd/system/$(basename "$f")"
done
for svc in nginx php$PHP_VER-fpm; do
  mkdir -p "/etc/systemd/system/$svc.service.d"
  printf '[Service]\nRestart=on-failure\nRestartSec=5s\n' > "/etc/systemd/system/$svc.service.d/casemix-restart.conf"
done
install -d -m 700 /var/backups/casemix-ai
touch /var/log/casemix-deploy.log

systemctl daemon-reload
systemctl enable --now mysql redis-server php$PHP_VER-fpm nginx
systemctl restart php$PHP_VER-fpm nginx
systemctl enable --now casemix-queue.service casemix-autodeploy.timer casemix-backup.timer

# -----------------------------------------------------------------------------
step "9/9 Firewall"
if [[ "$ENABLE_UFW" == "1" ]]; then
  SSH_PORT="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')"; SSH_PORT="${SSH_PORT:-22}"
  ufw allow "$SSH_PORT"/tcp
  ufw allow 'Nginx Full'
  ufw --force enable
  ufw status
else
  echo "Dilewati (ENABLE_UFW=0). MySQL dan Redis hanya mendengar di localhost."
fi

echo
step "Selesai"
cat <<MSG
Aplikasi : http://$SERVER_NAME/masuk
Langkah berikut:
  1. Isi SIMRS_FTP_* dan CASEMIX_HOSPITAL_NAME:  sudo nano $ENV
  2. Terapkan:  sudo bash $APP_DIR/deploy/deploy.sh --reload
  3. Cek layanan:  systemctl status casemix-queue  |  systemctl list-timers 'casemix-*'
Log deploy: /var/log/casemix-deploy.log   Backup: /var/backups/casemix-ai
MSG
