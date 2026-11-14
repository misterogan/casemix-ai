<?php

namespace Tests\Feature;

use App\Enums\ClaimStatus;
use App\Models\AiReview;
use App\Models\Claim;
use App\Models\User;
use App\Support\Eklaim;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $koordinatorA;

    private User $koordinatorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->koordinatorA = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->koordinatorB = User::factory()->create(['is_active' => true])->assignRole('koordinator');
    }

    /** Berkas yang siap disetujui; daftar cek diisi oleh $pemeriksa. */
    private function siap(User $pemeriksa): Claim
    {
        $claim = Claim::create(['nama_pasien' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001',
            'jenis_rawat' => 'inap', 'tanggal_masuk' => '2026-11-01', 'tanggal_keluar' => '2026-11-04', 'dpjp' => 'dr. Ani']);
        foreach (['sep', 'resume_medis', 'rincian_biaya'] as $i => $t) {
            $claim->documents()->create(['type' => $t, 'original_name' => "$t.pdf", 'path' => "x/$t.pdf", 'mime' => 'application/pdf',
                'size' => 1, 'sha256' => str_repeat((string) $i, 64)]);
        }
        AiReview::create(['claim_id' => $claim->id, 'status' => 'selesai', 'started_at' => now()->addSecond(), 'finished_at' => now()->addSeconds(2)]);
        $claim->forceFill(['checklist' => array_fill_keys(array_keys(Eklaim::CHECKLIST), true), 'checklist_by' => $pemeriksa->id])->save();

        return $claim->fresh();
    }

    public function test_koordinator_tidak_bisa_menyetujui_berkas_yang_ia_periksa_sendiri(): void
    {
        $claim = $this->siap($this->koordinatorA);

        $this->actingAs($this->koordinatorA)->post(route('claims.approve', $claim))->assertSessionHas('warn');
        $this->assertNotSame(ClaimStatus::Disetujui, $claim->fresh()->status);

        $this->actingAs($this->koordinatorB)->post(route('claims.approve', $claim))->assertSessionHas('ok');
        $this->assertSame(ClaimStatus::Disetujui, $claim->fresh()->status);
    }

    public function test_bisa_dimatikan_untuk_rs_dengan_satu_koordinator(): void
    {
        config(['casemix.approval.separate_duties' => false]);
        $claim = $this->siap($this->koordinatorA);

        $this->actingAs($this->koordinatorA)->post(route('claims.approve', $claim))->assertSessionHas('ok');
        $this->assertSame(ClaimStatus::Disetujui, $claim->fresh()->status);
    }
}
