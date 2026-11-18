<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daftar folder FTP Enigma diperbarui otomatis tiap jam. Butuh cron: * * * * * php artisan schedule:run
\Illuminate\Support\Facades\Schedule::command('casemix:sync-ftp')->everyFifteenMinutes()->withoutOverlapping(14);

// Salinan arsip dokumen yang melewati masa simpan dihapus tiap hari (hasil verifikasi tetap)
\Illuminate\Support\Facades\Schedule::command('casemix:archive-prune')->daily()->withoutOverlapping();

// Bridging VClaim Monitoring (hanya GET; berhenti diam bila CASEMIX_VCLAIM_ENABLED=false)
\Illuminate\Support\Facades\Schedule::command('casemix:vclaim-sync-kunjungan')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('casemix:vclaim-sync-klaim')->dailyAt('02:00')->withoutOverlapping();
