<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\AiReview;
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

    public int $timeout = 300;

    public function __construct(public int $reviewId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->reviewId;
    }

    public function handle(AiManager $ai, ClaimReviewer $reviewer, ConsistencyChecker $rules): void
    {
        $review = AiReview::with('claim.documents')->find($this->reviewId);
        if (! $review || $review->status !== ReviewStatus::Antre) {
            return;
        }
        $claim = $review->claim;
        $start = microtime(true);

        // started_at dipakai sebagai patokan "usang": perubahan setelah titik ini membuat hasil usang.
        $review->update(['status' => ReviewStatus::Berjalan, 'started_at' => now()]);

        try {
            $config = $ai->defaultConfig();
            $ruleFindings = $rules->check($claim);
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

            Audit::system('ai.selesai', $claim, 'Pemeriksaan AI selesai (' . count($result['findings']) . ' temuan, '
                . count($result['suggestions']) . ' saran diagnosis)', $review->requested_by, ['review' => $review->id, 'model' => $review->model]);
        } catch (Throwable $e) {
            // Temuan aturan tetap disimpan agar petugas tetap mendapat hasil sebagian.
            $review->update([
                'status' => ReviewStatus::Gagal,
                'rule_findings' => $ruleFindings ?? null,
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
