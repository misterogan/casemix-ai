# Casemix AI

Aplikasi web untuk tim casemix: memeriksa kelengkapan dan konsistensi berkas klaim JKN sebelum dientri dan difinalkan di E-Klaim.
AI hanya memberi temuan dan saran. AI tidak memberi kode ICD, dan keputusan akhir tetap pada petugas.

Laravel 12 · PHP 8.3 · MySQL 8 · Redis · Blade + Tailwind CSS v4 (tanpa Livewire)

## Isi paket

Paket ini berisi kode aplikasi yang disalin ke instalasi Laravel 12 baru. File bawaan Laravel yang ditimpa:
`bootstrap/app.php`, `routes/web.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`,
`app/Http/Controllers/Controller.php`, `database/seeders/DatabaseSeeder.php`, `resources/css/app.css`, `resources/js/app.js`.

## Kebutuhan server

- PHP 8.3 atau lebih baru (lokal 8.3, produksi 8.4) dengan ekstensi `ftp`, `redis` (phpredis), `fileinfo`, `mbstring`, `pdo_mysql`, `intl`
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

1. **Berkas masuk** lewat dua jalur. Jalur pertama: Buat berkas lalu unggah per slot dokumen. Jalur kedua: Impor dari SIMRS. Pada impor, identitas diambil dari nama folder FTP (pola `NoRM_NAMA_NoSEP`) dan jenis dokumen ditebak dari nama file, tanpa AI (maks. 60 file per impor). Petugas mengoreksi pengelompokan, melengkapi tanggal masuk, jenis rawat, dan DPJP bila tahu, lalu menyimpan. Field yang tidak diketahui dibiarkan kosong, bukan ditebak, dan menahan persetujuan sampai diisi. Hanya file yang isinya benar-benar PDF atau gambar yang disimpan.
2. **Isi otomatis dari dokumen.** Supaya petugas tidak mengetik ulang data yang sudah ada di SEP, resume medis, rincian biaya, dan laporan operasi, AI membaca dokumen tersebut dan menampilkan **usulan** isian yang berbeda atau belum ada di berkas, lengkap dengan sumber dan tingkat keyakinan. Tidak ada yang diterapkan sebelum petugas mencentang dan menekan *Terapkan*; setiap isian yang diterapkan tercatat di audit (nilai lama, nilai baru, nomor usulan). Nilai yang tidak valid (tanggal salah, SEP bukan 19 karakter, pilihan di luar referensi E-Klaim) dibuang, kode ICD dihapus, dan No. peserta tidak diusulkan. Bisa dijalankan otomatis saat impor (pilihan bawaan) atau dari tombol *Isi otomatis dari dokumen* di halaman berkas. Form data klaim hanya mewajibkan nama, RM, jenis rawat, tanggal masuk, dan DPJP.
3. **Periksa dengan AI.** Aturan konsistensi E-Klaim berjalan lebih dulu tanpa AI. Setelah itu AI membaca dokumen dan memberi temuan bertingkat (kritis, perhatian, sesuai) serta kondisi yang belum tertulis sebagai diagnosis, untuk dikonfirmasi ke DPJP.
4. **Daftar cek.** Petugas mencentang 5 butir.
5. **Setujui.** Hanya Koordinator yang bisa menyetujui, dan hanya jika data dan dokumen wajib lengkap, hasil AI tidak usang, dan daftar cek lengkap. Pemisahan tugas: Koordinator yang mengisi daftar cek tidak bisa menyetujui berkas yang sama (`CASEMIX_APPROVAL_SEPARATE_DUTIES=true`, bawaan). Matikan hanya bila RS hanya punya satu Koordinator yang juga memeriksa. Berkas yang disetujui dikunci. Hanya Koordinator yang bisa membuka kembali, dengan alasan tertulis.

Setiap perubahan data atau dokumen membuat hasil AI usang dan mengosongkan daftar cek.

Jejak audit: perubahan data klaim dicatat beserta nilai lama dan nilai baru (terlihat di Riwayat aktivitas). Dokumen yang dihapus hanya disembunyikan (soft delete); file dan barisnya tetap tersimpan sebagai bukti.

Dokumen susulan dari FTP untuk SEP yang berkasnya sudah **Disetujui** tidak ditempel dan tidak dibuat menjadi berkas baru. File tersebut tertahan di halaman impor sampai Koordinator membuka kembali berkasnya, lalu impor disimpan lagi.

## Anggaran AI (dua lapis)

**Lapis 1, di penyedia AI (wajib).** Untuk Claude API: buat workspace khusus "Casemix AI" di Claude Console, pasang *Spend limit* bulanan (mis. US$200) dan notifikasi (mis. US$150) di workspace itu, lalu buat API key dari dalam workspace tersebut. Workspace *Default* tidak bisa diberi batas sendiri. Batas ini dipegang penyedia, jadi tetap berlaku apa pun yang terjadi di aplikasi.

**Lapis 2, di aplikasi.** Admin → Anggaran AI: atur anggaran bulanan (USD) dan ambang peringatan (bawaan 80%). Setiap pemeriksaan dan usulan isian mencatat token dan perkiraan biayanya (harga dari Penyedia AI, atau harga bawaan model Claude di `config/casemix.php`). Akibatnya:

- Di atas ambang peringatan: Admin dan Koordinator melihat pemberitahuan, dan riwayat aktivitas mencatatnya sekali per bulan.
- Saat pemakaian ditambah perkiraan antrean mencapai anggaran: tombol pemeriksaan AI dan isi otomatis ditahan dengan pesan yang jelas, impor tetap menyimpan berkas tanpa mengantre AI, dan job yang sudah mengantre berhenti sebelum memanggil AI. Aturan otomatis tetap berjalan.
- Terbuka lagi otomatis tanggal 1 bulan berikutnya, atau saat Admin menaikkan anggaran.
- Halaman Anggaran AI menampilkan pemakaian per fitur, per petugas, dan 6 bulan terakhir.

Angka di aplikasi adalah perkiraan dari token yang tercatat (pemanggilan yang gagal di tengah jalan tidak terhitung). Tagihan resmi tetap yang ada di Console, karena itu lapis 1 tetap dipasang dan sebaiknya sama atau sedikit di atas anggaran di aplikasi.

## Status

Label sengaja **berbeda** dari status E-Klaim DHealth. Di DHealth, "Sudah Koreksi" berarti koding ICD sudah disimpan di Edit Koreksi; di Casemix AI artinya berkas sudah diperiksa. Nilai di database tidak berubah (`belum_koreksi`, `sudah_koreksi`).

| Status | Kapan | Langkah di DHealth sesudahnya |
|---|---|---|
| No. SEP belum ada | SEP kosong | Buat SEP (batal bayar, edit pendaftaran) |
| Perlu Dilengkapi | Dokumen wajib (SEP, resume medis, rincian biaya) belum lengkap | Lengkapi dokumen di unit asalnya |
| Belum Diperiksa | Dokumen lengkap, belum diperiksa atau hasilnya usang | — |
| Sudah Diperiksa | Sudah diperiksa AI dan hasilnya terbaru, siap disetujui | — |
| Disetujui | Disetujui Koordinator, terkunci | Edit Koreksi (koding) → formulir E-Klaim → Proses → Final → Kirim |

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
- Gunakan HTTPS: `deploy/nginx/casemix-ai-tls.conf`, lalu set `SESSION_SECURE_COOKIE=true`.
- Backup dienkripsi GPG bila `BACKUP_GPG_RECIPIENT` diatur di `/etc/default/casemix-backup` (lihat `deploy/backup-casemix.sh`).
- File yang isinya bukan PDF/gambar tidak diimpor dan tidak pernah ditampilkan inline di browser.
- No. peserta BPJS tidak dikirim ke penyedia AI. Lokasi pemrosesan data wajib diisi untuk setiap penyedia AI.
- Antrean Redis: set `REDIS_QUEUE_RETRY_AFTER=1900`, lebih besar dari timeout job terpanjang (1800 detik).
- Catat lokasi pemrosesan data tiap penyedia AI di halaman Penyedia AI sebagai bahan kepatuhan UU PDP.

## Asumsi versi pertama (silakan koreksi)

- **Folder FTP `path`** (`/ftp/data/`) berisi scan berkas klaim dari SIMRS. File yang sudah pernah diimpor dikenali dari path dan ukurannya, dan file di FTP tidak dihapus.
- **Folder FTP `path_riwayat_pasien`** (`/ftp/riwayat_pasien/`) dicari berdasarkan No. RM yang utuh: nama folder/file harus **diawali** No. RM (mis. `000123_NAMA`, `000123.pdf`) atau memuat label RM (mis. `Lab RM 000123.pdf`). Angka di tengah nama (tanggal, SEP, RM lain) tidak dihitung, supaya file pasien lain tidak ikut terambil. Bila SIMRS memakai pola lain, sesuaikan `SimrsFtp::pathBelongsToRm()` dan tesnya. File yang dipilih dilampirkan sebagai dokumen tambahan.
- Temuan kritis **tidak memblokir** persetujuan. Koordinator tetap bisa menyetujui, tetapi diberi peringatan dan jumlah temuan kritis yang masih terbuka dicatat di audit log.
- `DocumentClassifier` (pemilahan dokumen dengan AI) belum dipakai; impor saat ini hanya membaca nama folder dan nama file.
- Adapter AI: OpenAI-compatible dan Anthropic. Bedrock/Vertex bisa ditambahkan kemudian.
- Integrasi API SIMRS DHealth (membaca status E-Klaim, menarik data formulir E-Klaim agar tidak perlu dibaca dari dokumen) belum dibuat karena dokumentasi dari vendor belum tersedia.
