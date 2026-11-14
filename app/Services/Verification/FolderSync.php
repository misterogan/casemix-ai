<?php

namespace App\Services\Verification;

use App\Models\AppSetting;
use App\Models\FtpFolder;
use App\Services\Import\SimrsFtp;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Menyalin DAFTAR folder pasien di FTP ke database (bukan isi filenya), supaya halaman verifikasi
 * cepat dan bisa dicari walau FTP berisi ribuan folder. Dijalankan otomatis tiap jam.
 */
class FolderSync
{
    public const KEY_LAST = 'ftp_last_sync';

    public function __construct(private SimrsFtp $ftp) {}

    /** @return array{folders: int, baru: int, berubah: int, hilang: int} */
    public function run(): array
    {
        $now = now();
        $stats = ['folders' => 0, 'baru' => 0, 'berubah' => 0, 'hilang' => 0];

        try {
            $seen = [];
            foreach ($this->ftp->listPatientFolders() as $f) {
                $files = array_values(array_map(fn ($x) => [
                    'name' => $x['name'], 'path' => $x['path'], 'size' => (int) $x['size'], 'modified' => (int) ($x['modified'] ?? 0),
                ], $f['files']));
                usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));
                $fp = FtpFolder::fingerprintOf($files);
                $latest = collect($files)->max('modified');

                $folder = FtpFolder::firstOrNew(['path' => $f['path']]);
                if (! $folder->exists) {
                    $stats['baru']++;
                    $folder->first_seen_at = $now;
                } elseif ($folder->fingerprint !== $fp) {
                    $stats['berubah']++;
                }
                $folder->fill([
                    'name' => $f['folder'],
                    'no_rm' => $f['no_rm'],
                    'nama_pasien' => $f['nama'],
                    'no_sep' => $f['no_sep'],
                    'jenis_rawat' => $f['jenis_rawat'],
                    'files' => $files,
                    'file_count' => count($files),
                    'fingerprint' => $fp,
                    'files_modified_at' => $latest ? Carbon::createFromTimestamp($latest) : ($folder->files_modified_at ?? $now),
                    'last_seen_at' => $now,
                    'missing_at' => null,
                ])->save();
                $seen[] = $folder->id;
                $stats['folders']++;
            }

            // Folder yang tidak ada lagi (dipindah atau dihapus Enigma): tandai, jangan hapus hasil analisanya.
            $stats['hilang'] = FtpFolder::whereNotIn('id', $seen ?: [0])->whereNull('missing_at')->update(['missing_at' => $now]);

            AppSetting::put(self::KEY_LAST, ['at' => $now->toIso8601String(), 'ok' => true] + $stats);

            return $stats;
        } catch (Throwable $e) {
            AppSetting::put(self::KEY_LAST, ['at' => $now->toIso8601String(), 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }
}
