<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeserZonaWaktuTest extends TestCase
{
    use RefreshDatabase;

    public function test_timezone_bawaan_wib(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    public function test_dry_run_tidak_mengubah_dan_jalan_sekali(): void
    {
        DB::table('ftp_folders')->insert(['name' => 'F1', 'path' => '/F1', 'fingerprint' => 'x', 'files' => '[]', 'files_modified_at' => '2026-11-01 01:00:00',
            'created_at' => '2026-11-01 01:00:00', 'updated_at' => '2026-11-01 01:00:00']);

        $this->artisan('casemix:geser-zona-waktu --dry-run')->expectsOutputToContain('ftp_folders')->assertSuccessful();
        $this->assertSame('2026-11-01 01:00:00', DB::table('ftp_folders')->value('created_at'));

        $this->artisan('casemix:geser-zona-waktu')->assertSuccessful();
        $f = DB::table('ftp_folders')->first();
        $this->assertSame('2026-11-01 08:00:00', $f->created_at);
        $this->assertSame('2026-11-01 01:00:00', $f->files_modified_at);

        $this->artisan('casemix:geser-zona-waktu')->assertFailed();
        $this->assertSame('2026-11-01 08:00:00', DB::table('ftp_folders')->value('created_at'));
    }
}
