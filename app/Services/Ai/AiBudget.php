<?php

namespace App\Services\Ai;

use App\Enums\ReviewStatus;
use App\Models\AiProvider;
use App\Models\AiReview;
use App\Models\AppSetting;
use App\Models\ClaimExtraction;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\PnpkDokumen;
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

    /** Semua jenis pemanggilan AI yang dicatat biayanya, dengan label untuk laporan. */
    public const SOURCES = [
        FolderAnalysis::class => 'Verifikasi folder (periksa silang)',
        FolderDocument::class => 'Verifikasi folder (baca dokumen)',
        AiReview::class => 'Pemeriksaan berkas',
        ClaimExtraction::class => 'Usulan isian',
        PnpkDokumen::class => 'PNPK (OCR & usulan butir)',
    ];

    /** Sumber yang kolom status/penggunanya berbeda dari bawaan (status antre|berjalan, requested_by). */
    private const STATUS_COLUMN = [PnpkDokumen::class => ['status_proses', ['antre', 'ekstrak', 'usulan']]];

    private const USER_COLUMN = [FolderDocument::class => null, PnpkDokumen::class => 'diunggah_oleh'];

    /** Anggaran bulanan dalam USD, atau null bila tidak dibatasi. Sumber: app_settings.ai_budget_usd; env hanya nilai awal bila belum diisi. */
    public function budget(): ?float
    {
        $v = AppSetting::get(self::KEY_BUDGET) ?? config('casemix.ai.anggaran_bulanan_usd', 0);

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

    /** Tambahkan token satu pemanggilan ke baris (dijumlah dengan pemanggilan sebelumnya, mis. baca ulang), lalu catat biayanya. */
    public function charge(Model $row, AiResponse $res, ?int $providerId): void
    {
        if ($res->tokensIn === null && $res->tokensOut === null) {
            return;
        }
        $row->forceFill([
            'ai_provider_id' => $providerId,
            'model' => $res->model,
            'tokens_in' => (int) $row->tokens_in + (int) $res->tokensIn,
            'tokens_out' => (int) $row->tokens_out + (int) $res->tokensOut,
            'finished_at' => now(),
        ]);
        $this->record($row);
    }

    public function monthStart(): Carbon
    {
        return now()->startOfMonth();
    }

    /** Perkiraan biaya bulan berjalan (USD) = SUM(ai_usage.biaya_usd), sama dengan halaman Pemakaian AI. */
    public function spent(): float
    {
        return \App\Models\AiUsage::totalBulanIni();
    }

    /** Pemanggilan bulan ini yang memakai token tetapi harganya tidak diketahui (biaya tidak terhitung). */
    public function unpriced(): int
    {
        return \App\Models\AiUsage::where('created_at', '>=', $this->monthStart())->where('biaya_usd', '<=', 0)
            ->where(fn ($q) => $q->where('input_tokens', '>', 0)->orWhere('output_tokens', '>', 0))->count();
    }

    /** Rata-rata biaya per pemanggilan dari 50 pemanggilan terakhir, untuk memperkirakan antrean. */
    public function averageJobCost(): float
    {
        $costs = collect(array_keys(self::SOURCES))->flatMap(fn ($m) => $m::whereNotNull('cost_usd')->latest('id')->limit(50)->pluck('cost_usd'));

        return $costs->isEmpty() ? (float) config('casemix.budget.default_job_cost_usd') : (float) $costs->avg();
    }

    public function activeJobs(): int
    {
        $active = [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value];

        return collect(array_keys(self::SOURCES))->sum(function ($m) use ($active) {
            [$col, $values] = self::STATUS_COLUMN[$m] ?? ['status', $active];

            return $m::whereIn($col, $values)->count();
        });
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

    /** Alasan proses AI baru tidak boleh dimulai (dengan memperhitungkan antrean), atau null. Analisa boleh memakai cadangan. */
    public function startBlocker(?string $jenis = null): ?string
    {
        $s = $this->status();
        if ($jenis === 'analisa' && $s['state'] === 'habis') {
            return $s['spent'] + $s['reserved'] >= $s['budget'] + $this->cadangan() ? $this->message($s) : null;
        }

        return $s['state'] === 'habis' ? $this->message($s) : null;
    }

    /**
     * Pengecekan terakhir di dalam job, tepat sebelum memanggil AI. Tidak memperhitungkan antrean (termasuk job ini).
     * Analisa diserahkan ke gate() (cadangan, lalu ditahan "menunggu anggaran", bukan gagal).
     */
    public function runBlocker(?string $jenis = null): ?string
    {
        $budget = $this->budget();
        if ($jenis === 'analisa') {
            return null;
        }

        return $budget && $this->spent() >= $budget ? $this->message($this->status()) : null;
    }

    private function cadangan(): float
    {
        return (float) config('casemix.ai.anggaran_cadangan_analisa_usd', 1);
    }

    // ------------------------------------------------ penahan per jenis (tabel ai_usage)

    /** Anggaran yang sama dengan budget(); 0 = tanpa batas. */
    public function anggaran(): float
    {
        return (float) ($this->budget() ?? 0);
    }

    /** Persen pemakaian ai_usage bulan ini terhadap anggaran, atau null bila tanpa batas. */
    public function persenPemakaian(): ?int
    {
        $a = $this->anggaran();

        return $a > 0 ? (int) floor(\App\Models\AiUsage::totalBulanIni() / $a * 100) : null;
    }

    /**
     * Dicek sebelum tiap panggilan AI. ≥100%: usulan_regulasi & draf_sanggahan ditolak;
     * analisa boleh memakai cadangan, sesudahnya ditahan (AnggaranHabis).
     */
    public function gate(string $jenis): void
    {
        $a = $this->anggaran();
        if ($a <= 0) {
            return;
        }
        $spent = \App\Models\AiUsage::totalBulanIni();
        if ($spent < $a) {
            return;
        }
        $teks = sprintf('Anggaran AI bulan ini habis (US$%s dari US$%s).', number_format($spent, 2), number_format($a, 2));
        if ($jenis !== 'analisa') {
            throw new AnggaranHabis("$teks Usulan regulasi dan draf sanggahan tidak dijalankan sampai anggaran dinaikkan atau bulan berganti.");
        }
        if ($spent >= $a + $this->cadangan()) {
            throw new AnggaranHabis("$teks Cadangan analisa juga habis; analisa menunggu anggaran dan bisa dilanjutkan setelah anggaran dinaikkan.");
        }
    }

    /** Ringkasan per bulan dan per fitur/petugas untuk halaman Admin. */
    public function report(int $months = 6): array
    {
        $from = $this->monthStart()->subMonths($months - 1);
        $rows = collect();
        foreach (self::SOURCES as $model => $label) {
            $user = array_key_exists($model, self::USER_COLUMN) ? self::USER_COLUMN[$model] : 'requested_by';
            $rows = $rows->merge($model::query()->where('finished_at', '>=', $from)->whereNotNull('finished_at')
                ->get(array_merge(['finished_at', 'cost_usd', 'tokens_in', 'tokens_out'], $user ? [$user] : []))
                ->map(fn ($r) => ['bulan' => $r->finished_at->format('Y-m'), 'fitur' => $label, 'biaya' => (float) $r->cost_usd,
                    'token' => (int) $r->tokens_in + (int) $r->tokens_out, 'user' => $user ? $r->{$user} : null]));
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
