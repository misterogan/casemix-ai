<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Models\User;
use App\Services\Dashboard\ClaimVerdict;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Vonis/prioritas antrean klaim tanpa AI, dan penugasan folder oleh Koordinator. */
class ClaimVerdictTest extends TestCase
{
    use RefreshDatabase;

    private ClaimVerdict $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->v = new ClaimVerdict;
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(10, 0));
    }

    private function folder(array $attr = [], array $kurang = []): FtpFolder
    {
        static $n = 0;
        $n++;
        $f = FtpFolder::create($attr + ['path' => "/ftp/data/$n", 'name' => "00000{$n}_PASIEN_SEP$n", 'files' => [], 'fingerprint' => 'f',
            'files_modified_at' => now()]);
        foreach ($kurang as $k) {
            RubricItem::create(['ftp_folder_id' => $f->id, 'kode' => $k, 'status' => 'kurang']);
        }

        return $f;
    }

    private function selesai(int $kurang): array
    {
        return ['analysis_status' => 'selesai', 'analyzed_fingerprint' => 'f', 'rubric_kurang' => $kurang];
    }

    public function test_tiap_vonis(): void
    {
        $this->assertSame('belum', $this->v->verdict($this->folder()));
        $this->assertSame('siap', $this->v->verdict($this->folder($this->selesai(0))));
        $this->assertSame('blokir', $this->v->verdict($this->folder($this->selesai(1), ['dok_resume'])));
        $this->assertSame('blokir', $this->v->verdict($this->folder(['analysis_status' => 'gagal'])));
        $this->assertSame('tinjau', $this->v->verdict($this->folder($this->selesai(1), ['ttd_dpjp'])));
        $this->assertSame('tinjau', $this->v->verdict($this->folder(['analysis_status' => 'selesai', 'analyzed_fingerprint' => 'lama', 'rubric_kurang' => 0])), 'File berubah = belum siap');
    }

    public function test_urutan_prioritas(): void
    {
        $blokirLama = $this->folder($this->selesai(2) + ['files_modified_at' => now()->subDays(50)], ['dok_sep', 'dok_biaya']);
        $blokir = $this->folder($this->selesai(1), ['dok_sep']);
        $tinjau = $this->folder($this->selesai(1) + ['files_modified_at' => now()->subDays(3)], ['ttd_dpjp']);
        $siap = $this->folder($this->selesai(0) + ['files_modified_at' => now()->subDays(2)]);

        $this->assertSame(40 + 10 + 30, $this->v->priority($blokirLama), 'Umur dibatasi 30');
        $this->assertSame(45, $this->v->priority($blokir));
        $this->assertSame(20 + 5 + 3, $this->v->priority($tinjau));
        $this->assertSame(2, $this->v->priority($siap));
        $this->assertSame(100, $this->v->priority($this->folder($this->selesai(9) + ['files_modified_at' => now()->subDays(99)], ['dok_sep'])));

        $urut = collect([$siap, $tinjau, $blokir, $blokirLama])->sortByDesc(fn ($f) => $this->v->priority($f))->pluck('id')->all();
        $this->assertSame([$blokirLama->id, $blokir->id, $tinjau->id, $siap->id], $urut);
        $this->assertSame('2026-11-24', $this->v->dueDate($tinjau->fresh())->addDays(3)->toDateString());
    }

    public function test_penugasan_oleh_koordinator_diaudit(): void
    {
        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $petugas = User::factory()->create(['is_active' => true, 'name' => 'Petugas A'])->assignRole('koordinator');
        $f = $this->folder();

        $this->actingAs($koor)->put(route('folders.assign', $f), ['user_id' => $petugas->id])->assertSessionHas('ok');
        $this->assertSame($petugas->id, $f->fresh()->assigned_to);
        $this->assertNotNull($f->fresh()->assigned_at);
        $this->assertSame('Petugas A', $f->fresh()->assignee->name);

        $this->actingAs($koor)->put(route('folders.assign', $f), ['user_id' => null])->assertSessionHas('ok');
        $this->assertNull($f->fresh()->assigned_to);

        $log = AuditLog::whereIn('action', ['folder.tugaskan', 'folder.lepas_tugas'])->orderBy('id')->get();
        $this->assertSame(['folder.tugaskan', 'folder.lepas_tugas'], $log->pluck('action')->all());
        $this->assertSame(['lama' => null, 'baru' => $petugas->id], $log[0]->properties['perubahan']['assigned_to']);
        $this->assertSame(['lama' => $petugas->id, 'baru' => null], $log[1]->properties['perubahan']['assigned_to']);

        $lain = User::factory()->create(['is_active' => true]);
        $this->actingAs($lain)->put(route('folders.assign', $f), ['user_id' => $lain->id])->assertForbidden();
    }
}
