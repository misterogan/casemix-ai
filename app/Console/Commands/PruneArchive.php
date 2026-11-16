<?php

namespace App\Console\Commands;

use App\Models\FolderDocument;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Hapus salinan arsip yang melewati masa simpan; hasil verifikasi tetap. */
class PruneArchive extends Command
{
    protected $signature = 'casemix:archive-prune';

    protected $description = 'Hapus salinan arsip dokumen yang lebih tua dari masa simpan (casemix.archive.retention_months)';

    public function handle(): int
    {
        $months = (int) config('casemix.archive.retention_months', 24);
        $cutoff = now()->subMonths($months);
        $old = FolderDocument::whereNotNull('stored_path')->where('stored_at', '<', $cutoff);
        $paths = (clone $old)->distinct()->pluck('stored_path');
        $docs = $old->update(['stored_path' => null]);

        $disk = Storage::disk(config('casemix.storage_disk'));
        $files = 0;
        foreach ($paths as $p) {
            // File yang masih dipakai dokumen yang lebih baru tetap disimpan
            if (! FolderDocument::where('stored_path', $p)->exists() && $disk->exists($p)) {
                $disk->delete($p);
                $files++;
            }
        }

        if ($docs) {
            Audit::system('arsip.hapus_kedaluwarsa', null, "Menghapus salinan arsip lebih tua dari $months bulan: $files file ($docs dokumen)",
                null, ['files' => $files, 'documents' => $docs, 'cutoff' => $cutoff->toDateTimeString()]);
        }
        $this->info("Dihapus: $files file salinan ($docs dokumen).");

        return self::SUCCESS;
    }
}
