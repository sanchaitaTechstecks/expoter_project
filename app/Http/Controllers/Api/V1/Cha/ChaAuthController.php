<?php

namespace App\Http\Controllers\Api\V1\Cha;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bCustomsDeclaration;
use App\Models\B2B\B2bShipment;
use App\Models\User;
use App\Services\B2B\B2bAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ChaAuthController extends B2bBaseController
{
    /**
     * CHA Portal Login.
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
            return $this->errorResponse('Invalid customs agent credentials', 401);
        }

        $isCha = $user->user_type === 'cha'
            || $user->admin_role === 'cha'
            || in_array($user->user_type, ['admin', 'staff'], true);

        if (!$isCha) {
            return $this->errorResponse('Access forbidden. Account is not registered as a Customs House Agent (CHA).', 403);
        }

        if ($user->banned == 1) {
            return $this->errorResponse('Customs broker account has been suspended.', 403);
        }

        $token = $user->createToken('cha_auth_token')->plainTextToken;

        B2bAuditService::log(
            $user->id,
            'cha',
            'cha_login',
            'User',
            $user->id,
            null,
            ['email' => $user->email, 'login_at' => now()->toIso8601String()],
            'Customs House Agent authenticated'
        );

        return $this->successResponse([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
                'phone' => $user->phone,
                'country' => $user->country,
            ],
        ], 'CHA authenticated successfully');
    }

    /**
     * Get authenticated CHA profile and workload stats.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        // Calculate assigned workload
        $assignedCount = B2bShipment::where('assigned_cha_id', $user->id)->count();
        $pendingFilings = B2bShipment::where('assigned_cha_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('customs_status')->orWhere('customs_status', 'pending');
            })->count();
        $holdsCount = B2bShipment::where('assigned_cha_id', $user->id)
            ->where('customs_status', 'customs_hold')->count();
        $clearedCount = B2bShipment::where('assigned_cha_id', $user->id)
            ->where('customs_status', 'cleared')->count();

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'country' => $user->country,
                'created_at' => $user->created_at,
            ],
            'workload' => [
                'assigned_shipments' => $assignedCount,
                'pending_filings' => $pendingFilings,
                'customs_holds' => $holdsCount,
                'cleared_shipments' => $clearedCount,
            ],
        ], 'CHA profile retrieved successfully');
    }

    /**
     * Update CHA profile details.
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

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ], 'CHA profile updated successfully');
    }

    /**
     * CHA Logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return $this->successResponse(null, 'CHA logged out successfully');
    }
}
