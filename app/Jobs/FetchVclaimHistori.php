<?php

namespace App\Jobs;

use App\Services\Vclaim\VclaimClient;
use App\Services\Vclaim\VclaimHistoriService;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Histori pelayanan VClaim satu peserta (hanya GET), simpanan 24 jam lewat VclaimHistoriService. */
class FetchVclaimHistori implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $noKartu) {}

    public function handle(): void
    {
        if (! VclaimClient::enabled() || trim($this->noKartu) === '') {
            return;
        }
        [$mulai, $akhir] = VclaimHistoriService::rentang(null);
        try {
            $res = app(VclaimHistoriService::class)->fetch($this->noKartu, $mulai, $akhir);
            $msg = 'Ambil histori pelayanan VClaim: '.$res['rows']->count().' baris'.($res['from_cache'] ? ' (dari simpanan)' : '');
        } catch (Throwable) {
            $msg = 'Ambil histori pelayanan VClaim gagal';
        }
        Audit::system('vclaim.histori', null, $msg);
    }
}
