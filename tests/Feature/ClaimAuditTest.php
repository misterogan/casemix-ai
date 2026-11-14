<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClaimAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->petugas = User::factory()->create(['is_active' => true]);
        $this->petugas->assignRole('petugas');
    }

    private function claim(): Claim
    {
        return Claim::create(['nama_pasien' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001',
            'jenis_rawat' => 'inap', 'tanggal_masuk' => '2026-11-01', 'tanggal_keluar' => '2026-11-04',
            'dpjp' => 'dr. Ani', 'diagnosis_utama' => 'Pneumonia']);
    }

    public function test_perubahan_data_klaim_mencatat_nilai_lama_dan_baru(): void
    {
        $claim = $this->claim();

        $this->actingAs($this->petugas)->put(route('claims.update', $claim), [
            'nama_pasien' => 'BUDI', 'no_rm' => '000123', 'no_sep' => '0301R0011126V000001',
            'jenis_rawat' => 'inap', 'tanggal_masuk' => '2026-11-01', 'tanggal_keluar' => '2026-11-04',
            'dpjp' => 'dr. Ani', 'diagnosis_utama' => 'Pneumonia berat', 'diagnosis_sekunder' => 'Diabetes melitus tipe 2',
        ])->assertRedirect(route('claims.show', $claim));

        $log = AuditLog::where('action', 'berkas.ubah')->sole();
        $diff = $log->properties['perubahan'];

        $this->assertSame(['lama' => 'Pneumonia', 'baru' => 'Pneumonia berat'], $diff['diagnosis_utama']);
        $this->assertSame(['lama' => null, 'baru' => 'Diabetes melitus tipe 2'], $diff['diagnosis_sekunder']);
        $this->assertSame($this->petugas->id, $log->user_id);
    }

    public function test_hapus_dokumen_hanya_menyembunyikan_dan_file_tetap_ada(): void
    {
        Storage::fake(config('casemix.storage_disk'));
        $claim = $this->claim();
        Storage::disk(config('casemix.storage_disk'))->put("claims/{$claim->id}/a.pdf", '%PDF-1.4');
        $doc = $claim->documents()->create(['type' => 'resume_medis', 'original_name' => 'resume.pdf',
            'path' => "claims/{$claim->id}/a.pdf", 'mime' => 'application/pdf', 'size' => 8, 'sha256' => str_repeat('a', 64)]);

        $this->actingAs($this->petugas)->delete(route('claims.documents.destroy', [$claim, $doc]))->assertRedirect();

        $this->assertSame(0, $claim->documents()->count(), 'Dokumen tidak lagi tampil di berkas.');
        $this->assertSoftDeleted($doc);
        $this->assertSame($this->petugas->id, $doc->fresh()->deleted_by);
        Storage::disk(config('casemix.storage_disk'))->assertExists("claims/{$claim->id}/a.pdf");
    }

    public function test_file_yang_bukan_pdf_atau_gambar_tidak_ditampilkan_inline(): void
    {
        Storage::fake(config('casemix.storage_disk'));
        $claim = $this->claim();
        Storage::disk(config('casemix.storage_disk'))->put("claims/{$claim->id}/x.pdf", '<html><script>alert(1)</script></html>');
        $doc = $claim->documents()->create(['type' => 'tambahan', 'original_name' => 'x.pdf',
            'path' => "claims/{$claim->id}/x.pdf", 'mime' => 'text/html', 'size' => 10, 'sha256' => str_repeat('b', 64)]);

        $res = $this->actingAs($this->petugas)->get(route('claims.documents.show', [$claim, $doc]));

        $res->assertOk();
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $res->headers->get('Content-Disposition'));
    }
}
