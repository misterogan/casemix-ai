<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FolderAnalysis;
use App\Services\Verification\FolderAnalyzer;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Memulai analisa satu folder FTP: siapkan daftar dokumen (file yang tidak berubah memakai hasil lama),
 * lalu antrekan baca per dokumen dan periksa silang secara berurutan.
 */
class RunFolderAnalysis implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $analysisId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function uniqueId(): string
    {
        return 'folder-analisa-' . $this->analysisId;
    }

    public function handle(FolderAnalyzer $analyzer): void
    {
        $a = FolderAnalysis::with('folder')->find($this->analysisId);
        if (! $a || $a->status !== ReviewStatus::Antre) {
            return;
        }
        $folder = $a->folder;

        try {
            $analyzer->prepare($a);
            $a->update(['status' => ReviewStatus::Berjalan, 'started_at' => now()]);
            $folder->update(['analysis_status' => 'berjalan', 'last_analysis_id' => $a->id]);

            $jobs = $a->documents()->where('status', ReviewStatus::Antre->value)->pluck('id')
                ->map(fn ($id) => new ReadFolderDocument($id))->all();
            $jobs[] = new SynthesizeFolderAnalysis($a->id);
            Bus::chain($jobs)->onQueue(config('casemix.ai.queue'))->dispatch();
        } catch (Throwable $e) {
            $a->update(['status' => ReviewStatus::Gagal, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            $folder->update(['analysis_status' => 'gagal']);
            Audit::system('folder.analisa_gagal', $folder, 'Analisa AI gagal: ' . mb_substr($e->getMessage(), 0, 180), $a->requested_by);
            report($e);
        }
    }
}
