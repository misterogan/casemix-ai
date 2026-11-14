<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FolderAnalysis;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Verification\FolderAnalyzer;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Tahap 2: periksa silang antardokumen (satu panggilan teks), lalu simpan rekomendasi keseluruhan. */
class SynthesizeFolderAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $analysisId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function handle(AiManager $ai, FolderAnalyzer $analyzer, AiBudget $budget): void
    {
        $a = FolderAnalysis::with(['folder', 'documents'])->find($this->analysisId);
        if (! $a || ! $a->status?->isActive()) {
            return;
        }
        $folder = $a->folder;

        try {
            // Berhenti saat gagal: tahap 2 tidak memanggil AI bila ada dokumen yang gagal dibaca
            if ($failed = $a->documents->firstWhere('status', ReviewStatus::Gagal)) {
                throw new RuntimeException("Dihentikan: {$failed->name} gagal dibaca. Dokumen berikutnya tidak diproses.");
            }
            $config = $ai->defaultConfig();
            $req = $analyzer->synthesisRequest($a, $config);
            if (! $req) {
                $err = $a->documents->firstWhere('status', ReviewStatus::Gagal)?->error;
                throw new RuntimeException('Tidak ada dokumen yang berhasil dibaca AI' . ($err ? ": $err" : '.'));
            }
            if ($blocked = $budget->runBlocker()) {
                throw new RuntimeException($blocked);
            }
            $res = $ai->for($config)->generate($req);
            $budget->charge($a, $res, $config->id);
            ReadFolderDocument::ensureComplete($res, $req->maxTokens);
            $r =$analyzer->synthesize($a, $res->json());

            $a->update([
                'status' => ReviewStatus::Selesai,
                'summary' => $r['summary'],
                'findings' => $r['findings'],
                'suggestions' => $r['suggestions'],
                'extracted' => $r['extracted'],
                'error' => null,
                'finished_at' => now(),
            ]);
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
