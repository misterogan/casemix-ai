<?php

namespace App\Console\Commands;

use App\Jobs\ReadFolderDocument;
use App\Models\FolderDocument;
use App\Services\Import\SimrsFtp;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

/** Unduh dan simpan salinan terenkripsi untuk dokumen lama yang belum punya salinan (tanpa memanggil AI). */
class ArchiveBackfill extends Command
{
    protected $signature = 'casemix:archive-backfill {--limit=50 : Jumlah dokumen maksimal yang diunduh}';

    protected $description = 'Simpan salinan arsip dokumen yang belum tersimpan, untuk folder yang masih ada di FTP';

    public function handle(SimrsFtp $ftp): int
    {
        if (! ReadFolderDocument::archiveEnabled()) {
            $this->warn('Arsip dimatikan (CASEMIX_ARCHIVE_ENABLED=false).');

            return self::SUCCESS;
        }
        $limit = max(1, (int) $this->option('limit'));
        $saved = 0;
        $bytes = 0;

        $docs = FolderDocument::with('folder')->whereNull('stored_path')
            ->whereHas('folder', fn ($q) => $q->whereNull('missing_at'))->orderByDesc('id')->lazyById(100);
        foreach ($docs as $d) {
            if ($saved >= $limit) {
                break;
            }
            $d->refresh(); // bisa sudah terisi lewat dokumen kembar
            if ($d->stored_path) {
                continue;
            }
            try {
                $size = ReadFolderDocument::archiveFromFtp($d, $ftp);
            } catch (Throwable $e) {
                $this->warn("Gagal: {$d->name} (dokumen #{$d->id}): " . $e->getMessage());

                continue;
            }
            if ($d->stored_path) {
                $saved++;
                $bytes += $size;
            }
        }

        $this->info("Tersimpan: $saved dokumen, ukuran total " . Number::fileSize($bytes, 1) . '.');

        return self::SUCCESS;
    }
}
