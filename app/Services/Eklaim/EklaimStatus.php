<?php

namespace App\Services\Eklaim;

/**
 * SATU-SATUNYA tempat pemetaan respons get_claim_data -> status CERMAT.
 * Nama field masih perkiraan (Juknis WS E-Klaim 5.x); koreksi di sini setelah dicek dengan respons nyata (`--sep`).
 */
class EklaimStatus
{
    public const FINAL = 'final';

    public const TERKIRIM = 'terkirim';

    public const LABEL = [
        self::FINAL => 'Final di E-Klaim',
        self::TERKIRIM => 'Terkirim ke BPJS',
    ];

    /** Field status yang boleh ditampilkan di konsol (--sep). Bukan data pasien. */
    public const FIELD = ['klaim_status_cd', 'kemenkes_dc_status_cd', 'bpjs_dc_status_cd'];

    /** Kode error E-Klaim untuk "klaim/SEP belum ada". */
    public const KODE_TIDAK_ADA = ['E2004', 'E2002'];

    /** @param array $data isi response.data dari get_claim_data */
    public static function dari(array $data): ?string
    {
        $v = fn (string $k) => strtolower(trim((string) ($data[$k] ?? '')));

        return match (true) {
            $v('bpjs_dc_status_cd') === 'sent' => self::TERKIRIM,
            $v('klaim_status_cd') === 'final' => self::FINAL,
            default => null,
        };
    }

    public static function field(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELD));
    }
}
