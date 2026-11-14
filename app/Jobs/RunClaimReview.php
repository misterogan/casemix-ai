<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\AiReview;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Ai\ClaimReviewer;
use App\Services\Rules\ConsistencyChecker;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunClaimReview implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    /**
     * Harus lebih panjang dari timeout penyedia AI × 3 percobaan (retry 2×), dan lebih pendek dari
     * REDIS_QUEUE_RETRY_AFTER, supaya job tidak dimatikan atau diambil worker lain di tengah jalan.
     */
    public int $timeout = 1800;

    public function __construct(public int $reviewId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->reviewId;
    }

    public function handle(AiManager $ai, ClaimReviewer $reviewer, ConsistencyChecker $rules, AiBudget $budget): void
    {
        $review = AiReview::with('claim.documents')->find($this->reviewId);
        if (! $review || $review->status !== ReviewStatus::Antre) {
            return;
        }
        $claim = $review->claim;
        $start = microtime(true);

        // started_at dipakai sebagai patokan "usang": perubahan setelah titik ini membuat hasil usang.
        $review->update(['status' => ReviewStatus::Berjalan, 'started_at' => now()]);

        // Aturan E-Klaim dijalankan lebih dulu agar hasilnya tetap tersimpan bila AI gagal.
        $ruleFindings = $rules->check($claim);

        try {
            // Pengecekan anggaran terakhir: job bisa sudah mengantre sebelum anggaran habis.
            if ($blocked = $budget->runBlocker()) {
                throw new \RuntimeException($blocked);
            }
            $config = $ai->defaultConfig();
            $result = $reviewer->review($claim, $config, $ruleFindings);

            $review->update([
                'ai_provider_id' => $config->id,
                'model' => $result['response']->model,
                'status' => ReviewStatus::Selesai,
                'summary' => $result['summary'],
                'findings' => $result['findings'],
                'rule_findings' => $ruleFindings,
                'suggestions' => $result['suggestions'],
                'documents_snapshot' => $claim->documents->map(fn ($d) => ['id' => $d->id, 'sha256' => $d->sha256])->all(),
                'tokens_in' => $result['response']->tokensIn,
                'tokens_out' => $result['response']->tokensOut,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'finished_at' => now(),
            ]);

            $budget->record($review);
            Audit::system('ai.selesai', $claim, 'Pemeriksaan AI selesai (' . count($result['findings']) . ' temuan, '
                . count($result['suggestions']) . ' saran diagnosis)', $review->requested_by, ['review' => $review->id, 'model' => $review->model]);
        } catch (Throwable $e) {
            // Temuan aturan tetap disimpan agar petugas tetap mendapat hasil sebagian.
            $review->update([
                'status' => ReviewStatus::Gagal,
                'rule_findings' => $ruleFindings,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'finished_at' => now(),
            ]);
            Audit::system('ai.gagal', $claim, 'Pemeriksaan AI gagal: ' . mb_substr($e->getMessage(), 0, 180), $review->requested_by);
            report($e);
        }

        $claim->refresh()->refreshStatus();
    }
}
