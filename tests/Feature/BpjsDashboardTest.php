<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VclaimKlaim;
use App\Models\VclaimKlaimStatusHistory;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use App\Services\Dashboard\BpjsStats;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tab Ringkasan Klaim BPJS: kartu status, donut, tren dari tabel vclaim_*. */
class BpjsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $koor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(10, 0));
        config(['casemix.vclaim.enabled' => true, 'casemix.go_live_date' => '2026-06-01']);
        $this->koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
    }

    private function klaim(int $status, array $attr = []): VclaimKlaim
    {
        $sep = $attr['no_sep'] ?? fake()->unique()->numerify('0301R001##########');

        return VclaimKlaim::create($attr + ['no_sep' => $sep, 'status_kode' => $status, 'tgl_sep' => '2026-10-03', 'tgl_pulang' => '2026-10-10',
            'jns_pelayanan' => 'Rawat Inap', 'nama' => fake()->name(), 'by_pengajuan' => 1000000, 'by_setujui' => 1000000]);
    }

    private function sep(string $noSep, array $attr = []): VclaimKunjungan
    {
        return VclaimKunjungan::create($attr + ['no_sep' => $noSep, 'tgl_sep' => '2026-10-03', 'jns_pelayanan' => 'Rawat Inap', 'nama' => fake()->name()]);
    }

    private function dataset(): void
    {
        $this->sep('SEPA');
        $this->sep('SEPB');
        $this->sep('SEPBELUM1');
        $this->sep('SEPBELUM2', ['jns_pelayanan' => 'Rawat Jalan']);
        $this->klaim(1, ['no_sep' => 'SEPA']);
        $this->klaim(2, ['no_sep' => 'SEPB']);
        $this->klaim(2);
        $this->klaim(3, ['by_pengajuan' => 2000000, 'by_setujui' => 1500000]);
        $this->klaim(3, ['jns_pelayanan' => 'Rawat Jalan']);
        $this->klaim(1, ['by_pengajuan' => 3000000, 'by_setujui' => 1000000]); // bukan status klaim: tidak dihitung "kurang"
        $this->klaim(2, ['tgl_pulang' => '2026-09-15']); // bulan sebelumnya
    }

    private function ringkasan(array $q = [])
    {
        return $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'ringkasan', 'periode' => '2026-10'] + $q))->assertOk();
    }

    private function payload($res): array
    {
        preg_match('#<script type="application/json" id="bpjs-data">(.*?)</script>#s', $res->getContent(), $m);
        $this->assertNotEmpty($m, 'JSON #bpjs-data tidak ada');

        return json_decode(html_entity_decode($m[1]), true);
    }

    public function test_angka_kartu_sesuai_data(): void
    {
        $this->dataset();
        $res = $this->ringkasan();
        $k = $res->viewData('stats')['kartu'];

        $this->assertSame(4, $k['sep']['n']);
        $this->assertSame(2, $k['proses']['n']);
        $this->assertSame(2, $k['pending']['n']);
        $this->assertSame(2, $k['klaim']['n']);
        $this->assertSame(2, $k['belum']['n']);
        $this->assertSame(1, $k['kurang']['n']);
        $this->assertSame(33.3, $k['pending']['pct']);
        $this->assertSame(4000000.0, $k['proses']['rp']);
        $this->assertSame(1, $k['pending']['delta']);
        $res->assertSee('+1 vs bulan lalu')->assertSee('Perlu impor umpan balik BPJS')->assertSee('Sumber: VClaim Monitoring BPJS');
    }

    public function test_belum_masuk_dan_kurang_hanya_status_klaim(): void
    {
        $this->dataset();
        $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'sep', 'belum' => 1, 'dari' => '2026-10-01', 'sampai' => '2026-10-31']))
            ->assertOk()->assertSee('SEPBELUM1')->assertSee('SEPBELUM2')->assertDontSee('SEPA<')->assertDontSee('data-row="SEPB"', false);

        $res = $this->actingAs($this->koor)->get(route('bpjs.index', ['tab' => 'klaim', 'status' => 'klaim', 'kurang' => 1, 'dari' => '2026-10-01', 'sampai' => '2026-10-31']));
        $this->assertSame(1, $res->viewData('rows')->total());
        $this->assertSame(3, $res->viewData('rows')->first()->status_kode);
    }

    public function test_json_tren_dan_donut(): void
    {
        $this->dataset();
        $d = $this->payload($this->ringkasan());

        $this->assertCount(12, $d['tren']['bulan']);
        $this->assertSame('2026-10', end($d['tren']['bulan']));
        $this->assertSame(2, $d['tren']['pending'][11]);
        $this->assertSame(1, $d['tren']['pending'][10]);
        $this->assertSame(7, $d['tren']['go_live']);
        $this->assertSame([2, 2, 2], $d['donut_status']['counts']);
        $this->assertSame([5, 1], $d['donut_jenis']['counts']);
        $this->assertEquals([3000000, 2500000], [$d['tren']['diajukan'][11], $d['tren']['disetujui'][11]]);
        $this->assertCount(12, $d['tren']['links']);
    }

    public function test_tautan_kartu_membawa_filter(): void
    {
        $this->dataset();
        $l = $this->ringkasan(['jenis' => 'inap'])->viewData('links')['kartu'];

        foreach (['tab=klaim', 'status=pending', 'dari=2026-10-01', 'sampai=2026-10-31', 'jenis=inap'] as $s) {
            $this->assertStringContainsString($s, $l['pending']);
        }
        $this->assertStringContainsString('tab=sep', $l['belum']);
        $this->assertStringContainsString('belum=1', $l['belum']);
        $this->assertStringContainsString('kurang=1', $l['kurang']);
        $this->assertStringContainsString('status=klaim', $l['kurang']);
        $this->assertStringContainsString('tab=sep', $l['sep']);
    }

    public function test_filter_jenis(): void
    {
        $this->dataset();
        $k = $this->ringkasan(['jenis' => 'jalan'])->viewData('stats')['kartu'];
        $this->assertSame(1, $k['sep']['n']);
        $this->assertSame(1, $k['klaim']['n']);
        $this->assertSame(0, $k['pending']['n']);
        $this->assertSame(1, $k['belum']['n']);
    }

    public function test_admin_403(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $this->actingAs($admin)->get(route('bpjs.index'))->assertForbidden();
    }

    public function test_tanpa_data_empty_state(): void
    {
        $this->ringkasan()->assertSee('Belum ada data BPJS untuk periode ini.')->assertDontSee('id="bpjs-data"', false);
    }

    public function test_kalimat_ringkasan_jumlah_dan_nilai(): void
    {
        $this->dataset();
        $res = $this->ringkasan();
        $teks = BpjsStats::teksKalimat($res->viewData('stats')['kalimat']);

        $this->assertSame('Oktober 2026: 6 klaim diajukan senilai Rp9 jt. 2 layak (Rp2,5 jt disetujui), 2 pending dengan Rp2 jt tertahan, 2 masih diverifikasi.', $teks);
        $res->assertSee('title="Rp9.000.000"', false)->assertSee('Pusat kendali klaim BPJS')->assertSee('Nilai resmi VClaim');
        $this->assertSame('Rp4,2 M', BpjsStats::rupiahRingkas(4200000000));
        $this->assertSame('Rp950 rb', BpjsStats::rupiahRingkas(950000));
    }

    public function test_kartu_nilai_tertahan_sama_dengan_sum_pending(): void
    {
        $this->dataset();
        $this->klaim(2, ['by_pengajuan' => 2500000]);
        $k = $this->ringkasan()->viewData('stats')['kartu'];

        $this->assertSame(4500000.0, $k['tertahan']['n']);
        $this->assertSame($k['pending']['rp'], $k['tertahan']['n']);
    }

    public function test_panel_bertindak_hitung_dan_hijau_saat_nol(): void
    {
        $this->dataset();
        $res = $this->ringkasan();
        $this->assertSame(['pending14' => 0, 'belum7' => 2], array_intersect_key($res->viewData('stats')['aksi'], ['pending14' => 1, 'belum7' => 1]));
        $res->assertSee('data-aksi="pending14" data-aksi-aman', false)->assertSee('Tidak ada yang perlu dikejar');

        cache()->flush();
        $this->klaim(2, ['status_changed_at' => '2026-11-03 08:00:00']); // 15 hari
        $this->klaim(2, ['status_changed_at' => '2026-11-04 08:00:00']); // 14 hari: belum lewat
        $this->sep('SEPLAMA', ['tgl_plg_sep' => '2026-11-10']); // 8 hari
        $this->sep('SEPBARU', ['tgl_plg_sep' => '2026-11-11']); // 7 hari: belum lewat
        $this->sep('SEPLAMA2', ['tgl_sep' => '2026-10-20']); // tanpa tgl pulang, pakai tgl SEP
        $res = $this->ringkasan();
        $a = $res->viewData('stats')['aksi'];

        $this->assertSame(1, $a['pending14']);
        $this->assertSame(4, $a['belum7']); // SEPBELUM1, SEPBELUM2 (tgl SEP 3 Okt), SEPLAMA, SEPLAMA2; SEPBARU belum lewat 7 hari
        $res->assertDontSee('Tidak ada yang perlu dikejar')->assertSee('Pending lebih dari 14 hari')->assertSee('Lihat daftar');

        $daftar = $this->actingAs($this->koor)->get($res->viewData('links')['aksi']['pending14']);
        $this->assertSame(1, $daftar->viewData('rows')->total());
    }

    public function test_status_sinkron_jumlah_berubah_hari_itu(): void
    {
        $this->dataset();
        VclaimSyncRun::create(['jenis' => 'klaim', 'status' => 'ok', 'jumlah' => 40]);
        VclaimSyncRun::create(['jenis' => 'klaim', 'status' => 'ok', 'jumlah' => 2]);
        VclaimSyncRun::create(['jenis' => 'kunjungan', 'status' => 'gagal', 'error' => 'Timeout VClaim']);
        VclaimKlaimStatusHistory::create(['no_sep' => 'SEPA', 'status_lama' => 1, 'status_baru' => 2, 'terlihat_at' => now()]);
        VclaimKlaimStatusHistory::create(['no_sep' => 'SEPB', 'status_lama' => 2, 'status_baru' => 3, 'terlihat_at' => now()]);
        VclaimKlaimStatusHistory::create(['no_sep' => 'SEPC', 'status_lama' => null, 'status_baru' => 1, 'terlihat_at' => now()]);
        VclaimKlaimStatusHistory::create(['no_sep' => 'SEPD', 'status_lama' => 1, 'status_baru' => 3, 'terlihat_at' => now()->subDay()]);

        $this->ringkasan()->assertSee('42 SEP dicek, 2 status berubah')->assertSee('SEP baru')
            ->assertSee('Tarikan terakhir gagal: Timeout VClaim')->assertSee('bg-triage-red', false);
    }

    public function test_larangan_kata_tidak_muncul(): void
    {
        $this->dataset();
        $this->klaim(2, ['status_changed_at' => '2026-10-01 08:00:00']);
        $html = mb_strtolower(strip_tags($this->ringkasan()->getContent()));
        foreach (['penghasilan', 'naik', 'fraud', 'upcoding', 'uplift'] as $kata) {
            $this->assertStringNotContainsString($kata, $html, "Kata terlarang: $kata");
        }
    }
}
