<?php

namespace App\Console\Commands;

use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderSync;
use Illuminate\Console\Command;

class SyncFtpFolders extends Command
{
    protected $signature = 'casemix:sync-ftp';

    protected $description = 'Perbarui daftar folder pasien dari FTP DHealth (berjalan otomatis tiap jam)';

    public function handle(SimrsFtp $ftp, FolderSync $sync): int
    {
        if (! $ftp->configured()) {
            $this->warn('FTP belum diatur. Lewati.');

            return self::SUCCESS;
        }
        $s = $sync->run();
        $this->info("{$s['folders']} folder · {$s['baru']} baru · {$s['berubah']} berubah · {$s['hilang']} tidak ada lagi");

        return self::SUCCESS;
    }
}
