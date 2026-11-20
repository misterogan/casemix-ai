<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\RunClaimReview;
use App\Jobs\RunFieldExtraction;
use App\Models\AiProvider;
use App\Models\AiReview;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\ClaimExtraction;
use App\Models\User;
use App\Jobs\RunFolderAnalysis;
use App\Models\AiUsage;
use App\Models\CodeSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\PnpkButir;
use App\Models\PnpkDokumen;
use App\Models\PnpkIcd;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Ai\AnggaranHabis;
use App\Services\Import\SimrsFtp;
use App\Services\Pnpk\PnpkAcuan;
use App\Services\Pnpk\PnpkTextExtractor;
use App\Support\VerificationRubric;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    private User $admin;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->provider = AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k',
            'model_fast' => 'claude-haiku-5-5', 'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true,
            'data_location' => 'Anthropic, global']);
    }

    private function budget(): AiBudget
    {
        return app(AiBudget::class);
    }

    private function setBudget(?float $usd, int $warn = 80): void
    {
        AppSetting::put(AiBudget::KEY_BUDGET, $usd);
        AppSetting::put(AiBudget::KEY_WARN, $warn);
        Cache::forget('ai_budget_status');
    }

    /** Pemakaian yang sudah terjadi bulan ini. */
    private function spend(float $usd, ?\DateTimeInterface $at = null): void
    {
        $claim = Claim::create(['nama_pasien' => 'X', 'no_rm' => (string) random_int(1000, 99999)]);
        AiReview::create(['claim_id' => $claim->id, 'status' => 'selesai', 'cost_usd' => $usd, 'tokens_in' => 1, 'tokens_out' => 1,
            'model' => 'claude-sonnet-5-5', 'finished_at' => $at ?? now(), 'requested_by' => $this->petugas->id]);
        AiUsage::forceCreate(['jenis' => 'analisa', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 1, 'output_tokens' => 1,
            'biaya_usd' => $usd, 'created_at' => $at ?? now()]);
    }

    private function claimWithDocs(): Claim
    {
        \Illuminate\Support\Facades\Storage::fake(config('casemix.storage_disk'));
        $claim = Claim::create(['nama_pasien' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001']);
        \Illuminate\Support\Facades\Storage::disk(config('casemix.storage_disk'))->put('x/sep.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $claim->documents()->create(['type' => 'sep', 'original_name' => 'sep.png', 'path' => 'x/sep.png', 'mime' => 'image/png',
            'size' => 70, 'sha256' => str_repeat('a', 64)]);

        return $claim;
    }

    // ------------------------------------------------------------ harga dan biaya

    public function test_harga_dari_model_claude_dan_penyedia(): void
    {
        $this->assertSame([2.0, 10.0], $this->budget()->prices(null, 'claude-sonnet-5-5'), 'Sonnet 5.5, bukan Sonnet 5');
        $this->assertSame([2.0, 10.0], $this->budget()->prices(null, 'claude-sonnet-5'));
        $this->assertSame([0.1, 0.5], $this->budget()->prices(null, 'claude-haiku-5-5', 90_000));
        $this->assertSame([0.5, 2.5], $this->budget()->prices(null, 'claude-haiku-5-5', 150_000), 'Haiku 5.5 lebih mahal untuk prompt > 100 ribu token');
        $this->assertNull($this->budget()->prices(null, 'llama-3-70b'));

        $this->provider->update(['price_input_usd' => 0.3, 'price_output_usd' => 1.2]);
        $this->assertSame([0.3, 1.2], $this->budget()->prices($this->provider->fresh(), 'llama-3-70b'), 'Harga di Penyedia AI diutamakan');
    }

    public function test_biaya_dihitung_dari_token(): void
    {
        // 30.000 token input × $2/juta + 2.000 token output × $10/juta = $0,06 + $0,02
        $this->assertSame(0.08, $this->budget()->cost(null, 'claude-sonnet-5-5', 30_000, 2_000));
    }

    public function test_pemakaian_hanya_bulan_berjalan(): void
    {
        $this->spend(5.0);
        $this->spend(100.0, now()->subMonthNoOverflow()->endOfMonth());

        $this->assertSame(5.0, $this->budget()->spent());
    }

    // ------------------------------------------------------------ penahanan

    public function test_tanpa_anggaran_tidak_pernah_menahan(): void
    {
        $this->spend(10_000);
        $this->assertNull($this->budget()->startBlocker());
        $this->assertSame('tanpa_batas', $this->budget()->status()['state']);
    }

    public function test_status_aman_peringatan_habis(): void
    {
        $this->setBudget(200);
        $this->spend(100);
        $this->assertSame('aman', $this->budget()->status()['state']);

        $this->spend(65); // 165 = 82%
        $this->assertSame('peringatan', $this->budget()->status()['state']);
        $this->assertNull($this->budget()->startBlocker());

        $this->spend(35); // 200
        $this->assertSame('habis', $this->budget()->status()['state']);
        $this->assertStringContainsString('Anggaran AI bulan ini sudah habis', $this->budget()->startBlocker());
    }

    public function test_antrean_ikut_diperhitungkan(): void
    {
        $this->setBudget(1.00);
        $this->spend(0.85); // rata-rata 0,85 per proses
        $claim = $this->claimWithDocs();
        AiReview::create(['claim_id' => $claim->id, 'status' => ReviewStatus::Antre]);

        $this->assertNotNull($this->budget()->startBlocker(), '0,85 terpakai + 1 antrean ≈ 0,85 melewati 1,00');
        $this->assertNull($this->budget()->runBlocker(), 'Job yang sudah antre tetap boleh jalan selama pemakaian nyata < anggaran');
    }

    public function test_tombol_periksa_dan_isi_otomatis_ditahan_saat_habis(): void
    {
        Queue::fake();
        $this->setBudget(10);
        $this->spend(10);
        $claim = $this->claimWithDocs();

        $this->actingAs($this->petugas)->post(route('claims.review', $claim))
            ->assertSessionHas('warn', fn ($m) => str_contains($m, 'Anggaran AI bulan ini sudah habis'));
        $this->actingAs($this->petugas)->post(route('claims.extract', $claim))
            ->assertSessionHas('warn', fn ($m) => str_contains($m, 'Anggaran AI bulan ini sudah habis'));

        Queue::assertNothingPushed();
        $this->assertSame(0, $claim->reviews()->count());
        $this->assertSame(0, $claim->extractions()->count());
    }

    public function test_job_yang_sudah_antre_berhenti_sebelum_memanggil_ai_dan_aturan_tetap_tersimpan(): void
    {
        Http::fake();
        $claim = $this->claimWithDocs();
        $review = AiReview::create(['claim_id' => $claim->id, 'status' => ReviewStatus::Antre]);
        $x = ClaimExtraction::create(['claim_id' => $claim->id, 'status' => ReviewStatus::Antre]);
        $this->setBudget(5);
        $this->spend(5);

        RunClaimReview::dispatchSync($review->id);
        RunFieldExtraction::dispatchSync($x->id);

        Http::assertNothingSent();
        $review->refresh();
        $this->assertSame(ReviewStatus::Gagal, $review->status);
        $this->assertStringContainsString('Anggaran AI', $review->error);
        $this->assertNotEmpty($review->rule_findings, 'Temuan aturan otomatis tetap tersimpan');
        $this->assertSame(ReviewStatus::Gagal, $x->fresh()->status);
    }

    public function test_biaya_dicatat_setelah_proses_dan_peringatan_dicatat_sekali(): void
    {
        $this->setBudget(0.10, 50);
        $claim = $this->claimWithDocs();
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 30_000, 'output_tokens' => 2_000],
            'content' => [['type' => 'text', 'text' => json_encode(['isian' => []])]],
        ])]);

        foreach ([1, 2] as $_) {
            $x = ClaimExtraction::create(['claim_id' => $claim->id, 'status' => ReviewStatus::Antre]);
            RunFieldExtraction::dispatchSync($x->id);
        }

        $this->assertSame(ReviewStatus::Selesai, $x->fresh()->status, (string) $x->fresh()->error);
        $this->assertEquals(0.08, (float) $x->fresh()->cost_usd);
        $this->assertSame(1, AuditLog::where('action', 'anggaran.peringatan')->count(), 'Peringatan cukup sekali per bulan');
    }

    // ------------------------------------------------------------ halaman

    public function test_admin_mengatur_anggaran_dengan_audit(): void
    {
        $this->actingAs($this->admin)->get(route('admin.budget.index'))->assertOk()->assertSee('Anggaran AI');
        $this->actingAs($this->admin)->put(route('admin.budget.update'), ['budget' => '200', 'warn_pct' => 80])->assertSessionHas('ok');

        $this->assertSame(200.0, $this->budget()->budget());
        $this->assertEquals(['lama' => null, 'baru' => 200], AuditLog::where('action', 'admin.anggaran')->sole()->properties['perubahan']['anggaran_usd']);

        $this->actingAs($this->petugas)->get(route('admin.budget.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.providers.create'))->assertOk()->assertSee('Harga input');
    }

    public function test_petugas_melihat_pemberitahuan_saat_habis(): void
    {
        $this->setBudget(1);
        $this->spend(1);
        $claim = $this->claimWithDocs();

        $this->actingAs($this->petugas)->get(route('claims.show', $claim))
            ->assertOk()->assertSee('Anggaran AI bulan ini habis')->assertDontSee('Isi otomatis dari dokumen</button>', false);
    }

    // ------------------------------------------------------------ ai_usage & anggaran dari config

    private function pakai(float $usd, string $jenis = 'analisa', ?int $ref = null): void
    {
        AiUsage::create(['jenis' => $jenis, 'ref_id' => $ref, 'model' => 'claude-sonnet-5-5', 'input_tokens' => 10, 'output_tokens' => 1, 'biaya_usd' => $usd]);
    }

    private function balasan(string $model = 'claude-haiku-5-5', string $text = '{"icd":[],"butir":[]}', int $in = 1_000_000, int $out = 1_000_000): array
    {
        return ['id' => 'x', 'model' => $model, 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
            'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function panggil(string $jenis, string $model = 'claude-haiku-5-5'): AiResponse
    {
        return app(AiManager::class)->for($this->provider->fresh())->generate((new AiRequest($model, jenis: $jenis, refId: 7))->text('x'));
    }

    public function test_ai_usage_dicatat_dengan_biaya_per_model(): void
    {
        $this->assertSame(3.0, AiUsage::biaya('claude-haiku-5-5', 1_000_000, 1_000_000)); // prompt > 100K: $0,50/$2,50
        $this->assertSame(12.0, AiUsage::biaya('claude-sonnet-5-5', 1_000_000, 1_000_000));
        $this->assertSame(24.0, AiUsage::biaya('claude-opus-5-5', 1_000_000, 1_000_000));

        Http::fake(['*/v1/messages' => Http::sequence()->push($this->balasan())->push($this->balasan('claude-opus-5-5', 'ok', 500_000, 100_000))]);
        $this->panggil('usulan_regulasi');
        $this->panggil('analisa', 'claude-opus-5-5');

        $rows = AiUsage::orderBy('id')->get();
        $this->assertSame(['usulan_regulasi', 'analisa'], $rows->pluck('jenis')->all());
        $this->assertSame([3.0, 4.0], $rows->pluck('biaya_usd')->all()); // 0,5×4 + 0,1×20
        $this->assertSame(7, $rows[0]->ref_id);
        $this->assertSame(1_000_000, $rows[0]->input_tokens);
        $this->assertSame('claude-haiku-5-5', app(AiManager::class)->model('usulan_regulasi', $this->provider));
        $this->assertSame('claude-sonnet-5-5', app(AiManager::class)->model('draf_sanggahan', $this->provider));
    }

    public function test_banner_dan_penahan_memakai_sum_ai_usage_dan_app_settings(): void
    {
        config(['casemix.ai.anggaran_bulanan_usd' => 3]);
        $this->assertSame(3.0, $this->budget()->budget(), 'env hanya nilai awal');
        $this->setBudget(4.2);
        $this->assertSame(4.2, $this->budget()->budget(), 'app_settings mengalahkan env');
        $this->assertSame(4.2, $this->budget()->anggaran());

        // Biaya di baris AiReview (cost_usd) tidak lagi dihitung; hanya ai_usage.
        $claim = Claim::create(['nama_pasien' => 'X', 'no_rm' => '1']);
        AiReview::create(['claim_id' => $claim->id, 'status' => 'selesai', 'cost_usd' => 4.08, 'finished_at' => now()]);
        $this->pakai(0.5291);
        AiUsage::forceCreate(['jenis' => 'analisa', 'model' => 'x', 'biaya_usd' => 9, 'created_at' => now()->subMonthNoOverflow()->endOfMonth()]);
        $this->assertSame(0.5291, $this->budget()->spent());
        $this->assertSame('aman', $this->budget()->status()['state']);

        $this->setBudget(null);
        $this->actingAs($this->admin)->put(route('admin.budget.update'), ['budget' => '', 'warn_pct' => 80]);
        $this->assertNull($this->budget()->budget(), 'Tanpa batas disimpan sebagai 0, tidak kembali ke env');
    }

    public function test_tarif_haiku_5_5_prompt_di_atas_100_ribu(): void
    {
        $this->assertSame(0.06, AiUsage::biaya('claude-haiku-5-5', 100_000, 100_000)); // 0,01 + 0,05
        $this->assertSame(0.29955, AiUsage::biaya('claude-haiku-5-5', 99_000, 100_000, 1_001)); // prompt > 100K → $0,50/$2,50
        $this->assertSame(0.3125, AiUsage::biaya('claude-haiku-5-5', 125_000, 100_000)); // 0,0625 + 0,25
    }

    public function test_banner_80_persen_untuk_koordinator(): void
    {
        config(['casemix.ai.anggaran_bulanan_usd' => 10]);
        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->pakai(7.9);
        $this->actingAs($koor)->get(route('sistem.pemakaian_ai'))->assertOk()->assertDontSee('data-banner-pemakaian-ai', false);
        $this->pakai(0.1);
        $this->actingAs($koor)->get(route('sistem.pemakaian_ai'))->assertOk()->assertSee('Pemakaian AI bulan ini 80% dari anggaran');
    }

    public function test_100_persen_usulan_ditolak_analisa_jalan_sampai_cadangan_lalu_ditahan(): void
    {
        config(['casemix.ai.anggaran_bulanan_usd' => 10, 'casemix.ai.anggaran_cadangan_analisa_usd' => 1]);
        $this->pakai(10);
        Http::fake(['*/v1/messages' => Http::response($this->balasan('claude-haiku-5-5', 'ok', 100_000, 180_000))]); // $0,01 + $0,09 = $0,10 per panggilan

        foreach (['usulan_regulasi', 'draf_sanggahan'] as $jenis) {
            try {
                $this->panggil($jenis);
                $this->fail("$jenis seharusnya ditolak");
            } catch (AnggaranHabis $e) {
                $this->assertStringContainsString('Anggaran AI bulan ini habis', $e->getMessage());
            }
        }
        Http::assertSentCount(0);

        for ($i = 0; $i < 10; $i++) { // cadangan $1 = 10 panggilan analisa
            $this->panggil('analisa');
        }
        Http::assertSentCount(10);
        $this->expectException(AnggaranHabis::class);
        $this->panggil('analisa');
    }

    public function test_analisa_ditahan_menunggu_anggaran_bukan_gagal(): void
    {
        config(['casemix.ai.anggaran_bulanan_usd' => 10, 'casemix.ai.anggaran_cadangan_analisa_usd' => 1]);
        $this->pakai(11);
        Http::fake();
        [$folder, $a] = $this->analisaFolder();

        $this->assertSame(ReviewStatus::MenungguAnggaran, $a->status);
        $this->assertSame('menunggu_anggaran', $folder->analysis_status);
        $this->assertStringContainsString('menunggu anggaran', $a->error);
        $this->assertSame(['dibatalkan'], $a->documents()->pluck('status')->map(fn ($s) => $s->value)->unique()->values()->all());
        Http::assertSentCount(0);
    }

    public function test_anggaran_nol_tanpa_batas(): void
    {
        config(['casemix.ai.anggaran_bulanan_usd' => 0]);
        $this->pakai(5000);
        Http::fake(['*/v1/messages' => Http::response($this->balasan())]);
        $this->panggil('usulan_regulasi');
        $this->assertNull($this->budget()->persenPemakaian());
        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->actingAs($koor)->get(route('sistem.pemakaian_ai'))->assertOk()->assertDontSee('data-banner-pemakaian-ai', false)->assertSee('tanpa batas');
    }

    public function test_butir_pnpk_dipangkas_sesuai_batas_dan_prioritas(): void
    {
        $f = FtpFolder::create(['path' => '/ftp/x', 'name' => 'x', 'nama_pasien' => 'P', 'files' => [], 'fingerprint' => 'f']);
        $a = FolderAnalysis::create(['ftp_folder_id' => $f->id, 'status' => ReviewStatus::Selesai]);
        CodeSuggestion::create(['folder_analysis_id' => $a->id, 'system' => 'icd10', 'peran' => 'utama', 'code' => 'J18.9', 'title' => 'x',
            'alasan' => 'x', 'sumber' => 'resume', 'bukti' => 'x', 'halaman' => 1, 'keyakinan' => 'kuat']);
        $dok = function (string $judul, string $kode, int $n) {
            $d = PnpkDokumen::create(['judul' => $judul, 'tahun' => 2023, 'tanggal_berlaku' => '2023-01-01', 'status' => 'berlaku',
                'path' => 'pnpk/x.pdf', 'sha256' => hash('sha256', $judul), 'status_proses' => 'siap_tinjau']);
            PnpkIcd::create(['dokumen_id' => $d->id, 'kode' => $kode, 'sumber' => 'manual', 'status' => 'disetujui']);
            for ($i = 0; $i < $n; $i++) {
                PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'tata_laksana', 'ringkasan' => str_repeat('r', 50),
                    'kutipan' => 'kutipan', 'halaman' => 1, 'cocok_kutipan' => true, 'status' => 'disetujui']);
            }

            return $d;
        };
        $prefix = $dok('A Prefix', 'J18', 10); // judul A diurutkan lebih dulu, tetapi hanya cocok prefix 3 digit
        $lengkap = $dok('B Lengkap', 'J18.9', 10);

        config(['casemix.regulasi.max_butir' => 12, 'casemix.regulasi.max_token' => 100_000]);
        $b = app(PnpkAcuan::class)->butirForFolder($f);
        $this->assertCount(12, $b);
        $this->assertSame(array_fill(0, 10, $lengkap->id), $b->take(10)->pluck('dokumen_id')->all());
        $this->assertSame($prefix->id, $b[10]->dokumen_id);

        config(['casemix.regulasi.max_token' => 100]); // tiap butir ±40 token → 2 butir
        $this->assertCount(2, app(PnpkAcuan::class)->butirForFolder($f));

        $blok = app(PnpkAcuan::class)->promptBlock($b);
        $this->assertStringContainsString('"halaman":1', $blok);
        $this->assertStringNotContainsString('"teks"', $blok);
    }

    /** Folder 1 file + analisa lewat pipeline (FTP tiruan). @return array{0: FtpFolder, 1: FolderAnalysis} */
    private function analisaFolder(?FtpFolder $folder = null, bool $paksa = false): array
    {
        Storage::fake(config('casemix.storage_disk'));
        $this->app->instance(SimrsFtp::class, new class extends SimrsFtp
        {
            public function __construct() {}

            public function download(string $remotePath, string $localRelative): string
            {
                Storage::disk(config('casemix.storage_disk'))->put($localRelative,
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

                return $localRelative;
            }
        });
        $folder ??= FtpFolder::create(['path' => '/ftp/data/000001_PASIEN RAHASIA_SEP1', 'name' => '000001_PASIEN RAHASIA_SEP1',
            'nama_pasien' => 'PASIEN RAHASIA', 'fingerprint' => 'f',
            'files' => [['path' => '/ftp/data/000001_PASIEN RAHASIA_SEP1/Resume Medis.png', 'name' => 'Resume Medis.png', 'size' => 1000, 'modified' => 1]]]);
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre, 'requested_by' => $this->admin->id, 'paksa' => $paksa]);
        RunFolderAnalysis::dispatchSync($a->id);

        return [$folder->fresh(), $a->fresh()];
    }

    public function test_analisa_tidak_diulang_bila_hash_sama_kecuali_dipaksa(): void
    {
        $rubrik = json_encode(['ringkasan' => 'Diperiksa.', 'rubrik' => array_map(fn ($k) => ['kode' => $k, 'status' => 'kurang', 'catatan' => 'kurang'],
            VerificationRubric::aiCodes())]);
        $baca = json_encode(['doc_type' => 'resume_medis', 'summary' => 'Resume.', 'findings' => []]);
        Http::fake(['*/v1/messages' => Http::sequence()->push($this->balasan('claude-sonnet-5-5', $baca, 100, 10))
            ->push($this->balasan('claude-sonnet-5-5', $rubrik, 100, 10))->push($this->balasan('claude-sonnet-5-5', $rubrik, 100, 10))]);

        [$folder, $a1] = $this->analisaFolder();
        $this->assertSame(ReviewStatus::Selesai, $a1->status, (string) $a1->error);
        $this->assertNotNull($a1->hash_masukan);
        Http::assertSentCount(2); // baca + periksa silang

        [, $a2] = $this->analisaFolder($folder);
        $this->assertSame(ReviewStatus::Selesai, $a2->status, (string) $a2->error);
        $this->assertSame($a1->hash_masukan, $a2->hash_masukan);
        Http::assertSentCount(2); // tidak ada panggilan AI baru

        [, $a3] = $this->analisaFolder($folder, paksa: true); // tombol "Analisa ulang"
        $this->assertSame(ReviewStatus::Selesai, $a3->status, (string) $a3->error);
        Http::assertSentCount(3); // dokumen tetap dipakai ulang, periksa silang dipanggil lagi
        $this->assertSame(2, AiUsage::where('jenis', 'analisa')->where('ref_id', $a1->id)->count());
    }

    public function test_estimasi_pnpk_tidak_menyimpan_dan_menandai_scan(): void
    {
        Queue::fake();
        Storage::fake('local');
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pnpk-estimasi-' . uniqid();
        File::ensureDirectoryExists($dir);
        file_put_contents("$dir/teks.pdf", "%PDF-1.4\nteks");
        file_put_contents("$dir/scan.pdf", "%PDF-1.4\nscan");
        file_put_contents("$dir/lain.pdf", "%PDF-1.4\nteks");
        $this->app->instance(PnpkTextExtractor::class, new class(app(AiManager::class), $this->budget()) extends PnpkTextExtractor
        {
            public function textPages(string $path): array
            {
                return str_contains(file_get_contents($path), 'scan') ? [1 => 'x', 2 => ''] : array_fill(1, 30, str_repeat('a', 900));
            }
        });

        try {
            $this->artisan('casemix:pnpk-import', ['path' => $dir, '--estimasi' => true, '--hanya' => '*s*.pdf'])
                ->expectsOutputToContain('kemungkinan scan')
                ->expectsOutputToContain('claude-sonnet-5-5')
                ->doesntExpectOutputToContain('lain.pdf')
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }
        $this->assertSame(0, PnpkDokumen::count());
        $this->assertSame(0, AiUsage::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_halaman_pemakaian_ai_admin_tanpa_data_pasien(): void
    {
        $folder = FtpFolder::create(['path' => '/ftp/a', 'name' => '000009_PASIEN RAHASIA_SEP9', 'nama_pasien' => 'PASIEN RAHASIA',
            'files' => [], 'fingerprint' => 'f']);
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Selesai]);
        $this->pakai(1.25, 'analisa', $a->id);
        $this->pakai(0.5, 'usulan_regulasi', 3);
        config(['casemix.ai.anggaran_bulanan_usd' => 10]);

        $this->actingAs($this->admin)->get(route('sistem.pemakaian_ai'))->assertOk()
            ->assertSee('Bulan ini $1.75 dari $10.00')->assertSee('Analisa berkas')->assertSee('Usulan butir regulasi')
            ->assertSee('10 pemakaian terbesar')->assertDontSee('PASIEN RAHASIA')->assertDontSee('000009');
        $this->actingAs($this->petugas)->get(route('sistem.pemakaian_ai'))->assertForbidden();
    }
}
