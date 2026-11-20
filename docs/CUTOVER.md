# Cutover CERMAT: host → Docker

Prasyarat: uji Docker di :8310 lulus (login, berkas, arsip, regulasi, analisa AI); `/srv/cermat/{.env,mysql.env,storage,mysql}` sudah ada (lihat [DOCKER.md](DOCKER.md)).
Semua perintah di server, sebagai **oem** (pakai `sudo` bila tertulis). Semua perintah compose **lewat** `scripts/cermat-compose.sh` (di sini `$C`; di shell oem tersedia alias `cermat`).

> Catatan cutover 20 Nov 2026: scheduler & backup host ternyata **systemd timer** (`casemix-scheduler.timer`, `casemix-backup.timer` → `/usr/local/bin/backup-casemix.sh`), bukan crontab. Crontab oem hanya berisi antrol + `backup-cermat.sh` — **jangan disentuh**.

> `docker-compose.yml` kini memakai `name: cermat`. Kontainer uji lama bernama `casemix-ai-*` — hentikan dulu di langkah 0.
> **Jangan** hentikan `php8.4-fpm`, `nginx`, atau `mysql` host — dipakai antrol.

## 0. Persiapan (boleh sebelum jendela cutover)

```bash
cd /var/www/casemix-ai
C=/var/www/casemix-ai/scripts/cermat-compose.sh
docker compose -p casemix-ai down          # kontainer uji lama (nama proyek lama); data /srv/cermat tetap
git fetch origin main && git log --oneline -1 origin/main   # pastikan commit cutover ada (deploy host akan menariknya)
mkdir -p /srv/cermat/backup
```

Workflow GitHub masih jalur host (variabel `CERMAT_DEPLOY` belum `docker`). **Jangan push** selama jendela cutover.

## a. Cek anggaran AI

```bash
grep -E '^CASEMIX_AI_ANGGARAN' /srv/cermat/.env /var/www/casemix-ai/.env
```

Nilai di `/srv/cermat/.env` harus sama dengan produksi (bukan nilai uji). Sekalian cek `APP_KEY` sama persis dan `TRUSTED_PROXIES` terisi.

## b. Matikan worker & scheduler host

```bash
cd /var/www/casemix-ai && php8.4 artisan down          # cegah tulis selama dump
sudo systemctl stop casemix-queue.service
sudo systemctl disable casemix-queue.service
sudo systemctl disable --now casemix-scheduler.timer casemix-backup.timer
systemctl list-timers --all | grep casemix      # keduanya inactive/tidak terjadwal
```

Tanpa mematikan `casemix-scheduler.timer` FTP/VClaim sync jalan ganda. Crontab tidak diubah (crontab oem = antrol + backup-cermat.sh).

## c. Pindahkan database

```bash
cd /var/www/casemix-ai
$C stop nginx app queue scheduler                      # biarkan mysql kontainer hidup
mysqldump -h 127.0.0.1 -u <user-prod> -p --single-transaction --quick --routines --triggers \
  --no-tablespaces --set-gtid-purged=OFF <db-prod> | gzip > /srv/cermat/backup/final-host-$(date +%F-%H%M).sql.gz
ls -lh /srv/cermat/backup/final-host-*.sql.gz          # ukuran wajar, bukan 20 byte

$C up -d mysql
$C exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  DROP DATABASE IF EXISTS cermat;
  CREATE DATABASE cermat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON cermat.* TO \"cermat\"@\"%\";"'
gunzip -c /srv/cermat/backup/final-host-<tanggal>.sql.gz \
  | $C exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" cermat'
$C exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" cermat -e "
  TRUNCATE jobs; TRUNCATE job_batches; TRUNCATE failed_jobs; TRUNCATE cache; TRUNCATE cache_locks; TRUNCATE sessions;"'
```

(`--set-gtid-purged=OFF` hapus bila mysqldump menolak opsi itu.)

## d. Salin ulang storage

```bash
sudo rsync -aH --delete --info=progress2 \n  --exclude 'framework/down' --exclude 'framework/maintenance.php' \n  /var/www/casemix-ai/storage/ /srv/cermat/storage/
sudo chown -R oem:oem /srv/cermat/storage
sudo rm -rf /srv/cermat/storage/framework/views/*.php /srv/cermat/storage/framework/cache/data/*
sudo rm -f /srv/cermat/storage/framework/down /srv/cermat/storage/framework/maintenance.php
ls /srv/cermat/storage/framework/        # down & maintenance.php TIDAK ada
```

`artisan down` (langkah b) membuat `storage/framework/maintenance.php`, bukan hanya `down` — bila ikut tersalin, Docker tampil 503.

## e. Lepas site nginx host CERMAT (port 8300 saja)

```bash
grep -ln 'listen.*8300' /etc/nginx/sites-enabled/*     # pastikan hanya site casemix-ai
sudo mv /etc/nginx/sites-enabled/<site-casemix-ai> /root/nginx-casemix-ai.disabled-link   # atau: sudo rm (file asli di sites-available tetap)
sudo nginx -t && sudo systemctl reload nginx
ss -ltnp | grep ':8300' || echo "8300 bebas"
```

Worker nginx lama bisa menahan 8300 beberapa detik setelah reload — ulangi `ss -tlnp | grep :8300` sampai kosong sebelum `up -d`.

Site antrol tidak disentuh.

## f. Jalankan Docker di 8300

```bash
nano /srv/cermat/.env      # APP_URL=http://192.168.1.253:8300   dan   CERMAT_PORT=8300
cd /var/www/casemix-ai
ss -tlnp | grep :8300 || echo "8300 bebas"   # wajib bebas
$C up -d --build           # nginx, app, queue, scheduler, mysql
$C exec -T app php artisan migrate --force
$C ps                      # 5 layanan Up, nginx 0.0.0.0:8300->80
curl -sI http://127.0.0.1:8300/login | head -1           # 200
```

Lalu alihkan workflow ke Docker (sebelum push berikutnya):

```bash
gh variable set CERMAT_DEPLOY --body docker -R <owner>/<repo>    # atau lewat Settings → Variables
sudo chmod -x /usr/local/bin/deploy-casemix.sh                   # sabuk pengaman: skrip host tak bisa jalan
```

## g. Tailscale

```bash
tailscale serve status                 # https:443 / → http://127.0.0.1:8300 tetap
sudo tailscale serve --https=8443 off  # hapus pemetaan uji
tailscale serve status
```

## h. Verifikasi

```bash
$C exec -T app php artisan tinker --execute='echo config("app.timezone"), " ", now(), PHP_EOL;'   # Asia/Jakarta, jam WIB (HOME=/tmp sudah di image)
$C exec -T scheduler php artisan schedule:list
$C logs -f --since=30m queue scheduler        # pantau 30 menit pertama
```

Di browser — via LAN `http://192.168.1.253:8300` **dan** via Tailscale `https://<nama-tailnet>`:
1. Login (sesi lama hilang karena `sessions` dikosongkan — normal).
2. Buka satu berkas/folder verifikasi (PDF tampil).
3. Buka satu arsip terenkripsi (APP_KEY benar).
4. Via Tailscale: setelah login tetap di `https://` (TRUSTED_PROXIES benar).
5. Log scheduler menampilkan `casemix:sync-ftp` / `vclaim-sync-*` berjalan, log queue `DONE` tanpa `FAIL` berulang.

Backup harian (crontab oem, 01.30 — baris ini sudah ada; cukup pastikan):

```bash
crontab -l | grep backup-cermat
30 1 * * * /var/www/casemix-ai/scripts/backup-cermat.sh >> /srv/cermat/backup/backup.log 2>&1
```

Uji sekali manual: `/var/www/casemix-ai/scripts/backup-cermat.sh && ls -l /srv/cermat/backup`.

## i. Rollback

Data yang ditulis di Docker setelah cutover **tidak** ada di DB host. Bila perlu dipertahankan, dump DB kontainer dulu (`scripts/backup-cermat.sh`).

```bash
cd /var/www/casemix-ai
$C down
sudo ln -s /etc/nginx/sites-available/<site-casemix-ai> /etc/nginx/sites-enabled/   # atau kembalikan link yang dipindah
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now casemix-queue.service
sudo systemctl enable --now casemix-scheduler.timer casemix-backup.timer
php8.4 artisan up
gh variable set CERMAT_DEPLOY --body host -R <owner>/<repo>
sudo chmod +x /usr/local/bin/deploy-casemix.sh
```

Tailscale tetap ke 127.0.0.1:8300 → otomatis ke host lagi.

## j. Bersih-bersih (setelah 1–2 minggu stabil, opsional)

```bash
scripts/backup-cermat.sh
sudo tar czf /srv/cermat/backup/host-lama-$(date +%F).tgz -C /var/www/casemix-ai storage .env
sudo systemctl disable casemix-queue.service && sudo rm /etc/systemd/system/casemix-queue.service
sudo rm /etc/systemd/system/casemix-{scheduler,backup}.{timer,service} && sudo systemctl daemon-reload
sudo rm /etc/nginx/sites-available/<site-casemix-ai> /usr/local/bin/deploy-casemix.sh /usr/local/bin/backup-casemix.sh
mysql -h 127.0.0.1 -u root -p -e 'DROP DATABASE <db-prod>;'      # setelah dump final-host disimpan di luar server
sudo rm -rf /var/www/casemix-ai/storage/app/*                       # repo tetap: dipakai build Docker
```

Hapus juga job `deploy-host` dari `.github/workflows/deploy.yml`. Jangan hapus `/var/www/casemix-ai` (sumber build & lokasi runner deploy).
