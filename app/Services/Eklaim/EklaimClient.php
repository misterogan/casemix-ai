<?php

namespace App\Services\Eklaim;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Klien WS E-Klaim INA-CBG. READ-ONLY: hanya metode di WHITELIST yang bisa dikirim,
 * metode lain (new_claim, set_claim_data, grouper, claim_final, send_claim_*, delete_claim, ...) ditolak.
 * Tidak memakai mode debug; key tidak pernah dicetak atau di-log.
 */
class EklaimClient
{
    public const WHITELIST = ['get_claim_data'];

    /** metadata respons terakhir (code, message, error_no) untuk diagnosa --tampil-field. */
    public array $meta = [];

    public static function enabled(): bool
    {
        return (bool) config('services.eklaim.sync_enabled');
    }

    /** URL dan key E-Klaim sudah diisi. */
    public static function configured(): bool
    {
        return trim((string) config('services.eklaim.ws_url')) !== ''
            && trim((string) config('services.eklaim.encryption_key')) !== '';
    }

    /**
     * Data klaim per SEP, atau null HANYA bila E-Klaim membalas kode error "klaim tidak ditemukan".
     * Kegagalan lain (koneksi, HTTP, dekripsi/signature, kode error lain) dilempar sebagai EklaimException.
     *
     * @return array|null isi response.data
     */
    public function getClaimData(string $noSep): ?array
    {
        $r = $this->call('get_claim_data', ['nomor_sep' => $noSep]);
        $meta = $this->meta = is_array($r['metadata'] ?? null) ? $r['metadata'] : [];
        if ((int) ($meta['code'] ?? 0) !== 200) {
            if (in_array($meta['error_no'] ?? null, EklaimStatus::KODE_TIDAK_ADA, true)) {
                return null;
            }
            throw new EklaimException('E-Klaim menolak permintaan (kode '.($meta['code'] ?? '?').', '.($meta['error_no'] ?? '-')
                .': '.mb_strimwidth((string) ($meta['message'] ?? '-'), 0, 200, '…').').');
        }

        return $r['response']['data'] ?? $r['response'] ?? [];
    }

    /** Kirim satu permintaan terenkripsi. Metode di luar WHITELIST dilempar sebelum apa pun dikirim. */
    public function call(string $method, array $data): array
    {
        if (! in_array($method, self::WHITELIST, true)) {
            throw new EklaimException("Metode E-Klaim '$method' tidak diizinkan (CERMAT hanya membaca).");
        }
        $this->meta = [];
        if (! self::configured()) {
            throw new EklaimException('Konfigurasi E-Klaim belum diisi (EKLAIM_WS_URL/EKLAIM_ENCRYPTION_KEY).');
        }
        $url = trim((string) config('services.eklaim.ws_url'));
        $crypto = new EklaimCrypto((string) config('services.eklaim.encryption_key'));
        $body = $crypto->encrypt(json_encode(['metadata' => ['method' => $method], 'data' => $data]));

        try {
            $res = Http::timeout((int) config('services.eklaim.timeout', 10))
                ->withBody($body, 'application/x-www-form-urlencoded')->post($url);
        } catch (ConnectionException) {
            throw new EklaimException('E-Klaim tidak terjangkau (timeout/koneksi).', true);
        }
        if ($res->status() !== 200) {
            throw new EklaimException('E-Klaim error HTTP '.$res->status().'.', $res->serverError());
        }
        $json = json_decode($crypto->decrypt(EklaimCrypto::potong($res->body())), true);
        if (! is_array($json)) {
            throw new EklaimException('Respons E-Klaim bukan JSON.');
        }

        return $json;
    }
}
