<?php

namespace App\Http\Controllers;

use App\Models\FtpFolder;
use App\Models\VclaimKlaim;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use App\Services\Dashboard\BpjsStats;
use App\Services\Vclaim\VclaimHistoriService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Menu Klaim BPJS (Koordinator): hanya membaca tabel vclaim_*; tidak memanggil BPJS kecuali tombol riwayat pelayanan. */
class BpjsController extends Controller
{
    public const TABS = ['ringkasan' => 'Ringkasan', 'klaim' => 'Daftar klaim', 'sep' => 'SEP terbit'];

    /** Filter status daftar klaim => kategori (status_kategori). */
    private const STATUS = ['proses' => ['proses'], 'pending' => ['pending'], 'tidak_layak' => ['tidak_layak'], 'dispute' => ['dispute'],
        'klaim' => ['klaim'], 'lainnya' => ['lainnya'], 'tolak' => ['tidak_layak', 'dispute']];

    private const MAKS_CSV = 10000;

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'ringkasan';
        $enabled = (bool) config('casemix.vclaim.enabled');
        $filters = $this->filters($request);
        $data = ['tab' => $tab, 'enabled' => $enabled, 'filters' => $filters, 'rows' => null, 'folders' => collect(), 'klaimStatus' => collect(),
            'lastSync' => VclaimSyncRun::where('status', 'ok')->latest('id')->value('created_at')];

        if ($enabled && $tab === 'ringkasan') {
            $data += $this->ringkasan($request);
        }

        if ($enabled && $tab !== 'ringkasan') {
            $rows = ($tab === 'klaim' ? $this->klaimQuery($filters) : $this->sepQuery($filters))->paginate(50)->withQueryString();
            $seps = $rows->pluck('no_sep')->all();
            $data['rows'] = $rows;
            $data['folders'] = FtpFolder::whereIn('no_sep', $seps)->orderByDesc('id')->get(['id', 'no_sep'])->unique('no_sep')->keyBy('no_sep');
            if ($tab === 'klaim') {
                $data['rentangData'] = VclaimKlaim::selectRaw('MIN(tgl_pulang) mn, MAX(tgl_pulang) mx')->first();
            }
            if ($tab === 'sep') {
                $data['klaimStatus'] = VclaimKlaim::whereIn('no_sep', $seps)->pluck('status_kategori', 'no_sep');
            }
        }

        return view('bpjs.index', $data);
    }

    public function csv(Request $request)
    {
        abort_unless(config('casemix.vclaim.enabled'), 404);
        $tab = $request->query('tab') === 'sep' ? 'sep' : 'klaim';
        $filters = $this->filters($request);
        $query = ($tab === 'klaim' ? $this->klaimQuery($filters) : $this->sepQuery($filters))->limit(self::MAKS_CSV);
        $rows = $query->get();
        $folders = FtpFolder::whereIn('no_sep', $rows->pluck('no_sep'))->pluck('id', 'no_sep');
        $status = $tab === 'sep' ? VclaimKlaim::whereIn('no_sep', $rows->pluck('no_sep'))->pluck('status_kategori', 'no_sep') : collect();

        Audit::log('bpjs.ekspor', null, 'Mengunduh CSV Klaim BPJS', ['tab' => $tab, 'filter' => array_filter($filters), 'jumlah' => $rows->count()]);

        $d = fn ($t) => $t?->format('d/m/Y');
        $st = fn ($k) => VclaimKlaim::KATEGORI[$k] ?? 'Belum terlihat di data klaim';

        return response()->streamDownload(function () use ($tab, $rows, $folders, $status, $d, $st) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            if ($tab === 'klaim') {
                fputcsv($out, ['Tgl pulang', 'Nama', 'No RM', 'No SEP', 'Jenis', 'INA-CBG', 'Nama INA-CBG', 'Diajukan', 'Disetujui', 'Selisih', 'Status', 'Lama di status (hari)', 'Ada folder'], ';');
                foreach ($rows as $r) {
                    fputcsv($out, [$d($r->tgl_pulang), $r->nama, $r->no_mr, $r->no_sep, $r->jns_pelayanan, $r->inacbg_kode, $r->inacbg_nama,
                        $r->by_pengajuan, $r->by_setujui, self::selisih($r), $st($r->status_kategori), self::lama($r), $folders->has($r->no_sep) ? 'ya' : 'tidak'], ';');
                }
            } else {
                fputcsv($out, ['Tgl SEP', 'Tgl pulang', 'Nama', 'No SEP', 'Jenis', 'Diagnosa', 'Ada folder', 'Status klaim'], ';');
                foreach ($rows as $r) {
                    fputcsv($out, [$d($r->tgl_sep), $d($r->tgl_plg_sep), $r->nama, $r->no_sep, $r->jns_pelayanan, $r->diagnosa,
                        $folders->has($r->no_sep) ? 'ya' : 'tidak', $st($status[$r->no_sep] ?? null)], ';');
                }
            }
            fclose($out);
        }, "bpjs-{$tab}-" . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(string $noSep)
    {
        $enabled = (bool) config('casemix.vclaim.enabled');
        $klaim = VclaimKlaim::with('statusHistory')->where('no_sep', $noSep)->first();
        $kunjungan = VclaimKunjungan::where('no_sep', $noSep)->first();
        abort_unless($klaim || $kunjungan, 404);

        $noKartu = $klaim?->no_kartu ?: $kunjungan?->no_kartu;

        return view('bpjs.show', [
            'noSep' => $noSep, 'klaim' => $klaim, 'kunjungan' => $kunjungan, 'noKartu' => $noKartu, 'enabled' => $enabled,
            'histori' => $noKartu ? app(VclaimHistoriService::class)->simpanan($noSep) : null,
            'folder' => FtpFolder::where('no_sep', $noSep)->orderByDesc('id')->first(['id', 'no_sep']),
        ]);
    }

    /** Tombol "Ambil riwayat pelayanan": JSON, tanpa muat ulang dan tanpa antrean. */
    public function histori(Request $request, string $noSep, VclaimHistoriService $svc)
    {
        abort_unless(VclaimKlaim::where('no_sep', $noSep)->exists() || VclaimKunjungan::where('no_sep', $noSep)->exists(), 404);

        return $svc->jawab($request, $noSep, null, ['no_sep' => $noSep]);
    }

    public static function selisih(VclaimKlaim $k): ?float
    {
        return $k->status_kategori === 'klaim' && $k->by_pengajuan !== null && $k->by_setujui !== null
            ? (float) $k->by_setujui - (float) $k->by_pengajuan : null;
    }

    public static function lama(VclaimKlaim $k): ?int
    {
        return $k->status_changed_at ? (int) $k->status_changed_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null;
    }

    private function ringkasan(Request $request): array
    {
        $periode = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('periode'))
            ? $request->query('periode') : now()->subMonthNoOverflow()->format('Y-m');
        $jenis = in_array($request->query('jenis'), ['inap', 'jalan'], true) ? $request->query('jenis') : '';
        $stats = app(BpjsStats::class)->get($periode, $jenis);

        $rentang = fn (string $b) => ['dari' => Carbon::createFromFormat('!Y-m', $b)->toDateString(),
            'sampai' => Carbon::createFromFormat('!Y-m', $b)->endOfMonth()->toDateString()];
        $j = array_filter(['jenis' => $jenis]);
        $p = $rentang($periode) + $j;
        $klaim = fn (array $x) => route('bpjs.index', ['tab' => 'klaim'] + $x + $p);
        $links = [
            'kartu' => ['sep' => route('bpjs.index', ['tab' => 'sep'] + $p), 'belum' => route('bpjs.index', ['tab' => 'sep', 'belum' => 1] + $p),
                'proses' => $klaim(['status' => 'proses']), 'pending' => $klaim(['status' => 'pending']), 'klaim' => $klaim(['status' => 'klaim']),
                'kurang' => $klaim(['status' => 'klaim', 'kurang' => 1]), 'tertahan' => $klaim(['status' => 'pending']),
                'tolak' => $klaim(['status' => 'tolak'])],
            'aksi' => ['pending14' => $klaim(['status' => 'pending', 'lama' => 14]), 'tidak_layak' => $klaim(['status' => 'tidak_layak']),
                'belum7' => route('bpjs.index', ['tab' => 'sep', 'belum' => 1, 'lama' => 7] + $p)],
            'status' => array_map(fn ($s) => $klaim(['status' => $s]), $stats['donut_status']['keys']),
            'jenis' => array_map(fn ($x) => route('bpjs.index', ['tab' => 'klaim', 'jenis' => $x] + $rentang($periode)), $stats['donut_jenis']['keys']),
            'tren' => array_map(fn ($b) => array_map(fn ($s) => route('bpjs.index', ['tab' => 'klaim', 'status' => $s] + $rentang($b) + $j),
                array_keys(BpjsStats::STATUS)), $stats['tren']['bulan']),
        ];

        return ['periode' => $periode, 'jenisRingkasan' => $jenis, 'stats' => $stats, 'links' => $links, 'sinkron' => BpjsStats::sinkron(),
            'dasarLink' => ['tab' => 'klaim'] + $p];
    }

    private function filters(Request $request): array
    {
        $tgl = fn ($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($k)) ? $request->query($k) : $def;
        $bulan = now();
        if ($request->query('tab') !== 'sep' && ! ($request->filled('dari') && $request->filled('sampai'))) {
            $max = VclaimKlaim::max('tgl_pulang');
            $bulan = $max ? Carbon::parse($max) : now()->subMonthNoOverflow();
        }

        return [
            'dari' => $tgl('dari', $bulan->copy()->startOfMonth()->toDateString()),
            'sampai' => $tgl('sampai', $bulan->copy()->endOfMonth()->toDateString()),
            'jenis' => in_array($request->query('jenis'), ['inap', 'jalan'], true) ? $request->query('jenis') : '',
            'status' => array_key_exists((string) $request->query('status'), self::STATUS) ? $request->query('status') : '',
            'q' => mb_substr(trim((string) $request->query('q')), 0, 100),
            'belum' => $request->query('belum') === '1' ? '1' : '',
            'kurang' => $request->query('kurang') === '1' ? '1' : '',
            'lama' => in_array($request->query('lama'), ['7', '14'], true) ? $request->query('lama') : '',
            'inacbg' => preg_match('/^[A-Za-z0-9.\-]{1,20}$/', (string) $request->query('inacbg')) ? strtoupper($request->query('inacbg')) : '',
            'sev' => in_array($request->query('sev'), ['I', 'II', 'III'], true) ? $request->query('sev') : '',
            'dx' => preg_match('/^[A-Za-z]\d{2}$/', (string) $request->query('dx')) ? strtoupper($request->query('dx')) : '',
        ];
    }

    private function jenis(Builder $q, string $jenis): void
    {
        BpjsStats::jenis($q, $jenis);
    }

    private function klaimQuery(array $f): Builder
    {
        $q = VclaimKlaim::query()->whereBetween('tgl_pulang', [$f['dari'], $f['sampai']]);
        $this->jenis($q, $f['jenis']);
        if ($f['status']) {
            $q->whereIn('status_kategori', self::STATUS[$f['status']]);
        }
        if ($f['kurang']) {
            $q->where('status_kategori', 'klaim')->whereColumn('by_setujui', '<', 'by_pengajuan');
        }
        if ($f['lama']) {
            $q->whereNotNull('status_changed_at')->whereDate('status_changed_at', '<', now()->startOfDay()->subDays((int) $f['lama'])->toDateString());
        }
        if ($f['inacbg']) {
            $q->where('inacbg_kode', $f['inacbg']);
        }
        if ($f['sev']) {
            BpjsStats::jenis($q, 'inap');
            $q->where('inacbg_kode', 'like', '%-' . $f['sev']);
        }
        if ($f['dx']) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('vclaim_kunjungan')->whereColumn('vclaim_kunjungan.no_sep', 'vclaim_klaim.no_sep')
                ->whereRaw('UPPER(TRIM(vclaim_kunjungan.diagnosa)) LIKE ?', [$f['dx'] . '%']));
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('no_sep', 'like', $like)->orWhere('nama', 'like', $like)->orWhere('no_mr', 'like', $like));
        }

        return $q->orderByRaw("CASE WHEN status_kategori IN ('pending', 'tidak_layak', 'dispute') THEN 0 ELSE 1 END")->orderByDesc('tgl_pulang')->orderBy('id');
    }

    private function sepQuery(array $f): Builder
    {
        $q = VclaimKunjungan::query()->whereBetween('tgl_sep', [$f['dari'], $f['sampai']]);
        $this->jenis($q, $f['jenis']);
        if ($f['belum']) {
            $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('vclaim_klaim')->whereColumn('vclaim_klaim.no_sep', 'vclaim_kunjungan.no_sep'));
        }
        if ($f['lama']) {
            $q->whereRaw('COALESCE(tgl_plg_sep, tgl_sep) < ?', [now()->startOfDay()->subDays((int) $f['lama'])->toDateString()]);
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('no_sep', 'like', $like)->orWhere('nama', 'like', $like));
        }

        return $q->orderByDesc('tgl_sep')->orderBy('id');
    }
}
