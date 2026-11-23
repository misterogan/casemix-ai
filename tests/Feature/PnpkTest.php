<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\EkstrakPnpk;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Jobs\UsulkanButirPnpk;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\CodeSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Models\IcdCode;
use App\Models\PnpkButir;
use App\Models\PnpkDokumen;
use App\Models\PnpkHalaman;
use App\Models\PnpkIcd;
use App\Models\User;
use App\Services\Pnpk\PnpkAcuan;
use App\Services\Pnpk\PnpkTextExtractor;
use App\Services\Pnpk\PnpkVerifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** PNPK: PDF resmi = sumber kebenaran; AI mengusulkan; sistem memverifikasi kutipan; Koordinator menyetujui. */
class PnpkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $koor;

    private const HAL1 = "BAB III TATA LAKSANA\nPasien pneumonia komunitas dengan CURB-65 skor 2 atau lebih\nperlu dirawat inap di rumah sakit.";

    private const HAL2 = "Kriteria pulang: pasien stabil secara klinis selama 24 jam, bebas demam, dan dapat minum obat oral.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
        IcdCode::create(['system' => 'icd10', 'code' => 'J18.9', 'title' => 'Pneumonia, unspecified']);
        IcdCode::create(['system' => 'icd10', 'code' => 'J18.0', 'title' => 'Bronchopneumonia, unspecified']);
    }

    private function fakeAi(array $json): void
    {
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
        ])]);
    }

    private function dokumen(array $over = [], array $pages = [1 => self::HAL1, 2 => self::HAL2]): PnpkDokumen
    {
        $d = PnpkDokumen::create($over + ['judul' => 'PNPK Pneumonia', 'nomor_kmk' => 'HK.01.07/MENKES/1/2023', 'tahun' => 2023,
            'tanggal_berlaku' => '2023-01-01', 'status' => 'berlaku', 'path' => 'pnpk/x.pdf', 'sha256' => hash('sha256', uniqid('', true)),
            'status_proses' => 'usulan']);
        foreach ($pages as $n => $t) {
            PnpkHalaman::create(['dokumen_id' => $d->id, 'halaman' => $n, 'teks' => $t]);
        }

        return $d;
    }

    private function setujui(PnpkDokumen $d, string $kode = 'J18', string $ringkasan = 'Rawat inap bila CURB-65 ≥ 2'): PnpkButir
    {
        PnpkIcd::create(['dokumen_id' => $d->id, 'kode' => $kode, 'sumber' => 'manual', 'status' => 'disetujui']);

        return PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'indikasi_rawat_inap', 'ringkasan' => $ringkasan,
            'kutipan' => 'skor 2 atau lebih perlu dirawat inap', 'halaman' => 1, 'cocok_kutipan' => true, 'status' => 'disetujui']);
    }

    private function folder(?string $tglPulang = null, string $kode = 'J18.9'): FtpFolder
    {
        $f = FtpFolder::create(['path' => '/ftp/' . uniqid('', true), 'name' => '000001_PASIEN_SEP1', 'nama_pasien' => 'PASIEN', 'tgl_pulang' => $tglPulang,
            'files' => [['path' => '/ftp/x/RESUME.pdf', 'name' => 'RESUME.pdf', 'size' => 1, 'modified' => 1]], 'fingerprint' => 'f']);
        $a = FolderAnalysis::create(['ftp_folder_id' => $f->id, 'status' => ReviewStatus::Selesai, 'fingerprint' => 'f', 'started_at' => now(), 'finished_at' => now()]);
        CodeSuggestion::create(['folder_analysis_id' => $a->id, 'system' => 'icd10', 'peran' => 'utama', 'code' => $kode, 'title' => 'x',
            'alasan' => 'x', 'sumber' => 'resume', 'bukti' => 'x', 'halaman' => 1, 'keyakinan' => 'kuat']);

        return $f;
    }

    public function test_ekstrak_per_halaman_dengan_ocr_untuk_halaman_pendek(): void
    {
        $d = $this->dokumen([], []);
        $this->app->instance(PnpkTextExtractor::class, new class(app(\App\Services\Ai\AiManager::class), app(\App\Services\Ai\AiBudget::class)) extends PnpkTextExtractor {
            public function textPages(string $path): array { return [PnpkTest::teks(1), '  ', PnpkTest::teks(3)]; }

            public function ocr(string $path, int $page): string { return "Hasil OCR halaman $page yang cukup panjang untuk disimpan sebagai teks."; }
        });
        Queue::fake();

        (new EkstrakPnpk($d->id))->handle(app(PnpkTextExtractor::class), app(\App\Services\Ai\AiBudget::class));

        $this->assertSame([1, 2, 3], PnpkHalaman::where('dokumen_id', $d->id)->orderBy('halaman')->pluck('halaman')->all());
        $this->assertStringStartsWith('Hasil OCR halaman 2', PnpkHalaman::where('halaman', 2)->value('teks'));
        $this->assertSame(3, $d->fresh()->jumlah_halaman);
        Queue::assertPushed(UsulkanButirPnpk::class);
    }

    public static function teks(int $n): string
    {
        return "Halaman $n berisi teks PNPK yang lebih dari lima puluh karakter panjangnya.";
    }

    public function test_usulan_diverifikasi_tanpa_ai(): void
    {
        $d = $this->dokumen();
        $this->fakeAi(['icd' => ['J18', 'Z99.9', 'bukan'], 'butir' => [
            ['jenis' => 'indikasi_rawat_inap', 'ringkasan' => 'Rawat inap bila CURB-65 ≥ 2', 'kutipan' => 'CURB-65 skor 2 atau lebih, perlu DIRAWAT inap', 'halaman' => 1, 'level_bukti' => null],
            ['jenis' => 'kriteria_pulang', 'ringkasan' => 'Stabil 24 jam', 'kutipan' => 'pasien stabil secara klinis selama 24 jam', 'halaman' => 1],
            ['jenis' => 'tata_laksana', 'ringkasan' => 'Antibiotik', 'kutipan' => 'berikan levofloksasin 750 mg selama lima hari', 'halaman' => 1],
            ['jenis' => 'ngawur', 'ringkasan' => 'x', 'kutipan' => 'x', 'halaman' => 1],
            ['jenis' => 'komplikasi', 'ringkasan' => 'x', 'kutipan' => 'pneumonia', 'halaman' => 99],
        ]]);

        UsulkanButirPnpk::dispatchSync($d->id);

        $this->assertSame('siap_tinjau', $d->fresh()->status_proses, (string) $d->fresh()->error);
        $this->assertSame(3, PnpkButir::count(), 'item tidak valid skema dibuang');
        $ok = PnpkButir::where('jenis', 'indikasi_rawat_inap')->first();
        $this->assertTrue($ok->cocok_kutipan);
        $this->assertSame('usulan', $ok->status);
        $this->assertTrue(PnpkButir::where('jenis', 'kriteria_pulang')->first()->cocok_kutipan, '±1 halaman');
        $karangan = PnpkButir::where('jenis', 'tata_laksana')->first();
        $this->assertFalse($karangan->cocok_kutipan);
        $this->assertSame('ditolak', $karangan->status);
        $this->assertSame(PnpkVerifier::TOLAK_KUTIPAN, $karangan->alasan_tolak);
        $this->assertSame('usulan', PnpkIcd::where('kode', 'J18')->value('status'));
        $this->assertSame('ditolak', PnpkIcd::where('kode', 'Z99.9')->value('status'));
        $this->assertSame(2, PnpkIcd::count());
        // Biaya usulan dicatat AiBudget pada baris dokumen PNPK
        $d->refresh();
        $this->assertSame([10, 10, 'claude-sonnet-5-5'], [$d->tokens_in, $d->tokens_out, $d->model]);
        $this->assertNotNull($d->finished_at);
        $this->assertArrayHasKey('PNPK (OCR & usulan butir)', app(\App\Services\Ai\AiBudget::class)->report()['per_fitur']);
    }

    public function test_hanya_butir_disetujui_tampil_di_panel_folder(): void
    {
        $d = $this->dokumen();
        $this->setujui($d);
        PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'tata_laksana', 'ringkasan' => 'Usulan belum disetujui', 'kutipan' => 'x',
            'halaman' => 1, 'cocok_kutipan' => true, 'status' => 'usulan']);
        $f = $this->folder();

        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()
            ->assertSee('Acuan PNPK')->assertSee('Rawat inap bila CURB-65')->assertDontSee('Usulan belum disetujui')
            ->assertSee(route('acuan.pnpk.pdf', $d) . '#page=1', false);
        $this->actingAs($this->admin)->get(route('folders.show', $f))->assertForbidden();

        $tanpa = $this->folder(null, 'I10');
        $this->assertTrue(app(PnpkAcuan::class)->forFolder($tanpa)->isEmpty());
    }

    public function test_migrasi_menandai_yang_sudah_disetujui_diperiksa_manusia(): void
    {
        $rename = require database_path('migrations/2026_11_18_000300_rename_pnpk_to_regulasi.php');
        $m = require database_path('migrations/2026_11_18_000110_add_pnpk_diperiksa_manusia.php');
        $d = $this->dokumen();
        $rename->down();
        $m->down();
        $db = \Illuminate\Support\Facades\DB::table(...);
        $db('pnpk_icd')->insert(['dokumen_id' => $d->id, 'kode' => 'J18', 'sumber' => 'manual', 'status' => 'disetujui']);
        $bid = $db('pnpk_butir')->insertGetId(['dokumen_id' => $d->id, 'jenis' => 'indikasi_rawat_inap', 'ringkasan' => 'R', 'kutipan' => 'x',
            'halaman' => 1, 'cocok_kutipan' => true, 'status' => 'disetujui']);
        $uid = $db('pnpk_butir')->insertGetId(['dokumen_id' => $d->id, 'jenis' => 'tata_laksana', 'ringkasan' => 'U', 'kutipan' => 'x', 'halaman' => 1,
            'cocok_kutipan' => true, 'status' => 'usulan']);
        $m->up();
        $rename->up();
        [$b, $u] = [PnpkButir::find($bid), PnpkButir::find($uid)];

        $this->assertTrue($b->fresh()->diperiksa_manusia);
        $this->assertFalse($u->fresh()->diperiksa_manusia);
        $this->assertTrue(PnpkIcd::first()->diperiksa_manusia);
    }

    public function test_persetujuan_otomatis_berlabel_dan_konfirmasi(): void
    {
        $d = $this->dokumen(['status_proses' => 'siap_tinjau']);
        $auto = $this->setujui($d);
        $icd = PnpkIcd::first();
        $usulan = PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'tata_laksana', 'ringkasan' => 'T', 'kutipan' => 'perlu dirawat inap',
            'halaman' => 1, 'cocok_kutipan' => true, 'status' => 'usulan']);
        $this->assertFalse($auto->fresh()->diperiksa_manusia);
        $f = $this->folder();

        // Butir otomatis tetap dipakai, berlabel kuning
        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()
            ->assertSee('Rawat inap bila CURB-65')->assertSee('Belum diperiksa koder/dokter');
        $this->actingAs($this->koor)->get(route('acuan.pnpk.index'))->assertSee('1 belum diperiksa');
        $this->actingAs($this->koor)->get(route('acuan.pnpk.show', $d))->assertSee('data-otomatis', false)
            ->assertSee('Konfirmasi semua di jenis ini');
        $this->assertSame([], PnpkButir::diperiksaManusia()->pluck('id')->all(), 'draf sanggahan mengabaikan butir otomatis');

        // Setujui di UI → diperiksa manusia
        $this->actingAs($this->koor)->post(route('acuan.pnpk.butir', [$d, $usulan]), ['aksi' => 'setujui'])->assertRedirect();
        $this->assertTrue($usulan->fresh()->diperiksa_manusia);
        $this->assertNotNull($usulan->fresh()->ditinjau_oleh);

        // Konfirmasi: isi tetap, label hilang
        $this->actingAs($this->koor)->post(route('acuan.pnpk.butir', [$d, $auto]), ['aksi' => 'konfirmasi'])->assertRedirect();
        $this->actingAs($this->koor)->post(route('acuan.pnpk.icd', [$d, $icd]), ['aksi' => 'konfirmasi'])->assertRedirect();
        $b = $auto->fresh();
        $this->assertSame([true, 'disetujui', 'Rawat inap bila CURB-65 ≥ 2', $this->koor->id], [$b->diperiksa_manusia, $b->status, $b->ringkasan, $b->ditinjau_oleh]);
        $this->assertTrue($icd->fresh()->diperiksa_manusia);
        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()->assertDontSee('Belum diperiksa koder/dokter');
        $this->assertCount(2, PnpkButir::diperiksaManusia()->get());
        $this->assertSame(1, AuditLog::where('action', 'pnpk.butir_konfirmasi')->count());
        $this->assertSame(1, AuditLog::where('action', 'pnpk.icd_konfirmasi')->count());

        // Konfirmasi massal per jenis
        $lain = PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'tata_laksana', 'ringkasan' => 'L', 'kutipan' => 'x', 'halaman' => 1,
            'cocok_kutipan' => true, 'status' => 'disetujui']);
        $this->actingAs($this->koor)->post(route('acuan.pnpk.butir.massal', $d), ['jenis' => 'tata_laksana', 'aksi' => 'konfirmasi'])->assertRedirect();
        $this->assertTrue($lain->fresh()->diperiksa_manusia);
        $this->assertSame(1, AuditLog::where('action', 'pnpk.butir_konfirmasi_massal')->count());
    }

    public function test_versi_diganti_dipakai_sesuai_tanggal_pulang(): void
    {
        $baru = $this->dokumen(['judul' => 'PNPK Pneumonia 2025', 'tanggal_berlaku' => '2025-01-01']);
        $lama = $this->dokumen(['judul' => 'PNPK Pneumonia 2023', 'status' => 'diganti', 'diganti_oleh_id' => $baru->id]);
        $this->setujui($lama, 'J18', 'Versi lama');
        $this->setujui($baru, 'J18', 'Versi baru');
        $acuan = app(PnpkAcuan::class);

        $this->assertSame(['PNPK Pneumonia 2023'], $acuan->forFolder($this->folder('2024-06-01'))->map(fn ($x) => $x['dokumen']->judul)->all());
        $this->assertSame(['PNPK Pneumonia 2025'], $acuan->forFolder($this->folder('2025-03-01'))->map(fn ($x) => $x['dokumen']->judul)->all());
        $this->assertSame(['PNPK Pneumonia 2025'], $acuan->forFolder($this->folder(null))->map(fn ($x) => $x['dokumen']->judul)->all());
    }

    public function test_rujukan_id_asing_dibuang_di_pipeline(): void
    {
        $b = $this->setujui($this->dokumen());
        $f = $this->folder();
        $a = FolderAnalysis::create(['ftp_folder_id' => $f->id, 'status' => ReviewStatus::Berjalan, 'fingerprint' => 'f']);
        FolderDocument::create(['folder_analysis_id' => $a->id, 'ftp_folder_id' => $f->id, 'file_index' => 0, 'name' => 'RESUME.pdf',
            'file_fingerprint' => '1|1', 'doc_type' => 'resume_medis', 'status' => ReviewStatus::Selesai, 'summary' => ['isian' => []]]);
        $this->fakeAi(['ringkasan' => 'ok', 'rubrik' => [], 'pnpk' => [['id' => $b->id, 'catatan' => 'CURB-65 tidak tertulis'], ['id' => 999999, 'catatan' => 'karangan']]]);

        SynthesizeFolderAnalysis::dispatchSync($a->id, true);

        $a->refresh();
        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $pnpk = collect($a->findings)->where('sumber', 'pnpk')->values();
        $this->assertSame([$b->id], $pnpk->pluck('pnpk_butir_id')->all());
        $this->assertStringContainsString('"id":' . $b->id, Http::recorded()[0][0]->data()['messages'][0]['content'][0]['text']);
    }

    public function test_unggah_duplikat_sha256_ditolak_dan_ganti_versi(): void
    {
        Queue::fake();
        $pdf = fn () => UploadedFile::fake()->createWithContent('pnpk.pdf', "%PDF-1.4\nisi uji\n%%EOF");
        $data = ['judul' => 'PNPK Pneumonia', 'nomor_kmk' => 'KMK 1', 'tahun' => 2023, 'tanggal_berlaku' => '2023-01-01'];

        $this->actingAs($this->admin)->post(route('acuan.pnpk.store'), $data + ['pdf' => $pdf()])->assertRedirect();
        $lama = PnpkDokumen::firstOrFail();
        Storage::disk('local')->assertExists($lama->path);
        Queue::assertPushed(EkstrakPnpk::class);

        $this->actingAs($this->koor)->post(route('acuan.pnpk.store'), $data + ['pdf' => $pdf()])->assertSessionHasErrors('pdf');
        $this->actingAs($this->koor)->post(route('acuan.pnpk.store'), $data + ['pdf' => UploadedFile::fake()->createWithContent('x.pdf', 'bukan pdf')])->assertSessionHasErrors('pdf');
        $this->assertSame(1, PnpkDokumen::count());

        $this->actingAs($this->koor)->post(route('acuan.pnpk.store'), ['judul' => 'PNPK Pneumonia 2025', 'tanggal_berlaku' => '2025-01-01', 'tahun' => 2025,
            'nomor_kmk' => 'KMK 2', 'menggantikan_id' => $lama->id, 'pdf' => UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.7\nversi baru")])->assertRedirect();
        $lama->refresh();
        $this->assertSame('diganti', $lama->status);
        $this->assertSame(PnpkDokumen::where('judul', 'PNPK Pneumonia 2025')->value('id'), $lama->diganti_oleh_id);
        $this->assertEqualsCanonicalizing(['pnpk.unggah', 'pnpk.ganti_versi'], AuditLog::where('action', 'like', 'pnpk.%')->distinct()->pluck('action')->all());
    }

    public function test_hak_akses_admin_dan_koordinator(): void
    {
        $d = $this->dokumen(['status_proses' => 'siap_tinjau']);
        $b = PnpkButir::create(['dokumen_id' => $d->id, 'jenis' => 'tata_laksana', 'ringkasan' => 'R', 'kutipan' => 'perlu dirawat inap',
            'halaman' => 1, 'cocok_kutipan' => true, 'status' => 'usulan']);
        $icd = PnpkIcd::create(['dokumen_id' => $d->id, 'kode' => 'J18', 'sumber' => 'ai', 'status' => 'usulan']);
        $meta = ['judul' => 'PNPK Pneumonia (revisi)', 'nomor_kmk' => 'KMK 9', 'tahun' => 2023, 'tanggal_berlaku' => '2023-01-01', 'status' => 'berlaku'];

        // Admin: daftar, lihat (hanya-baca), ubah metadata; tidak bisa meninjau
        $this->actingAs($this->admin)->get(route('acuan.pnpk.index', ['q' => 'J18']))->assertOk();
        $this->actingAs($this->admin)->get(route('acuan.pnpk.show', $d))->assertOk()->assertSee('Hanya-baca')->assertDontSee('Selesai ditinjau');
        $this->actingAs($this->admin)->put(route('acuan.pnpk.update', $d), $meta)->assertRedirect();
        $this->assertSame('PNPK Pneumonia (revisi)', $d->fresh()->judul);
        $this->actingAs($this->admin)->post(route('acuan.pnpk.butir', [$d, $b]), ['aksi' => 'setujui'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('acuan.pnpk.icd', [$d, $icd]), ['aksi' => 'setujui'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('acuan.pnpk.selesai', $d))->assertForbidden();

        // Koordinator: semuanya
        $this->actingAs($this->koor)->get(route('acuan.pnpk.index', ['isi' => 'dirawat']))->assertOk()->assertSee('<mark>dirawat</mark>', false);
        $this->actingAs($this->koor)->post(route('acuan.pnpk.butir', [$d, $b]), ['aksi' => 'ringkasan', 'ringkasan' => 'Ringkasan baru'])->assertRedirect();
        $this->actingAs($this->koor)->post(route('acuan.pnpk.butir', [$d, $b]), ['aksi' => 'setujui'])->assertRedirect();
        $this->assertSame(['disetujui', 'Ringkasan baru'], [$b->fresh()->status, $b->fresh()->ringkasan]);
        $this->actingAs($this->koor)->post(route('acuan.pnpk.icd', [$d, $icd]), ['aksi' => 'setujui'])->assertRedirect();
        $this->actingAs($this->koor)->post(route('acuan.pnpk.icd.tambah', $d), ['kode' => 'Z99'])->assertSessionHasErrors('kode');
        $this->actingAs($this->koor)->post(route('acuan.pnpk.icd.tambah', $d), ['kode' => 'j18.0'])->assertRedirect();
        $this->assertSame(['J18', 'J18.0'], PnpkIcd::where('status', 'disetujui')->orderBy('kode')->pluck('kode')->all());
        $this->actingAs($this->koor)->post(route('acuan.pnpk.butir', [$d, $b]), ['aksi' => 'tolak'])->assertSessionHasErrors('alasan');
        $this->actingAs($this->koor)->post(route('acuan.pnpk.selesai', $d))->assertRedirect();
        $this->assertSame('selesai', $d->fresh()->status_proses);
        $this->actingAs($this->koor)->put(route('acuan.pnpk.update', $d), $meta + ['judul' => 'X'])->assertRedirect();
        $this->actingAs($this->koor)->delete(route('acuan.pnpk.destroy', $d))->assertRedirect(route('acuan.pnpk.index'));
        $this->assertSame(0, PnpkDokumen::count());

        $this->assertEqualsCanonicalizing(['pnpk.ubah', 'pnpk.butir_ringkasan', 'pnpk.butir_setujui', 'pnpk.icd_setujui', 'pnpk.selesai_tinjau', 'pnpk.hapus'],
            AuditLog::where('action', 'like', 'pnpk.%')->distinct()->pluck('action')->all());

        // Petugas tidak punya akses
        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->actingAs($petugas)->get(route('acuan.pnpk.index'))->assertForbidden();
    }

    public function test_koordinator_melihat_tautan_acuan_regulasi_di_sidebar(): void
    {
        $this->actingAs($this->koor)->get(route('control.index'))->assertOk()
            ->assertSee('href="' . route('acuan.pnpk.index') . '"', false)->assertSee('Acuan regulasi');
        $this->actingAs($this->koor)->get('/acuan/pnpk')->assertOk()->assertSee('PNPK');
    }
}
