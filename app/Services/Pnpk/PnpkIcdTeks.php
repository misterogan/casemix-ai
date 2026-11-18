<?php

namespace App\Services\Pnpk;

use App\Models\PnpkDokumen;
use App\Models\PnpkIcd;

/** Sumber ICD tanpa AI: kode ICD-10 yang tertulis di teks dokumen (bukan daftar pustaka) dan ada di master. */
class PnpkIcdTeks
{
    public const POLA = '/(?<![A-Za-z0-9])([A-TV-Z][0-9]{2}(?:\.[0-9A-Z]{1,2})?)(?![A-Za-z0-9])/';

    private const JUDUL_PUSTAKA = '/^[ \t]*(?:BAB\s+[IVX\d]+\s*)?(?:daftar\s+pustaka|referensi|kepustakaan)[ \t]*:?[ \t]*$/imu';

    /**
     * @param array<int, string> $pages nomor halaman => teks
     * @return string[] kode ternormalisasi, valid di master
     */
    public function ekstrak(array $pages): array
    {
        ksort($pages);
        $pustaka = null;
        foreach ($pages as $h => $teks) {
            if (preg_match(self::JUDUL_PUSTAKA, (string) $teks)) {
                $pustaka = (int) $h; // judul terakhir (bukan daftar isi di depan)
            }
        }
        $kode = [];
        foreach ($pages as $h => $teks) {
            $teks = (string) $teks;
            if ($pustaka !== null && $h > $pustaka) {
                continue;
            }
            if ($pustaka !== null && $h === $pustaka && preg_match(self::JUDUL_PUSTAKA, $teks, $m, PREG_OFFSET_CAPTURE)) {
                $teks = substr($teks, 0, $m[0][1]);
            }
            preg_match_all(self::POLA, $teks, $mm);
            foreach ($mm[1] as $k) {
                $kode[PnpkIcd::normalize($k)] = true;
            }
        }

        return array_values(array_filter(array_keys($kode), fn ($k) => PnpkIcd::adaDiMaster($k)));
    }

    /** Simpan kode dari teks sebagai usulan (sumber=teks); kode yang sudah ada tidak diubah. @return int jumlah baru */
    public function simpan(PnpkDokumen $d): int
    {
        $ada = PnpkIcd::where('dokumen_id', $d->id)->pluck('kode')->map(fn ($k) => str_replace('.', '', PnpkIcd::normalize($k)))->all();
        $baru = 0;
        foreach ($this->ekstrak($d->halaman()->pluck('teks', 'halaman')->all()) as $k) {
            if (in_array(str_replace('.', '', $k), $ada, true)) {
                continue;
            }
            PnpkIcd::create(['dokumen_id' => $d->id, 'kode' => $k, 'sumber' => 'teks', 'status' => 'usulan']);
            $ada[] = str_replace('.', '', $k);
            $baru++;
        }

        return $baru;
    }
}
