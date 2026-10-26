<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class B2bAdminMiddleware
{
    /**
     * Handle an incoming request for B2B Admin routes.
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
                'message' => 'Unauthenticated. Administrator token required.',
            ], 401);
        }

        if ($user->banned == 1) {
            return response()->json([
                'success' => false,
                'message' => 'Your administrative account has been suspended.',
            ], 403);
        }

        $isAdmin = in_array($user->user_type, ['admin', 'staff'], true)
            || in_array($user->admin_role, ['super_admin', 'sub_admin', 'developer_admin'], true);

        if (!$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Administrator privileges required.',
            ], 403);
        }

        return $next($request);
    }
}
