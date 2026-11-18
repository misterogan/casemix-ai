<?php

namespace App\Services\Pnpk;

use App\Models\PnpkIcd;

/** Verifikasi tanpa AI: kutipan harus ada di teks halaman (±1 halaman); kode ICD harus ada di master. */
class PnpkVerifier
{
    public const TOLAK_KUTIPAN = 'Kutipan tidak ditemukan di dokumen';

    public const TOLAK_ICD = 'Kode tidak ada di master ICD-10';

    /** Huruf kecil, tanda baca → spasi, spasi dirapatkan. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** @param array<int, string> $pages nomor halaman => teks */
    public function kutipanCocok(string $kutipan, int $halaman, array $pages): bool
    {
        $k = self::normalize($kutipan);
        if ($k === '') {
            return false;
        }
        foreach ([$halaman, $halaman - 1, $halaman + 1] as $h) {
            if (isset($pages[$h]) && str_contains(self::normalize($pages[$h]), $k)) {
                return true;
            }
        }

        return false;
    }

    public function icdValid(string $kode): bool
    {
        return PnpkIcd::adaDiMaster($kode);
    }
}
