<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\User;
use App\Services\B2B\B2bAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminUserController extends B2bBaseController
{
    public function __construct(
        protected B2bAdminService $adminService
    ) {}

    /**
     * List platform users / staff with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->select([
            'id', 'name', 'email', 'phone', 'user_type', 'admin_role',
            'admin_permissions', 'banned', 'created_at', 'updated_at'
        ]);

        if ($request->filled('role')) {
            $role = $request->role;
            if (in_array($role, ['super_admin', 'sub_admin', 'developer_admin'], true)) {
                $query->where('admin_role', $role);
            } else {
                $query->where('user_type', $role);
            }
        }

        if ($request->filled('status')) {
            if ($request->status === 'banned' || $request->status === 'suspended') {
                $query->where('banned', 1);
            } elseif ($request->status === 'active') {
                $query->where('banned', 0);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $users = $query->latest('id')->paginate($perPage);

        return $this->successResponse($users, 'Platform users retrieved successfully');
    }

    /**
     * Create administrative staff user (Sub Admin, Developer Admin).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:30',
            'admin_role' => 'required|in:super_admin,sub_admin,developer_admin',
            'admin_permissions' => 'nullable|array',
            'admin_permissions.*' => 'string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = $this->adminService->createStaffUser($request->all(), $request->user());

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'admin_role' => $user->admin_role,
            'admin_permissions' => $user->admin_permissions,
            'created_at' => $user->created_at,
        ], 'Staff account created successfully', 201);
    }

    /**
     * Update user account status (active, suspended, banned).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,suspended,banned',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $targetUser = User::find($id);
        if (!$targetUser) {
            return $this->errorResponse('User not found', 404);
        }

        // Prevent self-suspension
        if ($targetUser->id === $request->user()->id) {
            return $this->errorResponse('Cannot modify your own administrative account status', 400);
        }

        $updated = $this->adminService->updateUserStatus(
            $id,
            $request->status,
            $request->reason,
            $request->user()
        );

        return $this->successResponse([
            'id' => $updated->id,
            'name' => $updated->name,
            'email' => $updated->email,
            'banned' => $updated->banned,
            'status' => $updated->banned ? 'suspended' : 'active',
        ], 'User account status updated successfully');
    }
}
