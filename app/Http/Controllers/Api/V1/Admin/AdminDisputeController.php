<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bDispute;
use App\Services\B2B\B2bAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminDisputeController extends B2bBaseController
{
    public function __construct(
        protected B2bAdminService $adminService
    ) {}

    /**
     * List order disputes with filters and order linkages.
     */
    public function index(Request $request): JsonResponse
    {
        $query = B2bDispute::query()->with([
            'order:id,order_number,status,total_amount,currency',
            'raisedBy:id,name,email,user_type',
            'againstParty:id,name,email,user_type',
            'resolvedBy:id,name,email',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('dispute_type')) {
            $query->where('dispute_type', $request->dispute_type);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('dispute_number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $disputes = $query->latest('id')->paginate($perPage);

        return $this->successResponse($disputes, 'Disputes retrieved successfully');
    }

    /**
     * Dispute details dossier with messages and order context.
     */
    public function show(int $id): JsonResponse
    {
        $dispute = B2bDispute::with([
            'order.items',
            'order.payments',
            'order.shipments',
            'raisedBy:id,name,email,user_type',
            'againstParty:id,name,email,user_type',
            'resolvedBy:id,name,email',
            'messages.sender:id,name,user_type',
        ])->find($id);

        if (!$dispute) {
            return $this->errorResponse('Dispute not found', 404);
        }

        return $this->successResponse($dispute, 'Dispute dossier retrieved successfully');
    }

    /**
     * Resolve order dispute / issue arbitration ruling.
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:full_refund,partial_refund,replacement_dispatch,order_cancelled,terms_enforced,claim_rejected',
            'settlement_amount' => 'nullable|numeric|min:0',
            'resolution_notes' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $dispute = B2bDispute::find($id);
        if (!$dispute) {
            return $this->errorResponse('Dispute not found', 404);
        }

        if (in_array($dispute->status, ['resolved', 'dismissed'], true)) {
            return $this->errorResponse("Dispute is already {$dispute->status}", 400);
        }

        $settlementAmount = $request->filled('settlement_amount') ? (float) $request->settlement_amount : null;

        $resolved = $this->adminService->resolveDispute(
            $id,
            $request->action,
            $settlementAmount,
            $request->resolution_notes,
            $request->user()
        );

        return $this->successResponse($resolved, 'Dispute resolved successfully');
    }
}
