<?php

namespace App\Services\Dashboard;

use App\Models\VclaimKlaim;
use App\Models\VclaimKlaimStatusHistory;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Ringkasan Klaim BPJS dari tabel vclaim_* saja (tanpa AI, tanpa panggilan BPJS). Cache 10 menit per filter. */
class BpjsStats
{
    /** Kategori status (kolom vclaim_klaim.status_kategori). */
    public const STATUS = ['proses' => 'proses', 'pending' => 'pending', 'tidak_layak' => 'tidak_layak', 'dispute' => 'dispute', 'klaim' => 'klaim', 'lainnya' => 'lainnya'];

    public const STATUS_LABEL = ['proses' => 'Proses verifikasi', 'pending' => 'Pending verifikasi', 'tidak_layak' => 'Tidak layak',
        'dispute' => 'Dispute', 'klaim' => 'Klaim layak', 'lainnya' => 'Lainnya'];

    public const JENIS_LABEL = ['inap' => 'Rawat inap', 'jalan' => 'Rawat jalan'];

    public function get(string $periode, string $jenis): array
    {
        return Cache::remember("bpjs-stats:v4-kategori:$periode:$jenis", 600, fn () => $this->build($periode, $jenis));
    }

    public static function jenis(Builder $q, string $jenis): void
    {
        if ($jenis === 'inap') {
            $q->where(fn ($w) => $w->where('jns_pelayanan', '1')->orWhere('jns_pelayanan', 'like', '%inap%'));
        } elseif ($jenis === 'jalan') {
            $q->where(fn ($w) => $w->where('jns_pelayanan', '2')->orWhere('jns_pelayanan', 'like', '%jalan%'));
        }
    }

    /** Severity INA-CBG dari akhiran kode (-I/-II/-III); hanya rawat inap, selain itu "tidak berlaku". */
    public static function severity(?string $kode, ?string $jns): string
    {
        if (self::kelompokJenis($jns) !== 'inap') {
            return 'tidak berlaku';
        }

        return preg_match('/-(III|II|I)$/', strtoupper(trim((string) $kode)), $m) ? $m[1] : 'tidak berlaku';
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

        $total = array_sum(array_map(fn ($k) => $kini[$k], array_keys(self::STATUS)));
        $pct = fn ($n, $d) => $d > 0 ? round($n * 100 / $d, 1) : null;
        $kartu = [];
        foreach ([
            'sep' => ['SEP terbit', null, true],
            'proses' => ['Proses verifikasi', $total, null],
            'pending' => ['Pending verifikasi', $total, false],
            'klaim' => ['Klaim layak (diterima)', $total, true],
            'belum' => ['Belum masuk data klaim', $kini['sep'], false],
            'kurang' => ['Disetujui kurang dari diajukan', $kini['klaim'], false],
            'tolak' => ['Tidak layak / dispute', $total, false],
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
                'counts' => array_map(fn ($k) => $kini[$k], array_keys(self::STATUS_LABEL)),
                'rp' => array_map(fn ($k) => $kini['rp'][$k], array_keys(self::STATUS_LABEL))],
            'donut_jenis' => $this->donutJenis($awal, $jenis),
            'tren' => $this->tren($awal, $jenis),
            'funnel' => [
                ['label' => 'SEP terbit', 'n' => $kini['sep']],
                ['label' => 'Masuk data klaim', 'n' => $kini['sep'] - $kini['belum']],
                ['label' => 'Klaim layak', 'n' => $kini['klaim']],
            ],
            'analisis' => $this->analisis($awal, $jenis),
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
            ->selectRaw("status_kategori, COUNT(*) n, COALESCE(SUM(by_pengajuan),0) rp, COALESCE(SUM(by_setujui),0) setuju,
                SUM(CASE WHEN status_kategori = 'klaim' AND by_setujui < by_pengajuan THEN 1 ELSE 0 END) kurang,
                COALESCE(SUM(CASE WHEN status_kategori = 'klaim' AND by_setujui < by_pengajuan THEN by_pengajuan ELSE 0 END),0) rp_kurang")
            ->groupBy('status_kategori')->get()->keyBy('status_kategori');
        $out = ['rp' => []];
        foreach (self::STATUS as $k => $kat) {
            $out[$k] = (int) ($rows[$kat]->n ?? 0);
            $out['rp'][$k] = (float) ($rows[$kat]->rp ?? 0);
        }
        $out['tolak'] = $out['tidak_layak'] + $out['dispute'];
        $out['rp']['tolak'] = $out['rp']['tidak_layak'] + $out['rp']['dispute'];
        $out['setuju'] = (float) ($rows['klaim']->setuju ?? 0);
        $out['kurang'] = (int) ($rows['klaim']->kurang ?? 0);
        $out['rp']['kurang'] = (float) ($rows['klaim']->rp_kurang ?? 0);
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
            ['t' => ' klaim diajukan senilai '], $r(array_sum(array_map(fn ($s) => $k['rp'][$s], array_keys(self::STATUS)))), ['t' => '. '],
            $n($k['klaim']), ['t' => ' layak ('], $r($k['setuju']), ['t' => ' disetujui), '],
            $n($k['pending']), ['t' => ' pending dengan '], $r($k['rp']['pending']), ['t' => ' tertahan, '],
            ...($k['tidak_layak'] > 0 ? [$n($k['tidak_layak']), ['t' => ' tidak layak, ']] : []),
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
        $pending = $this->klaimBulan($awal, $jenis)->where('status_kategori', 'pending')->whereNotNull('status_changed_at')
            ->whereDate('status_changed_at', '<', $batas14);

        return [
            'pending14' => (clone $pending)->count(),
            'pending14_rp' => (float) $pending->sum('by_pengajuan'),
            'tidak_layak' => $this->klaimBulan($awal, $jenis)->where('status_kategori', 'tidak_layak')->count(),
            'tidak_layak_rp' => (float) $this->klaimBulan($awal, $jenis)->where('status_kategori', 'tidak_layak')->sum('by_pengajuan'),
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
        $t = ['bulan' => [], 'label' => [], 'pct_pending' => [], 'diajukan' => [], 'disetujui' => []] + array_fill_keys(array_keys(self::STATUS), []);
        $idx = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $mulai->copy()->addMonths($i);
            $idx[$m->format('Y-m')] = $i;
            $t['bulan'][] = $m->format('Y-m');
            $t['label'][] = $nama[$m->month - 1] . ' ' . $m->format('y');
            foreach ([...array_keys(self::STATUS), 'diajukan', 'disetujui'] as $k) {
                $t[$k][] = 0;
            }
        }

        $q = VclaimKlaim::query()->whereBetween('tgl_pulang', [$mulai->toDateString(), $awal->copy()->endOfMonth()->toDateString()]);
        self::jenis($q, $jenis);
        $rows = $q->toBase()->selectRaw('tgl_pulang, status_kategori, COUNT(*) n, COALESCE(SUM(by_pengajuan),0) aju, COALESCE(SUM(by_setujui),0) setuju')
            ->groupBy('tgl_pulang', 'status_kategori')->get();
        $kunci = array_flip(self::STATUS);
        foreach ($rows as $r) {
            $i = $idx[substr((string) $r->tgl_pulang, 0, 7)] ?? null;
            $k = $kunci[(string) $r->status_kategori] ?? null;
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
            $tot = array_sum(array_map(fn ($s) => $t[$s][$i], array_keys(self::STATUS)));
            $t['pct_pending'][] = $tot > 0 ? round($t['pending'][$i] * 100 / $tot, 1) : 0;
        }

        $live = config('casemix.go_live_date');
        $t['go_live'] = $live && preg_match('/^\d{4}-\d{2}/', (string) $live) ? ($idx[substr((string) $live, 0, 7)] ?? null) : null;

        return $t;
    }

    /* ===================== Analisis (severity, kasus & tarif, diagnosis, pending, mutu, CERMAT) ===================== */

    public const MIN_KASUS = 5;

    public const MIN_BANDING = 20;

    public const TAMPIL = 7;

    public const CERMAT_LABEL = ['siap' => 'Dianalisa · Siap diajukan', 'belum' => 'Dianalisa · Belum siap', 'tidak' => 'Tidak dianalisa'];

    private const PENDING_C = "SUM(CASE WHEN c.status_kategori = 'pending' THEN 1 ELSE 0 END)";

    private const TL_C = "SUM(CASE WHEN c.status_kategori = 'tidak_layak' THEN 1 ELSE 0 END)";

    /** Teks chip "% tidak layak" untuk baris peringkat; null bila 0. */
    private static function chipTl($tl, $n): ?string
    {
        return (int) $tl > 0 ? self::pctTeks(self::pct((int) $tl, (int) $n)) . ' tidak layak' : null;
    }

    private static function inapSql(string $t = ''): string
    {
        $c = ($t ? "$t." : '') . 'jns_pelayanan';

        return "($c = '1' OR LOWER($c) LIKE '%inap%')";
    }

    /** Kolom terhitung severity (tabel tidak diubah). */
    public static function sevSql(string $t = ''): string
    {
        $k = 'UPPER(' . ($t ? "$t." : '') . 'inacbg_kode)';
        $i = self::inapSql($t);

        return "CASE WHEN $i AND $k LIKE '%-III' THEN 'III' WHEN $i AND $k LIKE '%-II' THEN 'II' WHEN $i AND $k LIKE '%-I' THEN 'I' ELSE 'tidak berlaku' END";
    }

    /** Selisih hari (akhir - awal) sebagai SQL, lintas driver. */
    public static function selisihHari(string $akhir, string $awal): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(julianday(date($akhir)) - julianday(date($awal)) AS INTEGER)",
            'pgsql' => "(($akhir)::date - ($awal)::date)",
            default => "DATEDIFF($akhir, $awal)",
        };
    }

    public static function kalimatCase(?string $s): string
    {
        $s = trim((string) $s);

        return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_strtolower(mb_substr($s, 1));
    }

    public static function pctTeks(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 1, ',', '.') . '%';
    }

    /**
     * Daftar peringkat: urut nilai menurun (lalu kode), 7 baris pertama + ringkasan sisanya.
     * Baris: kode, nama, nilai, klaim, chip, filter.
     */
    public static function peringkat(array $rows, array $filter = [], string $format = 'angka', int $tampil = self::TAMPIL): array
    {
        usort($rows, fn ($a, $b) => [$b['nilai'], $a['kode']] <=> [$a['nilai'], $b['kode']]);
        $sisa = array_slice($rows, $tampil);

        return [
            'items' => array_slice($rows, 0, $tampil), 'format' => $format, 'filter' => $filter,
            'kategori' => count($rows), 'klaim' => array_sum(array_column($rows, 'klaim')),
            'lain_kategori' => count($sisa), 'lain_klaim' => array_sum(array_column($sisa, 'klaim')),
        ];
    }

    /** Median dari distribusi [nilai => frekuensi]. */
    public static function median(array $dist): ?float
    {
        ksort($dist);
        $n = array_sum($dist);
        if ($n === 0) {
            return null;
        }
        $ambil = function (int $pos) use ($dist): float {
            $c = 0;
            foreach ($dist as $v => $f) {
                $c += $f;
                if ($c >= $pos) {
                    return (float) $v;
                }
            }

            return 0.0;
        };

        return $n % 2 ? $ambil(intdiv($n, 2) + 1) : ($ambil(intdiv($n, 2)) + $ambil(intdiv($n, 2) + 1)) / 2;
    }

    public static function rata(array $dist): ?float
    {
        $n = array_sum($dist);
        $s = 0;
        foreach ($dist as $v => $f) {
            $s += $v * $f;
        }

        return $n ? round($s / $n, 1) : null;
    }

    /** Nama kategori 3 digit dari icd_codes; bila tidak ada, nama kode lengkap pertama berawalan sama. */
    public static function namaKategori(array $kode): array
    {
        $kode = array_values(array_filter($kode));
        if (! $kode) {
            return [];
        }
        $rows = DB::table('icd_codes')->where('system', 'icd10')
            ->where(function ($w) use ($kode) {
                foreach ($kode as $k) {
                    $w->orWhere('code', 'like', $k . '%');
                }
            })->orderBy('code')->get(['code', 'title']);
        $tepat = [];
        $awal = [];
        foreach ($rows as $r) {
            $c = strtoupper(str_replace('.', '', $r->code));
            $k = substr($c, 0, 3);
            if ($c === $k) {
                $tepat[$k] = self::kalimatCase($r->title);
            }
            $awal[$k] ??= self::kalimatCase($r->title);
        }

        return $tepat + $awal;
    }

    private function klaimC(Carbon $awal, string $jenis)
    {
        $q = DB::table('vclaim_klaim as c')->whereBetween('c.tgl_pulang', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()]);
        if ($jenis === 'inap') {
            $q->whereRaw(self::inapSql('c'));
        } elseif ($jenis === 'jalan') {
            $q->where(fn ($w) => $w->where('c.jns_pelayanan', '2')->orWhere('c.jns_pelayanan', 'like', '%jalan%'));
        }

        return $q;
    }

    private function analisis(Carbon $awal, string $jenis): array
    {
        $inap = $jenis !== 'jalan';

        return [
            'inap_berlaku' => $inap,
            'severity' => $inap ? $this->severityData($awal, $jenis) : null,
            'tarif' => $this->tarifData($awal, $jenis),
            'diagnosis' => $this->diagnosisData($awal, $jenis),
            'pending' => $this->pendingData($awal, $jenis),
            'mutu' => $inap ? $this->mutuData($awal, $jenis) : null,
            'cermat' => $this->cermatData($awal, $jenis),
        ];
    }

    private static function pct($n, $d): ?float
    {
        return $d > 0 ? round($n * 100 / $d, 1) : null;
    }

    private function severityData(Carbon $awal, string $jenis): array
    {
        $sev = self::sevSql('c');
        $rows = $this->klaimC($awal, $jenis)->whereRaw(self::inapSql('c'))
            ->selectRaw("$sev sev, COUNT(*) n, " . self::PENDING_C . ' p')->groupBy('sev')->get()->keyBy('sev');
        $totN = (int) $rows->sum('n');
        $rata = self::pct((int) $rows->sum('p'), $totN);
        $kartu = [];
        foreach (['I', 'II', 'III'] as $s) {
            $n = (int) ($rows[$s]->n ?? 0);
            $pp = self::pct((int) ($rows[$s]->p ?? 0), $n);
            $kartu[$s] = ['n' => $n, 'pct_inap' => self::pct($n, $totN), 'pct_pending' => $pp, 'tinggi' => $pp !== null && $rata !== null && $pp > $rata];
        }

        $baris = $this->klaimC($awal, $jenis)->whereRaw(self::inapSql('c'))->whereRaw("UPPER(c.inacbg_kode) LIKE '%-III'")
            ->selectRaw('c.inacbg_kode kode, MAX(c.inacbg_nama) nama, COUNT(*) n, ' . self::PENDING_C . ' p, ' . self::TL_C . ' tl')
            ->groupBy('c.inacbg_kode')->get()->filter(fn ($r) => $r->p > 0)
            ->map(fn ($r) => ['kode' => $r->kode, 'nama' => self::kalimatCase($r->nama), 'nilai' => (int) $r->p, 'klaim' => (int) $r->p,
                'chip' => self::pctTeks(self::pct((int) $r->p, (int) $r->n)) . ' pending', 'chip_tl' => self::chipTl($r->tl, $r->n),
                'filter' => ['status' => 'pending', 'inacbg' => $r->kode]])
            ->values()->all();

        return ['kartu' => $kartu, 'rata_pending' => $rata, 'total_inap' => $totN,
            'iii_pending' => self::peringkat($baris, ['status' => 'pending', 'sev' => 'III'])];
    }

    private function tarifData(Carbon $awal, string $jenis): array
    {
        $rows = $this->klaimC($awal, $jenis)->whereNotNull('c.inacbg_kode')->where('c.inacbg_kode', '<>', '')
            ->selectRaw('c.inacbg_kode kode, MAX(c.inacbg_nama) nama, COUNT(*) n, COALESCE(SUM(c.by_pengajuan),0) rp, ' . self::TL_C . ' tl')->groupBy('c.inacbg_kode')->get();
        $jumlah = $rows->map(fn ($r) => ['kode' => $r->kode, 'nama' => self::kalimatCase($r->nama), 'nilai' => (int) $r->n, 'klaim' => (int) $r->n,
            'chip' => self::rupiahRingkas((float) $r->rp), 'chip_tl' => self::chipTl($r->tl, $r->n), 'filter' => ['inacbg' => $r->kode]])->all();
        $nilai = $rows->map(fn ($r) => ['kode' => $r->kode, 'nama' => self::kalimatCase($r->nama), 'nilai' => (float) $r->rp, 'klaim' => (int) $r->n,
            'chip' => number_format((int) $r->n, 0, ',', '.') . ' klaim', 'filter' => ['inacbg' => $r->kode]])->all();

        $sel = $this->klaimC($awal, $jenis)->whereNotNull('c.inacbg_kode')->whereColumn('c.by_tarif_rs', '>', 'c.by_tarif_gruper')
            ->selectRaw('c.inacbg_kode kode, MAX(c.inacbg_nama) nama, COUNT(*) n, SUM(c.by_tarif_rs - c.by_tarif_gruper) sel')->groupBy('c.inacbg_kode')->get()
            ->map(fn ($r) => ['kode' => $r->kode, 'nama' => self::kalimatCase($r->nama), 'nilai' => (float) $r->sel, 'klaim' => (int) $r->n,
                'chip' => null, 'filter' => ['inacbg' => $r->kode]])->all();

        return ['top_jumlah' => self::peringkat($jumlah), 'top_nilai' => self::peringkat($nilai, [], 'rupiah'),
            'selisih' => self::peringkat($sel, [], 'rupiah')];
    }

    private function diagnosisData(Carbon $awal, string $jenis): array
    {
        $dx = 'UPPER(SUBSTR(TRIM(k.diagnosa),1,3))';
        $rows = $this->klaimC($awal, $jenis)->join('vclaim_kunjungan as k', 'k.no_sep', '=', 'c.no_sep')
            ->whereNotNull('k.diagnosa')->whereRaw("TRIM(k.diagnosa) <> ''")
            ->selectRaw("$dx kode, COUNT(*) n, " . self::PENDING_C . ' p, ' . self::TL_C . ' tl')->groupBy('kode')->get();

        $semua = $rows->map(fn ($r) => ['kode' => $r->kode, 'nama' => '', 'nilai' => (int) $r->n, 'klaim' => (int) $r->n,
            'chip' => $r->n >= self::MIN_KASUS ? self::pctTeks(self::pct((int) $r->p, (int) $r->n)) . ' pending' : null,
            'chip_tl' => self::chipTl($r->tl, $r->n), 'filter' => ['dx' => $r->kode]])->all();
        $tinggi = $rows->filter(fn ($r) => $r->n >= self::MIN_KASUS && $r->p > 0)
            ->map(fn ($r) => ['kode' => $r->kode, 'nama' => '', 'nilai' => (int) $r->p, 'klaim' => (int) $r->p,
                'chip' => self::pctTeks(self::pct((int) $r->p, (int) $r->n)) . ' pending', 'chip_tl' => self::chipTl($r->tl, $r->n),
                'filter' => ['status' => 'pending', 'dx' => $r->kode]])
            ->values()->all();

        $kategori = self::peringkat($semua);
        $pending = self::peringkat($tinggi, ['status' => 'pending']);
        $nama = self::namaKategori(array_unique(array_merge(array_column($kategori['items'], 'kode'), array_column($pending['items'], 'kode'))));
        $isi = fn (array $l) => ['items' => array_map(fn ($i) => ['nama' => $nama[$i['kode']] ?? 'Nama belum ada di master ICD'] + $i, $l['items'])] + $l;

        return ['kategori' => $isi($kategori), 'pending_tinggi' => $isi($pending)];
    }

    private function pendingData(Carbon $awal, string $jenis): array
    {
        $hari = self::selisihHari("'" . now()->toDateString() . "'", 'c.status_changed_at');
        $rows = $this->klaimC($awal, $jenis)->where('c.status_kategori', 'pending')->whereNotNull('c.status_changed_at')
            ->selectRaw("$hari umur, COUNT(*) n")->groupBy('umur')->get();
        $ember = ['0–7 hari' => 0, '8–14 hari' => 0, '15–30 hari' => 0, '>30 hari' => 0];
        $dist = [];
        foreach ($rows as $r) {
            $u = max(0, (int) $r->umur);
            $dist[$u] = ($dist[$u] ?? 0) + (int) $r->n;
            $ember[$u <= 7 ? '0–7 hari' : ($u <= 14 ? '8–14 hari' : ($u <= 30 ? '15–30 hari' : '>30 hari'))] += (int) $r->n;
        }

        $terlama = $this->klaimC($awal, $jenis)->where('c.status_kategori', 'pending')->whereNotNull('c.status_changed_at')
            ->orderBy('c.status_changed_at')->orderBy('c.id')->limit(10)
            ->selectRaw("c.no_sep, c.nama, c.inacbg_kode, c.by_pengajuan, c.tgl_pulang, $hari umur")->get()
            ->map(fn ($r) => (array) $r)->all();

        return ['ember' => $ember, 'n' => array_sum($dist), 'median' => self::median($dist), 'rata' => self::rata($dist), 'terlama' => $terlama];
    }

    private function bulan12(Carbon $awal): array
    {
        $mulai = $awal->copy()->subMonths(11);
        $nama = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $mulai->copy()->addMonths($i);
            $out[$m->format('Y-m')] = $nama[$m->month - 1] . ' ' . $m->format('y');
        }

        return $out;
    }

    private function mutuData(Carbon $awal, string $jenis): array
    {
        $bulan = $this->bulan12($awal);
        $dari = array_key_first($bulan) . '-01';
        $sampai = $awal->copy()->endOfMonth()->toDateString();
        $los = self::selisihHari('c.tgl_pulang', 'c.tgl_sep');

        $rows = DB::table('vclaim_klaim as c')->whereBetween('c.tgl_pulang', [$dari, $sampai])->whereRaw(self::inapSql('c'))->whereNotNull('c.tgl_sep')
            ->selectRaw("SUBSTR(c.tgl_pulang,1,7) bln, $los los, COUNT(*) n")->groupBy('bln', 'los')->get();
        $dist = array_fill_keys(array_keys($bulan), []);
        foreach ($rows as $r) {
            if (isset($dist[$r->bln])) {
                $l = max(0, (int) $r->los);
                $dist[$r->bln][$l] = ($dist[$r->bln][$l] ?? 0) + (int) $r->n;
            }
        }
        $kiniB = $awal->format('Y-m');

        $cbg = $this->klaimC($awal, $jenis)->whereRaw(self::inapSql('c'))->whereNotNull('c.tgl_sep')->whereNotNull('c.inacbg_kode')
            ->selectRaw("c.inacbg_kode kode, MAX(c.inacbg_nama) nama, COUNT(*) n, AVG($los) los")->groupBy('c.inacbg_kode')
            ->havingRaw('COUNT(*) >= ' . self::MIN_KASUS)->get()
            ->map(fn ($r) => ['kode' => $r->kode, 'nama' => self::kalimatCase($r->nama), 'nilai' => round((float) $r->los, 1), 'klaim' => (int) $r->n,
                'chip' => number_format((int) $r->n, 0, ',', '.') . ' kasus', 'filter' => ['inacbg' => $r->kode, 'jenis' => 'inap']])->all();

        $plg = self::selisihHari('b.tgl_sep', 'a.tgl_plg_sep');
        $ulang = DB::table('vclaim_kunjungan as b')->whereBetween('b.tgl_sep', [$dari, $sampai])->whereRaw(self::inapSql('b'))->whereNotNull('b.no_kartu')
            ->selectRaw("SUBSTR(b.tgl_sep,1,7) bln, COUNT(*) n, SUM(CASE WHEN EXISTS (SELECT 1 FROM vclaim_kunjungan a WHERE a.no_kartu = b.no_kartu
                AND a.no_sep <> b.no_sep AND a.tgl_plg_sep IS NOT NULL AND a.tgl_sep <= b.tgl_sep AND " . self::inapSql('a') . "
                AND $plg BETWEEN 0 AND 30) THEN 1 ELSE 0 END) r")
            ->groupBy('bln')->get()->keyBy('bln');
        $re = ['label' => array_values($bulan), 'n' => [], 'r' => [], 'pct' => []];
        foreach (array_keys($bulan) as $b) {
            $n = (int) ($ulang[$b]->n ?? 0);
            $r = (int) ($ulang[$b]->r ?? 0);
            $re['n'][] = $n;
            $re['r'][] = $r;
            $re['pct'][] = self::pct($r, $n) ?? 0;
        }

        return [
            'los' => ['label' => array_values($bulan), 'median' => array_map(fn ($d) => self::median($d), array_values($dist)),
                'rata' => array_map(fn ($d) => self::rata($d), array_values($dist)),
                'median_kini' => self::median($dist[$kiniB] ?? []), 'rata_kini' => self::rata($dist[$kiniB] ?? [])],
            'los_cbg' => self::peringkat($cbg, ['jenis' => 'inap'], 'hari'),
            'readmisi' => ['n' => (int) ($ulang[$kiniB]->r ?? 0), 'dasar' => (int) ($ulang[$kiniB]->n ?? 0),
                'pct' => self::pct((int) ($ulang[$kiniB]->r ?? 0), (int) ($ulang[$kiniB]->n ?? 0)), 'tren' => $re],
        ];
    }

    private function cermatData(Carbon $awal, string $jenis): array
    {
        $rk = '(SELECT f.rubric_kurang FROM ftp_folders f WHERE f.no_sep = c.no_sep ORDER BY f.id DESC LIMIT 1)';
        $kel = "CASE WHEN $rk = 0 THEN 'siap' WHEN $rk > 0 THEN 'belum' ELSE 'tidak' END";
        $rows = $this->klaimC($awal, $jenis)->selectRaw("$kel kel, COUNT(*) n, " . self::PENDING_C . ' p')->groupBy('kel')->get()->keyBy('kel');
        $kelompok = [];
        foreach (self::CERMAT_LABEL as $k => $label) {
            $n = (int) ($rows[$k]->n ?? 0);
            $p = (int) ($rows[$k]->p ?? 0);
            $kelompok[$k] = ['label' => $label, 'n' => $n, 'p' => $p, 'cukup' => $n >= self::MIN_BANDING,
                'pct' => $n >= self::MIN_BANDING ? self::pct($p, $n) : null];
        }

        $golive = null;
        $live = config('casemix.go_live_date');
        if ($live && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $live)) {
            $t = Carbon::parse($live)->startOfDay();
            $hitung = function (Carbon $a, Carbon $b) use ($jenis) {
                $q = VclaimKlaim::query()->whereBetween('tgl_pulang', [$a->toDateString(), $b->toDateString()]);
                self::jenis($q, $jenis);
                $r = $q->toBase()->selectRaw('COUNT(*) n, SUM(CASE WHEN status_kategori = \'pending\' THEN 1 ELSE 0 END) p')->first();

                return ['n' => (int) $r->n, 'pct' => self::pct((int) $r->p, (int) $r->n), 'dari' => $a->toDateString(), 'sampai' => $b->toDateString()];
            };
            $golive = ['tanggal' => $t->toDateString(),
                'sebelum' => $hitung($t->copy()->subMonthsNoOverflow(3), $t->copy()->subDay()),
                'sesudah' => $hitung($t->copy(), $t->copy()->addMonthsNoOverflow(3)->subDay())];
        }

        return ['kelompok' => $kelompok, 'golive' => $golive];
    }
}
