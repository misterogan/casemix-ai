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

    /**
     * File riwayat pasien milik No. RM ini. Kosong bila folder riwayat tidak ada.
     * Dicocokkan per bagian path (nama folder/file), bukan potongan angka di mana saja,
     * supaya RM 123 tidak ikut mengambil file RM 1234, tanggal, atau nomor SEP pasien lain.
     */
    public function findPatientHistory(string $noRm): Collection
    {
        $dir = config('casemix.ftp.path_riwayat_pasien');
        if (self::normalizeRm($noRm) === '' || blank($dir) || ! $this->client()->isDir($dir)) {
            return collect();
        }

        $base = '/' . trim($dir, '/') . '/';

        return $this->listFiles($dir, recursive: true)
            ->filter(fn ($f) => self::pathBelongsToRm(str_starts_with($f['path'], $base) ? substr($f['path'], strlen($base)) : $f['path'], $noRm))
            ->values();
    }

    /** No. RM tanpa nol di depan dan tanpa karakter selain angka. */
    public static function normalizeRm(string $noRm): string
    {
        return ltrim(preg_replace('/\D/', '', $noRm), '0');
    }

    /**
     * Path (relatif terhadap folder riwayat) dianggap milik No. RM ini bila salah satu bagiannya
     * - diawali No. RM yang utuh, mis. "000123_NAMA", "000123.pdf", "123 - Lab", atau
     * - memuat label RM diikuti No. RM yang utuh, mis. "Lab RM 000123.pdf", "RM-123_ronsen".
     * Angka di tengah nama (tanggal, SEP, RM lain) tidak dihitung.
     */
    public static function pathBelongsToRm(string $relativePath, string $noRm): bool
    {
        $needle = self::normalizeRm($noRm);
        if ($needle === '') {
            return false;
        }
        // Setelah No. RM harus pemisah atau akhir nama, supaya "0301R0011..." (SEP) tidak terbaca RM 301.
        $rm = '0*' . preg_quote($needle, '/') . '(?![0-9a-z])';

        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (preg_match('/^' . $rm . '/i', $segment) || preg_match('/(?<![a-z])(?:no\.?\s*)?rm[\s_\-.:#]*' . $rm . '/i', $segment)) {
                return true;
            }
        }

        return false;
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
