# CERMAT di Docker — uji berdampingan dengan produksi

Produksi (nginx/PHP 8.4/MySQL/systemd di host, `/var/www/casemix-ai`, workflow deploy) **tidak diubah**.
Docker berjalan terpisah: web di port **8310**, MySQL sendiri tanpa port publik, data di `/srv/cermat`.

| Layanan | Isi |
|---|---|
| `app` | php-fpm 8.4 (UID/GID = `oem`) |
| `nginx` | port 8310 → 80, root `public/` |
| `queue` | `php artisan queue:work --queue=ai,default --tries=3` (job AI ada di antrean `ai`) |
| `scheduler` | `php artisan schedule:work` |
| `mysql` | MySQL 8, data `/srv/cermat/mysql` |

Semua perintah di bawah dijalankan di server, dari `/var/www/casemix-ai` (kode yang sudah di-pull), sebagai pengguna dengan akses docker.

## 1. Siapkan direktori data

```bash
sudo mkdir -p /srv/cermat/storage /srv/cermat/mysql /srv/cermat/backup
sudo chown -R oem:oem /srv/cermat/storage /srv/cermat/backup
```

## 2. `.env` kontainer

```bash
sudo cp /var/www/casemix-ai/.env /srv/cermat/.env
sudo chown oem:oem /srv/cermat/.env && sudo chmod 640 /srv/cermat/.env
sudo nano /srv/cermat/.env
```

Ubah **hanya**:

```dotenv
APP_URL=http://<ip-server>:8310
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=cermat
DB_USERNAME=cermat
DB_PASSWORD=<sandi-baru-kontainer>
```

**`APP_KEY` wajib SAMA dengan produksi** — arsip berkas dienkripsi `Crypt::encrypt` dan API key provider AI tersimpan terenkripsi; APP_KEY lain = arsip & key tidak terbaca.
Pertimbangkan `SESSION_COOKIE=cermat_docker` agar sesi tidak bentrok dengan produksi di host yang sama.

Kredensial MySQL kontainer (`/srv/cermat/mysql.env`, samakan dengan `.env` di atas):

```bash
sudo tee /srv/cermat/mysql.env >/dev/null <<'X'
MYSQL_ROOT_PASSWORD=<sandi-root-kontainer>
MYSQL_DATABASE=cermat
MYSQL_USER=cermat
MYSQL_PASSWORD=<sandi-baru-kontainer>
X
sudo chmod 600 /srv/cermat/mysql.env
```

## 3. Salin storage

```bash
sudo rsync -aH --info=progress2 /var/www/casemix-ai/storage/ /srv/cermat/storage/
sudo chown -R oem:oem /srv/cermat/storage
sudo rm -rf /srv/cermat/storage/framework/views/*.php /srv/cermat/storage/framework/cache/data/*
```

## 4. Dump DB produksi (satu-satunya akses ke DB produksi)

```bash
mysqldump -h 127.0.0.1 -u <user-prod> -p --single-transaction --quick --routines --triggers \
  --no-tablespaces --set-gtid-purged=OFF <db-prod> | gzip > /srv/cermat/backup/prod-$(date +%F-%H%M).sql.gz
```

(`--set-gtid-purged=OFF` hapus bila mysqldump menolak opsi itu.)

## 5. Build & jalankan

```bash
cd /var/www/casemix-ai
export CERMAT_UID=$(id -u oem) CERMAT_GID=$(id -g oem)   # (UID adalah variabel readonly di bash)
docker compose build
docker compose up -d mysql
docker compose ps        # tunggu mysql "healthy"
```

Impor dump ke kontainer:

```bash
gunzip -c /srv/cermat/backup/prod-<tanggal>.sql.gz \
  | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" cermat'
```

**Kosongkan antrean hasil salinan** (kalau tidak, job AI produksi yang masih antre ikut dijalankan ulang → biaya AI ganda):

```bash
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" cermat -e "TRUNCATE jobs; TRUNCATE job_batches; TRUNCATE failed_jobs; TRUNCATE cache; TRUNCATE cache_locks; TRUNCATE sessions;"'
```

Nyalakan semua & migrasi:

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose restart queue scheduler
```

## 6. Cek

```bash
docker compose ps                                   # 5 layanan Up
docker compose exec app php -m | grep -Ei 'pdo_mysql|zip|intl|bcmath|pcntl|opcache|ftp|gd'
docker compose exec app pdftotext -v
docker compose exec app php artisan about --only=environment
docker compose exec scheduler php artisan schedule:list
docker compose logs -f --tail=50 queue scheduler
curl -sI http://127.0.0.1:8310/login | head -1   # 200
```

Di browser `http://<ip-server>:8310`:
1. Login dengan akun produksi.
2. Buka satu berkas/folder verifikasi — penampil PDF tampil (cek DevTools: `pdf.worker.min-*.mjs` bertipe `application/javascript`).
3. Buka satu **arsip terenkripsi** (berkas yang sudah pernah dibaca AI) — isi tampil = APP_KEY benar.
4. Jalankan satu tugas kecil (mis. verifikasi ulang tanpa AI) → `docker compose logs queue` menampilkan `DONE`.
5. `schedule:list` menampilkan `casemix:sync-ftp`, `casemix:archive-prune`, `casemix:vclaim-sync-*`.

> Catatan: scheduler kontainer ikut menarik FTP Enigma & VClaim (hanya baca) — panggilan ke layanan luar jadi ganda selama uji. Untuk uji singkat tanpa itu: `docker compose stop scheduler`.

## 7. Backup (kontainer)

```bash
# DB
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines cermat' \
  | gzip > /srv/cermat/backup/docker-$(date +%F-%H%M).sql.gz
# storage + .env
sudo tar czf /srv/cermat/backup/storage-$(date +%F).tgz -C /srv/cermat storage .env mysql.env
```

Simpan salinan di luar server. Uji pemulihan sesekali dengan langkah impor di §5.

## 8. Rollback

Produksi tidak pernah disentuh, jadi rollback = matikan Docker:

```bash
docker compose down                     # hentikan & hapus kontainer (data /srv/cermat tetap)
```

Bersihkan total (setelah backup §7 bila perlu):

```bash
docker compose down --rmi local
sudo rm -rf /srv/cermat/mysql /srv/cermat/storage
```

Kembali ke versi image sebelumnya: `git checkout <commit-lama> && docker compose up -d --build`.

Bila nanti Docker menggantikan produksi: hentikan worker/cron produksi **dulu** (dua queue worker + dua scheduler pada data berbeda tidak masalah, tapi pada DB yang sama akan menjalankan job ganda), lakukan dump ulang, ulangi §3–§6.
