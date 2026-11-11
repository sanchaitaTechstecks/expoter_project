<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * P2-8: enforce an idle timeout on B2B bearer tokens.
 *
 * Sanctum refreshes `last_used_at` while resolving the token, so that column can
 * never show idleness by the time middleware runs. Idle time is therefore tracked
 * in the cache per token id and updated on every authenticated request.
 */
class B2bSessionTimeout
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user ? $user->currentAccessToken() : null;

        if (!$user) {
            return $next($request);
        }

        $timeout = (int) config('b2b.session_timeout_minutes', 120);

        if ($timeout > 0 && $token && !($token instanceof \Laravel\Sanctum\TransientToken)) {
            $key = 'b2b:session:last_seen:' . $token->id;
            $lastSeen = cache()->get($key);

            if ($lastSeen && now()->greaterThan(\Illuminate\Support\Carbon::parse($lastSeen)->addMinutes($timeout))) {
                $token->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Session expired due to inactivity. Please log in again.',
                    'errors' => ['reason' => 'session_timeout'],
                ], 401);
            }

            cache()->put($key, now()->toIso8601String(), now()->addMinutes(max($timeout * 2, 240)));
        }

        $user->last_activity_at = now();
        $user->saveQuietly();

        return $next($request);
    }
}
