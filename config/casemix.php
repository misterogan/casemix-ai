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
