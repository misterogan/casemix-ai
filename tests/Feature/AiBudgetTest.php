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
use App\Services\Ai\AiBudget;
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
}
