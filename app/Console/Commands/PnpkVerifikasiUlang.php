<?php

namespace App\Console\Commands;

use App\Models\PnpkDokumen;
use App\Services\Pnpk\PnpkVerifikasiUlang as Layanan;
use Illuminate\Console\Command;

/** Verifikasi ulang kutipan & ICD PNPK dengan aturan terbaru, tanpa memanggil AI. */
class PnpkVerifikasiUlang extends Command
{
    protected $signature = 'casemix:pnpk-verifikasi-ulang {--dokumen= : ID dokumen PNPK (bawaan: semua)}';

    protected $description = 'Verifikasi ulang kutipan butir & ICD PNPK tanpa AI';

    public function handle(Layanan $layanan): int
    {
        $q = PnpkDokumen::query()->orderBy('id');
        if ($id = $this->option('dokumen')) {
            $q->whereKey((int) $id);
        }
        $n = 0;
        foreach ($q->get() as $d) {
            $h = $layanan->jalankan($d);
            $this->line("#{$d->id} {$d->judul}: butir cocok {$h['butir_sebelum']} → {$h['butir_sesudah']}; ICD usulan {$h['icd_sebelum']} → {$h['icd_sesudah']}");
            $n++;
        }
        $n ?: $this->warn('Tidak ada dokumen.');

        return self::SUCCESS;
    }
}
