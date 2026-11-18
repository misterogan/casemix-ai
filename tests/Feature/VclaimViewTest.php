<?php

namespace Tests\Feature;

use App\Jobs\FetchVclaimHistori;
use App\Models\AuditLog;
use App\Models\FtpFolder;
use App\Models\User;
use App\Models\VclaimHistori;
use App\Models\VclaimKlaim;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Tampilan data VClaim (status klaim, riwayat, dashboard) untuk Koordinator. */
class VclaimViewTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        Queue::fake();
    }

    private function folder(?string $sep): FtpFolder
    {
        static $n = 0;
        $n++;

        return FtpFolder::create(['path' => "/ftp/v/$n", 'name' => "00000{$n}_PASIEN", 'no_sep' => $sep, 'files' => [], 'fingerprint' => 'f',
            'files_modified_at' => now()->subDay(), 'last_seen_at' => now()]);
    }

    private function klaim(string $sep, int $status, array $attr = []): VclaimKlaim
    {
        return VclaimKlaim::create($attr + ['no_sep' => $sep, 'status_kode' => $status, 'tgl_sep' => now()->subDays(3)->toDateString(),
            'tgl_pulang' => now()->toDateString(), 'no_kartu' => '0001234567890', 'inacbg_kode' => 'I-4-10-I', 'inacbg_nama' => 'GAGAL JANTUNG',
            'by_pengajuan' => 5000000, 'by_setujui' => 4500000, 'synced_at' => now()]);
    }

    public function test_chip_status_per_kode(): void
    {
        foreach ([1 => 'Proses verifikasi', 2 => 'Pending verifikasi', 3 => 'Klaim'] as $kode => $teks) {
            $f = $this->folder("0301R00111260000$kode");
            $this->klaim($f->no_sep, $kode);
            $res = $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()
                ->assertSee('data-bpjs-status="' . $kode . '"', false)->assertSee($teks)->assertSee('I-4-10-I')->assertSee('Rp 4.500.000');
            $kode === 2 ? $res->assertSee('Alasan pending tidak tersedia di VClaim') : $res->assertDontSee('Alasan pending tidak tersedia');
        }
        $f = $this->folder('0301R001112600009');
        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertSee('Belum ada di data BPJS');
        $this->assertTrue(AuditLog::where('action', 'vclaim.riwayat.buka')->exists());
    }

    public function test_folder_tanpa_sep_tidak_memanggil(): void
    {
        $f = $this->folder(null);
        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()->assertSee('SEP belum ada')->assertDontSee('Ambil riwayat');
        $this->actingAs($this->koor)->get(route('folders.show', [$f, 'ambil_riwayat' => 1]))->assertStatus(422);
        Queue::assertNotPushed(FetchVclaimHistori::class);
    }

    public function test_ambil_riwayat_dan_readmisi_30_hari(): void
    {
        $f = $this->folder('0301R001112600100');
        $this->klaim($f->no_sep, 1, ['tgl_sep' => now()->subDays(3)->toDateString()]);
        $base = ['no_kartu' => '0001234567890', 'jns_pelayanan' => 'Rawat Inap', 'ppk_pelayanan' => 'RS CONTOH', 'fetched_at' => now()];
        VclaimHistori::create($base + ['no_sep' => '0301R001112600050', 'tgl_sep' => now()->subDays(20), 'tgl_plg_sep' => now()->subDays(15), 'diagnosa' => 'READMISI-A']);
        VclaimHistori::create($base + ['no_sep' => '0301R001112600040', 'tgl_sep' => now()->subDays(60), 'tgl_plg_sep' => now()->subDays(55), 'diagnosa' => 'LAMA-B']);
        VclaimHistori::create($base + ['no_sep' => '0999R001112600030', 'tgl_sep' => now()->subDays(12), 'tgl_plg_sep' => now()->subDays(10), 'diagnosa' => 'RS-LAIN-C']);

        $html = $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()->assertSee('Kemungkinan readmisi')->getContent();
        $this->assertSame(1, substr_count($html, 'data-readmisi'));
        $this->assertMatchesRegularExpression('/data-readmisi[^>]*>(?:(?!<\/tr>).)*READMISI-A/s', $html);

        $this->actingAs($this->koor)->get(route('folders.show', [$f, 'ambil_riwayat' => 1]))->assertRedirect(route('folders.show', $f));
        Queue::assertPushed(FetchVclaimHistori::class, fn ($j) => $j->noKartu === '0001234567890');
        $log = AuditLog::where('action', 'vclaim.riwayat.ambil')->firstOrFail();
        $this->assertStringNotContainsString('0001234567890', json_encode($log->toArray()));
    }

    public function test_no_kartu_belum_diketahui_tombol_nonaktif(): void
    {
        $f = $this->folder('0301R001112600200');
        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()->assertSee('Nomor kartu peserta belum diketahui');
    }

    public function test_admin_tetap_403(): void
    {
        $f = $this->folder('0301R001112600300');
        $this->klaim($f->no_sep, 3);
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->actingAs($admin)->get(route('folders.show', $f))->assertForbidden();
        $this->actingAs($admin)->get(route('folders.show', [$f, 'ambil_riwayat' => 1]))->assertForbidden();
    }

    public function test_dashboard_enabled_false_tanpa_kartu_vclaim(): void
    {
        $this->klaim('0301R001112600400', 2);
        config(['casemix.vclaim.enabled' => false]);
        $this->actingAs($this->koor)->get(route('control.index'))->assertOk()
            ->assertSee('Status klaim BPJS (VClaim): belum tersambung')->assertDontSee('data-vclaim-kartu', false);

        config(['casemix.vclaim.enabled' => true]);
        $this->actingAs($this->koor)->get(route('control.index'))->assertOk()
            ->assertSee('VClaim terakhir')->assertSee('data-vclaim-kartu="pending"', false);
    }

    public function test_antrean_kolom_bpjs(): void
    {
        $f = $this->folder('0301R001112600500');
        $f->update(['rubric_kurang' => 1]);
        $this->klaim($f->no_sep, 2);
        $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()->assertSee('data-bpjs-status="2"', false);
    }
}
