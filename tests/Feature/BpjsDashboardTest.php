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
use Illuminate\Support\Facades\DB;
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
        $res->assertSee('+1 vs bulan lalu')->assertDontSee('Perlu impor umpan balik BPJS')->assertSee('Sumber: VClaim Monitoring BPJS');
    }

    public function test_tidak_layak_terpisah_dari_pending(): void
    {
        $this->dataset();
        $this->klaim(2, ['status_teks' => 'Klaim Tidak Layak', 'by_pengajuan' => 7000000]);
        $this->klaim(2, ['status_teks' => 'Klaim Tidak Layak', 'by_pengajuan' => 3000000]);
        $this->klaim(2, ['status_teks' => 'Klaim Dispute', 'by_pengajuan' => 500000]);
        $res = $this->ringkasan();
        $s = $res->viewData('stats');

        $this->assertSame(2, $s['kartu']['pending']['n']);
        $this->assertSame(3, $s['kartu']['tolak']['n']);
        $this->assertSame(10500000.0, $s['kartu']['tolak']['rp']);
        $this->assertSame(2, $s['aksi']['tidak_layak']);
        $this->assertSame(10000000.0, $s['aksi']['tidak_layak_rp']);
        $this->assertStringContainsString('2 tidak layak', BpjsStats::teksKalimat($s['kalimat']));
        $this->assertSame(2, $s['tren']['tidak_layak'][11]);
        $this->assertSame(2, $s['tren']['pending'][11]);
        $res->assertSee('data-kartu="tolak"', false)->assertSee('status=tolak', false)
            ->assertSee('Alasan tidak tersedia di VClaim. Lihat umpan balik verifikator.')
            ->assertSee('data-aksi="tidak_layak"', false)->assertSee('BPJS menolak klaim ini.');
    }

    public function test_panel_tidak_layak_tersembunyi_saat_nol(): void
    {
        $this->dataset();
        $res = $this->ringkasan();
        $res->assertDontSee('data-aksi="tidak_layak"', false)->assertDontSee('tidak layak,');
        $this->assertSame(0, $res->viewData('stats')['kartu']['tolak']['n']);
    }

    public function test_pct_pending_severity_tanpa_tidak_layak(): void
    {
        $this->klaim(2, ['inacbg_kode' => 'I-4-10-III', 'status_teks' => 'Klaim Pending', 'status_changed_at' => now()->subDays(3)]);
        $this->klaim(2, ['inacbg_kode' => 'I-4-10-III', 'status_teks' => 'Klaim Tidak Layak', 'status_changed_at' => now()->subDays(3)]);
        $this->klaim(3, ['inacbg_kode' => 'I-4-10-III']);
        $this->klaim(3, ['inacbg_kode' => 'I-4-10-III']);
        $a = $this->ringkasan()->viewData('stats')['analisis'];

        $this->assertSame(25.0, $a['severity']['kartu']['III']['pct_pending']);
        $row = $a['severity']['iii_pending']['items'][0];
        $this->assertSame(1, $row['nilai']);
        $this->assertSame('25,0% tidak layak', $row['chip_tl']);
        $this->assertSame(1, $a['pending']['n']);
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
        $this->assertSame([2, 2, 0, 0, 2, 0], $d['donut_status']['counts']);
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
        $res->assertSee('Sudah pulang, tagihan belum tercatat di BPJS')->assertSee('klaimnya belum sampai ke BPJS')
            ->assertSee('Cek di E-Klaim apakah sudah diajukan')->assertSee('&gt;30 hari (dahulukan)', false)->assertSee('8–30 hari');
        $kartu = $res->viewData('stats')['kartu']['belum']['label'];
        $this->assertSame('Sudah pulang, tagihan belum tercatat di BPJS', $kartu);
        $html = $res->getContent();
        $i = strpos($html, 'data-kartu="belum"');
        $this->assertStringNotContainsStringIgnoringCase('tidak layak', substr($html, $i, strpos($html, '</a>', $i) - $i));

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

    public function test_parse_severity_dan_rawat_jalan_tidak_berlaku(): void
    {
        $this->assertSame('I', BpjsStats::severity('K-1-14-I', 'Rawat Inap'));
        $this->assertSame('II', BpjsStats::severity('K-1-14-II', '1'));
        $this->assertSame('III', BpjsStats::severity('I-4-10-III', 'Rawat Inap'));
        $this->assertSame('tidak berlaku', BpjsStats::severity('K-1-14-I', 'Rawat Jalan'));
        $this->assertSame('tidak berlaku', BpjsStats::severity('Q-5-44-0', 'Rawat Inap'));

        $this->klaim(2, ['inacbg_kode' => 'I-4-10-III']);
        $this->klaim(3, ['inacbg_kode' => 'I-4-10-III']);
        $this->klaim(3, ['inacbg_kode' => 'K-1-14-II']);
        $this->klaim(3, ['inacbg_kode' => 'K-1-14-I']);
        $this->klaim(2, ['inacbg_kode' => 'K-1-14-I', 'jns_pelayanan' => 'Rawat Jalan']);
        $sv = $this->ringkasan()->viewData('stats')['analisis']['severity'];

        $this->assertSame([1, 1, 2], [$sv['kartu']['I']['n'], $sv['kartu']['II']['n'], $sv['kartu']['III']['n']]);
        $this->assertSame(4, $sv['total_inap']);
        $this->assertTrue($sv['kartu']['III']['tinggi']);
        $this->assertSame('I-4-10-III', $sv['iii_pending']['items'][0]['kode']);
        $this->assertNull($this->ringkasan(['jenis' => 'jalan'])->viewData('stats')['analisis']['severity']);
    }

    public function test_rank_list_urut_dan_kategori_lain(): void
    {
        $rows = [];
        foreach (range(1, 9) as $i) {
            $rows[] = ['kode' => "K$i", 'nama' => '', 'nilai' => $i, 'klaim' => $i, 'chip' => null, 'filter' => []];
        }
        $r = BpjsStats::peringkat($rows);
        $this->assertSame(['K9', 'K8', 'K7', 'K6', 'K5', 'K4', 'K3'], array_column($r['items'], 'kode'));
        $this->assertSame([9, 45, 2, 3], [$r['kategori'], $r['klaim'], $r['lain_kategori'], $r['lain_klaim']]);

        foreach (range(1, 9) as $i) {
            foreach (range(1, $i) as $_) {
                $this->klaim(3, ['inacbg_kode' => "A-1-0$i-I", 'inacbg_nama' => 'KELOMPOK UJI']);
            }
        }
        $this->ringkasan()->assertSee('+ 2 kategori lain · 3 klaim')->assertSee('9 kategori · 45 klaim')->assertSee('Kelompok uji');
    }

    public function test_kategori_diagnosis_nama_master_dan_pending_min_5(): void
    {
        DB::table('icd_codes')->insert([
            ['system' => 'icd10', 'code' => 'A09', 'title' => 'Diarrhoea and gastroenteritis', 'created_at' => now(), 'updated_at' => now()],
            ['system' => 'icd10', 'code' => 'A09.9', 'title' => 'Gastroenteritis unspecified', 'created_at' => now(), 'updated_at' => now()],
            ['system' => 'icd10', 'code' => 'J18.9', 'title' => 'PNEUMONIA, UNSPECIFIED', 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (range(1, 5) as $i) { // A09: 5 kasus, 1 pending
            $this->sep("SEPA$i", ['diagnosa' => 'A09.9 - Gastroenteritis']);
            $this->klaim($i === 1 ? 2 : 3, ['no_sep' => "SEPA$i"]);
        }
        foreach (range(1, 4) as $i) { // J18: 4 kasus, semua pending -> tidak masuk "pending tertinggi"
            $this->sep("SEPJ$i", ['diagnosa' => 'J18.9']);
            $this->klaim(2, ['no_sep' => "SEPJ$i"]);
        }
        $dx = $this->ringkasan()->viewData('stats')['analisis']['diagnosis'];

        $kat = collect($dx['kategori']['items'])->keyBy('kode');
        $this->assertSame('Diarrhoea and gastroenteritis', $kat['A09']['nama']);
        $this->assertSame('Pneumonia, unspecified', $kat['J18']['nama']);
        $this->assertSame('20,0% pending', $kat['A09']['chip']);
        $this->assertNull($kat['J18']['chip']);
        $this->assertSame(['A09'], array_column($dx['pending_tinggi']['items'], 'kode'));
        $this->assertSame(1, $dx['pending_tinggi']['items'][0]['nilai']);
    }

    public function test_los_dan_readmisi_30_31_hari(): void
    {
        $this->klaim(3, ['tgl_sep' => '2026-10-03', 'tgl_pulang' => '2026-10-10']);
        $this->klaim(3, ['tgl_sep' => '2026-10-01', 'tgl_pulang' => '2026-10-04']);
        $this->klaim(3, ['tgl_sep' => '2026-10-09', 'tgl_pulang' => '2026-10-10', 'jns_pelayanan' => 'Rawat Jalan']);

        $this->sep('R1', ['no_kartu' => '0001', 'tgl_sep' => '2026-09-01', 'tgl_plg_sep' => '2026-09-10']);
        $this->sep('R2', ['no_kartu' => '0001', 'tgl_sep' => '2026-10-10']); // 30 hari -> readmisi
        $this->sep('R3', ['no_kartu' => '0002', 'tgl_sep' => '2026-09-01', 'tgl_plg_sep' => '2026-09-09']);
        $this->sep('R4', ['no_kartu' => '0002', 'tgl_sep' => '2026-10-10']); // 31 hari -> bukan
        $this->sep('R5', ['no_kartu' => '0003', 'tgl_sep' => '2026-09-25', 'tgl_plg_sep' => '2026-09-28', 'jns_pelayanan' => 'Rawat Jalan']);
        $this->sep('R6', ['no_kartu' => '0003', 'tgl_sep' => '2026-10-02']); // sebelumnya rawat jalan -> bukan

        $m = $this->ringkasan()->viewData('stats')['analisis']['mutu'];
        $this->assertEquals(5.0, $m['los']['median_kini']);
        $this->assertEquals(5.0, $m['los']['rata_kini']);
        $this->assertSame(1, $m['readmisi']['n']);
        $this->assertSame(3, $m['readmisi']['dasar']);
        $this->assertSame(33.3, $m['readmisi']['pct']);
    }

    public function test_cermat_n_kurang_dari_20_tampil_teks(): void
    {
        $this->dataset();
        $res = $this->ringkasan();
        $kel = $res->viewData('stats')['analisis']['cermat']['kelompok'];
        $this->assertFalse($kel['tidak']['cukup']);
        $this->assertNull($kel['tidak']['pct']);
        $res->assertSee('Data belum cukup untuk dibandingkan')->assertSee('data-golive', false);
    }

    public function test_klik_baris_membawa_filter(): void
    {
        $this->klaim(2, ['inacbg_kode' => 'I-4-10-III', 'nama' => 'PASIEN BERAT']);
        $this->klaim(2, ['inacbg_kode' => 'K-1-14-III', 'nama' => 'PASIEN LAIN']);
        $html = $this->ringkasan()->getContent();
        preg_match('#href="([^"]+)"[^>]*data-rank-row="I-4-10-III"#', $html, $m);
        $this->assertNotEmpty($m);
        $url = html_entity_decode($m[1]);
        foreach (['tab=klaim', 'status=pending', 'inacbg=I-4-10-III', 'dari=2026-10-01', 'sampai=2026-10-31'] as $p) {
            $this->assertStringContainsString($p, $url);
        }
        $this->actingAs($this->koor)->get($url)->assertOk()->assertSee('PASIEN BERAT')->assertDontSee('PASIEN LAIN');
    }

    public function test_tata_letak_baru_kartu_dan_tabel(): void
    {
        $this->dataset();
        $res = $this->ringkasan();
        $k = $res->viewData('stats')['kartu'];
        $html = $res->getContent();

        $res->assertSee('Pusat kendali klaim BPJS')->assertSee('Tren status klaim')->assertSee('Status klaim')->assertSee('Nilai tertahan')
            ->assertSee('Severity rawat inap')->assertSee('Perlu tindak lanjut')->assertSee('Klaim terbaru')->assertSee('Lihat semua')
            ->assertSee('id="chart-spark"', false)->assertSee('data-tren-rentang', false);
        foreach (['klaim', 'pending', 'tolak', 'belum'] as $kk) {
            $this->assertMatchesRegularExpression('#data-kartu="' . $kk . '".*?data-stat>' . preg_quote(number_format($k[$kk]['n'], 0, ',', '.')) . '<#s', $html);
        }
        $this->assertStringContainsString('title="' . BpjsStats::rupiahPenuh($k['tertahan']['n']) . '"', $html);
        $this->assertMatchesRegularExpression('#overflow-x-auto[^"]*"[^>]*>\s*<table#', $html);
        $this->assertStringContainsString('data-terbaru="SEPA"', $html);
    }
}
