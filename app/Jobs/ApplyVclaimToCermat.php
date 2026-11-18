<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\FtpFolder;
use App\Models\User;
use App\Models\VclaimEvaluasi;
use App\Models\VclaimKlaim;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use App\Notifications\BpjsTindakLanjut;
use App\Services\Dashboard\BpjsStats;
use App\Services\Dashboard\ClaimVerdict;
use App\Services\Dashboard\DashboardStats;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Terapkan hasil sinkron VClaim ke ftp_folders sekali jalan. Tanpa AI, tanpa analisa ulang, tanpa panggilan BPJS. */
class ApplyVclaimToCermat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const TINDAK = ['pending', 'tidak_layak', 'dispute'];

    public const PENANDA = 'vclaim_apply_last_at';

    public const TANPA_FOLDER = 'vclaim_tanpa_folder';

    public int $uniqueFor = 3600;

    public function handle(ClaimVerdict $verdict): void
    {
        $t0 = hrtime(true);
        $mulai = now()->format('Y-m-d H:i:s');
        $tanda = AppSetting::get(self::PENANDA);

        // Hanya SEP yang berubah sejak run terakhir.
        $seps = VclaimKlaim::query()
            ->when($tanda, fn ($q) => $q->where(fn ($w) => $w->where('status_changed_at', '>=', $tanda)->orWhere('first_seen_at', '>=', $tanda)))
            ->pluck('no_sep')
            ->merge(VclaimKunjungan::query()->when($tanda, fn ($q) => $q->where('synced_at', '>=', $tanda))->pluck('no_sep'))
            ->unique()->values();

        $cari = fn () => DB::table('ftp_folders')->join('vclaim_klaim', 'vclaim_klaim.no_sep', '=', 'ftp_folders.no_sep')
            ->where(fn ($w) => $w->whereNull('ftp_folders.bpjs_status_kategori')
                ->orWhereColumn('ftp_folders.bpjs_status_kategori', '!=', 'vclaim_klaim.status_kategori'))
            ->select('ftp_folders.id', 'vclaim_klaim.status_kategori as baru', 'vclaim_klaim.tgl_pulang');
        $kandidat = collect();
        foreach ($seps->chunk(500) as $c) {
            $kandidat = $kandidat->merge($cari()->whereIn('ftp_folders.no_sep', $c->all())->get());
        }
        // Folder baru di FTP yang SEP-nya sudah ada di VClaim tetapi belum pernah diberi status.
        $kandidat = $kandidat->merge($cari()->whereNull('ftp_folders.bpjs_status_kategori')->get())->unique('id')->values();

        $diperbarui = 0;
        foreach ($kandidat->pluck('id')->chunk(500) as $ids) {
            $diperbarui += $this->perbarui($ids->values()->all());
        }

        $tindak = $kandidat->filter(fn ($r) => in_array($r->baru, self::TINDAK, true))->values();
        $this->evaluasi($verdict, $tindak->filter(fn ($r) => $r->baru !== 'dispute'), $mulai);
        $this->beritahu($tindak);

        AppSetting::put(self::TANPA_FOLDER, self::hitungTanpaFolder());
        AppSetting::put(self::PENANDA, $mulai);
        BpjsStats::forget();
        DashboardStats::forget();

        VclaimSyncRun::create(['jenis' => 'apply', 'status' => 'diterapkan', 'jumlah' => $diperbarui,
            'durasi_ms' => (int) ((hrtime(true) - $t0) / 1_000_000)]);
    }

    /** SEP pulang > 7 hari lalu yang berkasnya belum ada di FTP. */
    public static function hitungTanpaFolder(): int
    {
        return VclaimKunjungan::query()
            ->whereNotNull('tgl_plg_sep')->where('tgl_plg_sep', '<', now()->startOfDay()->subDays(7)->toDateString())
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('ftp_folders')->whereColumn('ftp_folders.no_sep', 'vclaim_kunjungan.no_sep'))
            ->count();
    }

    /** Satu UPDATE ... JOIN per 500 folder (SQLite di test: subquery berkorelasi). */
    private function perbarui(array $ids): int
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tindak = "'" . implode("','", self::TINDAK) . "'";
        if (DB::getDriverName() === 'mysql') {
            return DB::affectingStatement("UPDATE ftp_folders f JOIN vclaim_klaim k ON k.no_sep = f.no_sep
                SET f.bpjs_status_kategori = k.status_kategori,
                    f.bpjs_status_at = COALESCE(k.status_changed_at, k.first_seen_at, k.synced_at),
                    f.bpjs_tindak_lanjut = k.status_kategori IN ($tindak)
                WHERE f.id IN ($in)", $ids);
        }
        $k = 'FROM vclaim_klaim k WHERE k.no_sep = ftp_folders.no_sep';

        return DB::affectingStatement("UPDATE ftp_folders SET
            bpjs_status_kategori = (SELECT k.status_kategori $k),
            bpjs_status_at = (SELECT COALESCE(k.status_changed_at, k.first_seen_at, k.synced_at) $k),
            bpjs_tindak_lanjut = COALESCE((SELECT k.status_kategori IN ($tindak) $k), 0)
            WHERE id IN ($in)", $ids);
    }

    /** "Siap diajukan" menurut CERMAT tetapi pending/tidak layak di BPJS: catat sekali per folder+kategori. */
    private function evaluasi(ClaimVerdict $verdict, Collection $rows, string $waktu): void
    {
        $baru = $rows->pluck('baru', 'id');
        foreach ($rows->pluck('id')->chunk(500) as $ids) {
            $data = FtpFolder::whereIn('id', $ids->all())->get()
                ->filter(fn ($f) => $verdict->verdict($f) === 'siap')
                ->map(fn ($f) => ['folder_id' => $f->id, 'no_sep' => $f->no_sep, 'status_kategori' => $baru[$f->id],
                    'verdict_cermat' => 'siap', 'dicatat_at' => $waktu])
                ->values()->all();
            if ($data) {
                VclaimEvaluasi::insertOrIgnore($data);
            }
        }
    }

    /** Satu notifikasi per run untuk semua Koordinator aktif; tanpa nama pasien. */
    private function beritahu(Collection $rows): void
    {
        $users = User::role('koordinator')->where('is_active', true)->get();
        if ($rows->isEmpty() || $users->isEmpty()) {
            return;
        }
        $tgl = $rows->pluck('tgl_pulang')->filter()->map(fn ($d) => substr((string) $d, 0, 10));
        $url = route('bpjs.index', array_filter(['tab' => 'klaim', 'status' => 'tindak', 'dari' => $tgl->min(), 'sampai' => $tgl->max()]), false);
        Notification::send($users, new BpjsTindakLanjut($rows->count(), $url));
    }
}
