<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\CodeSuggestion;
use App\Models\ConditionSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pusat Kendali Klaim: Koordinator saja, angka dari data contoh. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
    }

    private function folder(array $attr = [], array $kurang = []): FtpFolder
    {
        static $n = 0;
        $n++;
        $f = FtpFolder::create($attr + ['path' => "/ftp/data/$n", 'name' => "00000{$n}_PASIEN_SEP$n", 'files' => [], 'fingerprint' => 'f',
            'files_modified_at' => now()->subDay(), 'last_seen_at' => now()->setTime(9, 15)]);
        foreach ($kurang as $k) {
            RubricItem::create(['ftp_folder_id' => $f->id, 'kode' => $k, 'status' => 'kurang']);
        }

        return $f;
    }

    private function code(FtpFolder $f, string $peran, string $code, bool $diResume, ?string $status = null): void
    {
        $a = $f->analyses()->first() ?? FolderAnalysis::create(['ftp_folder_id' => $f->id, 'status' => ReviewStatus::Selesai, 'fingerprint' => 'f']);
        CodeSuggestion::create(['folder_analysis_id' => $a->id, 'system' => 'icd10', 'peran' => $peran, 'code' => $code, 'title' => 'x',
            'bukti' => 'b', 'halaman' => 1, 'tercantum_di_resume' => $diResume, 'status_tindak_lanjut' => $status]);
    }

    private function sampleData(): void
    {
        $sel = fn (int $k) => ['analysis_status' => 'selesai', 'analyzed_fingerprint' => 'f', 'rubric_kurang' => $k];
        $this->folder($sel(0));
        $this->folder($sel(0));
        $this->folder($sel(0));
        $tinjau = $this->folder($sel(1), ['bukti_diagnosis']);
        $this->folder($sel(2), ['dok_sep', 'bukti_diagnosis']);
        $this->folder();
        $this->folder($sel(0) + ['files_modified_at' => now()->subDays(60)]); // di luar 30 hari

        ConditionSuggestion::create(['ftp_folder_id' => $tinjau->id, 'kondisi' => 'Anemia', 'status' => 'baru']);
        ConditionSuggestion::create(['ftp_folder_id' => $tinjau->id, 'kondisi' => 'Lain', 'status' => 'disetujui']);
        $this->code($tinjau, 'utama', 'J18.9', true);
        $this->code($tinjau, 'sekunder', 'D64.9', false);
        $this->code($tinjau, 'sekunder', 'D64.8', false, 'ditanyakan');
        $this->code($tinjau, 'sekunder', 'E87.1', false, 'tidak_relevan');
        $this->code($tinjau, 'sekunder', 'I10', true);
    }

    public function test_koordinator_boleh_admin_tidak(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');

        $this->actingAs($this->koor)->get(route('control.index'))->assertOk()->assertSee('Pusat Kendali Klaim')->assertSee('Status klaim BPJS (VClaim): belum tersambung');
        $this->actingAs($this->koor)->get('/')->assertRedirect(route('control.index'));
        $this->actingAs($admin)->get(route('control.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertDontSee(route('control.index'));
    }

    public function test_angka_kartu_sesuai_data(): void
    {
        $this->sampleData();

        $s = app(DashboardStats::class)->get('30');
        $this->assertSame(6, $s['total']);
        $this->assertSame(['siap' => 3, 'tinjau' => 1, 'blokir' => 1, 'belum' => 1], $s['vonis']);
        $this->assertSame(60, $s['skor']);
        $this->assertSame(1 + 2, $s['menunggu_dpjp'], '1 saran kondisi baru + 2 saran kode belum dijawab');
        $this->assertSame(['kode' => 'bukti_diagnosis', 'n' => 2], collect($s['rubrik'][0])->only(['kode', 'n'])->all());
        $this->assertSame([['kategori' => 'D64', 'n' => 2]], $s['kurang_klaim']['items']);
        $this->assertSame([['kategori' => 'J18', 'n' => 1]], $s['kurang_bukti']['items']);
        $this->assertSame(7, app(DashboardStats::class)->get('semua')['total']);

        $this->actingAs($this->koor)->get(route('control.index', ['periode' => '30']))->assertOk()
            ->assertSee('data-kartu="tinjau">1<', false)->assertSee('data-kartu="blokir">1<', false)->assertSee('data-kartu="belum">1<', false)
            ->assertSee('data-kartu="dpjp">3<', false)->assertSee('data-skor>60%<', false)
            ->assertSee('Sinkron FTP terakhir 09:15')->assertSee('Kemungkinan klaim kurang')->assertSee('D64');
    }
}
