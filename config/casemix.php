<?php

return [
    'app_name' => env('CASEMIX_APP_NAME', 'Casemix AI'),
    'hospital_name' => env('CASEMIX_HOSPITAL_NAME', 'Rumah Sakit'),

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
        'render_dpi' => 110,
        'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
        'queue' => env('CASEMIX_AI_QUEUE', 'ai'),
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
];
