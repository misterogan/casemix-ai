<?php

namespace App\Services\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Akses FTP SIMRS. Kredensial dibaca dari .env (SIMRS_FTP_*).
 * - path               : folder berisi satu subfolder per pasien, pola NoRM_NAMA_NoSEP
 * - path_riwayat_pasien: folder riwayat pasien (opsional), dicari berdasarkan No. RM
 */
class SimrsFtp
{
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    private ?FtpClient $client = null;

    public function client(): FtpClient
    {
        return $this->client ??= new FtpClient(config('casemix.ftp'));
    }

    public function configured(): bool
    {
        return filled(config('casemix.ftp.host')) && filled(config('casemix.ftp.username'));
    }

    /**
     * Folder pasien di SIMRS_FTP_PATH beserta file di dalamnya.
     *
     * @return Collection<int, array{path: string, folder: string, no_rm: ?string, nama: ?string, no_sep: ?string, jenis_rawat: ?string, dikenali: bool, files: array}>
     */
    public function listPatientFolders(): Collection
    {
        $root = config('casemix.ftp.path');

        return collect($this->client()->list($root))
            ->where('type', 'dir')
            ->map(function (array $dir) {
                $files = $this->onlyDocuments($this->client()->list($dir['path']));
                $parsed = DocumentGuesser::parseFolder($dir['name']);

                return [
                    'path' => $dir['path'],
                    'folder' => $dir['name'],
                    'no_rm' => $parsed['no_rm'] ?? null,
                    'nama' => $parsed['nama'] ?? null,
                    'no_sep' => $parsed['no_sep'] ?? null,
                    'jenis_rawat' => DocumentGuesser::careType(array_column($files, 'name')),
                    'dikenali' => $parsed !== null,
                    'files' => $files,
                ];
            })
            ->values();
    }

    /** @return Collection<int, array{path: string, name: string, size: int, modified: int}> */
    public function listFiles(string $dir, bool $recursive = false, int $depth = 3): Collection
    {
        $out = collect();
        foreach ($this->client()->list($dir) as $e) {
            if ($e['type'] === 'dir') {
                if ($recursive && $depth > 0) {
                    $out = $out->concat($this->listFiles($e['path'], true, $depth - 1));
                }
            } elseif ($this->isDocument($e['name'])) {
                $out->push(['path' => $e['path'], 'name' => $e['name'], 'size' => $e['size'], 'modified' => $e['modified']]);
            }
        }

        return $out->values();
    }

    /** File riwayat pasien yang nama file atau foldernya memuat No. RM. Kosong bila folder riwayat tidak ada. */
    public function findPatientHistory(string $noRm): Collection
    {
        $needle = ltrim(preg_replace('/\D/', '', $noRm), '0');
        $dir = config('casemix.ftp.path_riwayat_pasien');
        if ($needle === '' || blank($dir) || ! $this->client()->isDir($dir)) {
            return collect();
        }

        return $this->listFiles($dir, recursive: true)
            ->filter(fn ($f) => str_contains(preg_replace('/\D/', '', $f['path']), $needle))
            ->values();
    }

    public function download(string $remotePath, string $localRelative): string
    {
        $this->client()->download($remotePath, Storage::disk(config('casemix.storage_disk'))->path($localRelative));

        return $localRelative;
    }

    /** @return array{ok: bool, message: string, count?: int} */
    public function test(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Variabel SIMRS_FTP_* di .env belum diisi.'];
        }
        try {
            $folders = $this->listPatientFolders();
            $unrecognized = $folders->where('dikenali', false)->count();
            $files = $folders->sum(fn ($f) => count($f['files']));
            $riwayat = filled(config('casemix.ftp.path_riwayat_pasien')) && $this->client()->isDir(config('casemix.ftp.path_riwayat_pasien'));

            return [
                'ok' => true,
                'message' => "Terhubung. {$folders->count()} folder pasien, $files dokumen di " . config('casemix.ftp.path') . '.'
                    . ($unrecognized ? " $unrecognized folder tidak sesuai pola NoRM_NAMA_NoSEP." : '')
                    . ($riwayat ? ' Folder riwayat pasien ditemukan.' : ' Folder riwayat pasien tidak ditemukan.'),
                'count' => $folders->count(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Gagal: ' . $e->getMessage()];
        }
    }

    private function onlyDocuments(array $entries): array
    {
        return array_values(array_filter($entries, fn ($e) => $e['type'] === 'file' && $this->isDocument($e['name'])));
    }

    private function isDocument(string $name): bool
    {
        return in_array(Str::lower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
