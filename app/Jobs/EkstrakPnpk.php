<?php

namespace App\Jobs;

use App\Models\RegulasiDokumen;
use App\Services\Ai\AiBudget;
use App\Models\RegulasiHalaman;
use App\Services\Pnpk\PnpkTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Ekstrak teks PDF PNPK per halaman (pdftotext -layout; halaman < 50 karakter lewat OCR) lalu antrekan usulan AI. */
class EkstrakPnpk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $dokumenId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function handle(PnpkTextExtractor $extractor, AiBudget $budget): void
    {
        $d = RegulasiDokumen::find($this->dokumenId);
        if (! $d) {
            return;
        }
        try {
            $d->update(['status_proses' => 'ekstrak', 'error' => null]);
            $extractor->onAi = fn ($res, $providerId) => $budget->charge($d, $res, $providerId); // biaya OCR
            $pages = $extractor->pages($d->pdfPath());
            DB::transaction(function () use ($d, $pages) {
                RegulasiHalaman::where('dokumen_id', $d->id)->delete();
                foreach ($pages as $n => $teks) {
                    RegulasiHalaman::create(['dokumen_id' => $d->id, 'halaman' => $n, 'teks' => $teks]);
                }
                $d->update(['jumlah_halaman' => count($pages), 'status_proses' => 'usulan']);
            });
            UsulkanButirPnpk::dispatch($d->id);
        } catch (Throwable $e) {
            $d->update(['status_proses' => 'gagal', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            report($e);
        }
    }
}
