<?php

namespace App\Services\Import;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Akses FTP SIMRS. Kredensial dibaca dari .env (SIMRS_FTP_*), tidak pernah disimpan di database atau kode.
 * - path              : folder berkas scan klaim yang akan diimpor
 * - path_riwayat_pasien: folder riwayat pasien, dicari berdasarkan No. RM
 */
class SimrsFtp
{
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function disk(): Filesystem
    {
        $c = config('casemix.ftp');

        return Storage::build([
            'driver' => 'ftp',
            'host' => $c['host'],
            'username' => $c['username'],
            'password' => $c['password'],
            'port' => $c['port'],
            'ssl' => $c['ssl'],
            'passive' => $c['passive'],
            'timeout' => $c['timeout'],
            'root' => '/',
            'throw' => true,
        ]);
    }

    public function configured(): bool
    {
        return filled(config('casemix.ftp.host')) && filled(config('casemix.ftp.username'));
    }

    /** @return Collection<int, array{path: string, name: string, size: int, modified: int}> */
    public function listFiles(string $dir, bool $recursive = false): Collection
    {
        $disk = $this->disk();
        $dir = trim($dir, '/');
        $files = $recursive ? $disk->allFiles($dir) : $disk->files($dir);

        return collect($files)
            ->filter(fn ($p) => in_array(Str::lower(pathinfo($p, PATHINFO_EXTENSION)), self::EXTENSIONS, true))
            ->map(fn ($p) => [
                'path' => $p,
                'name' => basename($p),
                'size' => (int) $disk->size($p),
                'modified' => (int) $disk->lastModified($p),
            ])
            ->sortBy('modified')
            ->values();
    }

    /** Cari file riwayat pasien yang nama file atau nama foldernya memuat No. RM. */
    public function findPatientHistory(string $noRm): Collection
    {
        $rm = preg_replace('/\D/', '', $noRm);
        if ($rm === '') {
            return collect();
        }
        $needle = ltrim($rm, '0');

        return $this->listFiles(config('casemix.ftp.path_riwayat_pasien'), recursive: true)
            ->filter(fn ($f) => str_contains(preg_replace('/\D/', '', $f['path']), $needle))
            ->values();
    }

    public function download(string $remotePath, string $localRelative): string
    {
        $stream = $this->disk()->readStream($remotePath);
        Storage::disk(config('casemix.storage_disk'))->writeStream($localRelative, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $localRelative;
    }

    /** @return array{ok: bool, message: string, count?: int} */
    public function test(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Variabel SIMRS_FTP_* di .env belum diisi.'];
        }
        try {
            $data = $this->listFiles(config('casemix.ftp.path'));
            $riwayat = $this->disk()->directoryExists(trim(config('casemix.ftp.path_riwayat_pasien'), '/'));

            return [
                'ok' => true,
                'message' => "Terhubung. {$data->count()} file siap impor di folder data."
                    . ($riwayat ? ' Folder riwayat pasien ditemukan.' : ' Folder riwayat pasien tidak ditemukan.'),
                'count' => $data->count(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Gagal terhubung: ' . $e->getMessage()];
        }
    }
}
