<?php

namespace App\Console\Commands;

use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Musnahkan salinan arsip yang melewati masa simpan; hasil verifikasi, saran, dan audit tidak pernah dihapus.
 * Folder yang tidak pernah dianalisa: casemix.archive.auto_retention_months; yang sudah dianalisa: retention_months.
 */
class PruneArchive extends Command
{
    protected $signature = 'casemix:archive-prune';

    protected $description = 'Musnahkan salinan arsip dokumen yang lebih tua dari masa simpan';

    public function handle(): int
    {
        $never = FtpFolder::whereNull('analyzed_at')->whereNull('last_analysis_id')->select('id');
        $rules = [
            'belum_dianalisa' => [(int) config('casemix.archive.auto_retention_months', 3), fn ($q) => $q->whereIn('ftp_folder_id', $never)],
            'sudah_dianalisa' => [(int) config('casemix.archive.retention_months', 24), fn ($q) => $q->whereNotIn('ftp_folder_id', $never)],
        ];
        $disk = Storage::disk(config('casemix.storage_disk'));
        $sum = ['files' => 0, 'folders' => 0, 'documents' => 0, 'bytes' => 0];
        $kategori = [];

        foreach ($rules as $key => [$months, $scope]) {
            $cutoff = now()->subMonths($months);
            $old = $scope(FolderDocument::whereNotNull('stored_path')->where('stored_at', '<', $cutoff));
            $rows = (clone $old)->get(['ftp_folder_id', 'stored_path', 'stored_size']);
            $docs = $old->update(['stored_path' => null, 'stored_sha256' => null, 'stored_size' => null, 'stored_at' => null]);
            $files = 0;
            $bytes = 0;
            foreach ($rows->unique('stored_path') as $r) {
                // File yang masih dipakai dokumen lain (lebih baru) tetap disimpan
                if (! FolderDocument::where('stored_path', $r->stored_path)->exists() && $disk->exists($r->stored_path)) {
                    $disk->delete($r->stored_path);
                    $files++;
                    $bytes += (int) $r->stored_size;
                }
            }
            $folders = $rows->unique('ftp_folder_id')->count();
            $kategori[$key] = ['bulan' => $months, 'cutoff' => $cutoff->toDateTimeString(), 'files' => $files, 'folders' => $folders, 'documents' => $docs, 'bytes' => $bytes];
            foreach (['files', 'folders', 'documents', 'bytes'] as $k) {
                $sum[$k] += $kategori[$key][$k];
            }
        }

        $ukuran = Number::fileSize($sum['bytes'], 1);
        Audit::system('arsip.hapus_kedaluwarsa', null,
            "Pemusnahan salinan arsip: {$sum['files']} file dari {$sum['folders']} folder ($ukuran); belum dianalisa {$kategori['belum_dianalisa']['files']} file, sudah dianalisa {$kategori['sudah_dianalisa']['files']} file",
            null, $sum + ['kategori' => $kategori]);
        $this->info("Dimusnahkan: {$sum['files']} file salinan dari {$sum['folders']} folder ({$sum['documents']} dokumen, $ukuran).");

        return self::SUCCESS;
    }
}
