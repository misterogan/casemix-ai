<?php

namespace App\Services\Eklaim;

/**
 * Enkripsi sesuai Petunjuk Teknis WS E-Klaim (inacbg_encrypt/inacbg_decrypt):
 * AES-256-CBC, key dari hex (32 byte), IV acak 16 byte,
 * signature = 10 byte pertama HMAC-SHA256(ciphertext), payload = base64(signature + iv + ciphertext).
 */
class EklaimCrypto
{
    private const CIPHER = 'aes-256-cbc';

    private const SIG = 10;

    private string $key;

    public function __construct(string $hexKey)
    {
        $key = ctype_xdigit($hexKey) && strlen($hexKey) % 2 === 0 ? hex2bin($hexKey) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new EklaimException('EKLAIM_ENCRYPTION_KEY tidak valid (harus hex 64 karakter).');
        }
        $this->key = $key;
    }

    public function encrypt(string $data): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $enc = openssl_encrypt($data, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        $sig = substr(hash_hmac('sha256', $enc, $this->key, true), 0, self::SIG);

        return chunk_split(base64_encode($sig.$iv.$enc));
    }

    /** Signature diverifikasi dulu; bila tidak cocok, dekripsi tidak dilakukan. */
    public function decrypt(string $payload): string
    {
        $raw = base64_decode(preg_replace('/\s+/', '', $payload), true);
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        if ($raw === false || strlen($raw) <= self::SIG + $ivLen) {
            throw new EklaimException('Respons E-Klaim tidak terbaca.');
        }
        $sig = substr($raw, 0, self::SIG);
        $iv = substr($raw, self::SIG, $ivLen);
        $enc = substr($raw, self::SIG + $ivLen);
        if (! hash_equals(substr(hash_hmac('sha256', $enc, $this->key, true), 0, self::SIG), $sig)) {
            throw new EklaimException('Signature respons E-Klaim tidak cocok.');
        }
        $plain = openssl_decrypt($enc, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new EklaimException('Gagal mendekripsi respons E-Klaim.');
        }

        return $plain;
    }

    /** Ambil isi di antara penanda BEGIN/END ENCRYPTED DATA. */
    public static function potong(string $body): string
    {
        if (preg_match('/-+BEGIN ENCRYPTED DATA-+(.*?)-+END ENCRYPTED DATA-+/s', $body, $m)) {
            return trim($m[1]);
        }

        return trim($body);
    }
}
