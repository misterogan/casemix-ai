<?php

namespace App\Services\Ai;

use App\Enums\ReviewStatus;
use App\Models\AiProvider;
use App\Models\AiReview;
use App\Models\AppSetting;
use App\Models\ClaimExtraction;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Anggaran AI bulanan di dalam aplikasi (lapis kedua; lapis pertama adalah batas di Claude Console).
 * Biaya dihitung dari token yang dicatat setiap pemanggilan, jadi angkanya PERKIRAAN.
 * Tagihan resmi tetap yang ada di dasbor penyedia AI.
 */
class AiBudget
{
    public const KEY_BUDGET = 'ai_budget_usd';

    public const KEY_WARN = 'ai_budget_warn_pct';

    private const KEY_WARNED = 'ai_budget_warned_month';

    /** Anggaran bulanan dalam USD, atau null bila tidak dibatasi. */
    public function budget(): ?float
    {
        $v = AppSetting::get(self::KEY_BUDGET);

        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    public function warnPct(): int
    {
        return (int) AppSetting::get(self::KEY_WARN, 80);
    }

    /** [input, output] USD per 1 juta token untuk satu pemanggilan, atau null bila harganya tidak diketahui. */
    public function prices(?AiProvider $provider, ?string $model, int $tokensIn = 0): ?array
    {
        if ($provider && $provider->price_input_usd !== null && $provider->price_output_usd !== null) {
            return [(float) $provider->price_input_usd, (float) $provider->price_output_usd];
        }
        if (! $model) {
            return null;
        }
        $table = config('casemix.budget.prices', []);
        uksort($table, fn ($a, $b) => strlen($b) <=> strlen($a)); // "claude-sonnet-5-5" sebelum "claude-sonnet-5"
        foreach ($table as $prefix => $p) {
            if (str_starts_with($model, $prefix)) {
                if (isset($p['prompt_lebih_dari']) && $tokensIn > $p['prompt_lebih_dari'][0]) {
                    return [(float) $p['prompt_lebih_dari'][1], (float) $p['prompt_lebih_dari'][2]];
                }

                return [(float) $p[0], (float) $p[1]];
            }
        }

        return null;
    }

    public function cost(?AiProvider $provider, ?string $model, ?int $tokensIn, ?int $tokensOut): ?float
    {
        $p = $this->prices($provider, $model, (int) $tokensIn);
        if (! $p || ($tokensIn === null && $tokensOut === null)) {
            return null;
        }

        return round(((int) $tokensIn * $p[0] + (int) $tokensOut * $p[1]) / 1_000_000, 6);
    }

    /** Hitung dan simpan biaya satu pemanggilan (AiReview atau ClaimExtraction), lalu catat bila melewati ambang peringatan. */
    public function record(Model $row): void
    {
        $provider = $row->ai_provider_id ? AiProvider::find($row->ai_provider_id) : null;
        $row->forceFill(['cost_usd' => $this->cost($provider, $row->model, $row->tokens_in, $row->tokens_out)])->save();
        $this->auditThresholdOnce();
    }

    public function monthStart(): Carbon
    {
        return now()->startOfMonth();
    }

    /** Perkiraan biaya bulan berjalan (USD). */
    public function spent(): float
    {
        $from = $this->monthStart();

        return round((float) AiReview::where('finished_at', '>=', $from)->sum('cost_usd')
            + (float) ClaimExtraction::where('finished_at', '>=', $from)->sum('cost_usd'), 6);
    }

    /** Pemanggilan bulan ini yang memakai token tetapi harganya tidak diketahui (biaya tidak terhitung). */
    public function unpriced(): int
    {
        $from = $this->monthStart();
        $q = fn ($m) => $m::where('finished_at', '>=', $from)->whereNotNull('tokens_in')->whereNull('cost_usd')->count();

        return $q(AiReview::class) + $q(ClaimExtraction::class);
    }

    /** Rata-rata biaya per pemanggilan dari 50 pemanggilan terakhir, untuk memperkirakan antrean. */
    public function averageJobCost(): float
    {
        $costs = AiReview::whereNotNull('cost_usd')->latest('id')->limit(50)->pluck('cost_usd')
            ->merge(ClaimExtraction::whereNotNull('cost_usd')->latest('id')->limit(50)->pluck('cost_usd'));

        return $costs->isEmpty() ? (float) config('casemix.budget.default_job_cost_usd') : (float) $costs->avg();
    }

    public function activeJobs(): int
    {
        $active = [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value];

        return AiReview::whereIn('status', $active)->count() + ClaimExtraction::whereIn('status', $active)->count();
    }

    /**
     * @return array{enabled: bool, budget: ?float, spent: float, reserved: float, pct: ?int, warn_pct: int, state: string, unpriced: int}
     *         state: tanpa_batas | aman | peringatan | habis
     */
    public function status(): array
    {
        $budget = $this->budget();
        $spent = $this->spent();
        $reserved = round($this->activeJobs() * $this->averageJobCost(), 6);
        $pct = $budget ? (int) floor(($spent / $budget) * 100) : null;

        $state = match (true) {
            ! $budget => 'tanpa_batas',
            $spent + $reserved >= $budget => 'habis',
            $pct >= $this->warnPct() => 'peringatan',
            default => 'aman',
        };

        return ['enabled' => (bool) $budget, 'budget' => $budget, 'spent' => $spent, 'reserved' => $reserved,
            'pct' => $pct, 'warn_pct' => $this->warnPct(), 'state' => $state, 'unpriced' => $this->unpriced()];
    }

    /** Alasan proses AI baru tidak boleh dimulai (dengan memperhitungkan antrean), atau null. */
    public function startBlocker(): ?string
    {
        $s = $this->status();

        return $s['state'] === 'habis' ? $this->message($s) : null;
    }

    /** Pengecekan terakhir di dalam job, tepat sebelum memanggil AI. Tidak memperhitungkan antrean (termasuk job ini). */
    public function runBlocker(): ?string
    {
        $budget = $this->budget();

        return $budget && $this->spent() >= $budget ? $this->message($this->status()) : null;
    }

    /** Ringkasan per bulan dan per fitur/petugas untuk halaman Admin. */
    public function report(int $months = 6): array
    {
        $from = $this->monthStart()->subMonths($months - 1);
        $rows = collect();
        foreach ([AiReview::class => 'Pemeriksaan berkas', ClaimExtraction::class => 'Usulan isian'] as $model => $label) {
            $rows = $rows->merge($model::query()->where('finished_at', '>=', $from)->whereNotNull('finished_at')
                ->get(['finished_at', 'cost_usd', 'tokens_in', 'tokens_out', 'requested_by'])
                ->map(fn ($r) => ['bulan' => $r->finished_at->format('Y-m'), 'fitur' => $label, 'biaya' => (float) $r->cost_usd,
                    'token' => (int) $r->tokens_in + (int) $r->tokens_out, 'user' => $r->requested_by]));
        }
        $thisMonth = $rows->where('bulan', now()->format('Y-m'));
        $users = DB::table('users')->whereIn('id', $thisMonth->pluck('user')->filter()->unique())->pluck('name', 'id');

        return [
            'per_bulan' => $rows->groupBy('bulan')->map(fn ($g) => ['biaya' => $g->sum('biaya'), 'jumlah' => $g->count()])->sortKeysDesc()->all(),
            'per_fitur' => $thisMonth->groupBy('fitur')->map(fn ($g) => ['biaya' => $g->sum('biaya'), 'jumlah' => $g->count(), 'token' => $g->sum('token')])->all(),
            'per_petugas' => $thisMonth->groupBy('user')->map(fn ($g, $id) => ['nama' => $users[$id] ?? 'Sistem', 'biaya' => $g->sum('biaya'), 'jumlah' => $g->count()])
                ->sortByDesc('biaya')->values()->all(),
        ];
    }

    private function message(array $s): string
    {
        return sprintf('Anggaran AI bulan ini sudah habis (perkiraan US$%s dari US$%s). Pemeriksaan aturan otomatis tetap berjalan; '
            . 'pemeriksaan AI dan isi otomatis dibuka lagi awal bulan depan atau bila Admin menaikkan anggaran.',
            number_format($s['spent'] + $s['reserved'], 2), number_format((float) $s['budget'], 2));
    }

    /** Catat di riwayat aktivitas sekali per bulan saat pemakaian melewati ambang peringatan. */
    private function auditThresholdOnce(): void
    {
        $budget = $this->budget();
        if (! $budget || AppSetting::get(self::KEY_WARNED) === now()->format('Y-m')) {
            return;
        }
        $spent = $this->spent();
        if ($spent / $budget * 100 >= $this->warnPct()) {
            AppSetting::put(self::KEY_WARNED, now()->format('Y-m'));
            Audit::system('anggaran.peringatan', null, sprintf('Pemakaian AI mencapai %d%% anggaran bulan ini (US$%s dari US$%s)',
                (int) floor($spent / $budget * 100), number_format($spent, 2), number_format($budget, 2)));
        }
    }
}
