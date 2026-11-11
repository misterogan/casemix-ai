<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;

/**
 * Satu-satunya pintu aplikasi ke model AI. Setiap penyedia punya adapter sendiri
 * yang menerjemahkan AiRequest ke format API-nya. Mengganti penyedia cukup lewat menu Admin.
 */
interface AiProvider
{
    public function generate(AiRequest $request): AiResponse;

    /** Uji koneksi singkat untuk halaman Admin. */
    public function ping(string $model): AiResponse;
}
