<?php

namespace App\Jobs;

use App\Models\PnpkButir;
use App\Models\PnpkDokumen;
use App\Models\PnpkIcd;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Pnpk\PnpkProposer;
use App\Services\Pnpk\PnpkVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * AI mengusulkan ICD & butir per ±15 halaman (suhu 0). Sistem memverifikasi tanpa AI:
 * kutipan tidak ditemukan (±1 halaman) atau ICD tidak ada di master → langsung ditolak. Manusia menyetujui sisanya.
 */
class UsulkanButirPnpk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $dokumenId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function handle(AiManager $ai, PnpkProposer $proposer, PnpkVerifier $verifier, AiBudget $budget): void
    {
        $d = PnpkDokumen::find($this->dokumenId);
        if (! $d) {
            return;
        }
        try {
            $d->update(['status_proses' => 'usulan', 'error' => null]);
            $pages = $d->halaman()->pluck('teks', 'halaman')->all();
            $config = $ai->defaultConfig();
            $ada = PnpkIcd::where('dokumen_id', $d->id)->pluck('kode')->all();

            foreach (array_chunk($pages, PnpkProposer::CHUNK, true) as $chunk) {
                if ($blocked = $budget->runBlocker()) {
                    throw new RuntimeException($blocked);
                }
                $req = $proposer->request($ai->model('usulan_regulasi', $config), (int) $config->max_tokens ?: 4096, $d->judul, $chunk);
                $req->jenis = 'usulan_regulasi';
                $req->refId = $d->id;
                $res = $ai->for($config)->generate($req);
                $budget->charge($d, $res, $config->id); // biaya usulan, dijumlah dengan OCR
                $parsed = $proposer->parse((array) $res->json(), array_map('intval', array_keys($chunk)));

                foreach ($parsed['icd'] as $kode) {
                    if (in_array($kode, $ada, true)) {
                        continue;
                    }
                    $ada[] = $kode;
                    $valid = $verifier->icdValid($kode);
                    PnpkIcd::create(['dokumen_id' => $d->id, 'kode' => $kode, 'sumber' => 'ai', 'status' => $valid ? 'usulan' : 'ditolak',
                        'alasan_tolak' => $valid ? null : PnpkVerifier::TOLAK_ICD]);
                }
                foreach ($parsed['butir'] as $b) {
                    $halPdf = $verifier->cariHalaman($b['kutipan'], $b['halaman'], $pages);
                    $cocok = $halPdf !== null;
                    PnpkButir::create($b + ['dokumen_id' => $d->id, 'halaman_pdf' => $halPdf, 'cocok_kutipan' => $cocok, 'status' => $cocok ? 'usulan' : 'ditolak',
                        'alasan_tolak' => $cocok ? null : PnpkVerifier::TOLAK_KUTIPAN]);
                }
            }
            app(\App\Services\Pnpk\PnpkIcdTeks::class)->simpan($d); // ICD tertulis di teks, tanpa AI
            $d->update(['status_proses' => 'siap_tinjau']);
        } catch (Throwable $e) {
            $d->update(['status_proses' => 'gagal', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            report($e);
        }
    }
}
