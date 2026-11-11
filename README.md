# Casemix AI

Aplikasi web untuk tim casemix: memeriksa kelengkapan dan konsistensi berkas klaim JKN sebelum dientri dan difinalkan di E-Klaim.
AI hanya memberi temuan dan saran. AI tidak memberi kode ICD, dan keputusan akhir tetap pada petugas.

Laravel 12 · PHP 8.3 · MySQL 8 · Redis · Blade + Tailwind CSS v4 (tanpa Livewire)

## Isi paket

Paket ini berisi kode aplikasi yang disalin ke instalasi Laravel 12 baru. File bawaan Laravel yang ditimpa:
`bootstrap/app.php`, `routes/web.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`,
`app/Http/Controllers/Controller.php`, `database/seeders/DatabaseSeeder.php`, `resources/css/app.css`, `resources/js/app.js`.

## Kebutuhan server

- PHP 8.3 dengan ekstensi `ftp`, `redis` (phpredis), `fileinfo`, `mbstring`, `pdo_mysql`, `intl`
- MySQL 8, Redis 6+
- `poppler-utils` (perintah `pdftoppm`) untuk membaca PDF hasil scan: `sudo apt install poppler-utils`
- Node.js 20+ untuk build CSS/JS

## Instalasi

```bash
composer create-project laravel/laravel:^12.0 casemix-ai
cd casemix-ai

# Salin isi paket ini ke folder proyek (timpa file yang sama)
cp -r /path/ke/paket/* .

composer require spatie/laravel-permission:^6.10 league/flysystem-ftp:^3.0 smalot/pdfparser:^2.11
composer require laravel/horizon   # opsional, pemantau antrean Redis

php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# Gabungkan .env.casemix.example ke .env, lalu isi DB, Redis, dan SIMRS_FTP_*
php artisan key:generate
php artisan migrate --seed       # akun admin dicetak di terminal bila CASEMIX_ADMIN_PASSWORD kosong

npm install
npm run build
```

Jalankan worker antrean. Pemeriksaan AI dan impor FTP berjalan di antrean `ai`:

```bash
php artisan queue:work redis --queue=ai,default --timeout=1800 --tries=1
# atau: php artisan horizon
```

Di produksi, jalankan worker lewat Supervisor atau systemd dan aktifkan `php artisan config:cache`.

## Langkah pertama setelah masuk sebagai Admin

1. Admin → Penyedia AI → Tambah penyedia. Isi base URL, API key, model cepat dan model standar, lokasi pemrosesan data, lalu Uji koneksi.
2. Admin → Pengaturan dan FTP → Uji koneksi FTP SIMRS.
3. Admin → Pengguna → buat akun Koordinator, Petugas, dan Viewer.

## Alur kerja

1. **Berkas masuk** lewat dua jalur. Jalur pertama: Buat berkas lalu unggah per slot dokumen. Jalur kedua: Impor dari SIMRS. Pada impor, AI memilah jenis dokumen dan identitas tiap file di folder FTP (maks. 60 per impor), petugas mengoreksi pengelompokan, lalu menyimpan.
2. **Periksa dengan AI.** Aturan konsistensi E-Klaim berjalan lebih dulu tanpa AI. Setelah itu AI membaca dokumen dan memberi temuan bertingkat (kritis, perhatian, sesuai) serta kondisi yang belum tertulis sebagai diagnosis, untuk dikonfirmasi ke DPJP.
3. **Daftar cek.** Petugas mencentang 5 butir.
4. **Setujui.** Hanya Koordinator yang bisa menyetujui, dan hanya jika dokumen wajib lengkap, hasil AI tidak usang, dan daftar cek lengkap. Berkas yang disetujui dikunci. Hanya Koordinator yang bisa membuka kembali, dengan alasan tertulis.

Setiap perubahan data atau dokumen membuat hasil AI usang dan mengosongkan daftar cek.

## Status (mengikuti E-Klaim SIMRS)

| Status | Kapan |
|---|---|
| No. SEP belum ada | SEP kosong |
| Perlu Dilengkapi | Dokumen wajib (SEP, resume medis, rincian biaya) belum lengkap |
| Belum Koreksi | Dokumen lengkap, belum diperiksa atau hasilnya usang |
| Sudah Koreksi | Sudah diperiksa AI dan hasilnya terbaru, siap disetujui |
| Disetujui | Disetujui Koordinator, terkunci |

## Menambah aturan pemeriksaan

Aturan tanpa AI ada di `app/Services/Rules/ConsistencyChecker.php`. Tambahkan aturan dari riwayat pending/dispute BPJS rumah sakit di sini, karena hasilnya pasti dan tidak memakan token.
Instruksi untuk AI ada di `app/Services/Ai/ClaimReviewer.php`.

## Menambah penyedia AI

Buat kelas baru yang mengimplementasikan `App\Services\Ai\Contracts\AiProvider`, daftarkan di `AiManager::for()` dan `AiProvider::DRIVERS`.
Adapter yang sudah ada:

- `openai_compatible`: Telkomsel OCTA, XLSMART TokenKu.ai, OpenAI, Gemini (endpoint OpenAI-compatible), vLLM, Ollama
- `anthropic`: Claude API langsung

## Keamanan dan data pribadi

- Berkas disimpan di `storage/app/private/claims/{id}` di server rumah sakit, bukan folder publik. Akses file selalu lewat aplikasi dan tercatat di audit log.
- API key penyedia AI disimpan terenkripsi (`APP_KEY`). Kredensial FTP hanya di `.env`.
- FTP biasa tidak terenkripsi. Gunakan FTPS/SFTP bila SIMRS mendukung, dan batasi akses FTP hanya dari IP server aplikasi.
- Sesi dienkripsi, login dibatasi 10 percobaan per menit, akun nonaktif langsung dikeluarkan.
- Catat lokasi pemrosesan data tiap penyedia AI di halaman Penyedia AI sebagai bahan kepatuhan UU PDP.

## Asumsi versi pertama (silakan koreksi)

- **Folder FTP `path`** (`/ftp/data/`) berisi scan berkas klaim dari SIMRS. File yang sudah pernah diimpor dikenali dari path dan ukurannya, dan file di FTP tidak dihapus.
- **Folder FTP `path_riwayat_pasien`** (`/ftp/riwayat_pasien/`) dicari berdasarkan No. RM, baik di nama file maupun nama subfolder. File yang dipilih dilampirkan sebagai dokumen tambahan.
- Temuan kritis **tidak memblokir** persetujuan. Koordinator tetap bisa menyetujui, tetapi diberi peringatan dan jumlah temuan kritis yang masih terbuka dicatat di audit log.
- Adapter AI: OpenAI-compatible dan Anthropic. Bedrock/Vertex bisa ditambahkan kemudian.
- Integrasi API SIMRS DHealth (membaca status Belum Koreksi, mengirim balik hasil koreksi) belum dibuat karena dokumentasi dari vendor belum tersedia.
