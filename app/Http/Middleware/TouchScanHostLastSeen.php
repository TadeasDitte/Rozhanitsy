<?php

namespace App\Http\Middleware;

use App\Models\ScanHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records that an authenticated scan host contacted the API.
 *
 * Sanctum only stamps `last_used_at` on the token itself, which is discarded
 * whenever a token is regenerated or revoked, so the host row has to be
 * stamped separately for the "last seen" column to mean anything.
 */
class TouchScanHostLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->user('sanctum');

        if ($host instanceof ScanHost) {
            $host->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }
}
