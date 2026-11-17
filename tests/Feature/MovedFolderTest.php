<?php

namespace Tests\Feature;

use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Folder yang dipindahkan casemix dari FTP (missing_at = perkiraan diajukan). */
class MovedFolderTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(10, 0));
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        Cache::flush();
    }

    private function folder(string $sep, array $attr = []): FtpFolder
    {
        return FtpFolder::create($attr + ['path' => "/ftp/$sep", 'name' => "X_$sep", 'no_sep' => $sep, 'files' => [], 'fingerprint' => 'f',
            'files_modified_at' => now()->subDays(6), 'first_seen_at' => now()->subDays(6)]);
    }

    private function seedFolders(): array
    {
        $analisa = ['analysis_status' => 'selesai', 'analyzed_fingerprint' => 'f', 'analyzed_at' => now()->subDays(3)];
        $aktif = $this->folder('SEPAKTIF', $analisa + ['rubric_kurang' => 1]);
        RubricItem::create(['ftp_folder_id' => $aktif->id, 'kode' => 'ttd_dpjp', 'status' => 'kurang']);
        $siap = $this->folder('SEPPINDAHSIAP', $analisa + ['rubric_kurang' => 0, 'missing_at' => now()->subDays(2)]);
        $tinjau = $this->folder('SEPPINDAHTINJAU', $analisa + ['rubric_kurang' => 1, 'missing_at' => now()->subDays(2)]);
        RubricItem::create(['ftp_folder_id' => $tinjau->id, 'kode' => 'ttd_dpjp', 'status' => 'kurang']);
        $tanpa = $this->folder('SEPTANPA', ['missing_at' => now()->subDays(2)]);

        return [$aktif, $siap, $tinjau, $tanpa];
    }

    public function test_status_turunan(): void
    {
        [$aktif, $siap, , $tanpa] = $this->seedFolders();
        $this->assertNull($aktif->moved_status);
        $this->assertSame('dipindahkan', $siap->moved_status);
        $this->assertSame('Sudah dipindahkan casemix', $siap->moved_label);
        $this->assertSame('hilang_tanpa_periksa', $tanpa->moved_status);
        $this->assertSame('Dipindah tanpa diperiksa CERMAT', $tanpa->moved_label);
    }

    public function test_tab_dan_banner(): void
    {
        [, $siap] = $this->seedFolders();
        $this->actingAs($this->koor)->get(route('folders.index'))->assertOk()
            ->assertSee('SEPAKTIF')->assertDontSee('SEPPINDAHSIAP')->assertDontSee('SEPTANPA')
            ->assertSee('Sudah dipindahkan')->assertSee('Dipindah tanpa diperiksa');
        $this->actingAs($this->koor)->get(route('folders.index', ['tab' => 'dipindahkan']))->assertOk()
            ->assertSee('SEPPINDAHSIAP')->assertSee('SEPPINDAHTINJAU')->assertDontSee('SEPAKTIF')->assertDontSee('SEPTANPA')
            ->assertSee('Sudah dipindahkan casemix')->assertSee('dipindah ±15 Nov 2026');
        $this->actingAs($this->koor)->get(route('folders.index', ['tab' => 'hilang_tanpa_periksa']))->assertOk()
            ->assertSee('SEPTANPA')->assertDontSee('SEPPINDAHSIAP')->assertSee('Dipindah tanpa diperiksa CERMAT');
        $this->actingAs($this->koor)->get(route('folders.show', $siap))->assertOk()
            ->assertSee('Folder sudah dipindahkan dari FTP pada ±15 Nov 2026. Hasil verifikasi dan salinan dokumen tetap tersimpan.');
    }

    public function test_kartu_dashboard(): void
    {
        $this->seedFolders();
        $s = $this->actingAs($this->koor)->get(route('control.index'))->assertOk()
            ->assertSee('perkiraan dari waktu folder hilang di FTP')->viewData('s');
        $this->assertSame(['dipindahkan' => 3, 'belum_siap' => 1, 'tanpa_periksa' => 1, 'median_hari' => 4], $s['pindah']);
    }

    public function test_antrean_mengecualikan_folder_dipindah(): void
    {
        $this->seedFolders();
        $this->actingAs($this->koor)->get(route('work-queue.index'))->assertOk()
            ->assertSee('SEPAKTIF')->assertDontSee('SEPPINDAHTINJAU')->assertDontSee('SEPTANPA');
    }
}
