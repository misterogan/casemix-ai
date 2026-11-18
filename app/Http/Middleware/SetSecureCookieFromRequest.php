<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Cookie sesi Secure hanya bila permintaan HTTPS (langsung atau via proxy lokal tepercaya). */
class SetSecureCookieFromRequest
{
    public function handle(Request $request, Closure $next)
    {
        config(['session.secure' => $request->isSecure()]);

        return $next($request);
    }
}
