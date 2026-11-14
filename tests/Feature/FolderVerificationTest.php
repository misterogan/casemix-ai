<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\ReadFolderDocument;
use App\Jobs\RunFolderAnalysis;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderAnalyzer;
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

    /** Jawaban AI berurutan: satu per dokumen (urut nama file), lalu periksa silang. Tiap panggilan 1000 token masuk, 100 keluar. */
    private function fakeAi(array|string ...$replies): void
    {
        $seq = Http::sequence();
        foreach ($replies as $r) {
            $seq->push([
                'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
                'content' => [['type' => 'text', 'text' => is_string($r) ? $r : json_encode($r)]],
            ]);
        }
        Http::fake(['*/v1/messages' => $seq]);
    }

    private function analyze(FtpFolder $folder): FolderAnalysis
    {
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre, 'requested_by' => $this->koordinator->id]);
        RunFolderAnalysis::dispatchSync($a->id);

        return $a->fresh();
    }

    private const SILANG = ['ringkasan' => 'Berkas lengkap.', 'temuan' => [], 'kondisi' => []];

    // ------------------------------------------------------------ sinkronisasi

    public function test_sinkron_mencatat_folder_baru_berubah_dan_hilang(): void
    {
        $this->ftp->folders = [$this->ftpFolder('000001_A_0000R0000000V000001', ['SEP.pdf']), $this->ftpFolder('000002_B_0000R0000000V000002', ['SEP.pdf'])];
        $s = app(FolderSync::class)->run();
        $this->assertSame(['folders' => 2, 'baru' => 2, 'berubah' => 0, 'hilang' => 0], $s);
        $fp = FtpFolder::where('no_rm', '000001')->value('fingerprint');

        // Folder A dapat file baru, folder B dipindahkan Enigma
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

        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()->assertSee('AI sedang membaca')->assertSee('0 dari 2 file selesai');
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

    public function test_hasil_per_dokumen_tersimpan_lalu_diperiksa_silang(): void
    {
        $folder = $this->synced(['SEP.png', 'Resume Medis.png', 'Billing.png']);
        // Urutan file menurut nama: Billing, Resume Medis, SEP, lalu periksa silang
        $this->fakeAi(
            ['biaya' => [['komponen' => 'kamar', 'nilai' => 500000]], 'temuan' => []],
            ['isian' => [['field' => 'tanggal_keluar', 'nilai' => '2026-11-02']], 'diagnosis' => ['Pneumonia'],
                'temuan' => [['tingkat' => 'kritis', 'judul' => 'Resume belum ditandatangani DPJP', 'uraian' => 'Kolom tanda tangan kosong.', 'halaman' => 2]]],
            ['isian' => [['field' => 'tanggal_masuk', 'nilai' => '2026-11-04'], ['field' => 'jenis_rawat', 'nilai' => 'inap']], 'temuan' => []],
            ['ringkasan' => 'Dokumen wajib ada. Resume belum ditandatangani DPJP.',
                'temuan' => [['tingkat' => 'perhatian', 'kategori' => 'Tanggal', 'judul' => 'Tanggal keluar sebelum tanggal masuk', 'uraian' => 'SEP dan resume berbeda.', 'dokumen' => 'resume medis', 'halaman' => 1]],
                'kondisi' => [['kondisi' => 'Kemungkinan diabetes (E11.9)', 'bukti' => 'Insulin pada terapi', 'dokumen' => 'Resume Medis.png', 'halaman' => 1]]],
        );

        $a = $this->analyze($folder);

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $docs = $a->documents;
        $this->assertSame(['Billing.png', 'Resume Medis.png', 'SEP.png'], $docs->pluck('name')->all());
        $this->assertTrue($docs->every(fn ($d) => $d->status === ReviewStatus::Selesai && ! $d->reused));
        $this->assertSame(['Pneumonia'], $docs[1]->summary['diagnosis'], 'Hasil baca per dokumen tersimpan');
        $this->assertTrue($docs->every(fn ($d) => (float) $d->cost_usd === 0.003), 'Biaya tiap dokumen tercatat');
        $this->assertEquals(0.003, (float) $a->cost_usd, 'Biaya periksa silang tercatat');
        $this->assertEquals(0.012, app(AiBudget::class)->spent());

        $resume = collect($a->findings)->firstWhere('judul', 'Resume belum ditandatangani DPJP');
        $this->assertSame(['Resume Medis.png', 2], [$resume['dokumen'], $resume['halaman']]);
        $this->assertSame('Resume Medis.png', collect($a->findings)->firstWhere('judul', 'Tanggal keluar sebelum tanggal masuk')['dokumen']);
        $this->assertSame('Kemungkinan diabetes', $a->suggestions[0]['kondisi'], 'Kode ICD dibuang');
        $this->assertTrue(collect($a->findings)->contains(fn ($f) => $f['sumber'] === 'aturan' && str_contains($f['judul'], 'keluar')), 'Aturan E-Klaim berjalan atas isian gabungan');
        $this->assertSame('temuan', $folder->fresh()->state());
        $this->assertSame([], Storage::disk(config('casemix.storage_disk'))->allFiles('tmp'), 'File sementara dihapus setelah dibaca');

        // 3 panggilan per dokumen (masing-masing 1 gambar) + 1 periksa silang tanpa gambar
        $sent = Http::recorded()->map(fn ($p) => json_encode($p[0]->data()))->values();
        $this->assertCount(4, $sent);
        $this->assertTrue($sent->take(3)->every(fn ($b) => substr_count($b, '"type":"image"') === 1 && str_contains($b, '[Halaman 1]')));
        $this->assertStringNotContainsString('"type":"image"', $sent[3]);
        $this->assertStringContainsString('Pneumonia', $sent[3], 'Periksa silang memakai ringkasan tahap 1');

        // Dibuka lagi: hasil tersimpan tampil, AI tidak dipanggil lagi
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('3 dari 3 file selesai')->assertSee('Resume belum ditandatangani DPJP')->assertSee('Konfirmasi ke DPJP')
            ->assertSeeInOrder(['data-kepala', 'Analisa ulang dengan AI', 'data-docs-progress', 'Rekomendasi keseluruhan', 'File (3)', 'data-docs-list', 'data-doc='], false)
            ->assertSee('Buka dokumen')->assertDontSee('Dokumen di folder');
        $this->assertCount(4, Http::recorded());
    }

    public function test_analisa_ulang_hanya_membaca_file_yang_berubah(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG, $doc, self::SILANG);
        $this->analyze($folder);
        $this->assertCount(3, Http::recorded());

        // Resume diperbaiki di DHealth: hanya file itu yang berubah
        $f = $this->ftpFolder('000001_PASIEN CONTOH_0000R0000000V000001', ['SEP.png', 'Resume Medis.png']);
        $f['files'][1]['modified'] = 1_794_100_000;
        $this->ftp->folders = [$f];
        app(FolderSync::class)->run();

        $a = $this->analyze($folder->fresh());

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertCount(5, Http::recorded(), 'Hanya file yang berubah + periksa silang');
        $this->assertSame(['Resume Medis.png' => false, 'SEP.png' => true], $a->documents->pluck('reused', 'name')->all());
        $this->assertNull($a->documents->firstWhere('name', 'SEP.png')->cost_usd, 'Dokumen yang dipakai ulang tidak memakan biaya');
        $this->assertNotSame('berubah', $folder->fresh()->state(), 'Hasil baru sesuai isi folder terkini');
    }

    public function test_dokumen_gagal_menghentikan_dokumen_berikutnya_dan_tahap_dua(): void
    {
        $folder = $this->synced(['SEP.png', 'Resume Medis.png', 'Billing.png']);
        // Urutan: Billing berhasil, Resume Medis dijawab bukan JSON, SEP tidak diproses
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi(['biaya' => [], 'temuan' => []], 'maaf, tidak bisa', $doc, $doc, self::SILANG);

        $a = $this->analyze($folder);

        $this->assertCount(2, Http::recorded(), 'Tidak ada request setelah dokumen gagal, termasuk tahap 2');
        $st = $a->documents->pluck('status', 'name')->map->value->all();
        $this->assertSame(['Billing.png' => 'selesai', 'Resume Medis.png' => 'gagal', 'SEP.png' => 'dibatalkan'], $st);
        $resume = $a->documents->firstWhere('name', 'Resume Medis.png');
        $this->assertStringContainsString('bukan JSON', $resume->error);
        $this->assertEquals(0.003, (float) $resume->cost_usd, 'Biaya tetap tercatat walau jawaban gagal diolah');
        $this->assertSame(ReviewStatus::Gagal, $a->status);
        $this->assertSame('Dihentikan: Resume Medis.png gagal dibaca. Dokumen berikutnya tidak diproses.', $a->error);
        $this->assertSame('gagal', $folder->fresh()->state());

        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))
            ->assertSee('Tidak diproses karena dokumen sebelumnya gagal')->assertSee('Lanjutkan dari dokumen yang gagal')
            ->assertSee('data-resynth hidden', false);
        $this->actingAs($this->koordinator)->post(route('folders.resynthesize', $folder))->assertSessionHas('warn');
        $this->assertCount(2, Http::recorded(), 'Ulangi rekomendasi ditolak bila ada file gagal');

        // Lanjutkan: hanya dokumen gagal + dibatalkan, lalu tahap 2; Billing tidak dibaca ulang
        $this->actingAs($this->koordinator)->post(route('folders.resume', $folder))->assertSessionHas('ok');

        $a->refresh();
        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertCount(5, Http::recorded(), 'Resume + SEP + tahap 2');
        $this->assertTrue($a->documents->every(fn ($d) => $d->status === ReviewStatus::Selesai));
        $this->assertSame(1, AuditLog::where('action', 'folder.analisa_lanjut')->count());

        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->actingAs($petugas)->post(route('folders.resume', $folder))->assertForbidden();
    }

    public function test_lanjutkan_hanya_mengantrekan_dokumen_gagal_dan_dibatalkan(): void
    {
        $folder = $this->synced(['SEP.png', 'Resume Medis.png', 'Billing.png']);
        $this->fakeAi(['biaya' => [], 'temuan' => []], 'bukan json');
        $this->analyze($folder);

        Queue::fake();
        $this->actingAs($this->koordinator)->post(route('folders.resume', $folder))->assertSessionHas('ok');

        Queue::assertPushedWithChain(ReadFolderDocument::class, [ReadFolderDocument::class, SynthesizeFolderAnalysis::class]);
        $this->assertSame(['Billing.png' => 'selesai', 'Resume Medis.png' => 'antre', 'SEP.png' => 'antre'],
            FolderAnalysis::sole()->documents->pluck('status', 'name')->map->value->all());
    }

    public function test_model_dan_max_tokens_per_jenis_dokumen(): void
    {
        $folder = $this->synced(['SEP.png', 'Resume Medis.png', 'Asesmen.png']);
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre]);
        app(FolderAnalyzer::class)->prepare($a);
        $a->documents()->where('name', 'Asesmen.png')->update(['doc_type' => 'tambahan']);
        // Urutan: Asesmen (tambahan), Resume Medis, SEP, lalu tahap 2
        $this->fakeAi(['identitas' => [], 'temuan' => []], ['isian' => [], 'temuan' => []], ['isian' => [], 'temuan' => []], self::SILANG);

        RunFolderAnalysis::dispatchSync($a->id);

        $this->assertSame(ReviewStatus::Selesai, $a->fresh()->status, (string) $a->fresh()->error);
        $sent = Http::recorded()->map(fn ($p) => $p[0]->data())->values();
        $this->assertSame(
            [['claude-haiku-5-5', 4000], ['claude-sonnet-5-5', 4096], ['claude-haiku-5-5', 4000], ['claude-sonnet-5-5', 4096]],
            $sent->map(fn ($d) => [$d['model'], $d['max_tokens']])->all(),
        );
        $prompt = fn ($i) => json_encode($sent[$i]['messages']);
        $this->assertStringContainsString('dokumen pendukung', $prompt(0), 'Dokumen tambahan memakai instruksi singkat');
        $this->assertStringNotContainsString('\"isian\"', $prompt(0));
        $this->assertStringContainsString('\"isian\"', $prompt(1));
        $this->assertStringContainsString('\"isian\"', $prompt(2), 'SEP memakai model cepat tetapi tetap menyalin isian');
    }

    /** Jawaban AI palsu dengan stop_reason tertentu (1000 token masuk, 100 keluar → US$0,003). */
    private function reply(array $json, string $stop = 'end_turn'): array
    {
        return ['id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => $stop, 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
            'content' => [['type' => 'text', 'text' => $stop === 'max_tokens' ? '{"isian":[{"field":"dpjp","nil' : json_encode($json)]]];
    }

    public function test_terpotong_sekali_dicoba_ulang_lalu_berhasil(): void
    {
        AiProvider::query()->update(['max_tokens' => 20000]);
        $folder = $this->synced(['SEP.png']);
        Http::fake(['*/v1/messages' => Http::sequence()
            ->push($this->reply([], 'max_tokens'))
            ->push($this->reply(['isian' => [], 'temuan' => []]))
            ->push($this->reply(self::SILANG))]);

        $a = $this->analyze($folder);
        $doc = $a->documents->sole();

        $this->assertSame(ReviewStatus::Selesai, $doc->status, (string) $doc->error);
        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $sent = Http::recorded()->map(fn ($p) => $p[0]->data())->values();
        $this->assertCount(3, $sent, '2 request untuk dokumen + tahap 2');
        $this->assertSame([4000, 8000], [$sent[0]['max_tokens'], $sent[1]['max_tokens']], 'Coba ulang dengan batas dua kali lipat');
        $this->assertStringContainsString('Jawab lebih singkat: maks 3 temuan', json_encode($sent[1]['messages']));
        $this->assertSame([2000, 200], [$doc->tokens_in, $doc->tokens_out], 'Token kedua percobaan tercatat');
        $this->assertEquals(0.006, (float) $doc->cost_usd);
    }

    public function test_terpotong_dua_kali_ditandai_gagal(): void
    {
        AiProvider::query()->update(['max_tokens' => 20000]);
        $folder = $this->synced(['SEP.png']);
        Http::fake(['*/v1/messages' => Http::response($this->reply([], 'max_tokens'))]);

        $doc = $this->analyze($folder)->documents->sole();

        $this->assertCount(2, Http::recorded(), 'Tepat satu kali coba ulang; tahap 2 tidak dipanggil');
        $this->assertSame(ReviewStatus::Gagal, $doc->status);
        $this->assertStringStartsWith('Jawaban AI terpotong karena batas 8000 token tercapai (blok: text; output_tokens: 100;', $doc->error);
        $this->assertEquals(0.006, (float) $doc->cost_usd, 'Biaya kedua percobaan tercatat');
    }

    public function test_status_mengembalikan_jumlah_dokumen_selesai(): void
    {
        Queue::fake();
        $folder = $this->synced();
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder));

        $this->actingAs($this->koordinator)->getJson(route('folders.status', $folder))
            ->assertJson(['active' => true, 'done' => 0, 'total' => 2, 'summary_html' => null])->assertJsonCount(2, 'docs');
    }

    public function test_status_mengembalikan_html_per_file_dan_rekomendasi(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'diagnosis' => ['Pneumonia'], 'temuan' => [['tingkat' => 'kritis', 'judul' => 'TTD DPJP kosong', 'halaman' => 2]]];
        $this->fakeAi($doc, ['isian' => [], 'temuan' => []], self::SILANG);
        $a = $this->analyze($folder);

        $j = $this->actingAs($this->koordinator)->getJson(route('folders.status', $folder))->assertOk()
            ->assertJson(['active' => false, 'done' => 2, 'total' => 2, 'stuck' => false])
            ->assertJsonStructure(['docs' => [['id', 'status', 'html']], 'summary_html'])->json();

        $resume = collect($j['docs'])->firstWhere('id', $a->documents->firstWhere('name', 'Resume Medis.png')->id);
        $this->assertSame('selesai', $resume['status']);
        $this->assertStringContainsString('TTD DPJP kosong', $resume['html']);
        $this->assertStringContainsString('Pneumonia', $resume['html'], 'Data ringkas yang terbaca tampil');
        $this->assertStringContainsString('#page=2', $resume['html'], 'Tautan ke halaman temuan');
        $this->assertStringContainsString('Proses ulang file ini', $resume['html']);
        $this->assertMatchesRegularExpression('/<details[^>]*open/', $resume['html'], 'Item dengan temuan terbuka');
        $this->assertStringContainsString('Berkas lengkap.', $j['summary_html']);
    }

    public function test_proses_ulang_semua_file_memanggil_ai_untuk_semua_file(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG, $doc, $doc, self::SILANG);
        $this->analyze($folder);

        $this->actingAs($this->koordinator)->post(route('folders.process_all', $folder))->assertSessionHas('ok');

        $a = $folder->analyses()->first();
        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertCount(6, Http::recorded(), 'File tidak berubah tetap dibaca ulang');
        $this->assertTrue($a->documents->every(fn ($d) => ! $d->reused && $d->cost_usd !== null));
        $this->assertSame(1, AuditLog::where('action', 'folder.proses_semua')->count());
    }

    public function test_proses_ulang_satu_file_hanya_file_itu_dan_tahap_dua(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG, $doc, self::SILANG);
        $a = $this->analyze($folder);
        $sep = $a->documents->firstWhere('name', 'SEP.png');
        $resume = $a->documents->firstWhere('name', 'Resume Medis.png');

        $this->actingAs($this->koordinator)->post(route('folders.process_document', [$folder, $sep]))->assertSessionHas('ok');

        $this->assertCount(5, Http::recorded(), 'Hanya SEP + tahap 2');
        $this->assertSame(ReviewStatus::Selesai, $a->fresh()->status);
        $this->assertEquals($resume->finished_at, $resume->fresh()->finished_at, 'File lain tidak dibaca ulang');
        $this->assertEquals(0.006, (float) $sep->fresh()->cost_usd, 'Biaya kedua kali baca SEP dijumlahkan');

        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->actingAs($petugas)->post(route('folders.process_document', [$folder, $sep]))->assertForbidden();
        $this->actingAs($petugas)->post(route('folders.process_all', $folder))->assertForbidden();
    }

    public function test_ulangi_rekomendasi_hanya_memanggil_tahap_dua_tanpa_membaca_file(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        // Kedua file terbaca, tahap 2 dijawab bukan JSON → gagal; lalu diulang dan berhasil
        $this->fakeAi($doc, $doc, 'maaf', self::SILANG);
        $a = $this->analyze($folder);
        $this->assertSame(ReviewStatus::Gagal, $a->status);

        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('Semua file berhasil dibaca. Rekomendasi keseluruhan gagal dibuat:')
            ->assertSee('(file tidak dibaca ulang)', false)
            ->assertSee('Ulangi rekomendasi keseluruhan')->assertDontSee('data-resynth hidden', false)
            ->assertDontSee('Lanjutkan dari dokumen yang gagal');
        $this->actingAs($this->koordinator)->getJson(route('folders.status', $folder))->assertJson(['can_resynth' => true]);
        $unduhan = count($this->ftp->downloads);

        $this->actingAs($this->koordinator)->post(route('folders.resynthesize', $folder))->assertSessionHas('ok');

        $this->assertCount(4, Http::recorded(), 'Hanya satu panggilan AI (tahap 2)');
        $this->assertSame($unduhan, count($this->ftp->downloads), 'File tidak dibaca ulang');
        $this->assertSame(ReviewStatus::Selesai, $a->fresh()->status, (string) $a->fresh()->error);
        $this->assertSame(1, AuditLog::where('action', 'folder.ulang_rekomendasi')->count());
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertSee('data-resynth hidden', false)->assertSee('Berkas lengkap.');

        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->actingAs($petugas)->post(route('folders.resynthesize', $folder))->assertForbidden();
    }

    public function test_dokumen_wajib_yang_tidak_ada_dilaporkan_aturan(): void
    {
        $folder = $this->synced(['SEP.png']);
        $this->fakeAi(['isian' => [], 'temuan' => []], self::SILANG);

        $titles = collect($this->analyze($folder)->findings)->pluck('judul');
        $this->assertContains('Resume medis tidak ada di folder', $titles);
        $this->assertContains('Rincian biaya tidak ada di folder', $titles);
    }
}
