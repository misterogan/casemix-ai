<?php

namespace App\Services\Vclaim;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LZCompressor\LZString;

/**
 * Klien VClaim BPJS khusus Monitoring. HANYA GET — tidak ada penulisan ke BPJS.
 * Kredensial dibaca dari config (env) dan tidak pernah ditulis ke log.
 */
class VclaimClient
{
    private bool $pernahPanggil = false;

    public static function enabled(): bool
    {
        return (bool) config('casemix.vclaim.enabled');
    }

    /** @return array<int, array> daftar SEP kunjungan */
    public function kunjungan(string $tglSep, int $jns): array
    {
        $r = $this->get('Monitoring/Kunjungan/Tanggal/'.$this->tgl($tglSep).'/JnsPelayanan/'.$jns);

        return array_values($r['sep'] ?? []);
    }

    /** @return array<int, array> daftar klaim */
    public function klaim(string $tglPulang, int $jns, int $status): array
    {
        $r = $this->get('Monitoring/Klaim/Tanggal/'.$this->tgl($tglPulang).'/JnsPelayanan/'.$jns.'/Status/'.$status);

        return array_values($r['klaim'] ?? []);
    }

    /** @return array<int, array> histori pelayanan peserta */
    public function histori(string $noKartu, string $tglMulai, string $tglAkhir): array
    {
        $a = Carbon::parse($tglMulai)->startOfDay();
        $b = Carbon::parse($tglAkhir)->startOfDay();
        if ($b->lt($a) || $a->diffInDays($b) > 90) {
            throw new InvalidArgumentException('Rentang histori pelayanan maksimal 90 hari.');
        }

        $r = $this->get('monitoring/HistoriPelayanan/NoKartu/'.rawurlencode($noKartu)
            .'/tglMulai/'.$a->format('Y-m-d').'/tglAkhir/'.$b->format('Y-m-d'));

        return array_values($r['histori'] ?? []);
    }

    /** Header otentikasi VClaim untuk timestamp tertentu. */
    public function headers(string $timestamp): array
    {
        $c = config('casemix.vclaim');

        return [
            'X-cons-id' => (string) $c['cons_id'],
            'X-timestamp' => $timestamp,
            'X-signature' => base64_encode(hash_hmac('sha256', $c['cons_id'].'&'.$timestamp, (string) $c['secret'], true)),
            'user_key' => (string) $c['user_key'],
            'Content-Type' => 'application/json; charset=utf-8',
        ];
    }

    /** Dekripsi AES-256-CBC lalu dekompresi LZString. */
    public function decrypt(string $payload, string $timestamp): mixed
    {
        $c = config('casemix.vclaim');
        $kh = hex2bin(hash('sha256', $c['cons_id'].$c['secret'].$timestamp));
        $plain = openssl_decrypt(base64_decode($payload), 'AES-256-CBC', $kh, OPENSSL_RAW_DATA, substr($kh, 0, 16));
        if ($plain === false) {
            throw new VclaimException('DECRYPT', 'Gagal mendekripsi respons.');
        }

        return json_decode((string) LZString::decompressFromEncodedURIComponent($plain), true);
    }

    private function get(string $path): array
    {
        $c = config('casemix.vclaim');
        if ($this->pernahPanggil && ($ms = (int) $c['jeda_ms']) > 0) {
            usleep($ms * 1000);
        }
        $this->pernahPanggil = true;

        $timestamp = (string) Carbon::now('UTC')->getTimestamp();
        $url = rtrim((string) $c['base_url'], '/').'/'.trim((string) $c['service'], '/').'/'.$path;

        try {
            $res = Http::withHeaders($this->headers($timestamp))
                ->timeout((int) $c['timeout'])
                ->retry(3, 500, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->get($url);
        } catch (ConnectionException) {
            throw new VclaimException('TIMEOUT', 'Tidak dapat terhubung ke VClaim.');
        }

        if ($res->failed()) {
            throw new VclaimException('HTTP'.$res->status(), 'Permintaan VClaim gagal.');
        }

        $body = $res->json() ?? [];
        $meta = $body['metaData'] ?? $body['metadata'] ?? [];
        $code = (string) ($meta['code'] ?? '');
        $msg = (string) ($meta['message'] ?? '');

        if ($code === '201' || stripos($msg, 'tidak ditemukan') !== false) {
            return [];
        }
        if ($code !== '200') {
            throw new VclaimException($code ?: '?', $msg ?: 'Respons tidak dikenal.');
        }

        $resp = $body['response'] ?? null;
        if (is_string($resp)) {
            $resp = $this->decrypt($resp, $timestamp);
        }

        return is_array($resp) ? $resp : [];
    }

    private function tgl(string $d): string
    {
        return Carbon::parse($d)->format('Y-m-d');
    }
}
