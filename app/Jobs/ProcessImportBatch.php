<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\SimrsFtp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Unduh file dari FTP SIMRS ke penyimpanan aplikasi.
 * Identitas dan jenis dokumen sudah ditebak dari nama folder/file saat impor dibuat; tidak memakai AI.
 */
class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $batchId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function handle(SimrsFtp $ftp): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch) {
            return;
        }
        $batch->update(['status' => 'memilah']);
        $disk = Storage::disk(config('casemix.storage_disk'));

        foreach ($batch->items()->where('status', 'antre')->get() as $item) {
            try {
                $ext = Str::lower(pathinfo($item->original_name, PATHINFO_EXTENSION));
                $local = "imports/{$batch->id}/" . Str::ulid() . ".$ext";
                $ftp->download($item->remote_path, $local);

                $abs = $disk->path($local);
                $item->update([
                    'local_path' => $local,
                    'mime' => $disk->mimeType($local) ?: 'application/octet-stream',
                    'sha256' => hash_file('sha256', $abs),
                    'status' => 'dipilah',
                ]);
            } catch (Throwable $e) {
                $item->update(['status' => 'gagal', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
            $batch->increment('processed');
        }

        $ftp->client()->close();
        $batch->update(['status' => 'siap_ditinjau']);
    }
}
