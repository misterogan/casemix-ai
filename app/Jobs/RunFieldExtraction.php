<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\ClaimExtraction;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Ai\FieldExtractor;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Menyiapkan usulan isian data klaim dari dokumen. Tidak mengubah data klaim. */
class RunFieldExtraction implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $extractionId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function uniqueId(): string
    {
        return 'ekstraksi-' . $this->extractionId;
    }

    public function handle(AiManager $ai, FieldExtractor $extractor, AiBudget $budget): void
    {
        $x = ClaimExtraction::with('claim.documents')->find($this->extractionId);
        if (! $x || $x->status !== ReviewStatus::Antre) {
            return;
        }
        $claim = $x->claim;
        $x->update(['status' => ReviewStatus::Berjalan, 'started_at' => now()]);

        try {
            // Pengecekan anggaran terakhir: job bisa sudah mengantre sebelum anggaran habis.
            if ($blocked = $budget->runBlocker()) {
                throw new \RuntimeException($blocked);
            }
            $config = $ai->defaultConfig();
            $result = $extractor->extract($claim, $config);

            $x->update([
                'ai_provider_id' => $config->id,
                'model' => $result['response']->model,
                'status' => ReviewStatus::Selesai,
                'suggestions' => $result['suggestions'],
                'documents_snapshot' => $claim->documents->map(fn ($d) => ['id' => $d->id, 'sha256' => $d->sha256])->values()->all(),
                'tokens_in' => $result['response']->tokensIn,
                'tokens_out' => $result['response']->tokensOut,
                'finished_at' => now(),
            ]);
            $budget->record($x);
            Audit::system('isian.selesai', $claim, 'Usulan isian dari dokumen siap (' . count($result['suggestions']) . ' field)', $x->requested_by, ['ekstraksi' => $x->id]);
        } catch (Throwable $e) {
            $x->update(['status' => ReviewStatus::Gagal, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            Audit::system('isian.gagal', $claim, 'Usulan isian gagal: ' . mb_substr($e->getMessage(), 0, 180), $x->requested_by);
            report($e);
        }
    }
}
