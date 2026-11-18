<?php

namespace App\Services\Dashboard;

use App\Http\Controllers\Admin\SettingController;
use App\Models\CodeSuggestion;
use App\Models\ConditionSuggestion;
use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Models\VclaimKlaim;
use App\Models\VclaimSyncRun;
use App\Services\Vclaim\VclaimClient;
use App\Services\Ai\AiBudget;
use App\Support\VerificationRubric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Angka agregat Pusat Kendali Klaim (tanpa AI). Di-cache 5 menit per periode. */
class DashboardStats
{
    public const PERIODS = ['7' => '7 hari', '30' => '30 hari', 'bulan' => 'Bulan ini', 'semua' => 'Semua'];

    public const TOP = 8;

    public function __construct(private ClaimVerdict $verdict, private AiBudget $budget) {}

    public static function period(?string $p): string
    {
        return array_key_exists((string) $p, self::PERIODS) ? (string) $p : '30';
    }

    public function get(string $period): array
    {
        $period = self::period($period);

        $gen = (int) Cache::get('dashboard.stats.gen', 0);

        return Cache::remember("dashboard.stats.$gen.$period", 300, fn () => $this->compute($period)) + ['vclaim' => $this->vclaim()];
    }

    public static function forget(): void
    {
        Cache::forever('dashboard.stats.gen', (int) Cache::get('dashboard.stats.gen', 0) + 1);
    }

    /** Ringkasan VClaim (bulan berjalan menurut tanggal pulang); null bila VClaim tidak aktif. */
    private function vclaim(): ?array
    {
        if (! VclaimClient::enabled()) {
            return null;
        }
        $bulan = VclaimKlaim::where('status_kode', 3)->whereBetween('tgl_pulang', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        return [
            'terakhir' => VclaimSyncRun::where('status', 'ok')->max('created_at') ?? VclaimSyncRun::max('created_at'),
            'pending' => VclaimKlaim::where('status_kode', 2)->count(),
            'layak' => (clone $bulan)->count(),
            'layak_total' => (float) (clone $bulan)->sum('by_setujui'),
            'selisih' => (float) (clone $bulan)->sum('by_pengajuan') - (float) (clone $bulan)->sum('by_setujui'),
        ];
    }

    private function since(string $period): ?Carbon
    {
        return match ($period) {
            '7' => now()->subDays(7)->startOfDay(),
            '30' => now()->subDays(30)->startOfDay(),
            'bulan' => now()->startOfMonth(),
            default => null,
        };
    }

    /** Folder dalam periode, berdasar tanggal FTP (files_modified_at, bila kosong first_seen_at). */
    private function folders(string $period): Builder
    {
        $q = FtpFolder::query()->whereNull('missing_at');
        if ($from = $this->since($period)) {
            $q->where(fn ($w) => $w->where('files_modified_at', '>=', $from)
                ->orWhere(fn ($x) => $x->whereNull('files_modified_at')->where('first_seen_at', '>=', $from)));
        }

        return $q;
    }

    private function compute(string $period): array
    {
        $folders = $this->folders($period)->get();
        $ids = $folders->pluck('id');

        $vonis = ['siap' => 0, 'tinjau' => 0, 'blokir' => 0, 'belum' => 0];
        foreach ($folders as $f) {
            $vonis[$this->verdict->verdict($f)]++;
        }
        $dianalisa = $vonis['siap'] + $vonis['tinjau'] + $vonis['blokir'];

        $codes = CodeSuggestion::query()->join('folder_analyses', 'folder_analyses.id', '=', 'code_suggestions.folder_analysis_id')
            ->whereIn('folder_analyses.ftp_folder_id', $ids)->whereIn('code_suggestions.folder_analysis_id', $this->latestAnalysisIds($ids))
            ->get(['code_suggestions.*', 'folder_analyses.ftp_folder_id']);

        $kodeAktif = SettingController::codeSuggestionsOn() || CodeSuggestion::query()->exists();

        return [
            'total' => $folders->count(),
            'vonis' => $vonis,
            'dianalisa' => $dianalisa,
            'skor' => $dianalisa ? (int) round($vonis['siap'] / $dianalisa * 100) : null,
            'menunggu_dpjp' => ConditionSuggestion::whereIn('ftp_folder_id', $ids)->whereIn('status', ['baru', 'ditanyakan'])->count()
                + $codes->where('tercantum_di_resume', false)->filter(fn ($c) => in_array($c->status_tindak_lanjut, [null, 'ditanyakan'], true))->count(),
            'biaya_ai' => $this->budget->spent(),
            'sinkron' => FtpFolder::max('last_seen_at'),
            'rubrik' => $this->rubric($ids),
            'kode_aktif' => $kodeAktif,
            'kurang_klaim' => $kodeAktif ? $this->top($codes->where('peran', 'sekunder')->where('system', 'icd10')->where('tercantum_di_resume', false)
                ->reject(fn ($c) => in_array($c->status_tindak_lanjut, ['tidak_setuju', 'tidak_relevan'], true))->pluck('code')) : null,
            'kurang_bukti' => $kodeAktif ? $this->top($this->weakDiagnosis($ids, $codes)) : null,
            'pindah' => $this->moved($period),
        ];
    }

    /** Folder yang hilang dari FTP (perkiraan diajukan) dalam periode, berdasar missing_at. */
    private function moved(string $period): array
    {
        $moved = FtpFolder::query()->whereNotNull('missing_at')
            ->when($this->since($period), fn ($w, $from) => $w->where('missing_at', '>=', $from))->get();
        $periksa = $moved->filter(fn ($f) => $f->moved_status === 'dipindahkan');
        $hari = $moved->map(fn ($f) => ($f->first_seen_at ?? $f->files_modified_at)?->diffInDays($f->missing_at))
            ->filter(fn ($d) => $d !== null)->map(fn ($d) => max(0, (int) floor($d)));

        return [
            'dipindahkan' => $moved->count(),
            'belum_siap' => $periksa->filter(fn ($f) => in_array($this->verdict->verdict($f), ['tinjau', 'blokir'], true))->count(),
            'tanpa_periksa' => $moved->count() - $periksa->count(),
            'median_hari' => $hari->isEmpty() ? null : $hari->median(),
        ];
    }

    /** 14 butir rubrik dengan jumlah folder berstatus kurang, urut terbanyak. */
    private function rubric(Collection $ids): array
    {
        $n = RubricItem::whereIn('ftp_folder_id', $ids)->where('status', 'kurang')->selectRaw('kode, count(*) as n')->groupBy('kode')->pluck('n', 'kode');

        return collect(VerificationRubric::codes())->map(fn ($k) => ['kode' => $k, 'label' => VerificationRubric::label($k), 'n' => (int) ($n[$k] ?? 0)])
            ->sortByDesc('n')->values()->all();
    }

    /** Kode diagnosis utama (3 digit) dari folder yang butir bukti_diagnosis-nya kurang. */
    private function weakDiagnosis(Collection $ids, Collection $codes): Collection
    {
        $lemah = RubricItem::whereIn('ftp_folder_id', $ids)->where('kode', 'bukti_diagnosis')->where('status', 'kurang')->pluck('ftp_folder_id');

        return $codes->where('peran', 'utama')->whereIn('ftp_folder_id', $lemah)->unique('ftp_folder_id')->pluck('code');
    }

    /** Kelompokkan per kategori ICD-10 3 digit: top 8 + jumlah kategori lain. */
    private function top(Collection $codes): array
    {
        $g = $codes->map(fn ($c) => substr((string) $c, 0, 3))->countBy()->sortDesc();

        return ['items' => $g->take(self::TOP)->map(fn ($n, $k) => ['kategori' => $k, 'n' => $n])->values()->all(), 'lain' => max(0, $g->count() - self::TOP)];
    }

    /** Analisa terakhir per folder yang punya saran kode. */
    private function latestAnalysisIds(Collection $ids): Collection
    {
        return CodeSuggestion::query()->join('folder_analyses', 'folder_analyses.id', '=', 'code_suggestions.folder_analysis_id')
            ->whereIn('folder_analyses.ftp_folder_id', $ids)->groupBy('folder_analyses.ftp_folder_id')
            ->selectRaw('max(code_suggestions.folder_analysis_id) as id')->pluck('id');
    }
}
