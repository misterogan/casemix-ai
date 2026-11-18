<?php

namespace App\Jobs;

use App\Services\Vclaim\VclaimClient;
use App\Services\Vclaim\VclaimSync;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Histori pelayanan VClaim satu peserta (hanya GET), cache 24 jam. */
class FetchVclaimHistori implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $noKartu) {}

    public function handle(VclaimSync $sync): void
    {
        if (! VclaimClient::enabled() || trim($this->noKartu) === '') {
            return;
        }
        $n = $sync->histori($this->noKartu);
        Audit::system('vclaim.histori', null,
            $n === null ? 'Ambil histori pelayanan VClaim gagal' : "Ambil histori pelayanan VClaim: {$n} baris");
    }
}
