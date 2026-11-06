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

    /**
     * Save / update a quotation draft (status => draft). Creates a minimal record with a draft mode flag.
     */
    public function saveDraft($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $quotation = B2bQuotation::where('buyer_id', $user->id)->find($id);

        if (!$quotation) {
            return $this->errorResponse('Quotation not found', 404);
        }

        if (in_array($quotation->status, ['accepted', 'rejected', 'expired', 'cancelled'])) {
            return $this->errorResponse("Cannot save a quote in '{$quotation->status}' state", 400);
        }

        $validator = Validator::make($request->all(), [
            'rfq_id' => 'required|exists:rfqs,id',
            'currency' => 'nullable|string|max:10',
            'incoterm' => 'nullable|string|max:20',
            'named_place' => 'nullable|string|max:191',
            'validity_date' => 'nullable|date|after:now',
            'delivery_window' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'freight_charges' => 'nullable|numeric|min:0',
            'insurance_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'exclusions' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $quotation->fill([
            'currency' => $request->currency ?? $quotation->currency,
            'incoterm' => $request->incoterm ?? $quotation->incoterm,
            'named_place' => $request->named_place ?? $quotation->named_place,
            'validity_date' => $request->validity_date ?? $quotation->validity_date,
            'delivery_window' => $request->delivery_window ?? $quotation->delivery_window,
            'payment_terms' => $request->payment_terms ?? $quotation->payment_terms,
            'discount' => $request->discount ?? $quotation->discount,
            'tax' => $request->tax ?? $quotation->tax,
            'freight_charges' => $request->freight_charges ?? $quotation->freight_charges,
            'insurance_charges' => $request->insurance_charges ?? $quotation->insurance_charges,
            'other_charges' => $request->other_charges ?? $quotation->other_charges,
            'exclusions' => $request->exclusions ?? $quotation->exclusions,
            'notes' => $request->notes ?? $quotation->notes,
        ]);
        $quotation->status = 'draft';
        $quotation->save();

        return $this->successResponse($quotation, 'Quotation draft saved successfully', 200);
    }
}
