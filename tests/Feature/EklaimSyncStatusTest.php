<?php

namespace Tests\Feature;

use App\Models\FtpFolder;
use App\Models\User;
use App\Services\Eklaim\EklaimCrypto;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** casemix:eklaim-sync-status dengan E-Klaim palsu (Http::fake). */
class EklaimSyncStatusTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-23 10:00'));
        config(['services.eklaim' => ['ws_url' => 'http://e-klaim.test/ws.php', 'encryption_key' => self::KEY,
            'sync_enabled' => true, 'timeout' => 10, 'jeda_ms' => 0]]);
    }

    private function folder(string $sep, array $attr = []): FtpFolder
    {
        return FtpFolder::create($attr + ['path' => "/ftp/$sep", 'name' => "123_Pasien $sep", 'no_sep' => $sep, 'files' => [],
            'fingerprint' => 'f', 'analysis_status' => 'selesai', 'analyzed_at' => now()->subDay()]);
    }

    /** Respons terenkripsi seperti ws.php. Nilai per SEP: array data, 'timeout', atau tidak ada = SEP tidak ditemukan. */
    private function fakeEklaim(array $perSep): void
    {
        $c = new EklaimCrypto(self::KEY);
        Http::fake(['e-klaim.test/*' => function (Request $r) use ($c, $perSep) {
            $req = json_decode($c->decrypt($r->body()), true);
            $d = $perSep[$req['data']['nomor_sep']] ?? null;
            if ($d === 'timeout') {
                throw new ConnectionException('timeout');
            }
            if ($d === 'http404') {
                return Http::response('not found', 404);
            }
            if ($d === 'rusak') {
                return Http::response("----BEGIN ENCRYPTED DATA----
".base64_encode(str_repeat('x', 64))."----END ENCRYPTED DATA----
");
            }
            $json = match (true) {
                $d === null => ['metadata' => ['code' => 400, 'message' => 'Nomor SEP tidak ditemukan', 'error_no' => 'E2004']],
                isset($d['__meta']) => ['metadata' => $d['__meta']],
                default => ['metadata' => ['code' => 200, 'message' => 'Ok'], 'response' => ['data' => $d + ['nama_pasien' => 'RAHASIA']]],
            };

            return Http::response("----BEGIN ENCRYPTED DATA----\n".$c->encrypt(json_encode($json))."----END ENCRYPTED DATA----\n");
        }]);
    }

    public function test_final_terkirim_dan_belum_ada(): void
    {
        $a = $this->folder('SEPA');
        $b = $this->folder('SEPB');
        $c = $this->folder('SEPC');
        $this->fakeEklaim([
            'SEPA' => ['klaim_status_cd' => 'final', 'bpjs_dc_status_cd' => 'unsent'],
            'SEPB' => ['klaim_status_cd' => 'final', 'kemenkes_dc_status_cd' => 'sent', 'bpjs_dc_status_cd' => 'sent'],
        ]);

        $this->artisan('casemix:eklaim-sync-status')->expectsOutputToContain('dicek 3, berubah 2, belum ada di E-Klaim 1, gagal 0')->assertSuccessful();

        $a->refresh();
        $b->refresh();
        $c->refresh();
        $this->assertSame('final', $a->eklaim_status);
        $this->assertNotNull($a->eklaim_final_at);
        $this->assertNull($a->eklaim_terkirim_at);
        $this->assertSame('terkirim', $b->eklaim_status);
        $this->assertNotNull($b->eklaim_terkirim_at);
        $this->assertNull($c->eklaim_status);
        $this->assertNotNull($c->eklaim_dicek_at);
        $this->assertSame(0, $c->eklaim_gagal_cek);
        Http::assertSent(fn (Request $r) => ! str_contains($r->body(), 'get_claim_data'));
    }

    public function test_terkirim_lama_dan_belum_dianalisa_tidak_dicek(): void
    {
        $this->folder('SEPT', ['eklaim_status' => 'terkirim']);
        $this->folder('SEPL', ['analyzed_at' => now()->subDays(40)]);
        $this->folder('SEPN', ['analysis_status' => null, 'analyzed_at' => null]);
        $this->fakeEklaim([]);

        $this->artisan('casemix:eklaim-sync-status')->expectsOutputToContain('dicek 0')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_timeout_tiga_kali_berturut_turut_menghentikan_putaran(): void
    {
        foreach (['S1', 'S2', 'S3', 'S4'] as $s) {
            $this->folder($s);
        }
        $this->fakeEklaim(['S1' => 'timeout', 'S2' => 'timeout', 'S3' => 'timeout', 'S4' => ['klaim_status_cd' => 'final']]);

        $this->artisan('casemix:eklaim-sync-status')->expectsOutputToContain('gagal 3 — dihentikan')->assertSuccessful();
        $this->assertSame([1, 1, 1, 0], FtpFolder::orderBy('no_sep')->pluck('eklaim_gagal_cek')->map(fn ($v) => (int) $v)->all());
        $this->assertNull(FtpFolder::where('no_sep', 'S4')->value('eklaim_dicek_at'));
    }

    public function test_dry_run_tidak_menyimpan(): void
    {
        $a = $this->folder('SEPA');
        $this->fakeEklaim(['SEPA' => ['klaim_status_cd' => 'final']]);

        $this->artisan('casemix:eklaim-sync-status --dry-run')->expectsOutputToContain('berubah 1')->assertSuccessful();
        $this->assertNull($a->refresh()->eklaim_status);
        $this->assertNull($a->eklaim_dicek_at);
    }

    public function test_nonaktif_tidak_memanggil(): void
    {
        config(['services.eklaim.sync_enabled' => false]);
        $this->folder('SEPA');
        Http::fake();

        $this->artisan('casemix:eklaim-sync-status')->expectsOutputToContain('nonaktif')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_opsi_sep_hanya_menampilkan_field_status(): void
    {
        $this->folder('SEPA');
        $this->fakeEklaim(['SEPA' => ['klaim_status_cd' => 'final', 'bpjs_dc_status_cd' => 'unsent']]);

        $this->artisan('casemix:eklaim-sync-status --sep=SEPA')
            ->expectsOutput('klaim_status_cd: final')
            ->doesntExpectOutputToContain('RAHASIA')
            ->assertSuccessful();
        $this->assertSame('final', FtpFolder::where('no_sep', 'SEPA')->value('eklaim_status'));
    }

    public function test_tampil_field_hanya_nilai_status(): void
    {
        $this->folder('SEPA');
        $this->fakeEklaim(['SEPA' => ['klaim_status_cd' => 'final', 'nama_pasien' => 'RAHASIA', 'tarif' => ['total' => 999123],
            'grouper' => ['error_code' => 'E01', 'diagnosa' => 'RAHASIA2']]]);

        $this->artisan('casemix:eklaim-sync-status --sep=SEPA --dry-run --tampil-field')
            ->expectsOutput("  data.klaim_status_cd = 'final'")
            ->expectsOutput('  data.nama_pasien')
            ->expectsOutput('  data.tarif.total')
            ->expectsOutput("  data.grouper.error_code = 'E01'")
            ->doesntExpectOutputToContain('RAHASIA')
            ->doesntExpectOutputToContain('999123')
            ->assertSuccessful();
        $this->artisan('casemix:eklaim-sync-status --tampil-field')->assertFailed();
    }

    public function test_tandai_diajukan_manual_dan_filter(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $a = $this->folder('SEPA');
        $this->folder('SEPB', ['eklaim_status' => 'final']);

        $this->actingAs($u)->post(route('folders.diajukan', $a))->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->diajukan_manual_at);
        $this->assertSame($u->id, $a->diajukan_manual_oleh);

        $this->actingAs($u)->get(route('folders.show', $a))->assertSee('Sumber: manual', false);
        $this->actingAs($u)->get(route('folders.index', ['eklaim' => 'manual']))->assertSee('SEP SEPA')->assertDontSee('SEP SEPB');
        $this->actingAs($u)->get(route('folders.index', ['eklaim' => 'final']))->assertSee('Final di E-Klaim')->assertDontSee('SEP SEPA');
    }

    public function test_konfigurasi_kosong_berhenti_tanpa_mengubah_data(): void
    {
        $a = $this->folder('SEPA');
        Http::fake();
        foreach ([['ws_url' => ''], ['ws_url' => 'http://e-klaim.test/ws.php', 'encryption_key' => '']] as $cfg) {
            config(['services.eklaim' => $cfg + config('services.eklaim')]);
            $this->artisan('casemix:eklaim-sync-status --sep=SEPA')
                ->expectsOutputToContain('Konfigurasi E-Klaim belum diisi')
                ->doesntExpectOutputToContain('belum ada')
                ->assertExitCode(1);
            $this->artisan('casemix:eklaim-sync-status')->expectsOutputToContain('Konfigurasi E-Klaim belum diisi')->assertExitCode(1);
        }
        Http::assertNothingSent();
        $a->refresh();
        $this->assertNull($a->eklaim_dicek_at);
        $this->assertSame(0, (int) $a->eklaim_gagal_cek);
    }

    public function test_error_selain_tidak_ditemukan_dihitung_gagal(): void
    {
        foreach (['SEPK', 'SEPH', 'SEPR', 'SEPM'] as $s) {
            $this->folder($s);
        }
        $this->fakeEklaim([
            'SEPK' => ['__meta' => ['code' => 400, 'message' => 'Akses ditolak', 'error_no' => 'E2099']],
            'SEPH' => 'http404',
            'SEPR' => 'rusak',
            'SEPM' => ['__meta' => ['code' => 400, 'message' => 'Data tidak ditemukan untuk user ini']],
        ]);

        $this->artisan('casemix:eklaim-sync-status')->expectsOutputToContain('dicek 0, berubah 0, belum ada di E-Klaim 0, gagal 4')->assertSuccessful();
        $this->assertSame([1, 1, 1, 1], FtpFolder::orderBy('no_sep')->pluck('eklaim_gagal_cek')->map(fn ($v) => (int) $v)->all());

        $this->artisan('casemix:eklaim-sync-status --sep=SEPK')
            ->expectsOutputToContain('GAGAL: E-Klaim menolak permintaan (kode 400, E2099: Akses ditolak)')
            ->doesntExpectOutputToContain('belum ada')
            ->assertExitCode(1);
        $this->assertSame(2, (int) FtpFolder::where('no_sep', 'SEPK')->value('eklaim_gagal_cek'));
        $this->artisan('casemix:eklaim-sync-status --sep=SEPH')->expectsOutputToContain('HTTP 404')->assertExitCode(1);
        $this->artisan('casemix:eklaim-sync-status --sep=SEPR')->expectsOutputToContain('GAGAL')->assertExitCode(1);
    }

    public function test_tampil_field_metadata_dan_coder_disembunyikan(): void
    {
        $this->folder('SEPA');
        $this->fakeEklaim(['SEPA' => ['klaim_status_cd' => 'final', 'coder_nm' => 'KODER RAHASIA', 'coder_nik' => '3201999']]);

        $this->artisan('casemix:eklaim-sync-status --sep=SEPA --dry-run --tampil-field')
            ->expectsOutput('  metadata.code = 200')
            ->expectsOutput("  metadata.message = 'Ok'")
            ->expectsOutput('  data.coder_nm')
            ->expectsOutput('  data.coder_nik')
            ->doesntExpectOutputToContain('KODER RAHASIA')
            ->doesntExpectOutputToContain('3201999')
            ->assertSuccessful();
    }

    public function test_waktu_terkirim_dari_field_sent_dttm(): void
    {
        $a = $this->folder('SEPA');
        $b = $this->folder('SEPB');
        $c = $this->folder('SEPC');
        $this->fakeEklaim([
            'SEPA' => ['klaim_status_cd' => 'final', 'bpjs_dc_status_cd' => 'sent', 'bpjs_dc_sent_dttm' => '2026-11-20 08:15:00', 'kemenkes_dc_sent_dttm' => '2026-11-19 07:00:00'],
            'SEPB' => ['klaim_status_cd' => 'final', 'bpjs_dc_status_cd' => 'sent', 'kemenkes_dc_sent_dttm' => '2026-11-19 07:00:00'],
            'SEPC' => ['klaim_status_cd' => 'final', 'bpjs_dc_status_cd' => 'sent'],
        ]);

        $this->artisan('casemix:eklaim-sync-status')->assertSuccessful();
        $this->assertSame('2026-11-20 08:15:00', $a->refresh()->eklaim_terkirim_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-19 07:00:00', $b->refresh()->eklaim_terkirim_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-23 10:00:00', $c->refresh()->eklaim_terkirim_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-23 10:00:00', $a->eklaim_final_at->format('Y-m-d H:i:s'));
    }
}
