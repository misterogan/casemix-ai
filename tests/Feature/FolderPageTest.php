<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\FolderAnalysis;
use App\Models\FolderTemuanCek;
use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Halaman verifikasi berkas: satu gulir, spanduk butir kurang, temuan per tingkat, tanda "Sudah dicek". */
class FolderPageTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    private FtpFolder $folder;

    private const TEMUAN = [
        ['tingkat' => 'perhatian', 'kategori' => 'Dokumen', 'judul' => 'Tanggal lab tidak terbaca', 'uraian' => 'Cek ulang.', 'dokumen' => 'Lab.pdf', 'halaman' => 3, 'sumber' => 'ai'],
        ['tingkat' => 'kritis', 'kategori' => 'Dokumen', 'judul' => 'Resume belum ditandatangani DPJP', 'uraian' => 'Kolom kosong.', 'dokumen' => 'Resume Medis.pdf', 'halaman' => 2, 'sumber' => 'ai'],
        ['tingkat' => 'sesuai', 'kategori' => 'Lainnya', 'judul' => 'SEP sesuai identitas', 'uraian' => '', 'dokumen' => 'SEP.pdf', 'halaman' => 1, 'sumber' => 'ai'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $files = collect(['Lab.pdf', 'Resume Medis.pdf', 'SEP.pdf'])->map(fn ($n) => ['path' => "/ftp/x/$n", 'name' => $n, 'size' => 10, 'modified' => 1])->all();
        $this->folder = FtpFolder::create(['path' => '/ftp/x', 'name' => '000001_PASIEN UJI_SEP1', 'nama_pasien' => 'PASIEN UJI', 'files' => $files,
            'fingerprint' => 'fp', 'files_modified_at' => now()->subDay(), 'last_seen_at' => now()]);
        $this->analisa(self::TEMUAN);
        RubricItem::create(['ftp_folder_id' => $this->folder->id, 'kode' => 'res_ttd_dpjp', 'status' => 'kurang', 'catatan' => 'Tidak ada tanda tangan DPJP.']);
        $this->folder->recomputeReadiness();
    }

    private function analisa(array $temuan): FolderAnalysis
    {
        return FolderAnalysis::create(['ftp_folder_id' => $this->folder->id, 'status' => ReviewStatus::Selesai, 'requested_by' => $this->koor->id,
            'findings' => $temuan, 'summary' => 'Ringkas.', 'fingerprint' => 'fp', 'finished_at' => now()]);
    }

    private function key(int $i, array $temuan = self::TEMUAN): string
    {
        return FolderTemuanCek::key($temuan[$i]);
    }

    public function test_satu_gulir_spanduk_temuan_per_tingkat_dan_drawer(): void
    {
        $html = $this->actingAs($this->koor)->get(route('folders.show', $this->folder))->assertOk()
            ->assertSee('Belum siap · 1 butir kurang')
            ->assertSee('data-kurang="res_ttd_dpjp"', false)->assertSee('href="#butir-res_ttd_dpjp"', false)->assertSee('id="butir-res_ttd_dpjp"', false)
            ->assertSeeInOrder(['data-kelompok="wajib"', 'Resume belum ditandatangani DPJP', 'data-kelompok="perhatian"', 'Tanggal lab tidak terbaca', 'data-kelompok="info"', 'SEP sesuai identitas'], false)
            ->assertSee('data-temuan-dicek>0</span> dari 3 sudah dicek', false)
            ->assertSee('data-penampil', false)->assertSee('data-penampil-tutup', false)->assertSee('id="judul-penampil"', false)
            ->assertSee('data-nav="temuan"', false)->assertSee('Temuan (3)')
            ->getContent();

        // Kolom hasil tanpa gulir bersarang
        $kolom = \Illuminate\Support\Str::between($html, 'data-folder-page', 'data-penampil-latar');
        $this->assertStringContainsString('data-bagian="temuan"', $kolom);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*(max-h-(\[|\d)|overflow-y-auto)/', $kolom);
        // Resume medis menjadi tab bawaan penampil
        $this->assertStringContainsString('data-awal="1"', $html);
    }

    public function test_centang_tersimpan_dan_bertahan_setelah_analisa_ulang(): void
    {
        $k = $this->key(1);
        $this->actingAs($this->koor)->postJson(route('folders.temuan_cek.store', $this->folder), ['key' => $k])->assertOk()->assertJson(['dicek' => true, 'jumlah' => 1]);
        $this->assertDatabaseHas('folder_temuan_cek', ['folder_id' => $this->folder->id, 'temuan_key' => $k, 'user_id' => $this->koor->id]);
        $this->actingAs($this->koor)->postJson(route('folders.temuan_cek.store', $this->folder), ['key' => str_repeat('a', 40)])->assertNotFound();

        // Analisa ulang: temuan sama tetap tercentang, temuan baru belum
        $baru = [...self::TEMUAN, ['tingkat' => 'kritis', 'kategori' => 'Dokumen', 'judul' => 'Billing tidak ada', 'dokumen' => null, 'halaman' => null, 'sumber' => 'aturan']];
        $this->analisa($baru);
        $html = $this->actingAs($this->koor)->get(route('folders.show', $this->folder))->assertOk()
            ->assertSee('data-temuan-dicek>1</span> dari 4 sudah dicek', false)->getContent();
        $this->assertMatchesRegularExpression('/data-cek="' . $k . '"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-cek="' . $this->key(3, $baru) . '"[^>]*checked/', $html);

        $this->actingAs($this->koor)->deleteJson(route('folders.temuan_cek.destroy', $this->folder), ['key' => $k])->assertOk()->assertJson(['dicek' => false, 'jumlah' => 0]);
        $this->assertDatabaseCount('folder_temuan_cek', 0);
    }

    public function test_admin_dan_peran_lain_ditolak(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $k = $this->key(0);
        $this->actingAs($admin)->get(route('folders.show', $this->folder))->assertForbidden();
        $this->actingAs($admin)->postJson(route('folders.temuan_cek.store', $this->folder), ['key' => $k])->assertForbidden();
        $this->actingAs($admin)->deleteJson(route('folders.temuan_cek.destroy', $this->folder), ['key' => $k])->assertForbidden();
        $this->actingAs($petugas)->postJson(route('folders.temuan_cek.store', $this->folder), ['key' => $k])->assertForbidden();
        $this->assertDatabaseCount('folder_temuan_cek', 0);
    }
}
