<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daftar folder FTP DHealth diperbarui otomatis tiap jam. Butuh cron: * * * * * php artisan schedule:run
\Illuminate\Support\Facades\Schedule::command('casemix:sync-ftp')->hourly()->withoutOverlapping(50);
