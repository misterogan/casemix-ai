<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FolderAnalysis;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Verification\FolderAnalyzer;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Analisa AI atas satu folder FTP. Hasilnya disimpan; membuka folder lagi tidak memanggil AI. */
class RunFolderAnalysis implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $analysisId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function uniqueId(): string
    {
        return 'folder-analisa-' . $this->analysisId;
    }

    public function handle(AiManager $ai, FolderAnalyzer $analyzer, AiBudget $budget): void
    {
        $a = FolderAnalysis::with('folder')->find($this->analysisId);
        if (! $a || $a->status !== ReviewStatus::Antre) {
            return;
        }
        $folder = $a->folder;
        $a->update(['status' => ReviewStatus::Berjalan, 'started_at' => now(), 'fingerprint' => $folder->fingerprint, 'files_snapshot' => $folder->files]);
        $folder->update(['analysis_status' => 'berjalan', 'last_analysis_id' => $a->id]);

        try {
            if ($blocked = $budget->runBlocker()) {
                throw new \RuntimeException($blocked);
            }
            $config = $ai->defaultConfig();
            $r = $analyzer->analyze($folder, $config);

            $a->update([
                'status' => ReviewStatus::Selesai,
                'summary' => $r['summary'],
                'findings' => $r['findings'],
                'suggestions' => $r['suggestions'],
                'extracted' => $r['extracted'],
                'ai_provider_id' => $config->id,
                'model' => $r['response']->model,
                'tokens_in' => $r['response']->tokensIn,
                'tokens_out' => $r['response']->tokensOut,
                'finished_at' => now(),
            ]);
            $budget->record($a);
            $folder->update([
                'analysis_status' => 'selesai',
                'analyzed_fingerprint' => $a->fingerprint,
                'kritis_count' => $a->countBy('kritis'),
                'perhatian_count' => $a->countBy('perhatian'),
                'analyzed_at' => now(),
            ]);
            Audit::system('folder.analisa_selesai', $folder, sprintf('Analisa AI selesai: %d kritis, %d perhatian', $a->countBy('kritis'), $a->countBy('perhatian')), $a->requested_by, ['analisa' => $a->id]);
        } catch (Throwable $e) {
            $a->update(['status' => ReviewStatus::Gagal, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            $folder->update(['analysis_status' => 'gagal']);
            Audit::system('folder.analisa_gagal', $folder, 'Analisa AI gagal: ' . mb_substr($e->getMessage(), 0, 180), $a->requested_by);
            report($e);
        }
    }
}
