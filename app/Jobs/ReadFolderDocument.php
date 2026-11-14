<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FolderDocument;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiResponse;
use App\Services\Verification\FolderAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Tahap 1: AI membaca satu file. Error ditangkap di sini (status gagal) supaya dokumen lain dan periksa silang tetap jalan. */
class ReadFolderDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $documentId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function handle(AiManager $ai, FolderAnalyzer $analyzer, AiBudget $budget): void
    {
        $d = FolderDocument::with('folder')->find($this->documentId);
        if (! $d || $d->status !== ReviewStatus::Antre) {
            return;
        }
        // Berhenti saat gagal: dokumen sebelumnya gagal → yang ini tidak diproses (tanpa memanggil AI)
        if (FolderDocument::where('folder_analysis_id', $d->folder_analysis_id)->where('status', ReviewStatus::Gagal->value)->exists()) {
            $d->update(['status' => ReviewStatus::Dibatalkan, 'error' => 'Tidak diproses karena dokumen sebelumnya gagal.', 'finished_at' => now()]);

            return;
        }
        $d->update(['status' => ReviewStatus::Berjalan, 'started_at' => now(), 'error' => null]);

        try {
            if ($blocked = $budget->runBlocker()) {
                throw new RuntimeException($blocked);
            }
            $config = $ai->defaultConfig();
            $req = $analyzer->documentRequest($d, $config);
            if (! $req) {
                $d->update(['status' => ReviewStatus::Selesai, 'summary' => null, 'findings' => [], 'finished_at' => now(),
                    'error' => 'Bukan PDF/gambar; tidak dibaca AI.']);

                return;
            }

            $res = $ai->for($config)->generate($req);
            $budget->charge($d, $res, $config->id); // biaya tercatat walau jawabannya gagal diolah
            self::ensureComplete($res, $req->maxTokens);
            $r =$analyzer->parseDocument($d, $res->json());

            $d->update(['status' => ReviewStatus::Selesai, 'summary' => $r['summary'], 'findings' => $r['findings'], 'finished_at' => now()]);
        } catch (Throwable $e) {
            $d->update(['status' => ReviewStatus::Gagal, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
            report($e);
        }
    }

    /** Jawaban yang terpotong karena batas token dianggap gagal dengan pesan jelas, walau sisanya kebetulan bisa dibaca. */
    public static function ensureComplete(AiResponse $res, int $maxTokens): void
    {
        if (in_array($res->raw['stop_reason'] ?? null, ['max_tokens', 'length'], true)) {
            throw new RuntimeException("Jawaban AI terpotong karena batas $maxTokens token tercapai.");
        }
    }
}
