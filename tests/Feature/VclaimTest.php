<?php

namespace Tests\Feature;

use App\Jobs\FetchVclaimHistori;
use App\Models\VclaimKlaim;
use App\Models\VclaimKlaimStatusHistory;
use App\Models\VclaimSyncRun;
use App\Services\Vclaim\VclaimClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LZCompressor\LZString;
use Tests\TestCase;

class VclaimTest extends TestCase
{
    use RefreshDatabase;

    private const TS = 1_790_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        config(['casemix.vclaim' => array_merge(config('casemix.vclaim'), [
            'enabled' => true,
            'base_url' => 'https://vclaim.test',
            'service' => 'vclaim-rest',
            'cons_id' => '12345',
            'secret' => 'rahasia',
            'user_key' => 'kunci',
            'jeda_ms' => 0,
        ])]);
        Carbon::setTestNow(Carbon::createFromTimestamp(self::TS, 'UTC'));
    }

    private function encrypt(array $data): string
    {
        $kh = hex2bin(hash('sha256', '12345'.'rahasia'.self::TS));
        $lz = LZString::compressToEncodedURIComponent(json_encode($data));

        return base64_encode(openssl_encrypt($lz, 'AES-256-CBC', $kh, OPENSSL_RAW_DATA, substr($kh, 0, 16)));
    }

    private function ok(array $data): array
    {
        return ['metaData' => ['code' => '200', 'message' => 'OK'], 'response' => $this->encrypt($data)];
    }

    private function klaimRow(string $status, string $biaya = '1500000.50'): array
    {
        return [
            'noSEP' => '0001R0011125V000001', 'status' => $status, 'tglSep' => '2026-11-10', 'tglPulang' => '2026-11-12',
            'kelasRawat' => '3', 'poli' => 'INT', 'noFPK' => '', 'jnsPelayanan' => 'Rawat Inap',
            'Inacbg' => ['kode' => 'X-1-10-I', 'nama' => 'CONTOH'],
            'biaya' => ['byPengajuan' => $biaya, 'bySetujui' => '0', 'byTarifGruper' => '1.400.000', 'byTarifRS' => '2000000', 'byTopup' => '0'],
            'peserta' => ['noKartu' => '0000000000001', 'noMR' => 'RM1', 'nama' => 'PASIEN UJI'],
        ];
    }

    public function test_header_signature_benar_dan_hanya_get(): void
    {
        Http::fake(['*' => Http::response(['metaData' => ['code' => '201', 'message' => 'Data tidak ditemukan'], 'response' => null])]);

        $this->assertSame([], app(VclaimClient::class)->kunjungan('2026-11-10', 1));

        $sig = base64_encode(hash_hmac('sha256', '12345&'.self::TS, 'rahasia', true));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://vclaim.test/vclaim-rest/Monitoring/Kunjungan/Tanggal/2026-11-10/JnsPelayanan/1'
            && $r->header('X-cons-id')[0] === '12345'
            && $r->header('X-timestamp')[0] === (string) self::TS
            && $r->header('X-signature')[0] === $sig
            && $r->header('user_key')[0] === 'kunci');
    }

    public function test_dekripsi_dan_dekompresi(): void
    {
        Http::fake(['*' => Http::response($this->ok(['sep' => [['noSep' => 'S1', 'nama' => 'A']]]))]);

        $rows = app(VclaimClient::class)->kunjungan('2026-11-10', 2);

        $this->assertSame('S1', $rows[0]['noSep']);
    }

    public function test_upsert_klaim_riwayat_status_dan_biaya_decimal(): void
    {
        $status = 1;
        Http::fake(function (Request $r) use (&$status) {
            $s = (int) substr($r->url(), -1);
            $rows = ($s === $status && str_contains($r->url(), 'JnsPelayanan/1/')) ? [$this->klaimRow($s === 1 ? 'Proses Verifikasi' : 'Pending Verifikasi')] : [];

            return Http::response($rows ? $this->ok(['klaim' => $rows]) : ['metaData' => ['code' => '201', 'message' => 'Data tidak ditemukan'], 'response' => null]);
        });

        $this->artisan('casemix:vclaim-sync-klaim', ['--from' => '2026-11-12', '--to' => '2026-11-12'])->assertSuccessful();
        $k = VclaimKlaim::sole();
        $this->assertSame(1, $k->status_kode);
        $this->assertSame('1500000.50', $k->by_pengajuan);
        $this->assertSame('1400000.00', $k->by_tarif_gruper);
        $this->assertSame(0, VclaimKlaimStatusHistory::count());

        $status = 2;
        $this->artisan('casemix:vclaim-sync-klaim', ['--from' => '2026-11-12', '--to' => '2026-11-12'])->assertSuccessful();
        $k->refresh();
        $this->assertSame(2, $k->status_kode);
        $this->assertNotNull($k->status_changed_at);
        $h = VclaimKlaimStatusHistory::sole();
        $this->assertSame([1, 2], [$h->status_lama, $h->status_baru]);
        $this->assertSame(1, VclaimKlaim::count());
        $this->assertSame(12, VclaimSyncRun::where('status', 'ok')->count());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET');
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_pemetaan_klaim_jns_status_teks_dan_kosong(): void
    {
        $sync = app(\App\Services\Vclaim\VclaimSync::class);
        $row = ['poli' => '-', 'noFPK' => 'null', 'kelasRawat' => ''] + $this->klaimRow('2#Klaim Pending');
        $this->travelTo(Carbon::parse('2026-11-12 08:00'));
        $sync->upsertKlaim($row, 2, 2);
        $k = VclaimKlaim::sole();
        $this->assertSame('2', $k->jns_pelayanan);
        $this->assertSame('Klaim Pending', $k->status_teks);
        $this->assertNull($k->poli);
        $this->assertNull($k->no_fpk);
        $this->assertNull($k->kelas_rawat);
        $this->assertSame('2026-11-12 08:00:00', $k->status_changed_at->format('Y-m-d H:i:s'));

        $this->travelTo(Carbon::parse('2026-11-13 08:00'));
        $sync->upsertKlaim($row, 2, 2);
        $this->assertSame('2026-11-12 08:00:00', $k->refresh()->status_changed_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, VclaimKlaimStatusHistory::count());

        $sync->upsertKlaim($row, 3, 1);
        $this->assertSame('2026-11-13 08:00:00', $k->refresh()->status_changed_at->format('Y-m-d H:i:s'));
        $this->assertSame('1', $k->jns_pelayanan);
    }

    public function test_disabled_tanpa_panggilan_http(): void
    {
        config(['casemix.vclaim.enabled' => false]);
        Http::fake();

        $this->artisan('casemix:vclaim-sync-klaim')->assertSuccessful();
        $this->artisan('casemix:vclaim-sync-kunjungan')->assertSuccessful();
        (new FetchVclaimHistori('0000000000001'))->handle(app(\App\Services\Vclaim\VclaimSync::class));

        Http::assertNothingSent();
        $this->assertSame(0, VclaimSyncRun::count());
    }

    public function test_histori_rentang_lebih_90_hari_ditolak(): void
    {
        Http::fake();

        try {
            app(VclaimClient::class)->histori('0000000000001', '2026-01-01', '2026-06-01');
            $this->fail('Seharusnya ditolak');
        } catch (InvalidArgumentException) {
        }

        Http::assertNothingSent();
    }

    public function test_histori_disimpan_dan_dicache_24_jam(): void
    {
        Http::fake(['*' => Http::response($this->ok(['histori' => [['noSep' => 'H1', 'diagnosa' => 'Demam', 'ppkPelayanan' => 'RS']]]))]);
        $sync = app(\App\Services\Vclaim\VclaimSync::class);

        (new FetchVclaimHistori('0000000000001'))->handle($sync);
        (new FetchVclaimHistori('0000000000001'))->handle($sync);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/monitoring/HistoriPelayanan/NoKartu/0000000000001/'));
        $this->assertDatabaseHas('vclaim_histori', ['no_kartu' => '0000000000001', 'no_sep' => 'H1', 'diagnosa' => 'Demam']);
    }

    public function test_archive_prune_hapus_sync_runs_lebih_90_hari(): void
    {
        $lama = VclaimSyncRun::create(['jenis' => 'klaim', 'status' => 'ok']);
        $lama->forceFill(['created_at' => now()->subDays(91)])->save();
        $baru = VclaimSyncRun::create(['jenis' => 'klaim', 'status' => 'ok']);
        $baru->forceFill(['created_at' => now()->subDays(89)])->save();
        $klaim = VclaimKlaim::create(['no_sep' => 'SEPLAMA', 'status_kode' => 3]);
        $klaim->forceFill(['created_at' => now()->subDays(400)])->save();

        $this->artisan('casemix:archive-prune')->assertSuccessful();

        $this->assertDatabaseMissing('vclaim_sync_runs', ['id' => $lama->id]);
        $this->assertDatabaseHas('vclaim_sync_runs', ['id' => $baru->id]);
        $this->assertDatabaseHas('vclaim_klaim', ['no_sep' => 'SEPLAMA']);
        $log = \App\Models\AuditLog::where('action', 'arsip.hapus_kedaluwarsa')->latest('id')->firstOrFail();
        $this->assertSame(1, $log->properties['vclaim_sync_runs'] ?? null);
    }
}
