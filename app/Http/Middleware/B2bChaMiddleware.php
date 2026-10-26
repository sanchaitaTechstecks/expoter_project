<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class B2bChaMiddleware
{
    /**
     * Handle an incoming request for CHA portal routes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. CHA token required.',
            ], 401);
        }

        if ($user->banned == 1) {
            return response()->json([
                'success' => false,
                'message' => 'Your customs agent account has been suspended.',
            ], 403);
        }

        $isCha = $user->user_type === 'cha'
            || $user->admin_role === 'cha'
            || in_array($user->user_type, ['admin', 'staff'], true);

        if (!$isCha) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Customs House Agent (CHA) credentials required.',
            ], 403);
        }

        return $next($request);
    }
}
