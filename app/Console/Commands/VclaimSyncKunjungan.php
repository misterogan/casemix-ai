<?php

namespace App\Console\Commands;

use App\Jobs\ApplyVclaimToCermat;
use App\Services\Vclaim\VclaimClient;
use App\Services\Vclaim\VclaimSync;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Console\Command;

class VclaimSyncKunjungan extends Command
{
    protected $signature = 'casemix:vclaim-sync-kunjungan {--from=} {--to=}';

    protected $description = 'Ambil Monitoring Kunjungan VClaim (hanya GET)';

    public function handle(VclaimSync $sync): int
    {
        if (! VclaimClient::enabled()) {
            return self::SUCCESS;
        }
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : today();
        $from = $this->option('from') ? Carbon::parse($this->option('from'))
            : today()->subDays((int) config('casemix.vclaim.window_kunjungan_hari'));

        $r = $sync->kunjungan($from, $to);
        Audit::system('vclaim.sync_kunjungan', null,
            "Sinkron VClaim kunjungan {$from->toDateString()} s.d. {$to->toDateString()}: {$r['jumlah']} baris, {$r['gagal']} panggilan gagal",
            null, $r);
        $this->info("Kunjungan: {$r['jumlah']} baris, {$r['gagal']} gagal.");
        ApplyVclaimToCermat::dispatch();

        return self::SUCCESS;
    }
}
