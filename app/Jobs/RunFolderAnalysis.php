<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FolderAnalysis;
use App\Services\Verification\FolderAnalyzer;
use App\Services\Verification\VerifikasiLatar;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Memulai analisa satu folder FTP: siapkan daftar dokumen (file yang tidak berubah memakai hasil lama),
 * lalu serahkan ke VerifikasiBerkas (baca per dokumen lalu periksa silang; bisa dijeda/dilanjutkan).
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

    public function handle(FolderAnalyzer $analyzer, VerifikasiLatar $latar): void
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

            // Baca per file lewat VerifikasiBerkas (bisa dijeda/dilanjutkan), di antrean yang sama dengan job ini
            if ($folder->verifikasi_status !== 'antre' || ! $folder->verifikasi_token) {
                $latar->klaim($folder, true); // analisa yang tidak lewat VerifikasiLatar::mulai (mis. diantrekan sebelum fitur ini)
            }
            $latar->jalankan($folder, $a, $this->queue);
        } catch (Throwable $e) {
            $a->update(['status' => ReviewStatus::Gagal, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            $folder->update(['analysis_status' => 'gagal']);
            $latar->tandaiGagal($folder, 'Analisa AI gagal: ' . $e->getMessage());
            Audit::system('folder.analisa_gagal', $folder, 'Analisa AI gagal: ' . mb_substr($e->getMessage(), 0, 180), $a->requested_by);
            report($e);
        }
    }
}
