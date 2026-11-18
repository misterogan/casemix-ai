<?php

namespace Tests\Feature;

use App\Jobs\EkstrakPnpk;
use App\Models\AuditLog;
use App\Models\PnpkDokumen;
use App\Models\User;
use App\Services\Pnpk\PnpkTextExtractor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PnpkImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pnpk-import-' . uniqid();
        File::ensureDirectoryExists($this->dir);
        // pdftotext tidak dipanggil di test: halaman contoh memuat kalimat penetapan.
        $this->app->instance(PnpkTextExtractor::class, new class(app(\App\Services\Ai\AiManager::class), app(\App\Services\Ai\AiBudget::class)) extends PnpkTextExtractor {
            public function textPages(string $path): array
            {
                return str_contains(file_get_contents($path), 'tanpa-tanggal')
                    ? [1 => 'Sampul', 2 => 'Isi']
                    : [1 => 'Sampul', 2 => 'Isi', 3 => "Ditetapkan di Jakarta\n pada   tanggal 12 Januari\n2023\nMENTERI KESEHATAN"];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function pdf(string $nama, string $isi = 'isi'): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $nama, "%PDF-1.4\n$nama $isi\n%%EOF");
    }

    public function test_pola_nama_lengkap_metadata_tanggal_audit_dan_job(): void
    {
        $this->pdf('dm-tipe-2_2020_KMK-603-2020.pdf');
        $this->artisan('casemix:pnpk-import', ['path' => $this->dir])
            ->expectsOutputToContain('1 diimpor, 0 dilewati, 0 perlu dilengkapi')->assertSuccessful();

        $d = PnpkDokumen::sole();
        $this->assertSame('PNPK Tata Laksana DM tipe 2', $d->judul);
        $this->assertSame(2020, $d->tahun);
        $this->assertSame('HK.01.07/MENKES/603/2020', $d->nomor_kmk);
        $this->assertSame('2023-01-12', $d->tanggal_berlaku->format('Y-m-d'));
        $this->assertFalse($d->perlu_dilengkapi);
        $this->assertSame('berlaku', $d->status);
        Storage::disk('local')->assertExists($d->path);
        $this->assertFileExists($this->dir . '/dm-tipe-2_2020_KMK-603-2020.pdf'); // disalin, bukan dipindah
        Queue::assertPushed(EkstrakPnpk::class, fn ($j) => $j->dokumenId === $d->id);
        $audit = AuditLog::where('action', 'pnpk.impor')->sole();
        $this->assertSame('console', $audit->properties['user']);
    }

    public function test_tanpa_kmk_atau_tanggal_perlu_dilengkapi(): void
    {
        $this->pdf('ppok_2019.pdf');
        $this->pdf('asma_2021_KMK-1-2021.pdf', 'tanpa-tanggal');
        $this->artisan('casemix:pnpk-import', ['path' => $this->dir])
            ->expectsOutputToContain('2 diimpor, 0 dilewati, 2 perlu dilengkapi')->assertSuccessful();

        $ppok = PnpkDokumen::where('judul', 'PNPK Tata Laksana PPOK')->sole();
        $this->assertNull($ppok->nomor_kmk);
        $this->assertSame(2019, $ppok->tahun);
        $this->assertTrue($ppok->perlu_dilengkapi);
        $asma = PnpkDokumen::where('judul', 'PNPK Tata Laksana Asma')->sole();
        $this->assertNull($asma->tanggal_berlaku);
        $this->assertTrue($asma->perlu_dilengkapi);
    }

    public function test_duplikat_dan_bukan_pdf_dilewati(): void
    {
        $this->pdf('ppok_2019_KMK-1-2019.pdf');
        file_put_contents($this->dir . '/palsu_2020_KMK-2-2020.pdf', 'bukan pdf');
        $this->artisan('casemix:pnpk-import', ['path' => $this->dir])->expectsOutputToContain('1 diimpor, 1 dilewati')->assertSuccessful();
        $this->artisan('casemix:pnpk-import', ['path' => $this->dir])->expectsOutputToContain('0 diimpor, 2 dilewati')->assertSuccessful();
        $this->assertSame(1, PnpkDokumen::count());
    }

    public function test_dry_run_tidak_menyimpan_dan_hanya_memproses_sebagian(): void
    {
        $this->pdf('ppok_2019_KMK-1-2019.pdf');
        $this->pdf('pneumonia_2023_KMK-2-2023.pdf');
        $this->artisan('casemix:pnpk-import', ['path' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('2 diimpor, 0 dilewati, 0 perlu dilengkapi')->assertSuccessful();
        $this->assertSame(0, PnpkDokumen::count());
        Queue::assertNothingPushed();

        $this->artisan('casemix:pnpk-import', ['path' => $this->dir, '--hanya' => 'pneu*'])->expectsOutputToContain('1 diimpor')->assertSuccessful();
        $this->assertSame(['PNPK Tata Laksana Pneumonia'], PnpkDokumen::pluck('judul')->all());
    }

    public function test_badge_dan_form_lengkapi_metadata(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->pdf('ppok_2019.pdf');
        $this->artisan('casemix:pnpk-import', ['path' => $this->dir])->assertSuccessful();
        $d = PnpkDokumen::sole();

        $this->actingAs($admin)->get(route('acuan.pnpk.index'))->assertOk()->assertSee('Perlu dilengkapi');
        $this->actingAs($admin)->get(route('acuan.pnpk.edit', $d))->assertOk()->assertSee('Diganti oleh');
        $this->actingAs($admin)->put(route('acuan.pnpk.update', $d), ['judul' => 'PNPK Tata Laksana PPOK', 'nomor_kmk' => 'HK.01.07/MENKES/9/2019',
            'tahun' => 2019, 'tanggal_berlaku' => '2019-05-01', 'status' => 'berlaku'])->assertRedirect();
        $this->assertFalse($d->fresh()->perlu_dilengkapi);
        $this->actingAs($admin)->get(route('acuan.pnpk.index'))->assertDontSee('Perlu dilengkapi');
    }
}
