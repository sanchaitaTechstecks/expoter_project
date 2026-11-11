<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Restricts a route to exporter (seller) accounts, matching the B2B JSON error format.
 */
class B2bExporterMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'seller' || $user->banned) {
            return response()->json([
                'success' => false,
                'message' => 'This endpoint is available to exporter accounts only',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
