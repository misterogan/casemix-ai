<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\ReadFolderDocument;
use App\Jobs\RunFolderAnalysis;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ConditionSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderAnalyzer;
use App\Services\Verification\FolderSync;
use App\Support\VerificationRubric;
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

    /** true = file sudah dipindah/dihapus dari FTP */
    public bool $gone = false;

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
        if ($this->gone) {
            throw new \RuntimeException('File tidak ada di FTP.');
        }
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

    /** Jawaban tahap 2 berupa rubrik: semua butir AI lengkap kecuali yang disebut. */
    private function rubrik(array $kurang = [], array $extra = []): array
    {
        return ['ringkasan' => 'Berkas diperiksa.', 'rubrik' => array_map(fn ($k) => ['kode' => $k, 'status' => in_array($k, $kurang, true) ? 'kurang' : 'lengkap',
            'catatan' => in_array($k, $kurang, true) ? 'Tidak ada tanda tangan DPJP.' : null, 'dokumen' => 'Resume Medis.png', 'halaman' => 1], VerificationRubric::aiCodes())] + $extra;
    }

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
        $this->actingAs($this->koordinator)->get('/')->assertRedirect(route('control.index'));
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
        AppSetting::put(\App\Http\Controllers\Admin\SettingController::KEY_CONDITION_SUGGESTIONS, true, null); // saran kondisi dinyalakan untuk tes ini
        $folder = $this->synced(['SEP.png', 'Resume Medis.png', 'Billing.png']);
        // Urutan file menurut nama: Billing, Resume Medis, SEP, lalu periksa silang
        $this->fakeAi(
            ['biaya' => [['komponen' => 'kamar', 'nilai' => 500000]], 'temuan' => []],
            ['isian' => [['field' => 'tanggal_keluar', 'nilai' => '2026-11-02']], 'diagnosis' => ['Pneumonia'],
                'temuan' => [['tingkat' => 'kritis', 'judul' => 'Resume belum ditandatangani DPJP', 'uraian' => 'Kolom tanda tangan kosong.', 'halaman' => 2]]],
            ['isian' => [['field' => 'tanggal_masuk', 'nilai' => '2026-11-04'], ['field' => 'jenis_rawat', 'nilai' => 'inap']], 'temuan' => []],
            $this->rubrik(['res_ttd_dpjp'], ['kondisi' => [['kondisi' => 'Kemungkinan diabetes (E11.9)', 'bukti' => 'Insulin pada terapi', 'dokumen' => 'Resume Medis.png', 'halaman' => 1]]]),
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
        $this->assertEqualsCanonicalizing(VerificationRubric::codes(), $folder->rubricItems()->pluck('kode')->all(), '14 butir rubrik tersimpan');
        $this->assertCount(14, VerificationRubric::codes());
        $this->assertSame('kurang', $folder->rubricItems()->where('kode', 'res_ttd_dpjp')->value('status'));
        $this->assertSame('kurang', $folder->rubricItems()->where('kode', 'aturan_eklaim')->value('status'), 'Aturan E-Klaim mengisi butir aturan');
        $this->assertSame('lengkap', $folder->rubricItems()->where('kode', 'dok_biaya')->value('status'), 'Billing.png terbaca sebagai rincian biaya');
        $this->assertSame('Kemungkinan diabetes', ConditionSuggestion::sole()->kondisi, 'Kode ICD dibuang');
        $this->assertSame(2, $folder->fresh()->rubric_kurang);
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
            ->assertSee('3 dari 3 file selesai')->assertSee('Resume belum ditandatangani DPJP')->assertSee('Belum siap · 2 butir kurang')
            ->assertSeeInOrder(['data-kepala', 'Analisa ulang dengan AI', 'data-status-folder', 'Daftar periksa', 'Kemungkinan belum tercatat di resume', 'File (3)', 'data-docs-progress', 'data-docs-list', 'data-doc='], false)
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

        // Resume diperbaiki di Enigma: hanya file itu yang berubah
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
        $this->assertSame(['dok_biaya' => 'kurang', 'dok_resume' => 'kurang', 'dok_sep' => 'lengkap'],
            $folder->rubricItems()->whereIn('kode', VerificationRubric::RULE_CODES)->where('kode', 'like', 'dok_%')->orderBy('kode')->pluck('status', 'kode')->all());
    }

    // ------------------------------------------------------------ rubrik konvergen

    private function saranOn(bool $on = true): void
    {
        AppSetting::put(\App\Http\Controllers\Admin\SettingController::KEY_CONDITION_SUGGESTIONS, $on, null);
    }

    /** Teks instruksi tahap 2 (request terakhir). */
    private function lastPrompt(): string
    {
        $d = Http::recorded()->last()[0]->data();

        return json_encode($d['messages'], JSON_UNESCAPED_UNICODE) . json_encode($d['system'] ?? '', JSON_UNESCAPED_UNICODE);
    }

    private function butirDiminta(string $prompt): array
    {
        preg_match_all('/- ([a-z_]+): /', $prompt, $m);

        return $m[1];
    }

    private const DOC = ['isian' => [], 'temuan' => []];

    private const LENGKAP = ['SEP.png', 'Resume Medis.png', 'Billing.png'];

    public function test_butir_terkunci_tidak_dikirim_ulang_ke_ai(): void
    {
        $folder = $this->synced(self::LENGKAP);
        $this->fakeAi(self::DOC, self::DOC, self::DOC, $this->rubrik(['res_ttd_dpjp']),
            ['ringkasan' => 'x', 'rubrik' => [['kode' => 'res_ttd_dpjp', 'status' => 'lengkap'], ['kode' => 'id_sep', 'status' => 'kurang'], ['kode' => 'kode_asing', 'status' => 'kurang']]]);
        $this->analyze($folder);
        $this->assertSame(VerificationRubric::aiCodes(), $this->butirDiminta($this->lastPrompt()), 'Analisa pertama: semua butir AI dinilai');

        $a = $this->analyze($folder->fresh());

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertCount(5, Http::recorded(), 'File tidak berubah tidak dibaca ulang; satu panggilan tahap 2');
        $this->assertSame(['res_ttd_dpjp'], $this->butirDiminta($this->lastPrompt()), 'Hanya butir kurang yang dikirim');
        $this->assertSame('lengkap', $folder->rubricItems()->where('kode', 'id_sep')->value('status'), 'Butir terkunci tidak diubah walau AI menjawabnya');
        $this->assertSame(0, $folder->rubricItems()->where('kode', 'kode_asing')->count(), 'Kode di luar rubrik diabaikan');
        $this->assertSame('lengkap', $folder->fresh()->state());
        $this->assertTrue($folder->fresh()->ready());
    }

    public function test_perubahan_satu_file_hanya_menilai_butir_terkait(): void
    {
        $folder = $this->synced(self::LENGKAP);
        $this->fakeAi(self::DOC, self::DOC, self::DOC, $this->rubrik(), self::DOC, $this->rubrik());
        $this->analyze($folder);

        // SEP diperbarui di Enigma
        $f = $this->ftpFolder('000001_PASIEN CONTOH_0000R0000000V000001', self::LENGKAP);
        $f['files'][0]['modified'] = 1_794_100_000;
        $this->ftp->folders = [$f];
        app(FolderSync::class)->run();
        $this->assertSame('berubah', $folder->fresh()->state());

        $a = $this->analyze($folder->fresh());

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertCount(6, Http::recorded(), 'SEP dibaca ulang + tahap 2');
        $this->assertSame(['id_nama_rm', 'id_sep', 'res_tanggal'], $this->butirDiminta($this->lastPrompt()), 'Hanya butir bersumber SEP');
    }

    public function test_semua_butir_terkunci_tidak_ada_request_ai(): void
    {
        $folder = $this->synced(self::LENGKAP);
        $this->fakeAi(self::DOC, self::DOC, self::DOC, $this->rubrik());
        $this->analyze($folder);
        $this->assertCount(4, Http::recorded());

        $a = $this->analyze($folder->fresh());

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertCount(4, Http::recorded(), 'Tidak ada request AI');
        $this->assertNull($a->cost_usd);
        $this->assertTrue($folder->fresh()->ready());
    }

    public function test_status_siap_diajukan_tidak_dipengaruhi_saran(): void
    {
        $this->saranOn();
        $folder = $this->synced(self::LENGKAP);
        $this->fakeAi(self::DOC, self::DOC, self::DOC, $this->rubrik([], ['kondisi' => [['kondisi' => 'Hipokalemia', 'bukti' => 'Kalium 2,9', 'dokumen' => 'Resume Medis.png', 'halaman' => 2]]]));
        $this->analyze($folder);

        $this->assertSame(1, ConditionSuggestion::where('status', 'baru')->count());
        $this->assertTrue($folder->fresh()->ready());
        $this->assertSame(0, $folder->fresh()->rubric_kurang);
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('Siap diajukan')->assertSee('Saran DPJP: 1 menunggu tindak lanjut')
            ->assertSee('Kemungkinan belum tercatat di resume')->assertSee('Tidak menghalangi status Siap diajukan.')
            ->assertDontSee('Analisa ulang dengan AI');
        $this->actingAs($this->koordinator)->get(route('folders.index', ['kesiapan' => 'siap']))->assertOk()->assertSee('PASIEN CONTOH');
        $this->actingAs($this->koordinator)->get(route('folders.index', ['kesiapan' => 'belum_siap']))->assertOk()->assertDontSee('PASIEN CONTOH');
    }

    public function test_terima_butir_butuh_alasan_dan_diaudit(): void
    {
        $folder = $this->synced(self::LENGKAP);
        $this->fakeAi(self::DOC, self::DOC, self::DOC, $this->rubrik(['res_ttd_dpjp']));
        $this->analyze($folder);
        $this->assertSame(1, $folder->fresh()->rubric_kurang);
        $url = route('folders.rubric.accept', [$folder, 'res_ttd_dpjp']);

        $this->actingAs($this->koordinator)->post($url, ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->assertSame('kurang', $folder->rubricItems()->where('kode', 'res_ttd_dpjp')->value('status'));

        $this->actingAs($this->koordinator)->post($url, ['alasan' => 'TTD elektronik di Enigma'])->assertSessionHas('ok');
        $item = $folder->rubricItems()->where('kode', 'res_ttd_dpjp')->sole();
        $this->assertSame(['diterima', 'TTD elektronik di Enigma', $this->koordinator->id], [$item->status, $item->accepted_reason, $item->accepted_by]);
        $this->assertTrue($folder->fresh()->ready());
        $this->assertSame(1, AuditLog::where('action', 'folder.rubrik_terima')->count());

        $this->actingAs($this->koordinator)->delete($url)->assertSessionHas('ok');
        $this->assertSame('kurang', $folder->rubricItems()->where('kode', 'res_ttd_dpjp')->value('status'));
        $this->assertFalse($folder->fresh()->ready());
        $this->assertSame(1, AuditLog::where('action', 'folder.rubrik_batal_terima')->count());

        $this->actingAs($this->koordinator)->post(route('folders.rubric.accept', [$folder, 'bukan_butir']), ['alasan' => 'abc'])->assertNotFound();
        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->actingAs($petugas)->post($url, ['alasan' => 'abc'])->assertForbidden();
    }

    public function test_saran_mati_tidak_diminta_dan_tidak_ditampilkan(): void
    {
        $this->saranOn(false);
        $folder = $this->synced(self::LENGKAP);
        $this->fakeAi(self::DOC, self::DOC, self::DOC, $this->rubrik([], ['kondisi' => [['kondisi' => 'Hipokalemia', 'bukti' => 'Kalium 2,9']]]));
        $this->analyze($folder);

        $this->assertStringNotContainsString('kondisi', $this->lastPrompt());
        $this->assertSame(0, ConditionSuggestion::count());
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()
            ->assertDontSee('Kemungkinan belum tercatat di resume')->assertDontSee('Saran DPJP:');
    }

    public function test_saran_maks_lima_dan_yang_sudah_dijawab_tidak_muncul_lagi(): void
    {
        $this->saranOn();
        $folder = $this->synced(self::LENGKAP);
        $kondisi = collect(['Hipokalemia', 'Anemia', 'Hipertensi', 'Gagal ginjal', 'Sepsis', 'Asites'])
            ->map(fn ($k) => ['kondisi' => $k, 'bukti' => "Bukti $k", 'dokumen' => 'Resume Medis.png', 'halaman' => 1])->all();
        $this->fakeAi(self::DOC, self::DOC, self::DOC, $this->rubrik([], ['kondisi' => $kondisi]), $this->rubrik([], ['kondisi' => $kondisi]));
        $this->analyze($folder);

        $this->assertStringContainsString('kondisi', $this->lastPrompt());
        $this->assertSame(['Hipokalemia', 'Anemia', 'Hipertensi', 'Gagal ginjal', 'Sepsis'], ConditionSuggestion::orderBy('id')->pluck('kondisi')->all());
        ConditionSuggestion::where('kondisi', 'Hipokalemia')->update(['status' => 'tidak_relevan']);
        ConditionSuggestion::where('kondisi', 'Anemia')->update(['status' => 'disetujui']);

        // Dokumen tidak berubah: saran tidak diminta lagi, tidak ada request
        $this->analyze($folder->fresh());
        $this->assertCount(4, Http::recorded());

        // Dipaksa meminta saran lagi (dokumen tetap sama): yang sudah dijawab tidak dibuat ulang
        $folder->fresh()->update(['suggestions_fingerprint' => null]);
        $this->analyze($folder->fresh());
        $this->assertCount(5, Http::recorded());
        $this->assertStringContainsString('JANGAN diusulkan lagi', $this->lastPrompt());
        $this->assertSame(1, ConditionSuggestion::where('kondisi', 'Hipokalemia')->count());
        $this->assertSame(1, ConditionSuggestion::where('kondisi', 'Anemia')->count());
        $this->assertLessThanOrEqual(5, ConditionSuggestion::where('status', 'baru')->count());
    }

    public function test_ubah_status_saran_diaudit_dan_divalidasi(): void
    {
        $this->saranOn();
        $folder = $this->synced(self::LENGKAP);
        $s = $folder->conditionSuggestions()->create(['kondisi' => 'Hipokalemia', 'bukti' => 'Kalium 2,9', 'dokumen' => 'Resume Medis.png', 'halaman' => 2, 'status' => 'baru']);
        $url = route('folders.suggestion.status', [$folder, $s]);

        $this->actingAs($this->koordinator)->post($url, ['status' => 'ngawur'])->assertSessionHasErrors('status');
        $this->actingAs($this->koordinator)->post($url, ['status' => 'ditanyakan'])->assertSessionHas('ok');
        $this->actingAs($this->koordinator)->post($url, ['status' => 'tidak_disetujui', 'catatan' => 'Sudah dikoreksi'])->assertSessionHas('ok');

        $s->refresh();
        $this->assertSame(['tidak_disetujui', 'Sudah dikoreksi', $this->koordinator->id], [$s->status, $s->catatan_respons, $s->updated_by]);
        $this->assertNotNull($s->responded_at);
        $logs = AuditLog::where('action', 'folder.saran_status')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertStringContainsString('Sudah ditanyakan ke DPJP → DPJP tidak setuju', $logs[1]->description);

        $lain = $this->folder('LAIN');
        $this->actingAs($this->koordinator)->post(route('folders.suggestion.status', [$lain, $s]), ['status' => 'baru'])->assertNotFound();
        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->actingAs($petugas)->post($url, ['status' => 'baru'])->assertForbidden();
    }

    public function test_teks_salin_memuat_identitas_bukti_dan_halaman(): void
    {
        $this->saranOn();
        $folder = $this->synced(self::LENGKAP);
        $s = $folder->conditionSuggestions()->create(['kondisi' => 'Hipokalemia', 'bukti' => 'Kalium 2,9 mmol/L', 'dokumen' => 'Resume Medis.png', 'halaman' => 2, 'status' => 'disetujui']);

        $teks = $s->dpjpMessage($folder);
        foreach (['PASIEN CONTOH', 'RM 000001', 'SEP 0000R0000000V000001', 'Resume Medis.png hal. 2', 'Kalium 2,9 mmol/L', 'kondisi Hipokalemia'] as $bagian) {
            $this->assertStringContainsString($bagian, $teks);
        }
        Queue::fake(); // folder belum dianalisa: jangan jalankan analisa otomatis
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('Salin pesan untuk DPJP')->assertSee($teks)
            ->assertSee('DPJP melengkapi resume di Enigma')
            ->assertDontSee('Jangan menambahkan diagnosis di Edit Koreksi Enigma tanpa tertulis di resume oleh DPJP.')
            ->assertDontSee('Saran DPJP:');
    }

    // ------------------------------------------------------------ arsip salinan terenkripsi

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_setelah_dibaca_salinan_terenkripsi_tersimpan_dan_sha_sama_dipakai_ulang(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG);
        $a = $this->analyze($folder);

        $disk = Storage::disk(config('casemix.storage_disk'));
        $docs = $a->documents;
        $this->assertTrue($docs->every(fn ($d) => $d->stored_path && $d->stored_at), 'Semua dokumen punya salinan');
        $this->assertSame("arsip/{$folder->id}/" . hash('sha256', base64_decode(self::PNG)) . '.bin', $docs[0]->stored_path);
        $this->assertCount(1, $disk->files("arsip/{$folder->id}"), 'Isi sama (sha256 sama) disimpan sekali');
        $this->assertSame($docs[0]->stored_path, $docs[1]->stored_path);
        $raw = $disk->get($docs[0]->stored_path);
        $this->assertNotSame(base64_decode(self::PNG), $raw, 'Isi di disk terenkripsi');
        $this->assertSame(base64_decode(self::PNG), \Illuminate\Support\Facades\Crypt::decrypt($raw));
        $this->assertSame([], $disk->files('tmp', true), 'File sementara dibersihkan');
    }

    public function test_pratinjau_dari_salinan_saat_file_tidak_ada_lagi_di_ftp(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG);
        $this->analyze($folder);
        $this->ftp->gone = true;
        $folder->update(['missing_at' => now()]);
        $unduhan = count($this->ftp->downloads);

        $res = $this->actingAs($this->koordinator)->get(route('folders.file', [$folder, 1]))->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame(base64_decode(self::PNG), $res->getContent());
        $this->assertSame($unduhan, count($this->ftp->downloads), 'Tidak mengambil dari FTP');
        $this->assertSame(1, AuditLog::where('action', 'folder.buka_dokumen')->count());

        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()
            ->assertSee('Dokumen dibuka dari salinan arsip CERMAT')->assertSee('tersimpan');
    }

    public function test_pratinjau_dari_ftp_menyimpan_salinan_dan_dokumen_dipakai_ulang_mewarisinya(): void
    {
        $folder = $this->synced();
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Selesai, 'requested_by' => $this->koordinator->id]);
        app(FolderAnalyzer::class)->prepare($a);
        $a->documents()->update(['status' => ReviewStatus::Selesai->value, 'finished_at' => now()]);
        $a->update(['finished_at' => now()]);

        $this->actingAs($this->koordinator)->get(route('folders.file', [$folder, 1]))->assertOk();
        $sep = $a->documents()->where('name', 'SEP.png')->first();
        $this->assertNotNull($sep->stored_path, 'Dibuka dari FTP lalu disimpan');

        $b = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre, 'requested_by' => $this->koordinator->id]);
        app(FolderAnalyzer::class)->prepare($b);
        $folder->update(['last_analysis_id' => $b->id]);
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk();
        $baru = $b->documents()->where('name', 'SEP.png')->first();
        $this->assertTrue($baru->reused);
        $this->assertSame($sep->stored_path, $baru->stored_path, 'Dokumen dipakai ulang mewarisi salinan');
    }

    public function test_prune_menghapus_salinan_lama_tanpa_menghapus_hasil(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [['field' => 'tanggal_masuk', 'nilai' => '2026-11-04']], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG);
        $a = $this->analyze($folder);
        $path = $a->documents[0]->stored_path;
        $a->documents()->update(['stored_at' => now()->subMonths(25)]);

        $this->artisan('casemix:archive-prune')->assertSuccessful();

        $this->assertFalse(Storage::disk(config('casemix.storage_disk'))->exists($path));
        $docs = $a->fresh()->documents;
        $this->assertTrue($docs->every(fn ($d) => $d->stored_path === null && $d->status === ReviewStatus::Selesai && $d->summary !== null), 'Hasil verifikasi tetap');
        $log = AuditLog::where('action', 'arsip.hapus_kedaluwarsa')->sole();
        $this->assertSame(1, $log->properties['files']);
        $this->assertSame(2, $log->properties['documents']);
    }

    public function test_prune_tidak_menghapus_salinan_yang_masih_baru(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG);
        $a = $this->analyze($folder);

        $this->artisan('casemix:archive-prune')->assertSuccessful();

        $this->assertTrue($a->fresh()->documents->every(fn ($d) => $d->stored_path !== null));
    }

    public function test_backfill_menyimpan_salinan_tanpa_memanggil_ai(): void
    {
        Http::fake();
        $folder = $this->synced();
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Selesai, 'requested_by' => $this->koordinator->id]);
        app(FolderAnalyzer::class)->prepare($a);

        $this->artisan('casemix:archive-backfill', ['--limit' => 50])->expectsOutputToContain('Tersimpan: 2 dokumen')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertTrue($a->documents()->get()->every(fn ($d) => $d->stored_path !== null));
    }

    public function test_disk_lokal_membuat_folder_dengan_izin_grup(): void
    {
        $cfg = config('filesystems.disks.' . config('casemix.storage_disk'));
        $this->assertSame(0770, $cfg['permissions']['dir']['private']);
        $this->assertSame(0660, $cfg['permissions']['file']['private']);

        $root = sys_get_temp_dir() . '/casemix-izin-' . \Illuminate\Support\Str::ulid();
        $disk = Storage::build(array_merge($cfg, ['root' => $root]));
        $old = umask(0);
        try {
            $disk->put('arsip/1/x.bin', 'isi');
            $perm = fileperms("$root/arsip/1") & 0777;
        } finally {
            umask($old);
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0770, $perm, 'Folder baru: rwx untuk pemilik dan grup');
        }
    }

    public function test_pratinjau_tetap_200_bila_salinan_gagal_disimpan(): void
    {
        $folder = $this->synced();
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre, 'requested_by' => $this->koordinator->id]);
        app(FolderAnalyzer::class)->prepare($a);
        Storage::disk(config('casemix.storage_disk'))->put('arsip', 'bukan folder'); // folder arsip tidak bisa dibuat
        \Illuminate\Support\Facades\Log::spy();

        $res = $this->actingAs($this->koordinator)->get(route('folders.file', [$folder, 1]))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame(base64_decode(self::PNG), $res->getContent());
        $this->assertNull($a->documents()->where('name', 'SEP.png')->value('stored_path'));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
    }

    public function test_satu_dokumen_dibaca_ai_satu_unduhan_ftp(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG);
        $a = $this->analyze($folder);

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertCount(2, $this->ftp->downloads, 'Dua dokumen = dua unduhan');
        $this->assertTrue($a->documents->every(fn ($d) => $d->stored_path !== null));
    }

    public function test_proses_ulang_folder_hilang_dari_ftp_memakai_arsip(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG, $doc, self::SILANG);
        $a = $this->analyze($folder);
        $sep = $a->documents->firstWhere('name', 'SEP.png');
        $this->ftp->gone = true;
        $folder->update(['missing_at' => now()]);
        $unduhan = count($this->ftp->downloads);

        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()->assertSee('Proses ulang file ini');
        $this->actingAs($this->koordinator)->post(route('folders.process_document', [$folder, $sep]))->assertSessionHas('ok');

        $this->assertSame(ReviewStatus::Selesai, $sep->fresh()->status, (string) $sep->fresh()->error);
        $this->assertSame($unduhan, count($this->ftp->downloads), 'Tidak mengunduh dari FTP');
    }

    public function test_proses_ulang_tanpa_ftp_dan_tanpa_arsip_gagal_dengan_pesan_jelas(): void
    {
        $folder = $this->synced();
        $doc = ['isian' => [], 'temuan' => []];
        $this->fakeAi($doc, $doc, self::SILANG, $doc, self::SILANG);
        $a = $this->analyze($folder);
        $sep = $a->documents->firstWhere('name', 'SEP.png');
        \App\Models\FolderDocument::query()->update(['stored_path' => null, 'stored_sha256' => null]);
        $this->ftp->gone = true;

        // FTP hilang (file tidak ditemukan) dan tidak ada arsip: job gagal dengan pesan jelas
        $this->actingAs($this->koordinator)->post(route('folders.process_document', [$folder, $sep]))->assertSessionHas('ok');
        $this->assertSame(ReviewStatus::Gagal, $sep->fresh()->status);
        $this->assertSame('File sudah tidak ada di FTP dan belum diarsipkan.', $sep->fresh()->error);

        // Folder ditandai hilang: tombol nonaktif dan permintaan ditolak
        $sep->update(['status' => ReviewStatus::Selesai, 'error' => null]);
        $a->fresh()->update(['status' => ReviewStatus::Selesai]);
        $folder->update(['missing_at' => now()]);
        $this->actingAs($this->koordinator)->get(route('folders.show', $folder))->assertOk()->assertSee('belum diarsipkan');
        $this->actingAs($this->koordinator)->post(route('folders.process_document', [$folder, $sep]))
            ->assertSessionHas('warn', 'File sudah tidak ada di FTP dan belum diarsipkan.');
    }
}
