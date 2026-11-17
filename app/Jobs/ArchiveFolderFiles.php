<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Services\Import\SimrsFtp;
use App\Services\Import\DocumentGuesser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Arsip otomatis saat sinkron FTP (tanpa AI): tiap file folder diunduh sekali dan disimpan terenkripsi. */
class ArchiveFolderFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $folderId) {}

    public function handle(SimrsFtp $ftp): void
    {
        $folder = FtpFolder::find($this->folderId);
        if (! $folder || $folder->missing_at || ! ReadFolderDocument::archiveEnabled()) {
            return;
        }
        $files = array_values($folder->files ?? []);
        $maxMb = (int) config('casemix.archive.auto_max_folder_mb', 200);
        $bytes = array_sum(array_map(fn ($f) => (int) ($f['size'] ?? 0), $files));
        if ($bytes > $maxMb * 1024 * 1024) {
            Log::warning('Arsip otomatis dilewati: folder terlalu besar', ['folder_id' => $folder->id, 'mb' => round($bytes / 1048576, 1), 'batas_mb' => $maxMb]);

            return;
        }

        foreach ($files as $i => $f) {
            $fp = FolderDocument::fingerprintOf($f);
            $same = FolderDocument::where('ftp_folder_id', $folder->id)->where('name', $f['name'])->where('file_fingerprint', $fp);
            if ((clone $same)->whereNotNull('stored_path')->exists()) {
                continue; // sudah tersimpan (sha256 sama)
            }
            $d = (clone $same)->latest('id')->first() ?? FolderDocument::create([
                'folder_analysis_id' => null,
                'ftp_folder_id' => $folder->id,
                'file_index' => $i,
                'name' => $f['name'],
                'file_fingerprint' => $fp,
                'doc_type' => DocumentGuesser::type($f['name'])->value,
                'status' => ReviewStatus::Diarsipkan,
            ]);
            $d->setRelation('folder', $folder);
            try {
                ReadFolderDocument::archiveFromFtp($d, $ftp);
            } catch (Throwable $e) {
                Log::warning('Arsip otomatis gagal', ['folder_id' => $folder->id, 'file' => $f['name'], 'error' => $e->getMessage()]);
            }
        }
    }
}
