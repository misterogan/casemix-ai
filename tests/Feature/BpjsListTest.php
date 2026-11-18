<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FtpFolder;
use App\Models\User;
use App\Models\VclaimKlaim;
use App\Models\VclaimKlaimStatusHistory;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Menu Klaim BPJS: daftar klaim, SEP terbit, detail, CSV. Hanya baca tabel vclaim_*. */
class BpjsListTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(10, 0));
        config(['casemix.vclaim.enabled' => true]);
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
    }

    private function klaim(string $sep, int $status, array $attr = []): VclaimKlaim
    {
        return VclaimKlaim::create($attr + ['no_sep' => $sep, 'status_kode' => $status, 'tgl_sep' => '2026-11-05', 'tgl_pulang' => '2026-11-10',
            'jns_pelayanan' => 'Rawat Inap', 'nama' => "PASIEN $sep", 'no_mr' => "RM$sep", 'no_kartu' => '0001',
            'by_pengajuan' => 2000000, 'by_setujui' => 1500000, 'status_changed_at' => now()->subDays(4)]);
    }

    public function test_admin_403(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->actingAs($admin)->get(route('bpjs.index', ['tab' => 'klaim']))->assertForbidden();
    }

    public function test_koordinator_lihat_daftar_dan_filter_pending(): void
    {
        $this->klaim('SEPPROSES', 1);
        $this->klaim('SEPPENDING', 2, ['tgl_pulang' => '2026-11-02']);
        $this->klaim('SEPKLAIM', 3);
        VclaimSyncRun::create(['jenis' => 'klaim', 'status' => 'ok', 'jumlah' => 3]);

        $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim']))->assertOk()
            ->assertSee('Klaim BPJS')->assertSee('Data BPJS terakhir ditarik')
            ->assertSeeInOrder(['SEPPENDING', 'SEPPROSES'])->assertSee('SEPKLAIM')->assertSee('4 hari')->assertSee('data-selisih-minus', false);

        $res = $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim', 'status' => 'pending']))->assertOk()
            ->assertSee('SEPPENDING')->assertDontSee('SEPPROSES')->assertDontSee('SEPKLAIM');
        $this->assertSame(1, $res->viewData('rows')->total());
    }

    public function test_periode_bawaan_bulan_data_terakhir(): void
    {
        $this->klaim('SEPAGU', 3, ['tgl_pulang' => '2026-08-20']);
        $this->klaim('SEPSEP', 3, ['tgl_pulang' => '2026-09-15']);

        $res = $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim']))->assertOk()
            ->assertSee('Data tersedia 20/08/2026 s.d. 15/09/2026')->assertSee('SEPSEP')->assertDontSee('SEPAGU');
        $this->assertSame(['2026-09-01', '2026-09-30'], [$res->viewData('filters')['dari'], $res->viewData('filters')['sampai']]);
    }

    public function test_periode_bawaan_bulan_lalu_bila_kosong(): void
    {
        $res = $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim']))->assertOk();
        $this->assertSame('2026-10-01', $res->viewData('filters')['dari']);
    }

    public function test_tautan_folder_bila_sep_cocok(): void
    {
        $this->klaim('SEPADA', 1);
        $this->klaim('SEPTIDAK', 1);
        $f = FtpFolder::create(['path' => '/ftp/x', 'name' => 'X', 'no_sep' => 'SEPADA', 'files' => [], 'fingerprint' => 'f']);

        $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim']))->assertOk()
            ->assertSee(route('folders.show', $f->id))->assertSee('data-folder-link', false);
        $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim', 'q' => 'SEPTIDAK']))->assertOk()
            ->assertDontSee('data-folder-link', false);
    }

    public function test_csv_ikuti_filter_dan_tercatat_di_audit(): void
    {
        $this->klaim('SEPPROSES', 1);
        $this->klaim('SEPPENDING', 2);

        $res = $this->actingAs($this->koor)->get(route('bpjs.csv', ['tab' => 'klaim', 'status' => 'pending']))->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('SEPPENDING', $csv);
        $this->assertStringNotContainsString('SEPPROSES', $csv);

        $log = AuditLog::where('action', 'bpjs.ekspor')->sole();
        $this->assertSame($this->koor->id, $log->user_id);
        $this->assertSame('klaim', $log->properties['tab']);
        $this->assertSame('pending', $log->properties['filter']['status']);
        $this->assertSame(1, $log->properties['jumlah']);
        $this->assertStringNotContainsString('SEPPENDING', json_encode($log->properties));
    }

    public function test_sep_terbit(): void
    {
        VclaimKunjungan::create(['no_sep' => 'SEPK1', 'tgl_sep' => '2026-11-03', 'nama' => 'A', 'jns_pelayanan' => 'Rawat Jalan']);
        VclaimKunjungan::create(['no_sep' => 'SEPK2', 'tgl_sep' => '2026-11-04', 'nama' => 'B', 'jns_pelayanan' => 'Rawat Inap']);
        $this->klaim('SEPK2', 3);

        $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'sep']))->assertOk()
            ->assertSee('SEPK1')->assertSee('Belum terlihat di data klaim')->assertSee('data-bpjs-status="klaim"', false);
    }

    public function test_detail_menampilkan_riwayat_status(): void
    {
        $this->klaim('SEPD', 3);
        VclaimKlaimStatusHistory::create(['no_sep' => 'SEPD', 'status_lama' => null, 'status_baru' => 1, 'terlihat_at' => now()->subDays(9)]);
        VclaimKlaimStatusHistory::create(['no_sep' => 'SEPD', 'status_lama' => 1, 'status_baru' => 3, 'terlihat_at' => now()->subDays(4)]);

        $this->actingAs($this->koor)->get(route('bpjs.klaim.show', 'SEPD'))->assertOk()
            ->assertSee('Riwayat perubahan status')->assertSeeInOrder(['Pertama terlihat', 'Proses Verifikasi', 'Klaim'])
            ->assertSee('Ambil riwayat pelayanan');
    }

    public function test_filter_tidak_layak_dan_dispute(): void
    {
        $this->klaim('SEPPEND', 2, ['status_teks' => 'Klaim Pending']);
        $this->klaim('SEPTL', 2, ['status_teks' => 'Klaim Tidak Layak']);
        $this->klaim('SEPDSP', 2, ['status_teks' => 'Klaim Dispute']);
        $q = ['tab' => 'klaim', 'dari' => '2026-11-01', 'sampai' => '2026-11-30'];

        $res = $this->actingAs($this->koor)->get(route('bpjs.index', $q + ['status' => 'tidak_layak']))->assertOk()
            ->assertSee('Tidak layak')->assertSee('data-bpjs-status="tidak_layak"', false);
        $this->assertSame(['SEPTL'], $res->viewData('rows')->pluck('no_sep')->all());

        $res = $this->actingAs($this->koor)->get(route('bpjs.index', $q + ['status' => 'pending']));
        $this->assertSame(['SEPPEND'], $res->viewData('rows')->pluck('no_sep')->all());

        $res = $this->actingAs($this->koor)->get(route('bpjs.index', $q + ['status' => 'dispute']));
        $this->assertSame(['SEPDSP'], $res->viewData('rows')->pluck('no_sep')->all());

        $res = $this->actingAs($this->koor)->get(route('bpjs.index', $q + ['status' => 'tolak']));
        $this->assertEqualsCanonicalizing(['SEPTL', 'SEPDSP'], $res->viewData('rows')->pluck('no_sep')->all());
    }

    public function test_enabled_false_empty_state(): void
    {
        config(['casemix.vclaim.enabled' => false]);
        $this->klaim('SEPX', 1);

        $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim']))->assertOk()
            ->assertSee('Bridging VClaim belum diaktifkan.')->assertDontSee('SEPX')->assertDontSee('<table', false);
    }
}
