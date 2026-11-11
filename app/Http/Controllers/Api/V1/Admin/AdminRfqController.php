<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\Rfq;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminRfqController extends B2bBaseController
{
    /**
     * P2-2: Back-office RFQ queue with assignment filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Rfq::query()->with([
            'buyer:id,name,email,user_type',
            'supplier:id,name,email,user_type',
            'product:id,name,user_id',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('rfq_number', 'like', "%{$search}%")
                    ->orWhereHas('buyer', function ($b) use ($search) {
                        $b->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [$request->from, $request->to]);
        }

        $rfqs = $query->orderByDesc('id')->paginate($request->get('per_page', 20));

        return $this->successResponse($rfqs, 'RFQ queue retrieved successfully');
    }

    public function stats(): JsonResponse
    {
        return $this->successResponse([
            'total' => Rfq::count(),
            'unassigned' => Rfq::whereNull('assigned_to')->count(),
            'open' => Rfq::whereIn('status', ['submitted', 'under_review', 'supplier_responding'])->count(),
            'rejected' => Rfq::where('status', 'rejected')->count(),
            'converted' => Rfq::where('status', 'converted')->count(),
        ], 'RFQ queue statistics retrieved successfully');
    }

    public function show($id): JsonResponse
    {
        $rfq = Rfq::with([
            'buyer:id,name,email,phone,user_type',
            'supplier:id,name,email,phone,user_type',
            'product',
            'messages.sender:id,name,user_type',
            'invitations.supplier:id,name,email',
            'quotations',
        ])->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found', 404);
        }

        return $this->successResponse($rfq, 'RFQ retrieved successfully');
    }

    /**
     * P2-2: Assign an RFQ to a back-office user for follow-up.
     */
    public function assign($id, Request $request): JsonResponse
    {
        $rfq = Rfq::find($id);
        if (!$rfq) {
            return $this->errorResponse('RFQ not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'assigned_to' => 'required|integer|exists:users,id',
            'note' => 'nullable|string|max:1000',
            'status' => 'nullable|in:submitted,under_review,supplier_responding',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $assignee = User::find($request->assigned_to);
        if (!$assignee || !in_array($assignee->user_type, ['admin', 'staff'], true)) {
            return $this->errorResponse('RFQ can only be assigned to a back-office user', 422);
        }

        $rfq->assigned_to = $assignee->id;
        $rfq->assigned_by = $request->user()->id;
        $rfq->assigned_at = now();
        if ($request->filled('status')) {
            $rfq->status = $request->status;
        }
        $rfq->save();

        if ($request->filled('note')) {
            \App\Models\B2B\RfqMessage::create([
                'rfq_id' => $rfq->id,
                'sender_id' => $request->user()->id,
                'message' => '[Assignment note] ' . $request->note,
            ]);
        }

        return $this->successResponse($rfq->fresh()->load('buyer:id,name,email'), 'RFQ assigned successfully');
    }

    public function unassign($id): JsonResponse
    {
        $rfq = Rfq::find($id);
        if (!$rfq) {
            return $this->errorResponse('RFQ not found', 404);
        }

        $rfq->assigned_to = null;
        $rfq->assigned_by = null;
        $rfq->assigned_at = null;
        $rfq->save();

        return $this->successResponse($rfq, 'RFQ unassigned successfully');
    }
}
