<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FtpFolder;
use App\Models\Regulation;
use App\Models\RubricItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Acuan regulasi: CRUD Admin (diaudit), tampil sebagai "Acuan" pada butir rubrik berstatus kurang. */
class RegulationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true])->assignRole('admin');
    }

    private function data(array $over = []): array
    {
        return $over + ['judul' => 'Permenkes 24/2022 Rekam Medis', 'pasal' => 'Pasal 26', 'ringkasan' => 'Resume medis wajib ditandatangani DPJP.',
            'url' => 'https://example.org/pmk24', 'aktif' => '1', 'butir' => ['dok_resume']];
    }

    public function test_crud_admin_diaudit(): void
    {
        $this->actingAs($this->admin)->get(route('admin.regulations.create'))->assertOk();
        $this->actingAs($this->admin)->post(route('admin.regulations.store'), $this->data())->assertRedirect(route('admin.regulations.index'));
        $r = Regulation::firstOrFail();
        $this->assertSame(['dok_resume'], $r->rubricCodes());

        $this->actingAs($this->admin)->get(route('admin.regulations.index'))->assertOk()->assertSee('Permenkes 24/2022 Rekam Medis');
        $this->actingAs($this->admin)->get(route('admin.regulations.edit', $r))->assertOk();
        $this->actingAs($this->admin)->put(route('admin.regulations.update', $r), $this->data(['pasal' => 'Pasal 27', 'butir' => ['dok_sep', 'dok_biaya']]))->assertRedirect();
        $this->assertSame('Pasal 27', $r->fresh()->pasal);
        $this->assertSame(['dok_biaya', 'dok_sep'], $r->rubricCodes());

        $this->actingAs($this->admin)->delete(route('admin.regulations.destroy', $r))->assertRedirect();
        $this->assertSame(0, Regulation::count());

        $this->assertSame(['admin.regulasi_buat', 'admin.regulasi_ubah', 'admin.regulasi_hapus'],
            AuditLog::where('action', 'like', 'admin.regulasi_%')->orderBy('id')->pluck('action')->all());
    }

    public function test_validasi_butir_dan_hanya_admin(): void
    {
        $this->actingAs($this->admin)->post(route('admin.regulations.store'), $this->data(['butir' => ['ngawur']]))->assertSessionHasErrors('butir.0');
        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->actingAs($koor)->post(route('admin.regulations.store'), $this->data())->assertForbidden();
        $this->assertSame(0, Regulation::count());
    }

    public function test_acuan_tampil_hanya_pada_butir_kurang(): void
    {
        $folder = FtpFolder::create(['path' => '/ftp/data/x', 'name' => '000001_PASIEN_SEP1', 'nama_pasien' => 'PASIEN', 'files' => [], 'fingerprint' => 'f']);
        RubricItem::create(['ftp_folder_id' => $folder->id, 'kode' => 'dok_resume', 'status' => 'kurang']);
        RubricItem::create(['ftp_folder_id' => $folder->id, 'kode' => 'dok_sep', 'status' => 'lengkap']);
        Regulation::create($this->data())->syncRubric(['dok_resume', 'dok_sep']);
        Regulation::create($this->data(['judul' => 'Nonaktif X', 'aktif' => false]))->syncRubric(['dok_resume']);
        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');

        $this->actingAs($koor)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('data-acuan="dok_resume"', false)->assertDontSee('data-acuan="dok_sep"', false)
            ->assertSee('Permenkes 24/2022 Rekam Medis')->assertSee('Pasal 26')->assertSee('Resume medis wajib ditandatangani DPJP.')
            ->assertSee('https://example.org/pmk24')->assertDontSee('Nonaktif X');
    }
}
