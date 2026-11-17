<?php

namespace Tests\Feature;

use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Antrean kerja Koordinator: urutan prioritas, filter tugas saya, penugasan. */
class WorkQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(10, 0));
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
    }

    private function folder(string $sep, int $kurang, array $kodeKurang, array $attr = []): FtpFolder
    {
        $f = FtpFolder::create($attr + ['path' => "/ftp/$sep", 'name' => "X_$sep", 'no_sep' => $sep, 'files' => [], 'fingerprint' => 'f',
            'files_modified_at' => now(), 'analysis_status' => 'selesai', 'analyzed_fingerprint' => 'f', 'rubric_kurang' => $kurang]);
        foreach ($kodeKurang as $k) {
            RubricItem::create(['ftp_folder_id' => $f->id, 'kode' => $k, 'status' => 'kurang']);
        }

        return $f;
    }

    public function test_urutan_prioritas_dan_vonis(): void
    {
        $this->folder('SEPSIAP', 0, []);
        $tinjau = $this->folder('SEPTINJAU', 1, ['ttd_dpjp']);
        $blokir = $this->folder('SEPBLOKIR', 1, ['dok_sep']);
        $blokirLama = $this->folder('SEPLAMA', 2, ['dok_sep', 'dok_biaya'], ['files_modified_at' => now()->subDays(10)]);

        $res = $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()
            ->assertSee('Antrean kerja')->assertDontSee('SEPSIAP')->assertDontSee('Tarif')
            ->assertSeeInOrder(['SEPLAMA', 'SEPBLOKIR', 'SEPTINJAU'])
            ->assertSee('terlambat 3 hari')->assertSee('sisa 7 hari');
        $this->assertSame(3, $res->viewData('rows')->total());

        $this->actingAs($this->koor)->get(route('work-queue.index', ['vonis' => 'tinjau']))->assertOk()
            ->assertSee('SEPTINJAU')->assertDontSee('SEPBLOKIR');
        $this->actingAs($this->koor)->get(route('work-queue.index', ['periode' => '7']))->assertOk()
            ->assertSee('SEPBLOKIR')->assertDontSee('SEPLAMA');
    }

    public function test_filter_tugas_saya(): void
    {
        $lain = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->folder('SEPSAYA', 1, ['ttd_dpjp'], ['assigned_to' => $this->koor->id]);
        $this->folder('SEPLAIN', 1, ['ttd_dpjp'], ['assigned_to' => $lain->id]);
        $this->folder('SEPKOSONG', 1, ['ttd_dpjp']);

        $this->actingAs($this->koor)->get(route('work-queue.index', ['saya' => 1]))->assertOk()
            ->assertSee('SEPSAYA')->assertDontSee('SEPLAIN')->assertDontSee('SEPKOSONG');
    }

    public function test_penugasan_tersimpan_dan_akses(): void
    {
        $petugas = User::factory()->create(['is_active' => true, 'name' => 'Petugas Antrean'])->assignRole('koordinator');
        $f = $this->folder('SEPTUGAS', 1, ['ttd_dpjp']);

        $this->actingAs($this->koor)->from(route('work-queue.index'))
            ->put(route('folders.assign', $f), ['user_id' => $petugas->id])->assertRedirect(route('work-queue.index'));
        $this->assertSame($petugas->id, $f->fresh()->assigned_to);
        $this->actingAs($this->koor)->get(route('work-queue.index'))
            ->assertSee('<option value="' . $petugas->id . '" selected', false)->assertSee(route('folders.show', $f));

        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->actingAs($admin)->get(route('work-queue.index'))->assertForbidden();
    }

    public function test_kolom_penugasan_hanya_bila_koordinator_lebih_dari_satu(): void
    {
        $this->folder('SEPSATU', 1, ['ttd_dpjp']);
        $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()
            ->assertDontSee('Ditugaskan ke')->assertDontSee('Tugas saya saja')->assertViewHas('tampilPenugasan', false);

        User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()
            ->assertSee('Ditugaskan ke')->assertSee('Tugas saya saja')->assertViewHas('tampilPenugasan', true);
    }

    public function test_ringkasan_hari_ini_dan_kartu_berikutnya(): void
    {
        $this->folder('SEPSIAPHARIINI', 0, [], ['analyzed_at' => now()]);
        $this->folder('SEPSIAPKEMARIN', 0, [], ['analyzed_at' => now()->subDay()]);
        $tinjau = $this->folder('SEPTINJAU', 1, ['ttd_dpjp']);
        $lama = $this->folder('SEPLAMA', 2, ['dok_sep', 'dok_biaya'], ['files_modified_at' => now()->subDays(10), 'name' => '00123456_BUDI SANTOSO_', 'no_sep' => null]);

        $res = $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()
            ->assertSee('BUDI SANTOSO')->assertDontSee('BUDI SANTOSO_')->assertSee('RM 00123456')->assertSee('SEP belum ada');
        $this->assertSame(['selesai' => 1, 'tersisa' => 2, 'terlambat' => 1], $res->viewData('summary'));
        $next = $res->viewData('next');
        $this->assertSame($lama->id, $next['folder']->id);
        $this->assertSame('BUDI SANTOSO', $next['pasien']);
        $this->assertCount(2, $next['kurang']);
    }

    public function test_antrean_kosong(): void
    {
        $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()
            ->assertSee('Semua folder siap diajukan.')->assertSee('Buka verifikasi berkas')->assertSee(route('folders.index'));
    }
}
