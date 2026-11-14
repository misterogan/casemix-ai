<?php

namespace Tests\Feature;

use App\Enums\ClaimStatus;
use App\Models\Claim;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportCommitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportCommitterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('casemix.storage_disk'));
        $this->user = User::factory()->create();
    }

    /** Satu batch impor dengan file yang sudah "terunduh", satu kelompok per pasien. */
    private function batch(array $groups): ImportBatch
    {
        $batch = ImportBatch::create(['source' => 'ftp', 'remote_dir' => '/ftp/data/', 'total' => 0,
            'created_by' => $this->user->id, 'status' => 'siap_ditinjau']);
        foreach ($groups as $key => $detected) {
            foreach (['resume.pdf', 'sep.pdf'] as $i => $name) {
                $local = "imports/{$batch->id}/" . md5($key . $name) . '.pdf';
                Storage::disk(config('casemix.storage_disk'))->put($local, "%PDF-1.4 $key $name");
                $batch->items()->create([
                    'remote_path' => "/ftp/data/$key/$name", 'remote_size' => 10, 'original_name' => $name,
                    'detected_type' => $i ? 'sep' : 'resume_medis', 'detected' => $detected, 'group_key' => $key,
                    'local_path' => $local, 'mime' => 'application/pdf', 'sha256' => hash('sha256', $key . $name),
                    'status' => 'dipilah',
                ]);
            }
        }

        return $batch;
    }

    private function commit(ImportBatch $batch, array $groups = []): array
    {
        return app(ImportCommitter::class)->commit($batch, [], $groups, $this->user->id, ImportCommitter::NEXT_NONE);
    }

    public function test_field_yang_tidak_diketahui_dibiarkan_kosong_bukan_ditebak(): void
    {
        $batch = $this->batch(['sep:0301R0011126V000001' => [
            'nama' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001', 'tanggal' => null, 'jenis_rawat' => null,
        ]]);

        $result = $this->commit($batch);
        $claim = Claim::findOrFail($result['claims'][0]);

        $this->assertNull($claim->tanggal_masuk, 'Tanggal masuk tidak boleh diisi tanggal hari impor.');
        $this->assertNull($claim->dpjp);
        $this->assertNull($claim->jenis_rawat);
        $this->assertSame(['jenis_rawat', 'tanggal_masuk', 'dpjp'], array_keys($claim->missingRequiredFields()));
        $this->assertTrue(collect($claim->approvalBlockers())->contains(fn ($r) => str_contains($r, 'Data wajib belum diisi')));
    }

    public function test_isian_petugas_saat_impor_dipakai(): void
    {
        $batch = $this->batch(['sep:0301R0011126V000001' => ['nama' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001']]);

        $result = $this->commit($batch, ['sep:0301R0011126V000001' => [
            'tanggal_masuk' => '2026-11-01', 'jenis_rawat' => 'inap', 'dpjp' => 'dr. Ani, Sp.PD',
        ]]);
        $claim = Claim::findOrFail($result['claims'][0]);

        $this->assertSame('2026-11-01', $claim->tanggal_masuk->toDateString());
        $this->assertSame('inap', $claim->jenis_rawat);
        $this->assertSame('dr. Ani, Sp.PD', $claim->dpjp);
        $this->assertSame([], $claim->missingRequiredFields());
    }

    public function test_dokumen_susulan_untuk_berkas_disetujui_tertahan_dan_tidak_membuat_berkas_baru(): void
    {
        $approved = Claim::create(['nama_pasien' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001',
            'jenis_rawat' => 'inap', 'tanggal_masuk' => '2026-11-01', 'dpjp' => 'dr. Ani']);
        $approved->forceFill(['status' => ClaimStatus::Disetujui])->save();

        $batch = $this->batch(['sep:0301R0011126V000001' => ['nama' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001']]);
        $result = $this->commit($batch);

        $this->assertSame([], $result['claims']);
        $this->assertSame($approved->id, $result['blocked'][0]['claim_id']);
        $this->assertSame(1, Claim::where('no_sep', '0301R0011126V000001')->count(), 'Tidak boleh ada berkas kedua dengan SEP sama.');
        $this->assertSame(0, $approved->documents()->count(), 'Berkas yang disetujui tidak boleh berubah.');
        $this->assertSame('siap_ditinjau', $batch->fresh()->status, 'Impor harus bisa disimpan lagi setelah berkas dibuka kembali.');
        $this->assertSame(2, $batch->items()->where('status', 'dipilah')->count());

        // Setelah dibuka kembali oleh Koordinator, impor yang sama bisa disimpan.
        $approved->forceFill(['status' => ClaimStatus::BelumDiperiksa])->save();
        $again = $this->commit($batch->fresh());
        $this->assertSame([$approved->id], $again['claims']);
        $this->assertSame(2, $approved->documents()->count());
        $this->assertSame('disimpan', $batch->fresh()->status);
    }

    public function test_tanpa_sep_tidak_digabung_ke_episode_lain_yang_punya_sep(): void
    {
        $episodeLain = Claim::create(['nama_pasien' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001',
            'jenis_rawat' => 'inap', 'tanggal_masuk' => '2026-10-01', 'dpjp' => 'dr. Ani']);

        $batch = $this->batch(['rm:123' => ['nama' => 'BUDI', 'no_rm' => '000123', 'no_sep' => null]]);
        $result = $this->commit($batch);

        $this->assertNotContains($episodeLain->id, $result['claims']);
        $this->assertSame(0, $episodeLain->documents()->count());
        $this->assertSame(2, Claim::where('no_rm', '000123')->count());
    }

    public function test_opsi_isi_otomatis_menyiapkan_usulan_tanpa_menjalankan_pemeriksaan(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \App\Models\AiProvider::create(['name' => 'Uji', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'm',
            'model_standard' => 'm', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
        $batch = $this->batch(['sep:0301R0011126V000001' => ['nama' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001']]);

        $result = app(ImportCommitter::class)->commit($batch, [], [], $this->user->id, ImportCommitter::NEXT_FILL);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunFieldExtraction::class, 1);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RunClaimReview::class);
        $this->assertSame(1, \App\Models\ClaimExtraction::where('claim_id', $result['claims'][0])->count());
    }

    public function test_saat_anggaran_ai_habis_berkas_tetap_disimpan_tanpa_antrean_ai(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \App\Models\AiProvider::create(['name' => 'Uji', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'm',
            'model_standard' => 'm', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
        \App\Models\AppSetting::put(\App\Services\Ai\AiBudget::KEY_BUDGET, 1);
        $lama = Claim::create(['nama_pasien' => 'X', 'no_rm' => '999']);
        \App\Models\AiReview::create(['claim_id' => $lama->id, 'status' => 'selesai', 'cost_usd' => 1, 'finished_at' => now()]);
        $batch = $this->batch(['sep:0301R0011126V000001' => ['nama' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001']]);

        $result = app(ImportCommitter::class)->commit($batch, [], [], $this->user->id, ImportCommitter::NEXT_REVIEW);

        $this->assertCount(1, $result['claims'], 'Berkas tetap tersimpan');
        $this->assertSame(1, $result['ai_skipped']);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }
}
