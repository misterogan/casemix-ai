<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IcdCode;
use App\Models\PnpkButir;
use App\Models\PnpkDokumen;
use App\Models\PnpkHalaman;
use App\Models\PnpkIcd;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\Pnpk\PnpkIcdTeks;
use App\Services\Pnpk\PnpkVerifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PnpkVerifikasiTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        foreach (['A01.0', 'A01.1', 'J18.9', 'K35.8'] as $k) {
            IcdCode::create(['system' => 'icd10', 'code' => $k, 'title' => $k]);
        }
        IcdCode::create(['system' => 'icd10', 'code' => 'B509', 'title' => 'Malaria falsiparum (master tanpa titik)']);
    }

    /** Halaman berisi isi + sisa tanda air "KE M EN TE RI AN" di setiap halaman. */
    private function halaman(int $n, string $isi): string
    {
        return "KE M EN TE RI AN KE SE HA TAN\nHalaman isi nomor $n tentang demam tifoid.\n$isi\nRE PU BLIK IN DO NE SIA";
    }

    private function dokumen(array $pages, array $over = []): PnpkDokumen
    {
        $d = PnpkDokumen::create($over + ['judul' => 'PNPK Tifoid', 'nomor_kmk' => 'KMK 1', 'tahun' => 2023, 'tanggal_berlaku' => '2023-01-01',
            'status' => 'berlaku', 'path' => 'pnpk/tifoid.pdf', 'sha256' => hash('sha256', uniqid('', true)), 'status_proses' => 'siap_tinjau']);
        foreach ($pages as $h => $t) {
            PnpkHalaman::create(['dokumen_id' => $d->id, 'halaman' => $h, 'teks' => $t]);
        }

        return $d;
    }

    private function halamanUji(): array
    {
        $p = [];
        for ($i = 1; $i <= 12; $i++) {
            $p[$i] = $this->halaman($i, 'Teks umum pedoman tanpa isi khusus.');
        }
        $p[3] = $this->halaman(3, "Diagnosis demam tifoid (A01.0) ditegakkan bila terdapat demam lebih dari tujuh hari disertai gang-\nguan saluran cerna KE M EN dan hasil kultur darah positif.");
        $p[10] = $this->halaman(10, 'Pasien dapat dipulangkan bila bebas demam selama dua puluh empat jam tanpa antipiretik.');
        $p[12] = "DAFTAR PUSTAKA\n1. Smith J. Typhoid K35.8 review. 2019.";

        return $p;
    }

    public function test_kutipan_toleran_tanda_air_tanda_hubung_dan_halaman_jauh(): void
    {
        $v = new PnpkVerifier;
        $p = $this->halamanUji();

        $this->assertSame(3, $v->cariHalaman('demam lebih dari tujuh hari disertai gangguan saluran cerna dan hasil kultur darah positif', 3, $p));
        $this->assertSame(10, $v->cariHalaman('dapat dipulangkan bila bebas demam selama dua puluh empat jam tanpa antipiretik', 2, $p));
        $this->assertNull($v->cariHalaman('pasien wajib dirawat di ruang isolasi selama empat belas hari penuh', 3, $p));
        $this->assertArrayHasKey('ke', $v->tandaAir($p));
    }

    public function test_icd_berbagai_format_sesuai_master(): void
    {
        $this->assertTrue(PnpkIcd::adaDiMaster('A01.0'));
        $this->assertTrue(PnpkIcd::adaDiMaster('A010'));
        $this->assertTrue(PnpkIcd::adaDiMaster('a01'));
        $this->assertTrue(PnpkIcd::adaDiMaster('B50.9'));
        $this->assertTrue(PnpkIcd::adaDiMaster('B50'));
        $this->assertFalse(PnpkIcd::adaDiMaster('A02'));
        $this->assertSame('A01.0', PnpkIcd::normalize(' a010 '));
    }

    public function test_icd_dari_teks_abaikan_daftar_pustaka(): void
    {
        $d = $this->dokumen($this->halamanUji());
        app(PnpkIcdTeks::class)->simpan($d);

        $this->assertSame(['A01.0'], PnpkIcd::where('dokumen_id', $d->id)->pluck('kode')->all());
        $this->assertSame(['sumber' => 'teks', 'status' => 'usulan'], PnpkIcd::where('dokumen_id', $d->id)->first()->only(['sumber', 'status']));
    }

    public function test_verifikasi_ulang_tanpa_ai_dan_tidak_menyentuh_tolak_manual(): void
    {
        Http::fake();
        $this->app->instance(AiManager::class, \Mockery::mock(AiManager::class)->shouldNotReceive('for', 'generate', 'defaultConfig')->getMock());
        $d = $this->dokumen($this->halamanUji());
        $kutipan = 'dapat dipulangkan bila bebas demam selama dua puluh empat jam tanpa antipiretik';
        $otomatis = PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'kriteria_pulang', 'ringkasan' => 'Pulang bila bebas demam 24 jam',
            'kutipan' => $kutipan, 'halaman' => 2, 'cocok_kutipan' => false, 'status' => 'ditolak', 'alasan_tolak' => PnpkVerifier::TOLAK_KUTIPAN]);
        $manual = PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'kriteria_pulang', 'ringkasan' => 'x', 'kutipan' => $kutipan, 'halaman' => 2,
            'cocok_kutipan' => false, 'status' => 'ditolak', 'alasan_tolak' => 'Tidak relevan', 'ditinjau_oleh' => $this->koor->id, 'ditinjau_at' => now()]);
        $karangan = PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'lainnya', 'ringkasan' => 'y', 'kutipan' => 'antibiotik wajib diberikan secara intravena selama dua minggu',
            'halaman' => 3, 'cocok_kutipan' => false, 'status' => 'ditolak', 'alasan_tolak' => PnpkVerifier::TOLAK_KUTIPAN]);
        $icdAi = PnpkIcd::create(['dokumen_id' => $d->id, 'kode' => 'B509', 'sumber' => 'ai', 'status' => 'ditolak', 'alasan_tolak' => PnpkVerifier::TOLAK_ICD]);

        $this->artisan('casemix:pnpk-verifikasi-ulang', ['--dokumen' => $d->id])
            ->expectsOutputToContain('butir cocok 0 → 1; ICD usulan 0 → 2')->assertSuccessful();

        $this->assertSame(['usulan', true, 10, 2], [$otomatis->fresh()->status, $otomatis->fresh()->cocok_kutipan, $otomatis->fresh()->halaman_pdf, $otomatis->fresh()->halaman_label]);
        $this->assertSame(['ditolak', 'Tidak relevan', null], [$manual->fresh()->status, $manual->fresh()->alasan_tolak, $manual->fresh()->halaman_pdf]);
        $this->assertSame('ditolak', $karangan->fresh()->status);
        $this->assertSame(['B50.9', 'usulan'], [$icdAi->fresh()->kode, $icdAi->fresh()->status]);
        $this->assertTrue(AuditLog::where('action', 'pnpk.verifikasi_ulang')->exists());
        Http::assertNothingSent();

        $this->actingAs($this->koor)->get(route('acuan.pnpk.show', $d))->assertOk()->assertSee('data-goto="10"', false);
    }

    public function test_route_pdf_inline(): void
    {
        $d = $this->dokumen([1 => 'x']);
        Storage::disk('local')->put($d->path, "%PDF-1.4\nisi\n%%EOF");

        $r = $this->actingAs($this->koor)->get(route('acuan.pnpk.pdf', $d))->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'));
    }

    public function test_tombol_selesai_nonaktif_sesuai_aturan(): void
    {
        $d = $this->dokumen([1 => 'x']);
        $this->actingAs($this->koor)->get(route('acuan.pnpk.show', $d))->assertSee('Belum ada butir yang disetujui')->assertSee('data-selesai disabled', false);
        $this->actingAs($this->koor)->post(route('acuan.pnpk.selesai', $d))->assertSessionHasErrors('aksi');

        PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'lainnya', 'ringkasan' => 'r', 'kutipan' => 'x', 'halaman' => 1, 'cocok_kutipan' => true, 'status' => 'disetujui']);
        $d->update(['perlu_dilengkapi' => true]);
        $this->actingAs($this->koor)->get(route('acuan.pnpk.show', $d))->assertSee('Lengkapi metadata')->assertSee('data-selesai disabled', false);

        $d->update(['perlu_dilengkapi' => false]);
        $this->actingAs($this->koor)->get(route('acuan.pnpk.show', $d))->assertDontSee('data-selesai disabled', false);
        $this->actingAs($this->koor)->post(route('acuan.pnpk.selesai', $d))->assertRedirect(route('acuan.pnpk.index'));
    }
}
