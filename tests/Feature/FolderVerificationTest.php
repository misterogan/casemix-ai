<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\RunFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderSync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** FTP tiruan: daftar folder bisa diubah per tes, dan unduhan menulis gambar kecil. */
class FakeFtp extends SimrsFtp
{
    public array $folders = [];

    public array $downloads = [];

    public function configured(): bool
    {
        return true;
    }

    public function listPatientFolders(): Collection
    {
        return collect($this->folders);
    }

    public function download(string $remotePath, string $localRelative): string
    {
        $this->downloads[] = $remotePath;
        Storage::disk(config('casemix.storage_disk'))->put($localRelative,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        return $localRelative;
    }
}

class FolderVerificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeFtp $ftp;

    private User $koordinator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('casemix.storage_disk'));
        $this->seed(RolePermissionSeeder::class);
        $this->koordinator = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->ftp = new FakeFtp;
        $this->app->instance(SimrsFtp::class, $this->ftp);
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
    }

    private function ftpFolder(string $name, array $files, int $mtime = 1_794_000_000): array
    {
        return ['path' => "/ftp/data/$name", 'folder' => $name, 'no_rm' => explode('_', $name)[0], 'nama' => explode('_', $name)[1] ?? null,
            'no_sep' => explode('_', $name)[2] ?? null, 'jenis_rawat' => 'inap', 'dikenali' => true,
            'files' => array_map(fn ($f) => ['path' => "/ftp/data/$name/$f", 'name' => $f, 'size' => 1000, 'modified' => $mtime], $files)];
    }

    private function synced(array $files = ['SEP.png', 'Resume Medis.png']): FtpFolder
    {
        $this->ftp->folders = [$this->ftpFolder('000001_PASIEN CONTOH_0000R0000000V000001', $files)];
        app(FolderSync::class)->run();

        return FtpFolder::sole();
    }

    private function fakeAi(array $json): void
    {
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 30000, 'output_tokens' => 2000],
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
        ])]);
    }

    // ------------------------------------------------------------ sinkronisasi

    public function test_sinkron_mencatat_folder_baru_berubah_dan_hilang(): void
    {
        $this->ftp->folders = [$this->ftpFolder('000001_A_0000R0000000V000001', ['SEP.pdf']), $this->ftpFolder('000002_B_0000R0000000V000002', ['SEP.pdf'])];
        $s = app(FolderSync::class)->run();
        $this->assertSame(['folders' => 2, 'baru' => 2, 'berubah' => 0, 'hilang' => 0], $s);
        $fp = FtpFolder::where('no_rm', '000001')->value('fingerprint');

        // Folder A dapat file baru, folder B dipindahkan DHealth
        $this->ftp->folders = [$this->ftpFolder('000001_A_0000R0000000V000001', ['SEP.pdf', 'Hasil Radiologi.pdf'])];
        $s = app(FolderSync::class)->run();

        $this->assertSame(['folders' => 1, 'baru' => 0, 'berubah' => 1, 'hilang' => 1], $s);
        $this->assertNotSame($fp, FtpFolder::where('no_rm', '000001')->value('fingerprint'));
        $this->assertNotNull(FtpFolder::where('no_rm', '000002')->value('missing_at'));
        $this->assertTrue(AppSetting::get(FolderSync::KEY_LAST)['ok']);
    }

    public function test_sinkron_terjadwal_tiap_jam(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'casemix:sync-ftp'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }

    // ------------------------------------------------------------ halaman

    public function test_koordinator_diarahkan_ke_verifikasi_dan_daftar_bisa_dicari(): void
    {
        $this->synced();
        $this->actingAs($this->koordinator)->get('/')->assertRedirect(route('folders.index'));
        $this->actingAs($this->koordinator)->get(route('folders.index', ['sep' => '0000R0000000V000001']))->assertOk()->assertSee('PASIEN CONTOH')->assertSee('Belum diperiksa AI');
        $this->actingAs($this->koordinator)->get(route('folders.index', ['sep' => 'TIDAKADA']))->assertOk()->assertDontSee('PASIEN CONTOH');
    }

    private function folder(string $nama, array $attrs = []): FtpFolder
    {
        return FtpFolder::create($attrs + ['path' => "/ftp/data/$nama", 'name' => $nama, 'nama_pasien' => $nama, 'files' => [], 'fingerprint' => 'f']);
    }

    private function daftar(array $filter)
    {
        return $this->actingAs($this->koordinator)->get(route('folders.index', $filter))->assertOk();
    }

    public function test_filter_sep_cocok_sebagian_tanpa_peduli_spasi_dan_huruf(): void
    {
        $this->folder('PASIEN SATU', ['no_sep' => '0030R0011126V000143']);
        $this->folder('PASIEN DUA', ['no_sep' => '0030R0011126V000999']);

        $this->daftar(['sep' => ' 126v 000143 '])->assertSee('PASIEN SATU')->assertDontSee('PASIEN DUA')->assertSee('1 folder ditemukan');
        $this->daftar(['sep' => '0030r0011126'])->assertSee('PASIEN SATU')->assertSee('PASIEN DUA');
    }

    public function test_filter_tanggal_inklusif_dan_boleh_terbalik(): void
    {
        $this->folder('PASIEN AWAL', ['files_modified_at' => '2026-11-01 00:00:00']);
        $this->folder('PASIEN AKHIR', ['files_modified_at' => '2026-11-03 23:59:00']);
        $this->folder('PASIEN LUAR', ['files_modified_at' => '2026-11-04 00:00:01']);

        foreach ([['dari' => '2026-11-01', 'sampai' => '2026-11-03'], ['dari' => '2026-11-03', 'sampai' => '2026-11-01']] as $f) {
            $this->daftar($f)->assertSee('PASIEN AWAL')->assertSee('PASIEN AKHIR')->assertDontSee('PASIEN LUAR');
        }
    }

    public function test_filter_status_pemeriksaan(): void
    {
        $this->folder('PASIEN SUDAH', ['analysis_status' => 'selesai', 'analyzed_fingerprint' => 'f']);
        $this->folder('PASIEN BELUM');

        $this->daftar(['periksa' => 'sudah'])->assertSee('PASIEN SUDAH')->assertDontSee('PASIEN BELUM');
        $this->daftar(['periksa' => 'belum'])->assertSee('PASIEN BELUM')->assertDontSee('PASIEN SUDAH');
        $this->actingAs($this->koordinator)->get(route('folders.index', ['periksa' => 'lain']))->assertSessionHasErrors('periksa');
    }

    public function test_modul_lama_mati_bila_legacy_dimatikan(): void
    {
        $this->assertNotSame(404, $this->actingAs($this->koordinator)->get('/berkas')->status(), 'Saat menyala, route lama tetap ada');

        config(['casemix.legacy_claims' => false]);
        $this->actingAs($this->koordinator)->get('/berkas')->assertNotFound();
        $this->actingAs($this->koordinator)->get('/impor')->assertNotFound();
        $this->actingAs($this->koordinator)->get('/ringkasan')->assertNotFound();
        $this->actingAs($this->koordinator)->post('/berkas')->assertNotFound();
        $this->actingAs($this->koordinator)->post('/berkas/1/dokumen')->assertNotFound();
        $this->actingAs($this->koordinator)->get(route('folders.index'))->assertOk();
    }

    public function test_dibuka_pertama_kali_menganalisa_otomatis_lalu_tidak_lagi(): void
    {
        Queue::fake();
        $folder = $this->synced();

        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()->assertSee('AI sedang membaca');
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk();

        Queue::assertPushed(RunFolderAnalysis::class, 1);
        $this->assertSame(1, FolderAnalysis::count());
        $this->assertSame('proses', $folder->fresh()->state());
    }

    public function test_analisa_ulang_lewat_tombol(): void
    {
        Queue::fake();
        $folder = $this->synced();
        FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Selesai, 'finished_at' => now(), 'findings' => []]);

        $this->actingAs($this->koordinator)->post(route('folders.analyze', $folder))->assertSessionHas('ok');

        Queue::assertPushed(RunFolderAnalysis::class, 1);
        $this->assertSame(1, AuditLog::where('action', 'folder.analisa_ulang')->count());
    }

    public function test_anggaran_habis_tidak_menganalisa_otomatis(): void
    {
        Queue::fake();
        AppSetting::put(AiBudget::KEY_BUDGET, 1);
        FolderAnalysis::create(['ftp_folder_id' => $this->synced()->id, 'status' => ReviewStatus::Selesai, 'cost_usd' => 1, 'finished_at' => now()]);
        $lain = FtpFolder::create(['path' => '/ftp/data/x', 'name' => 'x', 'files' => [], 'fingerprint' => 'x']);

        $this->actingAs($this->koordinator)->get(route('folders.show', $lain))->assertOk()->assertSee('Anggaran AI bulan ini sudah habis');
        Queue::assertNothingPushed();
    }

    public function test_hanya_koordinator_yang_bisa_membuka(): void
    {
        $folder = $this->synced();
        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');

        // Petugas hanya membaca: boleh membuka, tetapi tidak memicu atau meminta analisa AI
        Queue::fake();
        $this->actingAs($petugas)->get(route('folders.show', $folder))->assertOk()->assertDontSee('Analisa ulang dengan AI');
        $this->actingAs($petugas)->post(route('folders.analyze', $folder))->assertForbidden();
        Queue::assertNothingPushed();
        $this->actingAs($admin)->get(route('folders.index'))->assertForbidden();
        $this->actingAs($admin)->get('/')->assertRedirect(route('admin.budget.index'));
    }

    public function test_dokumen_dibuka_dari_ftp_lewat_nomor_urut_tanpa_disimpan(): void
    {
        $folder = $this->synced();

        $this->actingAs($this->koordinator)->get(route('folders.file', [$folder, 1]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->koordinator)->get(route('folders.file', [$folder, 9]))->assertNotFound();

        // Daftar file diurutkan menurut nama: 0 = Resume Medis.png, 1 = SEP.png
        $this->assertSame(['/ftp/data/000001_PASIEN CONTOH_0000R0000000V000001/SEP.png'], $this->ftp->downloads);
        $this->assertSame(1, AuditLog::where('action', 'folder.buka_dokumen')->count());
    }

    // ------------------------------------------------------------ analisa

    public function test_analisa_menyimpan_rekomendasi_dengan_dokumen_dan_halaman(): void
    {
        $folder = $this->synced(['SEP.png', 'Resume Medis.png', 'Billing.png']);
        $this->fakeAi([
            'ringkasan' => 'Dokumen wajib ada. Resume belum ditandatangani DPJP.',
            'temuan' => [
                ['tingkat' => 'kritis', 'kategori' => 'Kelengkapan', 'judul' => 'Resume belum ditandatangani DPJP', 'uraian' => 'Kolom tanda tangan kosong.', 'dokumen' => 'resume medis', 'halaman' => 2],
                ['tingkat' => 'sesuai', 'kategori' => 'Identitas', 'judul' => 'Identitas sama', 'uraian' => '', 'dokumen' => 'SEP.png', 'halaman' => null],
            ],
            'kondisi' => [['kondisi' => 'Kemungkinan diabetes (E11.9)', 'bukti' => 'Insulin pada terapi', 'dokumen' => 'Resume Medis.png', 'halaman' => 1]],
            'isian' => [['field' => 'tanggal_masuk', 'nilai' => '2026-11-04', 'dokumen' => 'SEP.png', 'keyakinan' => 'tinggi'],
                ['field' => 'tanggal_keluar', 'nilai' => '2026-11-02', 'dokumen' => 'Resume Medis.png', 'keyakinan' => 'tinggi'],
                ['field' => 'jenis_rawat', 'nilai' => 'inap', 'dokumen' => 'SEP.png', 'keyakinan' => 'tinggi']],
        ]);
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre, 'requested_by' => $this->koordinator->id]);

        RunFolderAnalysis::dispatchSync($a->id);

        $a->refresh();
        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $resume = collect($a->findings)->firstWhere('judul', 'Resume belum ditandatangani DPJP');
        $this->assertSame('Resume Medis.png', $resume['dokumen'], 'Nama dokumen dicocokkan ke file yang ada');
        $this->assertSame(2, $resume['halaman']);
        $this->assertSame('Kemungkinan diabetes', $a->suggestions[0]['kondisi'], 'Kode ICD dibuang');
        $this->assertTrue(collect($a->findings)->contains(fn ($f) => $f['sumber'] === 'aturan' && str_contains($f['judul'], 'keluar')), 'Aturan E-Klaim berjalan atas data yang terbaca');
        $this->assertFalse(collect($a->findings)->contains(fn ($f) => str_contains($f['judul'], 'belum diisi')), 'Tidak ada temuan "belum diisi" pada verifikasi folder');
        $this->assertEquals(0.08, (float) $a->cost_usd);

        $folder->refresh();
        $this->assertSame('temuan', $folder->state());
        $this->assertSame(Storage::disk(config('casemix.storage_disk'))->allFiles('tmp'), [], 'File sementara dihapus setelah dibaca');
        Http::assertSent(fn (HttpRequest $r) => str_contains(json_encode($r->data()), '[Halaman 1]'));

        // Dibuka lagi: hasil tersimpan tampil, AI tidak dipanggil lagi
        Http::fake();
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('Resume belum ditandatangani DPJP')->assertSee('Resume Medis.png · hal. 2')->assertSee('Konfirmasi ke DPJP');
        Http::assertNothingSent();

        // File berubah di FTP: ditandai, tetapi tidak dianalisa ulang otomatis
        $this->ftp->folders = [$this->ftpFolder('000001_PASIEN CONTOH_0000R0000000V000001', ['SEP.png', 'Resume Medis.png', 'Billing.png'], 1_794_100_000)];
        app(FolderSync::class)->run();
        $this->assertSame('berubah', $folder->fresh()->state());
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertSee('File di folder ini berubah');
        $this->assertSame(1, FolderAnalysis::count());
    }

    public function test_dokumen_wajib_yang_tidak_ada_dilaporkan_aturan(): void
    {
        $folder = $this->synced(['SEP.png']);
        $this->fakeAi(['ringkasan' => 'x', 'temuan' => [], 'kondisi' => [], 'isian' => []]);
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre]);

        RunFolderAnalysis::dispatchSync($a->id);

        $titles = collect($a->fresh()->findings)->pluck('judul');
        $this->assertContains('Resume medis tidak ada di folder', $titles);
        $this->assertContains('Rincian biaya tidak ada di folder', $titles);
    }
}
