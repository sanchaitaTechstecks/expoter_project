<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImporterOrderController extends B2bBaseController
{
    /**
     * List buyer's confirmed B2B orders.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bOrder::with(['supplier.company', 'items'])
            ->where('buyer_id', $user->id);

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $query->where('payment_status', $request->payment_status);
        }

        $orders = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($orders, 'Orders retrieved successfully');
    }

    /**
     * Order detail view with immutable price/lot snapshots, payments, invoices and shipments.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $order = B2bOrder::with([
            'supplier.company',
            'items.lot',
            'agreement',
            'invoices',
            'payments',
            'shipments.containers',
            'documents',
        ])
        ->where('buyer_id', $user->id)
        ->find($id);

        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        return $this->successResponse($order, 'Order details retrieved successfully');
    }
}
