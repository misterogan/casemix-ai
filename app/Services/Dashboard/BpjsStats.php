<?php

namespace App\Services\Dashboard;

use App\Models\VclaimKlaim;
use App\Models\VclaimKlaimStatusHistory;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Ringkasan Klaim BPJS dari tabel vclaim_* saja (tanpa AI, tanpa panggilan BPJS). Cache 10 menit per filter. */
class BpjsStats
{
    public const STATUS = ['proses' => 1, 'pending' => 2, 'klaim' => 3];

    public const STATUS_LABEL = ['proses' => 'Proses verifikasi', 'pending' => 'Pending verifikasi', 'klaim' => 'Klaim layak'];

    public const JENIS_LABEL = ['inap' => 'Rawat inap', 'jalan' => 'Rawat jalan'];

    public function get(string $periode, string $jenis): array
    {
        return Cache::remember("bpjs-stats:v2:$periode:$jenis", 600, fn () => $this->build($periode, $jenis));
    }

    public static function jenis(Builder $q, string $jenis): void
    {
        if ($jenis === 'inap') {
            $q->where(fn ($w) => $w->where('jns_pelayanan', '1')->orWhere('jns_pelayanan', 'like', '%inap%'));
        } elseif ($jenis === 'jalan') {
            $q->where(fn ($w) => $w->where('jns_pelayanan', '2')->orWhere('jns_pelayanan', 'like', '%jalan%'));
        }
    }

    public static function kelompokJenis(?string $jns): ?string
    {
        $j = strtolower((string) $jns);

        return $j === '1' || str_contains($j, 'inap') ? 'inap' : ($j === '2' || str_contains($j, 'jalan') ? 'jalan' : null);
    }

    private function build(string $periode, string $jenis): array
    {
        $awal = Carbon::createFromFormat('!Y-m', $periode)->startOfMonth();
        $kini = $this->bulan($awal, $jenis);
        $lalu = $this->bulan($awal->copy()->subMonth(), $jenis);

        $total = $kini['proses'] + $kini['pending'] + $kini['klaim'];
        $pct = fn ($n, $d) => $d > 0 ? round($n * 100 / $d, 1) : null;
        $kartu = [];
        foreach ([
            'sep' => ['SEP terbit', null, true],
            'proses' => ['Proses verifikasi', $total, null],
            'pending' => ['Pending verifikasi', $total, false],
            'klaim' => ['Klaim layak (diterima)', $total, true],
            'belum' => ['Belum masuk data klaim', $kini['sep'], false],
            'kurang' => ['Disetujui kurang dari diajukan', $kini['klaim'], false],
            'tertahan' => ['Nilai tertahan di pending', null, false],
        ] as $k => [$label, $dasar, $naikBaik]) {
            if ($k === 'tertahan') {
                $kartu[$k] = ['label' => $label, 'n' => $kini['rp']['pending'], 'pct' => null, 'rp' => null,
                    'delta' => $kini['rp']['pending'] - $lalu['rp']['pending'], 'naik_baik' => false, 'rupiah' => true];

                continue;
            }
            $kartu[$k] = ['label' => $label, 'n' => $kini[$k], 'pct' => $dasar === null ? null : $pct($kini[$k], $dasar),
                'rp' => $kini['rp'][$k] ?? null, 'delta' => $kini[$k] - $lalu[$k], 'naik_baik' => $naikBaik];
        }

        return [
            'periode' => $periode, 'jenis' => $jenis, 'kosong' => $kini['sep'] === 0 && $total === 0,
            'kartu' => $kartu, 'total_klaim' => $total,
            'kalimat' => $this->kalimat($awal, $kini, $total),
            'aksi' => $this->aksi($awal, $jenis),
            'donut_status' => ['keys' => array_keys(self::STATUS_LABEL), 'labels' => array_values(self::STATUS_LABEL),
                'counts' => [$kini['proses'], $kini['pending'], $kini['klaim']],
                'rp' => [$kini['rp']['proses'], $kini['rp']['pending'], $kini['rp']['klaim']]],
            'donut_jenis' => $this->donutJenis($awal, $jenis),
            'tren' => $this->tren($awal, $jenis),
            'funnel' => [
                ['label' => 'SEP terbit', 'n' => $kini['sep']],
                ['label' => 'Masuk data klaim', 'n' => $kini['sep'] - $kini['belum']],
                ['label' => 'Klaim layak', 'n' => $kini['klaim']],
            ],
        ];
    }

    private function klaimBulan(Carbon $awal, string $jenis): Builder
    {
        $q = VclaimKlaim::query()->whereBetween('tgl_pulang', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()]);
        self::jenis($q, $jenis);

        return $q;
    }

    private function sepBulan(Carbon $awal, string $jenis): Builder
    {
        $q = VclaimKunjungan::query()->whereBetween('tgl_sep', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()]);
        self::jenis($q, $jenis);

        return $q;
    }

    private function bulan(Carbon $awal, string $jenis): array
    {
        $rows = $this->klaimBulan($awal, $jenis)->toBase()
            ->selectRaw('status_kode, COUNT(*) n, COALESCE(SUM(by_pengajuan),0) rp, COALESCE(SUM(by_setujui),0) setuju,
                SUM(CASE WHEN status_kode = 3 AND by_setujui < by_pengajuan THEN 1 ELSE 0 END) kurang,
                COALESCE(SUM(CASE WHEN status_kode = 3 AND by_setujui < by_pengajuan THEN by_pengajuan ELSE 0 END),0) rp_kurang')
            ->groupBy('status_kode')->get()->keyBy('status_kode');
        $out = ['rp' => []];
        foreach (self::STATUS as $k => $kode) {
            $out[$k] = (int) ($rows[$kode]->n ?? 0);
            $out['rp'][$k] = (float) ($rows[$kode]->rp ?? 0);
        }
        $out['setuju'] = (float) ($rows[3]->setuju ?? 0);
        $out['kurang'] = (int) ($rows[3]->kurang ?? 0);
        $out['rp']['kurang'] = (float) ($rows[3]->rp_kurang ?? 0);
        $out['sep'] = $this->sepBulan($awal, $jenis)->count();
        $out['belum'] = $this->sepBulan($awal, $jenis)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('vclaim_klaim')->whereColumn('vclaim_klaim.no_sep', 'vclaim_kunjungan.no_sep'))->count();

        return $out;
    }

    public const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** Rupiah ringkas: Rp950 rb, Rp1,2 jt, Rp4,2 M. */
    public static function rupiahRingkas(float $v): string
    {
        $a = abs($v);
        [$bagi, $suf, $des] = $a >= 1e9 ? [1e9, ' M', 1] : ($a >= 1e6 ? [1e6, ' jt', 1] : ($a >= 1e3 ? [1e3, ' rb', 0] : [1, '', 0]));
        $s = number_format($v / $bagi, $des, ',', '.');

        return 'Rp' . (str_ends_with($s, ',0') ? substr($s, 0, -2) : $s) . $suf;
    }

    public static function rupiahPenuh(float $v): string
    {
        return 'Rp' . number_format($v, 0, ',', '.');
    }

    /** Kalimat ringkasan (tanpa AI): daftar potongan teks; potongan 'b' ditebalkan, 'title' berisi nilai penuh. */
    private function kalimat(Carbon $awal, array $k, int $total): array
    {
        $n = fn (int $x) => ['b' => number_format($x, 0, ',', '.')];
        $r = fn (float $x) => ['b' => self::rupiahRingkas($x), 'title' => self::rupiahPenuh($x)];

        return [
            ['t' => self::BULAN[$awal->month - 1] . ' ' . $awal->year . ': '], $n($total),
            ['t' => ' klaim diajukan senilai '], $r($k['rp']['proses'] + $k['rp']['pending'] + $k['rp']['klaim']), ['t' => '. '],
            $n($k['klaim']), ['t' => ' layak ('], $r($k['setuju']), ['t' => ' disetujui), '],
            $n($k['pending']), ['t' => ' pending dengan '], $r($k['rp']['pending']), ['t' => ' tertahan, '],
            $n($k['proses']), ['t' => ' masih diverifikasi.'],
        ];
    }

    public static function teksKalimat(array $kalimat): string
    {
        return implode('', array_map(fn ($p) => $p['t'] ?? $p['b'], $kalimat));
    }

    /** Pending > 14 hari di status, dan SEP belum terlihat di data klaim > 7 hari sejak pulang. */
    private function aksi(Carbon $awal, string $jenis): array
    {
        $batas14 = now()->startOfDay()->subDays(14)->toDateString();
        $batas7 = now()->startOfDay()->subDays(7)->toDateString();
        $pending = $this->klaimBulan($awal, $jenis)->where('status_kode', 2)->whereNotNull('status_changed_at')
            ->whereDate('status_changed_at', '<', $batas14);

        return [
            'pending14' => (clone $pending)->count(),
            'pending14_rp' => (float) $pending->sum('by_pengajuan'),
            'belum7' => $this->sepBulan($awal, $jenis)->whereRaw('COALESCE(tgl_plg_sep, tgl_sep) < ?', [$batas7])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('vclaim_klaim')->whereColumn('vclaim_klaim.no_sep', 'vclaim_kunjungan.no_sep'))->count(),
        ];
    }

    /** Status sinkron per jenis tarikan: run terakhir, jumlah hari itu. Tidak di-cache. */
    public static function sinkron(): array
    {
        $out = [];
        foreach (['klaim', 'kunjungan'] as $j) {
            $last = VclaimSyncRun::where('jenis', $j)->latest('id')->first();
            if (! $last) {
                $out[$j] = null;

                continue;
            }
            $hari = $last->created_at->copy();
            $d = ['waktu' => $hari, 'ok' => $last->status === 'ok', 'error' => $last->error ? mb_substr($last->error, 0, 120) : null];
            if ($j === 'klaim') {
                $d['dicek'] = (int) VclaimSyncRun::where('jenis', 'klaim')->where('status', 'ok')->whereDate('created_at', $hari->toDateString())->sum('jumlah');
                $d['berubah'] = VclaimKlaimStatusHistory::whereNotNull('status_lama')->whereDate('terlihat_at', $hari->toDateString())->count();
            } else {
                $d['baru'] = VclaimKunjungan::whereDate('created_at', $hari->toDateString())->count();
            }
            $out[$j] = $d;
        }

        return $out;
    }

    private function donutJenis(Carbon $awal, string $jenis): array
    {
        $agg = ['inap' => [0, 0.0], 'jalan' => [0, 0.0]];
        $rows = $this->klaimBulan($awal, $jenis)->toBase()
            ->selectRaw('jns_pelayanan, COUNT(*) n, COALESCE(SUM(by_pengajuan),0) rp')->groupBy('jns_pelayanan')->get();
        foreach ($rows as $r) {
            if ($g = self::kelompokJenis($r->jns_pelayanan)) {
                $agg[$g][0] += (int) $r->n;
                $agg[$g][1] += (float) $r->rp;
            }
        }

        return ['keys' => array_keys($agg), 'labels' => array_values(self::JENIS_LABEL),
            'counts' => array_column($agg, 0), 'rp' => array_column($agg, 1)];
    }

    private function tren(Carbon $awal, string $jenis): array
    {
        $mulai = $awal->copy()->subMonths(11);
        $nama = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $t = ['bulan' => [], 'label' => [], 'proses' => [], 'pending' => [], 'klaim' => [], 'pct_pending' => [], 'diajukan' => [], 'disetujui' => []];
        $idx = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $mulai->copy()->addMonths($i);
            $idx[$m->format('Y-m')] = $i;
            $t['bulan'][] = $m->format('Y-m');
            $t['label'][] = $nama[$m->month - 1] . ' ' . $m->format('y');
            foreach (['proses', 'pending', 'klaim', 'diajukan', 'disetujui'] as $k) {
                $t[$k][] = 0;
            }
        }

        $q = VclaimKlaim::query()->whereBetween('tgl_pulang', [$mulai->toDateString(), $awal->copy()->endOfMonth()->toDateString()]);
        self::jenis($q, $jenis);
        $rows = $q->toBase()->selectRaw('tgl_pulang, status_kode, COUNT(*) n, COALESCE(SUM(by_pengajuan),0) aju, COALESCE(SUM(by_setujui),0) setuju')
            ->groupBy('tgl_pulang', 'status_kode')->get();
        $kunci = array_flip(self::STATUS);
        foreach ($rows as $r) {
            $i = $idx[substr((string) $r->tgl_pulang, 0, 7)] ?? null;
            $k = $kunci[(int) $r->status_kode] ?? null;
            if ($i === null || $k === null) {
                continue;
            }
            $t[$k][$i] += (int) $r->n;
            if ($k === 'klaim') {
                $t['diajukan'][$i] += (float) $r->aju;
                $t['disetujui'][$i] += (float) $r->setuju;
            }
        }
        for ($i = 0; $i < 12; $i++) {
            $tot = $t['proses'][$i] + $t['pending'][$i] + $t['klaim'][$i];
            $t['pct_pending'][] = $tot > 0 ? round($t['pending'][$i] * 100 / $tot, 1) : 0;
        }

        $live = config('casemix.go_live_date');
        $t['go_live'] = $live && preg_match('/^\d{4}-\d{2}/', (string) $live) ? ($idx[substr((string) $live, 0, 7)] ?? null) : null;

        return $t;
    }
}
