<?php

namespace Tests\Feature;

use App\Jobs\RunFolderAnalysis;
use App\Models\AiProvider;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\ManualFile;
use App\Models\User;
use App\Services\Import\SimrsFtp;
use App\Services\Manual\ManualStorage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManualUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    private const SEP = '0001R0011125V000001';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ManualStorage::DISK);
        Storage::fake(config('casemix.storage_disk'));
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
    }

    private function pdf(string $name = 'resume.pdf', string $isi = 'halo'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\n% $isi\n%%EOF");
    }

    private function jpeg(int $w = 40, int $h = 20, string $app1 = ''): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img, null, 90);
        $jpg = (string) ob_get_clean();

        return $app1 === '' ? $jpg : substr($jpg, 0, 2) . $app1 . substr($jpg, 2);
    }

    /** APP1 Exif: orientasi 6 (putar 90°) + data GPS tiruan. */
    private function exifGps(): string
    {
        $tiff = "MM\0\x2A" . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', 6) . "\0\0" . pack('N', 0) . 'GPSLatitude-6.2088';
        $body = "Exif\0\0" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($body) + 2) . $body;
    }

    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->actingAs($this->koor)->post(route('manual.store'), ['file' => $file, 'no_rm' => '123456', 'jenis_rawat' => 'inap'] + $extra,
            ['Accept' => 'application/json']);
    }

    private function manualFolder(): FtpFolder
    {
        $id = $this->upload($this->pdf())->assertCreated()->json('folder_id');

        return FtpFolder::findOrFail($id);
    }

    public function test_admin_ditolak(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->actingAs($admin)->get('/unggah')->assertForbidden();
        $this->actingAs($admin)->post(route('manual.store'), ['file' => $this->pdf()])->assertForbidden();
        $this->actingAs($this->koor)->get('/unggah')->assertOk()->assertSee('Unggah dan analisa');
    }

    public function test_halaman_unggah_memakai_komponen_form(): void
    {
        $html = $this->actingAs($this->koor)->get('/unggah')->assertOk()->getContent();

        // x-input: kelas .field (border + focus ring) dan label terhubung lewat for/id + aria-describedby
        $this->assertMatchesRegularExpression('/<input[^>]*class="[^"]*\bfield\b[^"]*"[^>]*name="no_sep"|<input[^>]*name="no_sep"[^>]*class="[^"]*\bfield\b/', $html);
        $this->assertStringContainsString('for="no_sep"', $html);
        $this->assertStringContainsString('aria-describedby="no_sep-hint"', $html);
        $this->assertStringContainsString('id="no_sep-hint"', $html);
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.field \{[^}]*border[^}]*focus:ring-\[3px\]/', $css);

        // Tombol aksi: disabled tanpa file, tanpa bentuk pil
        $this->assertMatchesRegularExpression('/<button[^>]*class="btn-primary"[^>]*data-submit disabled>Unggah dan analisa/', $html);
        preg_match_all('/<button[^>]*class="([^"]*)"/', $html, $m);
        foreach ($m[1] as $cls) {
            if (str_contains($cls, 'btn-')) {
                $this->assertStringNotContainsString('rounded-full', $cls);
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\.btn-primary \{[^}]*rounded-full/', $css);
        $this->assertStringContainsString('Lihat riwayat unggahan', $html);
        $this->assertStringContainsString('0/19', $html);
    }

    public function test_pdf_dan_jpg_diterima(): void
    {
        $r = $this->upload($this->pdf())->assertCreated();
        $this->upload(UploadedFile::fake()->createWithContent('foto.jpg', $this->jpeg()), ['folder_id' => $r->json('folder_id')])->assertCreated();
        $this->assertSame(2, ManualFile::count());
        ManualFile::all()->each(fn ($m) => Storage::disk('local')->assertExists($m->path));
        $this->assertStringStartsWith('manual/' . $r->json('folder_id') . '/', ManualFile::first()->path);
    }

    public function test_exe_berganti_nama_pdf_ditolak(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('virus.pdf', "MZ\x90\0\x03\0\0\0 program"))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Isi file bukan PDF, JPG, PNG, atau WEBP.']);
        $this->assertSame(0, ManualFile::count());
    }

    public function test_heic_ditolak_dengan_pesan(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('IMG_1.HEIC', "\0\0\0\x18ftypheic\0\0\0\0mif1heic"))
            ->assertStatus(422)->assertJson(fn ($j) => $j->where('message', fn ($m) => str_contains($m, 'Ubah foto ke JPG dulu'))->etc());
    }

    public function test_lebih_dari_25mb_ditolak(): void
    {
        $this->upload(UploadedFile::fake()->create('besar.pdf', 26 * 1024, 'application/pdf'))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Ukuran file melebihi 25 MB.']);
    }

    public function test_duplikat_sha256_ditolak(): void
    {
        $id = $this->upload($this->pdf('a.pdf', 'sama'))->assertCreated()->json('folder_id');
        $this->upload($this->pdf('b.pdf', 'sama'), ['folder_id' => $id])
            ->assertStatus(422)->assertJsonFragment(['message' => 'File yang sama sudah ada di folder ini.']);
    }

    public function test_pdf_terenkripsi_ditolak(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('kunci.pdf', "%PDF-1.4\ntrailer << /Encrypt 5 0 R >>"))->assertStatus(422);
    }

    public function test_exif_gps_terbuang_dan_rotasi_diperbaiki(): void
    {
        $raw = $this->jpeg(40, 20, $this->exifGps());
        $this->assertSame(6, ManualStorage::orientation($raw));
        $this->upload(UploadedFile::fake()->createWithContent('foto.jpg', $raw))->assertCreated();

        $isi = Storage::disk('local')->get(ManualFile::first()->path);
        $this->assertStringNotContainsString('Exif', $isi);
        $this->assertStringNotContainsString('GPS', $isi);
        [$w, $h] = getimagesizefromstring($isi);
        $this->assertSame([20, 40], [$w, $h]);
    }

    public function test_sep_yang_sudah_ada_tidak_membuat_folder_baru(): void
    {
        $ftp = FtpFolder::create(['path' => '/ftp/data/x', 'name' => 'x', 'no_sep' => self::SEP, 'files' => [], 'fingerprint' => FtpFolder::fingerprintOf([])]);
        $this->actingAs($this->koor)->getJson(route('manual.lookup', ['no_sep' => self::SEP]))->assertJsonPath('folder.id', $ftp->id);

        $this->upload($this->pdf(), ['no_sep' => self::SEP])->assertCreated()->assertJsonPath('folder_id', $ftp->id);
        $this->assertSame(1, FtpFolder::count());
        $this->assertSame('ftp', $ftp->fresh()->sumber);
    }

    public function test_folder_manual_dan_analisa_tersimpan_dengan_termasuk_manual(): void
    {
        $folder = $this->manualFolder();
        $this->assertSame('manual', $folder->sumber);
        $this->assertSame($this->koor->id, $folder->dibuat_oleh);

        $this->actingAs($this->koor)->postJson(route('manual.analyze', $folder))->assertOk()
            ->assertJsonPath('redirect', route('folders.show', $folder))->assertJsonPath('analisa', true);
        $a = FolderAnalysis::where('ftp_folder_id', $folder->id)->firstOrFail();
        $this->assertTrue($a->termasuk_manual);
        $this->assertSame(1, $a->documents()->whereNotNull('manual_file_id')->count());
        Queue::assertPushed(RunFolderAnalysis::class);

        $this->actingAs($this->koor)->getJson(route('folders.status_analisa', $folder))->assertJsonPath('active', true);
        $this->actingAs($this->koor)->get(route('folders.show', $folder))->assertOk()->assertSee('Dokumen tambahan')->assertSee('Manual');
    }

    public function test_sync_ftp_tidak_menyentuh_folder_manual(): void
    {
        $folder = $this->manualFolder();
        $this->app->instance(SimrsFtp::class, new class extends SimrsFtp
        {
            public function __construct() {}

            public function configured(): bool
            {
                return true;
            }

            public function listPatientFolders(): Collection
            {
                return collect([['path' => '/ftp/data/lain', 'folder' => 'lain', 'no_rm' => '9', 'nama' => null, 'no_sep' => null, 'jenis_rawat' => 'inap', 'files' => []]]);
            }
        });
        $this->artisan('casemix:sync-ftp')->assertSuccessful();

        $f = $folder->fresh();
        $this->assertNull($f->missing_at);
        $this->assertSame('manual', $f->sumber);
        $this->assertSame(1, $f->manualFiles()->count());
    }

    public function test_hapus_butuh_alasan_dan_memicu_analisa_ulang(): void
    {
        $folder = $this->manualFolder();
        $m = ManualFile::first();
        $this->actingAs($this->koor)->delete(route('manual.destroy', [$folder, $m]))->assertSessionHasErrors('alasan');
        $this->assertNotSoftDeleted($m);

        $this->actingAs($this->koor)->delete(route('manual.destroy', [$folder, $m]), ['alasan' => 'Salah pasien'])->assertRedirect();
        $this->assertSoftDeleted($m);
        $this->assertSame('Salah pasien', ManualFile::withTrashed()->find($m->id)->alasan_hapus);
        $this->assertSame(1, FolderAnalysis::where('ftp_folder_id', $folder->id)->count());
        Queue::assertPushed(RunFolderAnalysis::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'manual.hapus']);
    }

    public function test_file_tidak_bisa_diakses_tanpa_login(): void
    {
        $folder = $this->manualFolder();
        $m = ManualFile::first();
        auth()->logout();
        $this->get(route('manual.show', [$folder, $m]))->assertRedirect();

        $this->actingAs($this->koor)->get(route('manual.show', [$folder, $m]))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $this->actingAs($this->koor)->get(route('manual.show', [$folder, $m]))->headers->get('Content-Disposition'));
    }

    public function test_prune_menghapus_file_fisik(): void
    {
        $folder = $this->manualFolder();
        $m = ManualFile::first();
        $m->forceFill(['created_at' => now()->subMonths(30)])->saveQuietly();

        $this->artisan('casemix:archive-prune')->assertSuccessful();

        Storage::disk('local')->assertMissing($m->path);
        $this->assertNotNull($m->fresh()->dimusnahkan_at);
        $this->assertSame(0, $folder->manualFiles()->count());
    }

    public function test_panel_dokumen_tambahan_kosong_tampil_dropzone(): void
    {
        $folder = FtpFolder::create(['path' => '/ftp/data/kosong', 'name' => '000001_PASIEN_SEPX', 'files' => [], 'fingerprint' => 'f',
            'files_modified_at' => now()->subDay(), 'last_seen_at' => now()]);
        $html = $this->actingAs($this->koor)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('Belum ada dokumen tambahan')->assertSee('Pilih file')
            ->assertSee('Tambahkan dokumen yang tidak ada di folder FTP, misalnya hasil lab atau resume yang tertinggal.')->getContent();
        $this->assertStringContainsString('data-manual-drop data-manual-kosong', $html);
        $this->assertStringContainsString('data-jumlah-manual', $html);
        $this->assertStringContainsString('data-tambah hidden', $html);
    }
}
