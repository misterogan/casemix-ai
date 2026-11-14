<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Modul lama (berkas klaim, impor, unggah) hanya bisa dibuka bila CASEMIX_LEGACY_CLAIMS=true. */
class EnsureLegacyEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('casemix.legacy_claims'), 404);

        return $next($request);
    }
}
