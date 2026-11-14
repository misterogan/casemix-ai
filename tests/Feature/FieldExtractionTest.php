<?php

namespace Tests\Feature;

use App\Enums\ClaimStatus;
use App\Enums\ReviewStatus;
use App\Jobs\RunFieldExtraction;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\ClaimExtraction;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldExtractionTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('casemix.storage_disk'));
        $this->seed(RolePermissionSeeder::class);
        $this->petugas = User::factory()->create(['is_active' => true]);
        $this->petugas->assignRole('petugas');
        AiProvider::create(['name' => 'Uji', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'm', 'model_standard' => 'm',
            'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
    }

    /** Berkas hasil impor: identitas dari folder, sisanya kosong. */
    private function claim(): Claim
    {
        $claim = Claim::create(['nama_pasien' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001',
            'tarif' => ['kamar' => 0]]);
        foreach (['sep', 'resume_medis', 'rincian_biaya'] as $i => $type) {
            Storage::disk(config('casemix.storage_disk'))->put("claims/{$claim->id}/$type.png", base64_decode(self::PNG));
            $claim->documents()->create(['type' => $type, 'original_name' => "$type.png", 'path' => "claims/{$claim->id}/$type.png",
                'mime' => 'image/png', 'size' => 70, 'sha256' => str_repeat((string) $i, 64)]);
        }

        return $claim->fresh();
    }

    private function fakeAi(array $isian): void
    {
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'm', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'content' => [['type' => 'text', 'text' => json_encode(['isian' => $isian])]],
        ])]);
    }

    private function runExtraction(Claim $claim): ClaimExtraction
    {
        $x = ClaimExtraction::create(['claim_id' => $claim->id, 'status' => ReviewStatus::Antre, 'requested_by' => $this->petugas->id]);
        RunFieldExtraction::dispatchSync($x->id);

        return $x->fresh();
    }

    public function test_usulan_dibaca_dari_dokumen_tanpa_mengubah_berkas(): void
    {
        $claim = $this->claim();
        $this->fakeAi([
            ['field' => 'tanggal_masuk', 'nilai' => '01-11-2026', 'dokumen' => 'SEP', 'keyakinan' => 'tinggi'],
            ['field' => 'dpjp', 'nilai' => 'dr. Ani, Sp.PD', 'dokumen' => 'Resume medis', 'keyakinan' => 'tinggi'],
            ['field' => 'jenis_rawat', 'nilai' => 'inap', 'dokumen' => 'SEP', 'keyakinan' => 'tinggi'],
            ['field' => 'diagnosis_utama', 'nilai' => 'Pneumonia (J18.9)', 'dokumen' => 'Resume medis', 'keyakinan' => 'sedang'],
            ['field' => 'tarif.kamar', 'nilai' => '1.500.000', 'dokumen' => 'Rincian biaya', 'keyakinan' => 'rendah'],
            ['field' => 'no_peserta', 'nilai' => '0001234567890', 'dokumen' => 'SEP', 'keyakinan' => 'tinggi'],
            ['field' => 'tanggal_keluar', 'nilai' => 'tidak terbaca', 'dokumen' => 'SEP', 'keyakinan' => 'rendah'],
        ]);

        $x = $this->runExtraction($claim);

        $this->assertSame(ReviewStatus::Selesai, $x->status);
        // MySQL mengurutkan ulang kunci JSON, jadi urutan tidak dibandingkan
        $this->assertEqualsCanonicalizing(['tanggal_masuk', 'dpjp', 'jenis_rawat', 'diagnosis_utama', 'tarif.kamar'], array_keys($x->suggestions));
        $this->assertSame('Pneumonia', $x->suggestions['diagnosis_utama']['nilai'], 'Kode ICD harus dibuang.');
        $this->assertNull($claim->fresh()->dpjp, 'Usulan tidak boleh diterapkan otomatis.');

        // Nomor peserta tidak boleh ada di permintaan ke AI dari data berkas, dan instruksinya melarang menyalinnya.
        Http::assertSent(fn (HttpRequest $r) => str_contains(json_encode($r->data()), 'Jangan menyalin nomor kartu peserta'));
    }

    public function test_hanya_isian_yang_dicentang_yang_diterapkan_dengan_audit(): void
    {
        $claim = $this->claim();
        $this->fakeAi([
            ['field' => 'tanggal_masuk', 'nilai' => '2026-11-01', 'dokumen' => 'SEP', 'keyakinan' => 'tinggi'],
            ['field' => 'dpjp', 'nilai' => 'dr. Ani, Sp.PD', 'dokumen' => 'Resume medis', 'keyakinan' => 'tinggi'],
            ['field' => 'tarif.kamar', 'nilai' => 1500000, 'dokumen' => 'Rincian biaya', 'keyakinan' => 'sedang'],
        ]);
        $x = $this->runExtraction($claim);

        $this->actingAs($this->petugas)
            ->post(route('claims.extract.apply', [$claim, $x]), ['fields' => ['tanggal_masuk', 'tarif.kamar']])
            ->assertRedirect()->assertSessionHas('ok');

        $claim->refresh();
        $this->assertSame('2026-11-01', $claim->tanggal_masuk->toDateString());
        $this->assertSame(1500000, $claim->tarif['kamar']);
        $this->assertNull($claim->dpjp, 'Isian yang tidak dicentang tidak boleh diterapkan.');
        $this->assertNotNull($claim->content_updated_at);

        $log = AuditLog::where('action', 'berkas.isi_otomatis')->sole();
        $this->assertEquals(['lama' => null, 'baru' => '2026-11-01'], $log->properties['perubahan']['tanggal_masuk']);
        $this->assertSame($x->id, $log->properties['ekstraksi']);
        $this->assertSame(['tanggal_masuk', 'tarif.kamar'], $x->fresh()->applied_fields);

        // Usulan yang sudah sama dengan berkas tidak ditampilkan lagi
        $this->assertSame(['dpjp'], array_keys($x->fresh()->pendingFor($claim)));
    }

    public function test_usulan_sep_yang_dipakai_berkas_lain_dilewati(): void
    {
        Claim::create(['nama_pasien' => 'ANI', 'no_rm' => '000999', 'no_sep' => '0301R0011126V000999']);
        $claim = $this->claim();
        $this->fakeAi([['field' => 'no_sep', 'nilai' => '0301R0011126V000999', 'dokumen' => 'SEP', 'keyakinan' => 'tinggi']]);
        $x = $this->runExtraction($claim);

        $this->actingAs($this->petugas)->post(route('claims.extract.apply', [$claim, $x]), ['fields' => ['no_sep']])
            ->assertSessionHas('warn');
        $this->assertSame('0301R0011126V000001', $claim->fresh()->no_sep);
    }

    public function test_berkas_disetujui_tidak_bisa_diisi_otomatis(): void
    {
        $claim = $this->claim();
        $this->fakeAi([['field' => 'dpjp', 'nilai' => 'dr. Ani', 'dokumen' => 'Resume medis', 'keyakinan' => 'tinggi']]);
        $x = $this->runExtraction($claim);
        $claim->forceFill(['status' => ClaimStatus::Disetujui])->save();

        $this->actingAs($this->petugas)->post(route('claims.extract.apply', [$claim, $x]), ['fields' => ['dpjp']])->assertForbidden();
        $this->actingAs($this->petugas)->post(route('claims.extract', $claim))->assertForbidden();
    }

    public function test_jawaban_ai_rusak_ditandai_gagal(): void
    {
        $claim = $this->claim();
        Http::fake(['*/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'maaf, saya tidak bisa']]])]);

        $x = $this->runExtraction($claim);

        $this->assertSame(ReviewStatus::Gagal, $x->status);
        $this->assertNotEmpty($x->error);
    }

    public function test_halaman_berkas_menampilkan_usulan(): void
    {
        $claim = $this->claim();
        $this->fakeAi([['field' => 'dpjp', 'nilai' => 'dr. Ani, Sp.PD', 'dokumen' => 'Resume medis', 'keyakinan' => 'tinggi']]);
        $this->runExtraction($claim);

        $this->actingAs($this->petugas)->get(route('claims.show', $claim))
            ->assertOk()->assertSee('Usulan isian dari dokumen')->assertSee('dr. Ani, Sp.PD')->assertSee('Terapkan yang dicentang');
    }
}
