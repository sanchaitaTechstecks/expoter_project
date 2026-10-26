<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bAgreement;
use App\Models\B2B\B2bOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExporterOrderController extends B2bBaseController
{
    /**
     * List confirmed orders for this exporter.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bOrder::with(['buyer.company', 'items'])
            ->where('supplier_id', $user->id);

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $query->where('payment_status', $request->payment_status);
        }

        $orders = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($orders, 'Exporter orders retrieved successfully');
    }

    /**
     * Order detail view.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $order = B2bOrder::with([
            'buyer.company',
            'items.lot',
            'agreement',
            'invoices',
            'payments',
            'shipments.containers',
            'documents',
        ])
        ->where('supplier_id', $user->id)
        ->find($id);

        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        return $this->successResponse($order, 'Order details retrieved successfully');
    }

    /**
     * Update order fulfillment stage (Section 9.3).
     */
    public function updateStatus($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $order = B2bOrder::where('supplier_id', $user->id)->find($id);

        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:preparing,ready_for_dispatch,in_fulfilment,shipped,delivered,completed,on_hold',
            'expected_delivery' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $order->status = $request->status;
        if ($request->has('expected_delivery')) {
            $order->expected_delivery = $request->expected_delivery;
        }
        if ($request->has('notes')) {
            $order->notes = $request->notes;
        }
        $order->save();

        return $this->successResponse($order, "Order status updated to '{$request->status}'");
    }

    /**
     * Sign agreement as Exporter / Supplier.
     */
    public function signAgreement($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $agreement = B2bAgreement::where('supplier_id', $user->id)->find($id);

        if (!$agreement) {
            return $this->errorResponse('Agreement not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'signature' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $agreement->supplier_signature = $request->signature;
        $agreement->supplier_signed_at = now();

        if ($agreement->buyer_signed_at) {
            $agreement->status = 'accepted';
        } else {
            $agreement->status = 'sent_to_counterparty';
        }

        $agreement->save();

        return $this->successResponse($agreement, 'Agreement signed by supplier');
    }
}
