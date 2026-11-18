<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FtpFolder;
use App\Models\User;
use App\Models\VclaimKlaim;
use App\Models\VclaimKunjungan;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LZCompressor\LZString;
use Tests\TestCase;

/** Tombol "Ambil riwayat pelayanan" via JSON (tanpa antrean). */
class VclaimHistoriApiTest extends TestCase
{
    use RefreshDatabase;

    private const TS = 1_790_000_000; // 2026-09-21 UTC

    private const SEP = '0301R0011126V000100';

    private const KARTU = '0001234567890';

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['casemix.vclaim' => array_merge(config('casemix.vclaim'), [
            'enabled' => true, 'base_url' => 'https://vclaim.test', 'service' => 'vclaim-rest',
            'cons_id' => '12345', 'secret' => 'rahasia-xyz', 'user_key' => 'kunci-xyz', 'jeda_ms' => 0, 'ppk_nama' => 'contoh sehat',
        ])]);
        Carbon::setTestNow(Carbon::createFromTimestamp(self::TS, 'UTC'));
        $this->seed(RolePermissionSeeder::class);
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        VclaimKlaim::create(['no_sep' => self::SEP, 'status_kode' => 1, 'tgl_sep' => '2026-09-20', 'no_kartu' => self::KARTU, 'synced_at' => now()]);
    }

    private function ok(array $data): array
    {
        $kh = hex2bin(hash('sha256', '12345'.'rahasia-xyz'.self::TS));
        $lz = LZString::compressToEncodedURIComponent(json_encode($data));

        return ['metaData' => ['code' => '200', 'message' => 'OK'],
            'response' => base64_encode(openssl_encrypt($lz, 'AES-256-CBC', $kh, OPENSSL_RAW_DATA, substr($kh, 0, 16)))];
    }

    private function fakeHistori(): void
    {
        Http::fake(['*' => Http::response($this->ok(['histori' => [
            ['noSep' => '0301R0011126V000080', 'tglSep' => '2026-08-10', 'tglPlgSep' => '2026-08-20', 'jnsPelayanan' => '1', 'ppkPelayanan' => 'RSUD Contoh Sehat', 'diagnosa' => 'R31'],
            ['noSep' => self::SEP, 'tglSep' => '2026-09-20', 'jnsPelayanan' => '1', 'ppkPelayanan' => 'RSUD CONTOH SEHAT', 'diagnosa' => 'INI'],
            ['noSep' => '0999R0011126V000070', 'tglSep' => '2026-09-01', 'tglPlgSep' => '2026-09-01', 'jnsPelayanan' => '2', 'ppkPelayanan' => 'KLINIK LAIN', 'diagnosa' => 'JALAN'],
            ['noSep' => '0301R0011126V000090', 'tglSep' => '2026-08-15', 'tglPlgSep' => '2026-08-21', 'jnsPelayanan' => '1', 'ppkPelayanan' => 'RSUD Contoh Sehat', 'diagnosa' => 'R30'],
        ]]))]);
    }

    private function folder(?string $sep = self::SEP): FtpFolder
    {
        return FtpFolder::create(['path' => '/ftp/h/1', 'name' => '000001_PASIEN', 'no_sep' => $sep, 'files' => [], 'fingerprint' => 'f',
            'files_modified_at' => now()->subDay(), 'last_seen_at' => now()]);
    }

    public function test_json_cache_refresh_dan_throttle(): void
    {
        $this->fakeHistori();
        $url = route('bpjs.klaim.histori', self::SEP);

        $d = $this->actingAs($this->koor)->postJson($url, ['no_kartu' => '9999999999999'])->assertOk()
            ->assertJsonStructure(['ok', 'from_cache', 'fetched_at', 'fetched_label', 'rentang' => ['mulai', 'akhir'],
                'ringkasan' => ['total', 'inap', 'jalan', 'readmisi'],
                'items' => [['no_sep', 'tgl_sep', 'tgl_plg_sep', 'jns_pelayanan', 'kelas_rawat', 'ppk_pelayanan', 'poli', 'diagnosa', 'sep_ini', 'rs_ini', 'readmisi']]])
            ->assertJson(['ok' => true, 'from_cache' => false, 'rentang' => ['mulai' => '2026-06-23', 'akhir' => '2026-09-20'],
                'ringkasan' => ['total' => 4, 'inap' => 3, 'jalan' => 1, 'readmisi' => 1]])->json();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/NoKartu/'.self::KARTU.'/tglMulai/2026-06-23/tglAkhir/2026-09-20'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '9999999999999'));

        $this->assertSame(['INI', 'JALAN', 'R30', 'R31'], array_column($d['items'], 'diagnosa'));
        $by = collect($d['items'])->keyBy('diagnosa');
        $this->assertTrue($by['INI']['sep_ini']);
        $this->assertFalse($by['R30']['sep_ini']);
        $this->assertTrue($by['R30']['readmisi']);
        $this->assertFalse($by['R31']['readmisi']);
        $this->assertFalse($by['INI']['readmisi']);
        $this->assertTrue($by['INI']['rs_ini']);
        $this->assertFalse($by['JALAN']['rs_ini']);
        $this->assertSame('Rawat inap', $by['INI']['jns_pelayanan']);
        $this->assertSame('Rawat jalan', $by['JALAN']['jns_pelayanan']);

        // Kedua < 24 jam: dari simpanan tanpa HTTP (juga lewat folder)
        $this->postJson($url)->assertOk()->assertJson(['from_cache' => true, 'ringkasan' => ['total' => 4]]);
        $this->postJson(route('folders.histori', $this->folder()))->assertOk()->assertJson(['from_cache' => true]);
        Http::assertSentCount(1);

        // Halaman menampilkan hasil tersimpan langsung
        $this->get(route('bpjs.klaim.show', self::SEP))->assertOk()->assertSee('dari simpanan')->assertSee('Perbarui')
            ->assertSee('Kemungkinan readmisi')->assertSee('data-url="'.$url.'"', false);

        // refresh=1 memanggil HTTP; kedua dalam 1 menit → 429
        $this->postJson($url, ['refresh' => 1])->assertOk()->assertJson(['from_cache' => false]);
        Http::assertSentCount(2);
        $this->postJson($url, ['refresh' => 1])->assertStatus(429)->assertJson(['ok' => false]);
        Http::assertSentCount(2);

        $log = AuditLog::where('action', 'vclaim.riwayat.ambil')->get();
        $this->assertCount(4, $log);
        $this->assertSame(4, $log->first()->properties['jumlah']);
        $this->assertArrayHasKey('from_cache', $log->first()->properties);
        $this->assertStringNotContainsString(self::KARTU, $log->toJson());
        $this->assertStringNotContainsString('R30', $log->toJson());
    }

    public function test_admin_403(): void
    {
        Http::fake();
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->actingAs($admin)->postJson(route('bpjs.klaim.histori', self::SEP))->assertForbidden();
        $this->actingAs($admin)->postJson(route('folders.histori', $this->folder()))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_nonaktif_409_dan_tanpa_kartu_422(): void
    {
        Http::fake();
        VclaimKunjungan::create(['no_sep' => 'SEPTANPAKARTU', 'tgl_sep' => '2026-09-20', 'nama' => 'X']);
        $this->actingAs($this->koor)->postJson(route('bpjs.klaim.histori', 'SEPTANPAKARTU'), ['no_kartu' => self::KARTU])
            ->assertStatus(422)->assertJson(['ok' => false, 'message' => 'Nomor kartu peserta belum diketahui untuk SEP ini.']);
        $this->postJson(route('folders.histori', $this->folder(null)))->assertStatus(422);

        config(['casemix.vclaim.enabled' => false]);
        $this->postJson(route('bpjs.klaim.histori', self::SEP))->assertStatus(409)->assertJson(['message' => 'Bridging VClaim belum diaktifkan.']);
        Http::assertNothingSent();
    }

    public function test_bpjs_error_502_tanpa_kredensial(): void
    {
        Http::fake(['*' => Http::response(['metaData' => ['code' => '500', 'message' => 'Server sibuk'], 'response' => null])]);
        $res = $this->actingAs($this->koor)->postJson(route('bpjs.klaim.histori', self::SEP))->assertStatus(502)->assertJson(['ok' => false]);
        $this->assertStringContainsString('BPJS tidak merespons', $res->json('message'));
        $this->assertStringContainsString('Coba lagi beberapa saat.', $res->json('message'));
        foreach (['rahasia-xyz', 'kunci-xyz', 'cons_id', 'trace', 'X-signature'] as $s) {
            $this->assertStringNotContainsString($s, $res->getContent());
        }
    }
}
