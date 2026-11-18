<?php

namespace App\Console\Commands;

use App\Services\Vclaim\VclaimClient;
use App\Services\Vclaim\VclaimSync;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Console\Command;

class VclaimSyncKlaim extends Command
{
    protected $signature = 'casemix:vclaim-sync-klaim {--from=} {--to=}';

    protected $description = 'Ambil Monitoring Klaim VClaim (hanya GET) dan simpan status terbaru per SEP';

    public function handle(VclaimSync $sync): int
    {
        if (! VclaimClient::enabled()) {
            return self::SUCCESS;
        }
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : today();
        $from = $this->option('from') ? Carbon::parse($this->option('from'))
            : today()->subDays((int) config('casemix.vclaim.window_klaim_hari'));

        $r = $sync->klaim($from, $to);
        Audit::system('vclaim.sync_klaim', null,
            "Sinkron VClaim klaim {$from->toDateString()} s.d. {$to->toDateString()}: {$r['jumlah']} baris, {$r['gagal']} panggilan gagal",
            null, $r);
        $this->info("Klaim: {$r['jumlah']} baris, {$r['gagal']} gagal.");

        return self::SUCCESS;
    }
}
