<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\CodeSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\IcdCode;
use App\Models\PnpkButir;
use App\Models\PnpkDokumen;
use App\Models\RegulasiButir;
use App\Models\RegulasiDokumen;
use App\Models\RegulasiIcd;
use App\Models\User;
use App\Models\VclaimKlaim;
use App\Services\Pnpk\PnpkAcuan;
use App\Services\Pnpk\PnpkTextExtractor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Acuan regulasi umum: PNPK + Permenkes + BA kesepakatan, panel Dasar koding / Dasar sanggahan, draf sanggahan. */
class RegulasiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
        foreach (['E11.9', 'E11.6', 'J18.9', 'I10'] as $k) {
            IcdCode::create(['system' => 'icd10', 'code' => $k, 'title' => "Judul $k"]);
        }
    }

    private function dok(string $jenis, string $nomor, array $over = []): RegulasiDokumen
    {
        return RegulasiDokumen::create($over + ['judul' => "Dokumen $nomor", 'jenis' => $jenis, 'nomor' => $nomor, 'tahun' => 2023,
            'tanggal_berlaku' => '2023-01-01', 'status' => 'berlaku', 'path' => 'pnpk/x.pdf', 'sha256' => hash('sha256', uniqid('', true)),
            'status_proses' => 'selesai', 'cakupan' => RegulasiDokumen::CAKUPAN_BAWAAN[$jenis] ?? ['umum']]);
    }

    private function butir(RegulasiDokumen $d, string $jenis, string $ringkasan, array $over = []): RegulasiButir
    {
        return RegulasiButir::create($over + ['dokumen_id' => $d->id, 'jenis' => $jenis, 'ringkasan' => $ringkasan, 'kutipan' => "kutipan $ringkasan",
            'halaman' => 2, 'cocok_kutipan' => true, 'status' => 'disetujui', 'diperiksa_manusia' => true]);
    }

    private function folder(array $kode = ['utama' => 'E11.9'], array $over = []): FtpFolder
    {
        $f = FtpFolder::create($over + ['path' => '/ftp/' . uniqid('', true), 'name' => '000001_PASIEN_SEP1', 'nama_pasien' => 'PASIEN',
            'files' => [['path' => '/ftp/x/RESUME.pdf', 'name' => 'RESUME.pdf', 'size' => 1, 'modified' => 1]], 'fingerprint' => 'f']);
        $a = FolderAnalysis::create(['ftp_folder_id' => $f->id, 'status' => ReviewStatus::Selesai, 'fingerprint' => 'f', 'started_at' => now(), 'finished_at' => now()]);
        foreach ($kode as $peran => $k) {
            CodeSuggestion::create(['folder_analysis_id' => $a->id, 'system' => $peran === 'tindakan' ? 'icd9cm' : 'icd10', 'peran' => $peran, 'code' => $k,
                'title' => "Judul $k", 'alasan' => 'x', 'sumber' => 'resume', 'bukti' => 'x', 'halaman' => 1, 'keyakinan' => 'kuat']);
        }

        return $f;
    }

    private function fakeAi(array $json): void
    {
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
        ])]);
    }

    public function test_rename_tabel_tidak_merusak_data_pnpk_lama(): void
    {
        $rename = require database_path('migrations/2026_11_18_000300_rename_pnpk_to_regulasi.php');
        $rename->down();
        $id = DB::table('pnpk_dokumen')->insertGetId(['judul' => 'PNPK Pneumonia', 'nomor_kmk' => 'HK.01.07/MENKES/1/2023', 'tahun' => 2023,
            'tanggal_berlaku' => '2023-01-01', 'status' => 'berlaku', 'path' => 'p', 'sha256' => str_repeat('a', 64), 'status_proses' => 'selesai']);
        DB::table('pnpk_halaman')->insert(['dokumen_id' => $id, 'halaman' => 1, 'teks' => 'teks']);
        DB::table('pnpk_icd')->insert(['dokumen_id' => $id, 'kode' => 'J18', 'sumber' => 'manual', 'status' => 'disetujui', 'diperiksa_manusia' => true]);
        $b1 = DB::table('pnpk_butir')->insertGetId(['dokumen_id' => $id, 'jenis' => 'tata_laksana', 'ringkasan' => 'R', 'kutipan' => 'k', 'halaman' => 3,
            'halaman_pdf' => 5, 'cocok_kutipan' => true, 'status' => 'disetujui', 'diperiksa_manusia' => true]);
        $b2 = DB::table('pnpk_butir')->insertGetId(['dokumen_id' => $id, 'jenis' => 'tata_laksana', 'ringkasan' => 'O', 'kutipan' => 'k', 'halaman' => 3,
            'cocok_kutipan' => true, 'status' => 'disetujui', 'diperiksa_manusia' => false]);
        $rename->up();

        $d = PnpkDokumen::findOrFail($id);
        $this->assertSame(['kmk_pnpk', 'HK.01.07/MENKES/1/2023', ['medis'], 'berlaku'], [$d->jenis, $d->nomor, $d->cakupan, $d->status]);
        $this->assertSame([true, 5, 'disetujui'], [RegulasiButir::find($b1)->diperiksa_manusia, RegulasiButir::find($b1)->halaman_pdf, RegulasiButir::find($b1)->status]);
        $this->assertFalse(PnpkButir::find($b2)->diperiksa_manusia);
        $this->assertTrue(RegulasiIcd::sole()->diperiksa_manusia);
        $this->assertSame(1, $d->halaman()->count());
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('pnpk_butir'));
    }

    private function impor(string $jenis, string $nama, array $pages): RegulasiDokumen
    {
        Queue::fake();
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'regulasi-' . uniqid();
        File::ensureDirectoryExists($dir);
        file_put_contents("$dir/$nama", "%PDF-1.4\nisi\n%%EOF");
        $this->app->instance(PnpkTextExtractor::class, new class($pages) extends PnpkTextExtractor {
            public function __construct(private array $p) {}

            public function textPages(string $path): array
            {
                return $this->p;
            }
        });
        try {
            $this->artisan('casemix:regulasi-import', ['path' => $dir, '--jenis' => $jenis])->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }

        return RegulasiDokumen::latest('id')->firstOrFail();
    }

    public function test_impor_permenkes_membaca_tanggal_diundangkan(): void
    {
        $d = $this->impor('permenkes', 'permenkes-26-2021-ina-cbg.pdf', [
            1 => "PERATURAN MENTERI KESEHATAN REPUBLIK INDONESIA\nNOMOR 26 TAHUN 2021\nTENTANG PEDOMAN INDONESIAN CASE BASE GROUPS",
            2 => "Peraturan Menteri ini mulai berlaku pada tanggal diundangkan.\nDitetapkan di Jakarta\npada tanggal 2 Juli 2021",
            3 => "Diundangkan di Jakarta\npada tanggal 12 Juli 2021",
        ]);

        $this->assertSame(['permenkes', 'Permenkes 26/2021', 2021, '2021-07-12', false, ['koding']],
            [$d->jenis, $d->nomor, $d->tahun, $d->tanggal_berlaku->format('Y-m-d'), $d->perlu_dilengkapi, $d->cakupan]);
        $this->assertNull($d->nomor_kmk);
        $this->assertSame('permenkes', AuditLog::where('action', 'pnpk.impor')->sole()->properties['jenis']);
    }

    public function test_impor_ba_kesepakatan_nama_bebas_perlu_dilengkapi(): void
    {
        $d = $this->impor('ba_kesepakatan', 'BA kesepakatan koding 2023.pdf', [1 => 'BERITA ACARA', 2 => 'Kasus 1 ...']);

        $this->assertSame(['ba_kesepakatan', 'BA kesepakatan koding 2023', 2023, true], [$d->jenis, $d->judul, $d->tahun, $d->perlu_dilengkapi]);
        $this->assertNull($d->nomor);
        $this->assertSame(['koding', 'verifikasi_klaim'], $d->cakupan);
    }

    public function test_usulan_ai_permenkes_memakai_jenis_kaidah_dan_pasal_dari_teks(): void
    {
        $d = $this->dok('permenkes', 'Permenkes 26/2021');
        \App\Models\RegulasiHalaman::create(['dokumen_id' => $d->id, 'halaman' => 1,
            'teks' => "Lampiran Bab II huruf B\nDiagnosis utama adalah diagnosis yang ditegakkan setelah dikaji. Kode E11 untuk diabetes."]);
        $this->fakeAi(['icd' => [], 'butir' => [
            ['jenis' => 'kaidah_diagnosis_utama', 'ringkasan' => 'DU ditegakkan setelah dikaji', 'kutipan' => 'Diagnosis utama adalah diagnosis yang ditegakkan setelah dikaji.',
                'halaman' => 1, 'pasal' => 'Lampiran Bab II huruf B', 'cakupan_icd' => ['E11', 'K35']],
            ['jenis' => 'kaidah_diagnosis_utama', 'ringkasan' => 'Pasal karangan', 'kutipan' => 'Diagnosis utama adalah diagnosis', 'halaman' => 1, 'pasal' => 'Pasal 99'],
            ['jenis' => 'tata_laksana', 'ringkasan' => 'Jenis PNPK ditolak untuk Permenkes', 'kutipan' => 'Kode E11 untuk diabetes.', 'halaman' => 1],
        ]]);

        \App\Jobs\UsulkanButirPnpk::dispatchSync($d->id);

        $b = RegulasiButir::where('dokumen_id', $d->id)->orderBy('id')->get();
        $this->assertCount(2, $b);
        $this->assertSame(['Lampiran Bab II huruf B', ['E11'], 'usulan'], [$b[0]->pasal, $b[0]->cakupan_icd, $b[0]->status]);
        $this->assertNull($b[1]->pasal);
        $this->assertStringContainsString('KAIDAH KODING', Http::recorded()[0][0]->data()['system']);
        $this->assertSame(1, AiUsage::where('jenis', 'usulan_regulasi')->count());
    }

    public function test_dasar_koding_cocok_prefix_dan_kaidah_du_ds_dengan_label(): void
    {
        $ba = $this->dok('ba_kesepakatan', 'BA JP.02.03/H.IV/1627/2023');
        $pm = $this->dok('permenkes', 'Permenkes 26/2021');
        $this->butir($ba, 'kriteria_klinis_kesepakatan', 'DM dengan komplikasi dikode kombinasi', ['cakupan_icd' => ['E11'], 'pasal' => 'Kasus 3']);
        $this->butir($ba, 'kriteria_klinis_kesepakatan', 'Butir hipertensi', ['cakupan_icd' => ['I10']]);
        $this->butir($pm, 'kaidah_diagnosis_utama', 'Kaidah DU umum', ['diperiksa_manusia' => false, 'pasal' => 'Lampiran Bab II huruf B']);
        $this->butir($pm, 'kaidah_diagnosis_sekunder', 'Kaidah DS umum');
        $this->butir($pm, 'kaidah_diagnosis_utama', 'Butir usulan', ['status' => 'usulan']);
        $f = $this->folder(['utama' => 'E11.9', 'sekunder' => 'J18.9']);

        $res = $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()
            ->assertSee('Dasar koding')->assertSee('DM dengan komplikasi dikode kombinasi')->assertSee('BA JP.02.03/H.IV/1627/2023, Kasus 3, hal. 2')
            ->assertSee('Belum diperiksa koder/dokter')->assertSee('Salin dasar koding')->assertDontSee('Butir hipertensi')->assertDontSee('Butir usulan')
            ->assertDontSee('Kode tidak ada di ICD-10 WHO 2010');
        $res->assertSee('data-preview="' . $pm->namaPenampil() . '"', false)->assertSee('data-nama="' . $pm->namaPenampil() . '"', false);

        $items = app(\App\Services\Regulasi\DasarKoding::class)->untukFolder($f)->keyBy('kode');
        $this->assertSame(['DM dengan komplikasi dikode kombinasi', 'Kaidah DU umum'], $items['E11.9']['butir']->pluck('ringkasan')->all());
        $this->assertSame(['Kaidah DS umum'], $items['J18.9']['butir']->pluck('ringkasan')->all());

        $this->actingAs($this->koor)->postJson(route('folders.dasar_koding.salin', $f))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'regulasi.salin_dasar_koding')->count());
    }

    public function test_kode_icd10_cm_memunculkan_peringatan(): void
    {
        $f = $this->folder(['utama' => 'E11.649']);

        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()
            ->assertSee('Kode tidak ada di ICD-10 WHO 2010 (kemungkinan ICD-10-CM)');
    }

    private function klaim(string $sep, string $statusTeks): VclaimKlaim
    {
        return VclaimKlaim::create(['no_sep' => $sep, 'status_kode' => 2, 'status_teks' => $statusTeks, 'tgl_sep' => '2026-11-01',
            'tgl_pulang' => '2026-11-05', 'inacbg_kode' => 'E-4-10-I', 'jns_pelayanan' => 'Rawat Inap', 'nama' => 'P', 'no_kartu' => '1']);
    }

    /** @return array{0: RegulasiButir, 1: RegulasiButir, 2: RegulasiButir} terkonfirmasi BA, otomatis BA, PNPK terkonfirmasi */
    private function acuanSanggahan(): array
    {
        $ba = $this->dok('ba_kesepakatan', 'BA 1627/2023');
        $pnpk = $this->dok('kmk_pnpk', 'KMK DM');
        RegulasiIcd::create(['dokumen_id' => $pnpk->id, 'kode' => 'E11', 'sumber' => 'manual', 'status' => 'disetujui', 'diperiksa_manusia' => true]);

        return [
            $this->butir($ba, 'kriteria_klinis_kesepakatan', 'Kriteria DM disepakati', ['cakupan_inacbg' => ['E-4-10'], 'pasal' => 'Kasus 2']),
            $this->butir($ba, 'syarat_penunjang', 'Wajib hasil laboratorium HbA1c', ['cakupan_icd' => ['E11'], 'diperiksa_manusia' => false]),
            $this->butir($pnpk, 'kriteria_diagnosis', 'Diagnosis DM dari GDP'),
        ];
    }

    public function test_panel_dasar_sanggahan_hanya_untuk_pending_tidak_layak_dispute(): void
    {
        $this->acuanSanggahan();
        foreach (['Pending Verifikasi' => true, 'Tidak Layak' => true, 'Dispute' => true, 'Klaim' => false, 'Proses Verifikasi' => false] as $teks => $tampil) {
            $sep = 'SEP' . md5($teks);
            $this->klaim($sep, $teks);
            $r = $this->actingAs($this->koor)->get(route('bpjs.klaim.show', $sep))->assertOk();
            $tampil ? $r->assertSee('Dasar sanggahan')->assertSee('Kesepakatan Kemenkes–BPJS')->assertSee('Kriteria DM disepakati')
                : $r->assertDontSee('Dasar sanggahan');
        }
        $this->actingAs($this->koor)->postJson(route('bpjs.klaim.draf', 'SEP' . md5('Klaim')), ['butir' => [1]])->assertNotFound();

        // Folder: hanya bila bpjs_tindak_lanjut; daftar periksa tercentang otomatis bila lab terbaca
        $f = $this->folder(['utama' => 'E11.9'], ['bpjs_tindak_lanjut' => true]);
        $f->analyses()->first()->documents()->create(['ftp_folder_id' => $f->id, 'file_index' => 0, 'name' => 'LAB.pdf', 'file_fingerprint' => '1|1',
            'doc_type' => 'laboratorium', 'status' => ReviewStatus::Selesai]);
        $this->actingAs($this->koor)->get(route('folders.show', $f))->assertOk()->assertSee('Dasar sanggahan')->assertSee('Dasar medis (PNPK)')
            ->assertSee('data-periksa', false)->assertSee('terdeteksi di hasil analisa');
        $this->actingAs($this->koor)->get(route('folders.show', $this->folder()))->assertOk()->assertDontSee('bagian-sanggahan');
    }

    public function test_draf_hanya_memuat_sitasi_butir_terkonfirmasi_yang_dikirim(): void
    {
        [$konf, $oto, $pnpk] = $this->acuanSanggahan();
        $lain = $this->butir($this->dok('permenkes', 'Permenkes lain'), 'kaidah_prosedur', 'Tidak relevan', ['cakupan_icd' => ['Z99']]);
        $this->klaim('SEPD', 'Pending Verifikasi');
        $this->fakeAi(['paragraf' => [
            ['teks' => 'Kriteria klinis sudah sesuai kesepakatan (E11.9).', 'sitasi' => [$konf->id, $oto->id, 999]],
            ['teks' => 'Paragraf dari butir otomatis.', 'sitasi' => [$oto->id]],
            ['teks' => 'Paragraf dari butir tak relevan.', 'sitasi' => [$lain->id]],
            ['teks' => 'Paragraf tanpa sitasi.', 'sitasi' => []],
        ]]);

        $j = $this->actingAs($this->koor)->postJson(route('bpjs.klaim.draf', 'SEPD'), ['butir' => [$konf->id, $oto->id, $lain->id]])->assertOk()->json();

        $this->assertSame('Kriteria klinis sudah sesuai kesepakatan. [BA 1627/2023, Kasus 2, hal. 2]', $j['teks']);
        $this->assertSame([$konf->id], $j['butir']);
        $kirim = Http::recorded()[0][0]->data()['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('"id":' . $konf->id, $kirim);
        $this->assertStringNotContainsString('"id":' . $oto->id, $kirim, 'butir otomatis tidak dikirim');
        $this->assertStringNotContainsString('"id":' . $pnpk->id, $kirim, 'butir yang tidak dikirim halaman tidak dipakai');
        $this->assertSame(1, AiUsage::where('jenis', 'draf_sanggahan')->count());
        $this->assertSame('claude-sonnet-5-5', AiUsage::where('jenis', 'draf_sanggahan')->value('model'));

        $this->actingAs($this->koor)->post(route('bpjs.klaim.draf.unduh', 'SEPD'), ['draf' => $j['teks'], 'format' => 'txt'])
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="draf-sanggahan-SEPD.txt"');
        $docx = $this->actingAs($this->koor)->post(route('bpjs.klaim.draf.unduh', 'SEPD'), ['draf' => $j['teks'], 'format' => 'docx'])->assertOk();
        $this->assertStringStartsWith('PK', $docx->getContent());
        $this->assertEqualsCanonicalizing(['regulasi.draf_susun', 'regulasi.draf_unduh'], AuditLog::where('action', 'like', 'regulasi.draf%')->distinct()->pluck('action')->all());

        // Tanpa butir terkonfirmasi yang relevan → ditolak tanpa memanggil AI; tombol nonaktif
        $this->actingAs($this->koor)->postJson(route('bpjs.klaim.draf', 'SEPD'), ['butir' => [$oto->id]])
            ->assertStatus(422)->assertJsonPath('errors.butir.0', 'Belum ada acuan terkonfirmasi untuk kasus ini');
        Http::assertSentCount(1);
        RegulasiButir::whereKey([$konf->id, $pnpk->id])->update(['diperiksa_manusia' => false]);
        $this->actingAs($this->koor)->get(route('bpjs.klaim.show', 'SEPD'))->assertSee('data-draf-nonaktif', false);
    }

    public function test_anggaran_habis_draf_ditolak(): void
    {
        [$konf] = $this->acuanSanggahan();
        $this->klaim('SEPA', 'Dispute');
        config(['casemix.ai.anggaran_bulanan_usd' => 1]);
        AiUsage::create(['jenis' => 'analisa', 'model' => 'x', 'biaya_usd' => 2]);
        $this->fakeAi(['paragraf' => []]);

        $this->actingAs($this->koor)->postJson(route('bpjs.klaim.draf', 'SEPA'), ['butir' => [$konf->id]])
            ->assertStatus(422)->assertJsonPath('errors.butir.0', fn ($m) => str_contains($m, 'Anggaran AI bulan ini'));
        Http::assertSentCount(0);
        $this->assertSame(0, AiUsage::where('jenis', 'draf_sanggahan')->count());
    }

    public function test_prioritas_ba_di_atas_permenkes(): void
    {
        $pnpk = $this->dok('kmk_pnpk', 'KMK');
        RegulasiIcd::create(['dokumen_id' => $pnpk->id, 'kode' => 'E11.9', 'sumber' => 'manual', 'status' => 'disetujui']);
        $this->butir($pnpk, 'tata_laksana', 'PNPK lengkap');
        $pm = $this->dok('permenkes', 'Permenkes 26/2021', ['judul' => 'A Permenkes']);
        $this->butir($pm, 'kaidah_diagnosis_utama', 'Permenkes lengkap', ['cakupan_icd' => ['E11.9']]);
        $this->butir($pm, 'kaidah_diagnosis_sekunder', 'Permenkes umum');
        $ba = $this->dok('ba_kesepakatan', 'BA', ['judul' => 'Z BA']);
        $this->butir($ba, 'kriteria_klinis_kesepakatan', 'BA prefix', ['cakupan_icd' => ['E11']]);
        $this->butir($ba, 'kriteria_klinis_kesepakatan', 'BA lengkap', ['cakupan_icd' => ['E11.9']]);

        $urut = app(PnpkAcuan::class)->butirForFolder($this->folder())->pluck('ringkasan')->all();

        $this->assertSame(['BA lengkap', 'Permenkes lengkap', 'PNPK lengkap', 'BA prefix', 'Permenkes umum'], $urut);
    }

    public function test_dokumen_dicabut_dipakai_sebelum_tanggal_pencabutan_saja(): void
    {
        $lama = $this->dok('permenkes', 'Permenkes 76/2016', ['tanggal_berlaku' => '2016-01-01']);
        $this->butir($lama, 'kaidah_diagnosis_utama', 'Kaidah lama', ['cakupan_icd' => ['E11']]);
        $this->actingAs($this->koor)->post(route('acuan.pnpk.store'), ['judul' => 'Permenkes INA-CBG', 'jenis' => 'permenkes', 'nomor' => 'Permenkes 26/2021',
            'tahun' => 2021, 'tanggal_berlaku' => '2021-07-12', 'mencabut_id' => $lama->id, 'mencabut_teks' => 'Permenkes 76/2016',
            'pdf' => UploadedFile::fake()->createWithContent('p.pdf', "%PDF-1.7\npermenkes")])->assertRedirect();
        $baru = RegulasiDokumen::where('nomor', 'Permenkes 26/2021')->sole();
        $this->butir($baru, 'kaidah_diagnosis_utama', 'Kaidah baru', ['cakupan_icd' => ['E11']]);
        $this->assertSame('dicabut', $lama->fresh()->status);
        $acuan = fn (string $tgl) => app(PnpkAcuan::class)->butirForFolder($this->folder(['utama' => 'E11.9'], ['tgl_pulang' => $tgl]))->pluck('ringkasan')->all();

        $this->assertSame(['Kaidah lama'], $acuan('2020-05-01'));
        $this->assertSame(['Kaidah baru'], $acuan('2022-01-01'));
        $this->assertSame(1, AuditLog::where('action', 'pnpk.cabut')->count());
        $this->actingAs($this->koor)->get(route('acuan.pnpk.show', $lama))->assertSee('Dicabut oleh')->assertSee('Permenkes INA-CBG');
        $this->actingAs($this->koor)->get(route('acuan.pnpk.show', $baru))->assertSee('Mencabut')->assertSee('Permenkes 76/2016');
    }

    public function test_halaman_acuan_tab_dan_admin_unggah_tetapi_403_di_panel(): void
    {
        Queue::fake();
        $this->actingAs($this->admin)->post(route('acuan.pnpk.store'), ['judul' => 'BA Koding', 'jenis' => 'ba_kesepakatan', 'nomor' => 'BA JP.02.03/H.IV/1627/2023',
            'tahun' => 2023, 'tanggal_berlaku' => '2023-05-01', 'cakupan' => ['koding'],
            'pdf' => UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.7\nba")])->assertRedirect();
        $ba = RegulasiDokumen::sole();
        $this->assertSame(['ba_kesepakatan', ['koding'], null], [$ba->jenis, $ba->cakupan, $ba->nomor_kmk]);
        $this->butir($ba, 'kriteria_klinis_kesepakatan', 'Otomatis', ['diperiksa_manusia' => false]);
        $pnpk = $this->dok('kmk_pnpk', 'KMK 1', ['judul' => 'PNPK Asma']);

        $this->actingAs($this->admin)->get(route('acuan.pnpk.index', ['tab' => 'koding']))->assertOk()->assertSee('BA Koding')->assertDontSee('PNPK Asma')
            ->assertSee('BA kesepakatan')->assertSee('1 belum diperiksa');
        $this->actingAs($this->admin)->get(route('acuan.pnpk.index', ['tab' => 'medis']))->assertSee('PNPK Asma')->assertDontSee('BA Koding');
        $this->actingAs($this->admin)->get(route('acuan.pnpk.index', ['q' => '1627']))->assertSee('BA Koding')->assertDontSee('PNPK Asma');
        $this->actingAs($this->admin)->put(route('acuan.pnpk.update', $pnpk), ['judul' => 'PNPK Asma', 'jenis' => 'kmk_pnpk', 'nomor' => 'KMK 2',
            'tahun' => 2023, 'tanggal_berlaku' => '2023-01-01', 'status' => 'berlaku', 'mencabut_teks' => 'KMK lama'])->assertRedirect();
        $this->assertSame(['KMK 2', 'KMK 2', 'KMK lama'], [$pnpk->fresh()->nomor, $pnpk->fresh()->nomor_kmk, $pnpk->fresh()->mencabut_teks]);

        $this->klaim('SEPX', 'Pending Verifikasi');
        $f = $this->folder(['utama' => 'E11.9'], ['bpjs_tindak_lanjut' => true]);
        $this->actingAs($this->admin)->get(route('folders.show', $f))->assertForbidden();
        $this->actingAs($this->admin)->get(route('bpjs.klaim.show', 'SEPX'))->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('folders.draf', $f), ['butir' => []])->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('bpjs.klaim.draf', 'SEPX'), ['butir' => []])->assertForbidden();
        $this->actingAs($this->admin)->postJson(route('folders.dasar_koding.salin', $f))->assertForbidden();
        $this->actingAs($this->admin)->get('/acuan/regulasi')->assertRedirect('/acuan/pnpk');
    }
}
