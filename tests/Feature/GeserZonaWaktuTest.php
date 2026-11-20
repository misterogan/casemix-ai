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

        DB::table('ftp_folders')->insert(['name' => 'F2', 'path' => '/F2', 'fingerprint' => 'y', 'files' => '[]',
            'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:00:00']);

        $this->artisan('casemix:geser-zona-waktu')->assertFailed();
        $this->artisan('casemix:geser-zona-waktu --sebelum=2026-11-02')->assertFailed();
        $this->assertSame('2026-11-01 01:00:00', DB::table('ftp_folders')->where('name', 'F1')->value('created_at'));

        $this->artisan('casemix:geser-zona-waktu', ['--sebelum' => '2026-11-02 00:00:00'])->assertSuccessful();
        $f = DB::table('ftp_folders')->where('name', 'F1')->first();
        $this->assertSame('2026-11-01 08:00:00', $f->created_at);
        $this->assertSame('2026-11-01 08:00:00', $f->updated_at);
        $this->assertSame('2026-11-01 01:00:00', $f->files_modified_at);
        $this->assertSame('2026-11-02 10:00:00', DB::table('ftp_folders')->where('name', 'F2')->value('created_at'));

        $this->artisan('casemix:geser-zona-waktu', ['--sebelum' => '2026-11-02 00:00:00'])->assertFailed();
        $this->assertSame('2026-11-01 08:00:00', DB::table('ftp_folders')->value('created_at'));
    }

    public function test_hanya_kolom_lama_yang_digeser(): void
    {
        DB::table('ftp_folders')->insert(['name' => 'F1', 'path' => '/F1', 'fingerprint' => 'x', 'files' => '[]',
            'created_at' => '2026-11-01 01:00:00', 'updated_at' => '2026-11-03 09:00:00']);

        $this->artisan('casemix:geser-zona-waktu', ['--sebelum' => '2026-11-02 00:00:00'])->assertSuccessful();
        $f = DB::table('ftp_folders')->first();
        $this->assertSame('2026-11-01 08:00:00', $f->created_at);
        $this->assertSame('2026-11-03 09:00:00', $f->updated_at);
        $this->assertTrue(DB::table('app_settings')->where('key', 'tz_geser_wib_selesai')->exists());
    }
}
