<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\Rfq;
use App\Models\B2B\RfqMessage;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ImporterRfqController extends B2bBaseController
{
    /**
     * Submit an RFQ / Enquiry (Section 6 & 6.3).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'supplier_id' => 'nullable|exists:users,id',
            'preferred_lot_ids' => 'nullable|array',
            'requested_quantity' => 'required|numeric|min:0.001',
            'unit' => 'required|string|max:20',
            'target_price' => 'nullable|numeric|min:0',
            'quality_specification' => 'nullable|string',
            'packaging_requirement' => 'nullable|string|max:255',
            'trade_type' => 'required|in:domestic,international',
            'destination_country' => 'required|string|max:100',
            'destination_port_or_address' => 'required|string|max:255',
            'requested_delivery_window' => 'nullable|string|max:100',
            'incoterm' => 'nullable|string|max:20',
            'named_place' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'required_documents' => 'nullable|array',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = $request->user();
        $product = Product::find($request->product_id);

        // If supplier not specified, assign to the product's owner/supplier
        $supplierId = $request->supplier_id ?: $product->user_id;

        $rfqNumber = 'RFQ-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $rfq = Rfq::create([
            'rfq_number' => $rfqNumber,
            'buyer_id' => $user->id,
            'supplier_id' => $supplierId,
            'product_id' => $product->id,
            'preferred_lot_ids' => $request->preferred_lot_ids,
            'requested_quantity' => $request->requested_quantity,
            'unit' => $request->unit,
            'target_price' => $request->target_price,
            'quality_specification' => $request->quality_specification,
            'packaging_requirement' => $request->packaging_requirement,
            'trade_type' => $request->trade_type,
            'destination_country' => $request->destination_country,
            'destination_port_or_address' => $request->destination_port_or_address,
            'requested_delivery_window' => $request->requested_delivery_window,
            'incoterm' => $request->incoterm,
            'named_place' => $request->named_place,
            'currency' => $request->currency ?: 'USD',
            'required_documents' => $request->required_documents,
            'notes' => $request->notes,
            'attachments' => $request->attachments,
            'status' => 'submitted',
        ]);

        return $this->successResponse($rfq->load('product'), 'RFQ submitted successfully', 201);
    }

    /**
     * List all RFQs submitted by the buyer.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Rfq::with(['product', 'supplier.company', 'quotations'])
            ->where('buyer_id', $user->id);

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $rfqs = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($rfqs, 'RFQs retrieved successfully');
    }

    /**
     * Get RFQ details with received quotations and conversation thread.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = Rfq::with([
            'product',
            'supplier.company',
            'quotations.items',
            'messages.sender'
        ])
        ->where('buyer_id', $user->id)
        ->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found or access denied', 404);
        }

        return $this->successResponse($rfq, 'RFQ details retrieved successfully');
    }

    /**
     * Send a clarification message on the RFQ.
     */
    public function sendMessage($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = Rfq::where('buyer_id', $user->id)->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
            'attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $msg = RfqMessage::create([
            'rfq_id' => $rfq->id,
            'sender_id' => $user->id,
            'message' => $request->message,
            'attachments' => $request->attachments,
        ]);

        // Update RFQ status to clarification needed
        if ($rfq->status === 'submitted') {
            $rfq->status = 'clarification_needed';
            $rfq->save();
        }

        return $this->successResponse($msg->load('sender'), 'Message sent successfully', 201);
    }

    /**
     * Cancel an RFQ.
     */
    public function cancel($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = Rfq::where('buyer_id', $user->id)->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found', 404);
        }

        if (in_array($rfq->status, ['converted', 'closed'])) {
            return $this->errorResponse("Cannot cancel RFQ in '{$rfq->status}' state", 400);
        }

        $rfq->status = 'closed';
        $rfq->save();

        return $this->successResponse($rfq, 'RFQ closed successfully');
    }
}
