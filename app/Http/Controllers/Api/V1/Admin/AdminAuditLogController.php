<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends B2bBaseController
{
    /**
     * Queryable audit trail with filters (SRS Section 19.2 & 19.3).
     */
    public function index(Request $request): JsonResponse
    {
        $query = B2bAuditLog::query()->with('actor:id,name,email,user_type,admin_role');

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->actor_id);
        }

        if ($request->filled('actor_role')) {
            $query->where('actor_role', $request->actor_role);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('diff_summary', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 25), 100);
        $logs = $query->latest('id')->paginate($perPage);

        return $this->successResponse($logs, 'System audit logs retrieved successfully');
    }

    /**
     * Detailed single audit log entry with full state diff.
     */
    public function show(int $id): JsonResponse
    {
        $log = B2bAuditLog::with('actor:id,name,email,user_type,admin_role')->find($id);

        if (!$log) {
            return $this->errorResponse('Audit log record not found', 404);
        }

        return $this->successResponse($log, 'Audit log details retrieved successfully');
    }
}
