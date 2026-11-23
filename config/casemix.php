<?php

return [
    'app_name' => env('CASEMIX_APP_NAME', 'CERMAT'),
    'hospital_name' => env('CASEMIX_HOSPITAL_NAME', 'Rumah Sakit'),
    // Tanggal CERMAT mulai dipakai (Y-m-d); ditandai di grafik tren Klaim BPJS.
    'go_live_date' => env('CASEMIX_GO_LIVE_DATE'),

    // Modul lama berkas klaim/impor/unggah; dimatikan karena verifikasi kini langsung dari FTP.
    'legacy_claims' => (bool) env('CASEMIX_LEGACY_CLAIMS', false),

    // Disk penyimpanan berkas klaim. Harus di server RS / cloud yang disetujui (UU PDP).
    'storage_disk' => env('CASEMIX_STORAGE_DISK', 'local'),

    'roles' => [
        'admin' => 'Admin',
        'koordinator' => 'Koordinator casemix',
        'petugas' => 'Petugas casemix',
        'viewer' => 'Viewer / auditor',
    ],

    'upload' => [
        'max_kb' => (int) env('CASEMIX_UPLOAD_MAX_KB', 20480),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        // Isi file (bukan ekstensi) yang boleh disimpan dan ditampilkan inline. Berlaku juga untuk file dari FTP.
        'content_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
    ],

    'ai' => [
        // Batas isi yang dikirim ke AI per pemeriksaan
        'max_text_chars_per_doc' => 15000,
        'max_images_total' => (int) env('CASEMIX_AI_MAX_IMAGES', 16),
        'max_pages_per_scan' => (int) env('CASEMIX_AI_MAX_PAGES', 4),
        // Halaman scan maksimal per jenis dokumen (verifikasi folder). PDF teks tetap dikirim sebagai teks.
        'max_pages_by_type' => [
            'sep' => 1, 'tambahan' => 1, 'resume_medis' => 3, 'rincian_biaya' => 3,
            'laboratorium' => 2, 'radiologi' => 2, 'laporan_operasi' => 2, 'lainnya' => 2,
        ],
        // Sisi terpanjang gambar hasil render scan (px)
        'render_max_px' => (int) env('CASEMIX_AI_RENDER_MAX_PX', 1300),
        'render_dpi' => 110,
        'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
        'queue' => env('CASEMIX_AI_QUEUE', 'ai'),
        // Verifikasi massal di antrean terpisah supaya verifikasi satuan tidak antre di belakangnya
        'queue_massal' => env('CASEMIX_AI_QUEUE_MASSAL', 'ai-bulk'),
        // Claude: off = thinking dimatikan (token keluaran untuk jawaban), low = effort rendah
        'thinking' => env('CASEMIX_AI_THINKING', 'off'),
        // Model per tugas. Kosong = model standar Penyedia AI aktif.
        'model_analisa' => env('CASEMIX_AI_MODEL_ANALISA'),
        'model_regulasi' => env('CASEMIX_AI_MODEL_REGULASI', 'claude-haiku-5-5'),
        'model_draf' => env('CASEMIX_AI_MODEL_DRAF'), // kosong = model_analisa
        // Harga USD per 1 juta token [input, output]; dicocokkan dari awal nama model. Ubah di sini tanpa mengubah kode.
        'harga' => [
            'claude-haiku-5-5' => [0.10, 0.50, 'prompt_lebih_dari' => [100_000, 0.50, 2.50]],
            'claude-sonnet-5-5' => [2.00, 10.00],
            'claude-opus-5-5' => [4.00, 20.00],
        ],
        // Nilai AWAL anggaran bulanan (USD) bila app_settings.ai_budget_usd belum diisi; sesudah Admin menyimpan, app_settings yang berlaku. 0 = tanpa batas.
        'anggaran_bulanan_usd' => (float) env('CASEMIX_AI_ANGGARAN', 0),
        // Setelah anggaran habis, analisa tetap boleh memakai cadangan ini; sesudahnya ditahan "menunggu anggaran".
        'anggaran_cadangan_analisa_usd' => (float) env('CASEMIX_AI_CADANGAN_ANALISA', 1),
    ],

    'regulasi' => [
        // Butir PNPK yang disisipkan ke analisa (prioritas: cocok kode ICD lengkap > prefix 3 digit)
        'max_butir' => (int) env('CASEMIX_REGULASI_MAX_BUTIR', 12),
        'max_token' => (int) env('CASEMIX_REGULASI_MAX_TOKEN', 3000), // perkiraan 4 karakter/token
    ],

    'budget' => [
        // Perkiraan biaya per berkas bila belum ada riwayat, untuk menahan antrean sebelum anggaran terlampaui.
        'default_job_cost_usd' => 0.20,
        // Harga bawaan per 1 juta token (USD) bila harga di Penyedia AI dikosongkan. Dicocokkan dari awal nama model.
        // Sumber: platform.claude.com/docs/en/about-claude/pricing (November 2026). Perbarui bila harga berubah.
        'prices' => [
            'claude-fable-5-2' => [8.00, 40.00],
            'claude-opus-5-5' => [4.00, 20.00],
            'claude-sonnet-5-5' => [2.00, 10.00],
            'claude-haiku-5-5' => [0.10, 0.50, 'prompt_lebih_dari' => [100_000, 0.50, 2.50]],
            'claude-sonnet-5' => [2.00, 10.00],
            'claude-opus-5' => [5.00, 25.00],
            'claude-haiku-4-5' => [1.00, 5.00],
        ],
    ],

    'approval' => [
        // Yang mengisi daftar cek tidak boleh menyetujui berkas yang sama.
        // Matikan (false) hanya bila tim casemix hanya punya satu Koordinator yang juga memeriksa.
        'separate_duties' => (bool) env('CASEMIX_APPROVAL_SEPARATE_DUTIES', true),
    ],

    'ftp' => [
        'host' => env('SIMRS_FTP_HOST'),
        'username' => env('SIMRS_FTP_USERNAME'),
        'password' => env('SIMRS_FTP_PASSWORD'),
        'port' => (int) env('SIMRS_FTP_PORT', 21),
        'ssl' => (bool) env('SIMRS_FTP_SSL', false),
        'passive' => (bool) env('SIMRS_FTP_PASSIVE', true),
        'timeout' => (int) env('SIMRS_FTP_TIMEOUT', 30),
        'path' => env('SIMRS_FTP_PATH', '/ftp/data/'),
        'path_riwayat_pasien' => env('SIMRS_FTP_PATH_RIWAYAT', '/ftp/riwayat_pasien/'),
        'max_files_per_import' => (int) env('SIMRS_FTP_MAX_FILES', 60),
    ],

    'archive' => [
        // Salinan terenkripsi tiap dokumen yang dibaca AI (arsip/{folder}/{sha256}.bin di storage_disk)
        'enabled' => (bool) env('CASEMIX_ARCHIVE_ENABLED', true),
        'retention_months' => (int) env('CASEMIX_ARCHIVE_RETENTION_MONTHS', 24),
        // Arsip otomatis saat sinkron FTP (tanpa AI) untuk folder baru/berubah; dimusnahkan bila tidak pernah dianalisa
        'auto_enabled' => (bool) env('CASEMIX_ARCHIVE_AUTO', false),
        'auto_retention_months' => (int) env('CASEMIX_ARCHIVE_AUTO_RETENTION_MONTHS', 3),
        'auto_max_folder_mb' => (int) env('CASEMIX_ARCHIVE_AUTO_MAX_MB', 200),
    ],

    'dashboard' => [
        // Prioritas 0-100 (App\Services\Dashboard\ClaimVerdict): bobot vonis + per butir kurang + umur hari sejak tanggal FTP
        'weight_blokir' => 40,
        'weight_tinjau' => 20,
        'per_kurang' => 5,
        'max_kurang' => 30,
        'max_umur' => 30,
        'sla_days' => (int) env('CASEMIX_DASHBOARD_SLA_DAYS', 7), // tenggat pengajuan sejak tanggal FTP
    ],
    // Bridging VClaim BPJS (hanya GET Monitoring). Kredensial hanya dari .env.
    'vclaim' => [
        'enabled' => (bool) env('CASEMIX_VCLAIM_ENABLED', false),
        'base_url' => env('VCLAIM_BASE_URL'),
        'service' => env('VCLAIM_SERVICE'),
        'cons_id' => env('VCLAIM_CONS_ID'),
        'secret' => env('VCLAIM_SECRET'),
        'user_key' => env('VCLAIM_USER_KEY'),
        'ppk_nama' => env('VCLAIM_PPK_NAMA'),
        'timeout' => 30,
        'jeda_ms' => 300,
        'window_klaim_hari' => 60,
        'window_kunjungan_hari' => 7,
    ],

    // GIF loader sendiri (path relatif di public/); kosong = robot SVG bawaan.
    'loader_gif' => env('CASEMIX_LOADER_GIF'),
];
