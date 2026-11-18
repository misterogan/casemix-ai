<?php

namespace Tests\Feature;

use App\Jobs\ApplyVclaimToCermat;
use App\Models\AppSetting;
use App\Models\FtpFolder;
use App\Models\User;
use App\Models\VclaimEvaluasi;
use App\Models\VclaimKlaim;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Penerapan hasil penarikan VClaim ke data CERMAT (tanpa AI, tanpa panggilan BPJS). */
class VclaimApplyTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        config(['casemix.vclaim.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
    }

    private function folder(string $sep, int $kurang, array $attr = []): FtpFolder
    {
        return FtpFolder::create($attr + ['path' => "/ftp/$sep", 'name' => "123_Pasien Rahasia $sep", 'no_sep' => $sep, 'files' => [],
            'fingerprint' => 'f', 'files_modified_at' => now(), 'analysis_status' => 'selesai', 'analyzed_fingerprint' => 'f', 'rubric_kurang' => $kurang]);
    }

    private function klaim(string $sep, string $kategori, ?string $berubah = null): VclaimKlaim
    {
        return VclaimKlaim::updateOrCreate(['no_sep' => $sep], ['status_kode' => $kategori === 'klaim' ? 3 : 2, 'status_kategori' => $kategori,
            'tgl_pulang' => '2026-11-10', 'jns_pelayanan' => '1', 'nama' => 'Pasien Rahasia', 'first_seen_at' => now(),
            'status_changed_at' => $berubah ?? now(), 'synced_at' => now()]);
    }

    private function jalankan(): void
    {
        (new ApplyVclaimToCermat)->handle(app(\App\Services\Dashboard\ClaimVerdict::class));
    }

    public function test_alur_penerapan_lengkap(): void
    {
        $fKlaim = $this->folder('SEPKLAIM', 1);
        $fPending = $this->folder('SEPPEND', 0);   // Siap diajukan menurut CERMAT
        $fTinjau = $this->folder('SEPTINJAU', 1);
        $this->klaim('SEPKLAIM', 'klaim');
        $this->klaim('SEPPEND', 'pending');
        VclaimKunjungan::create(['no_sep' => 'SEPNOFTP', 'tgl_sep' => '2026-11-01', 'tgl_plg_sep' => '2026-11-03', 'synced_at' => now()]);
        VclaimKunjungan::create(['no_sep' => 'SEPBARU', 'tgl_sep' => '2026-11-15', 'tgl_plg_sep' => '2026-11-16', 'synced_at' => now()]);

        $this->jalankan();

        $this->assertSame('klaim', $fKlaim->fresh()->bpjs_status_kategori);
        $this->assertFalse($fKlaim->fresh()->bpjs_tindak_lanjut);
        $this->assertTrue($fPending->fresh()->bpjs_tindak_lanjut);
        $this->assertNull($fTinjau->fresh()->bpjs_status_kategori);
        $this->assertSame(2, VclaimSyncRun::where('jenis', 'apply')->latest('id')->value('jumlah'));

        // Evaluasi: Siap diajukan + pending tercatat sekali.
        $this->assertSame(1, VclaimEvaluasi::where('folder_id', $fPending->id)->where('status_kategori', 'pending')->count());

        // Notifikasi: satu per run, tanpa nama pasien.
        $this->assertSame(1, $this->koor->notifications()->count());
        $n = $this->koor->notifications()->first();
        $this->assertSame('1 klaim perlu tindak lanjut BPJS', $n->data['pesan']);
        $this->assertStringNotContainsString('Rahasia', json_encode($n->data));
        $this->assertStringContainsString('status=tindak', $n->data['url']);

        // Berkas belum ada di FTP: hanya SEP pulang > 7 hari lalu.
        $this->assertSame(1, AppSetting::get(ApplyVclaimToCermat::TANPA_FOLDER));
        $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'sep', 'tanpa_folder' => 1]))->assertOk()
            ->assertSee('SEPNOFTP')->assertDontSee('SEPBARU');

        // Antrean: klaim keluar, pending paling atas dengan chip.
        $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()
            ->assertDontSee('SEPKLAIM')->assertSeeInOrder(['SEPPEND', 'SEPTINJAU'])->assertSee('Perlu tindak lanjut BPJS');
        $this->actingAs($this->koor)->get(route('folders.show', $fKlaim))->assertOk()->assertSee('Selesai di BPJS');

        // Run kedua tanpa perubahan: 0 folder, tanpa notifikasi atau evaluasi baru.
        $this->travel(1)->hours();
        $this->jalankan();
        $this->assertSame(0, VclaimSyncRun::where('jenis', 'apply')->latest('id')->value('jumlah'));
        $this->assertSame(1, $this->koor->notifications()->count());
        $this->assertSame(1, VclaimEvaluasi::count());

        Http::assertNothingSent();
    }

    public function test_hanya_sep_berubah_diproses(): void
    {
        $f = $this->folder('SEPA', 1);
        $this->klaim('SEPA', 'proses');
        $this->jalankan();
        $this->assertSame('proses', $f->fresh()->bpjs_status_kategori);

        // Data diubah tanpa menandai perubahan (status_changed_at lama) → tidak diproses.
        $this->travel(1)->hours();
        VclaimKlaim::where('no_sep', 'SEPA')->update(['status_kategori' => 'pending', 'status_changed_at' => now()->subDays(3), 'first_seen_at' => now()->subDays(3)]);
        $this->jalankan();
        $this->assertSame('proses', $f->fresh()->bpjs_status_kategori);

        // Status berubah sesudah penanda → diproses dan notifikasi dikirim.
        $this->travel(1)->hours();
        $this->klaim('SEPA', 'tidak_layak');
        $this->jalankan();
        $this->assertSame('tidak_layak', $f->fresh()->bpjs_status_kategori);
        $this->assertTrue($f->fresh()->bpjs_tindak_lanjut);
        $this->assertSame(1, $this->koor->notifications()->count());

        $this->actingAs($this->koor)->post(route('notifications.open', $this->koor->notifications()->first()->id))->assertRedirect();
        $this->assertSame(0, $this->koor->unreadNotifications()->count());
        Http::assertNothingSent();
    }

    public function test_admin_403_di_route_lonceng(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->actingAs($admin)->post(route('notifications.open', 'x'))->assertForbidden();
        $this->actingAs($admin)->post(route('notifications.read-all'))->assertForbidden();
    }

    public function test_sinkron_mendispatch_job_sekali(): void
    {
        \Illuminate\Support\Facades\Bus::fake([ApplyVclaimToCermat::class]);
        config(['casemix.vclaim' => array_merge(config('casemix.vclaim'), ['enabled' => true, 'base_url' => 'https://vclaim.test',
            'service' => 'vclaim-rest', 'cons_id' => '1', 'secret' => 's', 'user_key' => 'k', 'jeda_ms' => 0])]);
        Http::fake(['*' => Http::response(['metaData' => ['code' => '201', 'message' => 'Data tidak ditemukan'], 'response' => null])]);
        $this->artisan('casemix:vclaim-sync-klaim', ['--from' => '2026-11-16', '--to' => '2026-11-17'])->assertSuccessful();
        \Illuminate\Support\Facades\Bus::assertDispatchedTimes(ApplyVclaimToCermat::class, 1);
    }
}
