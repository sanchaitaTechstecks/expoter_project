<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\User;
use App\Services\B2B\B2bAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminAuthController extends B2bBaseController
{
    /**
     * Admin login endpoint (Super Admin, Sub Admin, Developer Admin).
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid administrator credentials', 401);
        }

        $isAdmin = in_array($user->user_type, ['admin', 'staff'], true)
            || in_array($user->admin_role, ['super_admin', 'sub_admin', 'developer_admin'], true);

        if (!$isAdmin) {
            return $this->errorResponse('Access forbidden. Account lacks administrative privileges.', 403);
        }

        if ($user->banned == 1) {
            return $this->errorResponse('Administrative account has been suspended.', 403);
        }

        $token = $user->createToken('admin_auth_token')->plainTextToken;

        B2bAuditService::log(
            $user->id,
            $user->admin_role ?: $user->user_type,
            'admin_login',
            'User',
            $user->id,
            null,
            ['email' => $user->email, 'login_at' => now()->toIso8601String()],
            'Administrator logged in'
        );

        return $this->successResponse([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
                'admin_role' => $user->admin_role ?: 'super_admin',
                'admin_permissions' => $user->admin_permissions ?? [],
            ],
        ], 'Administrator authenticated successfully');
    }

    /**
     * Get profile of authenticated admin.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type,
            'admin_role' => $user->admin_role ?: 'super_admin',
            'admin_permissions' => $user->admin_permissions ?? [],
            'created_at' => $user->created_at,
        ], 'Admin profile retrieved successfully');
    }

    /**
     * Update admin profile details or password.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'phone' => 'nullable|string|max:30',
            'password' => 'nullable|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $before = ['name' => $user->name, 'phone' => $user->phone];

        if ($request->filled('name')) {
            $user->name = $request->name;
        }
        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        $after = ['name' => $user->name, 'phone' => $user->phone];

        B2bAuditService::log(
            $user->id,
            $user->admin_role ?: $user->user_type,
            'update_admin_profile',
            'User',
            $user->id,
            $before,
            $after,
            'Admin profile updated'
        );

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'admin_role' => $user->admin_role,
        ], 'Admin profile updated successfully');
    }

    /**
     * Revoke admin token / logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();

            B2bAuditService::log(
                $user->id,
                $user->admin_role ?: $user->user_type,
                'admin_logout',
                'User',
                $user->id,
                null,
                null,
                'Administrator logged out'
            );
        }

        return $this->successResponse(null, 'Administrator logged out successfully');
    }
}
