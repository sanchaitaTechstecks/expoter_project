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
            'currency' => 'nullable|string|max:10|nullable',
            'required_documents' => 'nullable|array',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'rfq_type' => 'nullable|string|in:standard,bulk,repeat,stock,sample,sample_stock,sample_bulk,stock_bulk,stock_sample,stock_sample_bulk',
            'source_channel' => 'nullable|string|in:web,whatsapp,store,email,api,other',
            'dec013_export_offers' => 'nullable|array',
            'dec013_external_id' => 'nullable|string|max:100',
            'offer_count' => 'nullable|integer|min:0',
            'offer_by_supplier' => 'nullable|integer|min:0',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = $request->user();
        $product = Product::find($request->product_id);

        if (!$product) {
            return $this->errorResponse('Product not found', 404);
        }

        // If supplier not specified, assign to the product's owner/supplier
        $supplierId = $request->supplier_id ?: $product->user_id;

        // Only the product owner can set supplier_id directly; draft may omit it
        if ($supplierId && $supplierId != $user->id && $product->user_id != $user->id) {
            return $this->errorResponse('Access denied: you can only create an RFQ for your own products', 403);
        }

        $rfqNumber = 'RFQ-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $status = $request->rfq_type === 'draft' ? 'draft' : 'submitted';

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
            'status' => $status,
            'assigned_to' => null,
            'assigned_by' => null,
            'assigned_at' => null,
            'draft_saved_at' => $status === 'draft' ? now() : null,
            'draft_notes' => $request->draft_notes ?? null,
            'rfq_type' => $request->rfq_type ?? 'standard',
            'source_channel' => $request->source_channel ?? 'web',
            'dec013_export_offers' => $request->dec013_export_offers,
            'dec013_external_id' => $request->dec013_external_id,
            'offer_count' => $request->offer_count ?? 0,
            'offer_by_supplier' => $request->offer_by_supplier ?? 0,
            'rejection_reason' => $request->rejection_reason,
            'created_by' => $user->id,
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
     * Save / update an RFQ draft (status => draft). Uses the same resource as the request.
     */
    public function saveDraft($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = Rfq::where('buyer_id', $user->id)->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found', 404);
        }

        if ($rfq->status === 'closed') {
            return $this->errorResponse("Cannot save a closed RFQ", 400);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'nullable|exists:products,id',
            'supplier_id' => 'nullable|exists:users,id',
            'preferred_lot_ids' => 'nullable|array',
            'requested_quantity' => 'nullable|numeric|min:0.001',
            'unit' => 'nullable|string|max:20',
            'target_price' => 'nullable|numeric|min:0',
            'quality_specification' => 'nullable|string',
            'packaging_requirement' => 'nullable|string|max:255',
            'trade_type' => 'nullable|in:domestic,international',
            'destination_country' => 'nullable|string|max:100',
            'destination_port_or_address' => 'nullable|string|max:255',
            'requested_delivery_window' => 'nullable|string|max:100',
            'incoterm' => 'nullable|string|max:20',
            'named_place' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'required_documents' => 'nullable|array',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'rfq_type' => 'nullable|string|in:standard,bulk,repeat,stock,sample,sample_stock,sample_bulk,stock_bulk,stock_sample,stock_sample_bulk',
            'source_channel' => 'nullable|string|in:web,whatsapp,store,email,api,other',
            'dec013_export_offers' => 'nullable|array',
            'dec013_external_id' => 'nullable|string|max:100',
            'offer_count' => 'nullable|integer|min:0',
            'offer_by_supplier' => 'nullable|integer|min:0',
            'draft_notes' => 'nullable|string',
            'rejection_reason' => 'nullable|string|max:1000',
            'assigned_to' => 'nullable|exists:users,id',
            'assigned_by' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $rfq->fill([
            'product_id' => $request->product_id ?? $rfq->product_id,
            'supplier_id' => $request->supplier_id ?? $rfq->supplier_id,
            'preferred_lot_ids' => $request->preferred_lot_ids ?? $rfq->preferred_lot_ids,
            'requested_quantity' => $request->requested_quantity ?? $rfq->requested_quantity,
            'unit' => $request->unit ?? $rfq->unit,
            'target_price' => $request->target_price ?? $rfq->target_price,
            'quality_specification' => $request->quality_specification ?? $rfq->quality_specification,
            'packaging_requirement' => $request->packaging_requirement ?? $rfq->packaging_requirement,
            'trade_type' => $request->trade_type ?? $rfq->trade_type,
            'destination_country' => $request->destination_country ?? $rfq->destination_country,
            'destination_port_or_address' => $request->destination_port_or_address ?? $rfq->destination_port_or_address,
            'requested_delivery_window' => $request->requested_delivery_window ?? $rfq->requested_delivery_window,
            'incoterm' => $request->incoterm ?? $rfq->incoterm,
            'named_place' => $request->named_place ?? $rfq->named_place,
            'currency' => $request->currency ?? $rfq->currency,
            'required_documents' => $request->required_documents ?? $rfq->required_documents,
            'notes' => $request->notes ?? $rfq->notes,
            'attachments' => $request->attachments ?? $rfq->attachments,
            'rfq_type' => $request->rfq_type ?? $rfq->rfq_type,
            'source_channel' => $request->source_channel ?? $rfq->source_channel,
            'dec013_export_offers' => $request->dec013_export_offers ?? $rfq->dec013_export_offers,
            'dec013_external_id' => $request->dec013_external_id ?? $rfq->dec013_external_id,
            'offer_count' => $request->offer_count ?? $rfq->offer_count,
            'offer_by_supplier' => $request->offer_by_supplier ?? $rfq->offer_by_supplier,
            'draft_notes' => $request->draft_notes ?? $rfq->draft_notes,
            'rejection_reason' => $request->rejection_reason ?? $rfq->rejection_reason,
        ]);

        $rfq->assigned_to = $request->assigned_to ?? $rfq->assigned_to;
        $rfq->assigned_by = $request->assigned_by ?? $rfq->assigned_by;
        $rfq->draft_saved_at = now();
        $rfq->status = 'draft';
        $rfq->save();

        return $this->successResponse($rfq->load('product'), 'Draft saved successfully', 200);
    }

    /**
     * Assign an RFQ to a back-office / operational user for review or action.
     */
    public function assign($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = Rfq::where('buyer_id', $user->id)->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'assigned_to' => 'required|exists:users,id',
            'assigned_by' => 'nullable|exists:users,id',
            'assigned_at' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $assignedBy = $request->assigned_by ?? $user->id;
        $assignedAt = $request->assigned_at ?? now();

        $rfq->assigned_to = $request->assigned_to;
        $rfq->assigned_by = $assignedBy;
        $rfq->assigned_at = $assignedAt;
        $rfq->save();

        return $this->successResponse($rfq, 'RFQ assigned successfully');
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
