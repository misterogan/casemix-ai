<?php

namespace App\Services\Eklaim;

use RuntimeException;

/** Kesalahan panggilan E-Klaim. $koneksi = true bila server tidak terjangkau/timeout. Pesan tidak memuat key atau data pasien. */
class EklaimException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $koneksi = false)
    {
        parent::__construct($message);
    }
}
