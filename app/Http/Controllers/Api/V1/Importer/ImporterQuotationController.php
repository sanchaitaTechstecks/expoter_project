<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bQuotation;
use App\Services\B2B\B2bTradeService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ImporterQuotationController extends B2bBaseController
{
    protected B2bTradeService $tradeService;

    public function __construct(B2bTradeService $tradeService)
    {
        $this->tradeService = $tradeService;
    }

    /**
     * List all quotations received by the buyer.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bQuotation::with(['rfq.product', 'supplier.company', 'items'])
            ->where('buyer_id', $user->id);

        if ($request->has('rfq_id') && !empty($request->rfq_id)) {
            $query->where('rfq_id', $request->rfq_id);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $quotations = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($quotations, 'Quotations retrieved successfully');
    }

    /**
     * View quotation details.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $quotation = B2bQuotation::with(['rfq.product', 'supplier.company', 'items.lot'])
            ->where('buyer_id', $user->id)
            ->find($id);

        if (!$quotation) {
            return $this->errorResponse('Quotation not found or access denied', 404);
        }

        return $this->successResponse($quotation, 'Quotation details retrieved successfully');
    }

    /**
     * Accept quotation (Atomically reserves stock and creates confirmed B2B Order - BR-003, BR-004, BR-005).
     */
    public function accept($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $quotation = B2bQuotation::where('buyer_id', $user->id)->find($id);

        if (!$quotation) {
            return $this->errorResponse('Quotation not found or access denied', 404);
        }

        try {
            $order = $this->tradeService->acceptQuotationAndCreateOrder($quotation, $user->id);
            return $this->successResponse($order, 'Quotation accepted successfully! B2B Order confirmed with reserved stock.', 200);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Reject a quotation with an optional reason.
     */
    public function reject($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $quotation = B2bQuotation::where('buyer_id', $user->id)->find($id);

        if (!$quotation) {
            return $this->errorResponse('Quotation not found or access denied', 404);
        }

        if (!in_array($quotation->status, ['issued', 'pending_approval'])) {
            return $this->errorResponse("Quotation cannot be rejected in '{$quotation->status}' status", 400);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $quotation->status = 'rejected';
        $quotation->rejection_reason = $request->reason ?: 'Rejected by buyer';
        $quotation->save();

        return $this->successResponse($quotation, 'Quotation rejected');
    }
}
