<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // File di storage dibuat oleh web (www-data) dan oleh worker/deploy (oem, grup www-data).
        // umask 002 membuat file baru bisa ditulis oleh grup, sehingga keduanya tidak saling terkunci.
        umask(0002);
    }

    public function boot(): void
    {
        Carbon::setLocale('id');
        Paginator::defaultView('components.pagination');
    }
}
