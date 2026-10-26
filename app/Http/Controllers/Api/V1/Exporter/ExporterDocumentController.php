<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bTradeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ExporterDocumentController extends B2bBaseController
{
    /**
     * Upload trade documentation (Section 11).
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:b2b_orders,id',
            'shipment_id' => 'nullable|exists:b2b_shipments,id',
            'lot_id' => 'nullable|exists:product_lots,id',
            'document_type' => 'required|string|max:100', // packing_list, commercial_invoice, phytosanitary_certificate, certificate_of_origin, bill_of_lading, inspection_certificate, insurance_certificate, etc.
            'title' => 'required|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:20480', // max 20MB
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'issuer' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $order = B2bOrder::where('id', $request->order_id)->where('supplier_id', $user->id)->first();
        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        $file = $request->file('file');
        $filePath = $file->store('b2b/documents', 'public');

        $doc = B2bTradeDocument::create([
            'document_type' => $request->document_type,
            'order_id' => $order->id,
            'shipment_id' => $request->shipment_id,
            'lot_id' => $request->lot_id,
            'uploader_id' => $user->id,
            'document_number' => $request->document_number,
            'title' => $request->title,
            'file_path' => $filePath,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'issue_date' => $request->issue_date,
            'expiry_date' => $request->expiry_date,
            'issuer' => $request->issuer,
            'version' => 1,
            'status' => 'approved', // documents issued directly by exporter/cert authority are approved
        ]);

        return $this->successResponse($doc, 'Trade document uploaded successfully', 201);
    }

    /**
     * List trade documents for exporter orders.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = B2bTradeDocument::whereHas('order', function ($q) use ($user) {
            $q->where('supplier_id', $user->id);
        })->orWhere('uploader_id', $user->id);

        if ($request->has('order_id') && !empty($request->order_id)) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('document_type') && !empty($request->document_type)) {
            $query->where('document_type', $request->document_type);
        }

        $documents = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($documents, 'Documents retrieved successfully');
    }

    /**
     * Update review status for a document.
     */
    public function updateStatus($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $doc = B2bTradeDocument::whereHas('order', function ($q) use ($user) {
            $q->where('supplier_id', $user->id);
        })->find($id);

        if (!$doc) {
            return $this->errorResponse('Document not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:approved,rejected,under_review,expired',
            'review_comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $doc->status = $request->status;
        $doc->review_comments = $request->review_comments;
        $doc->reviewed_by = $user->id;
        $doc->reviewed_at = now();
        $doc->save();

        return $this->successResponse($doc, "Document status updated to '{$request->status}'");
    }

    /**
     * Download document.
     */
    public function download($id, Request $request)
    {
        $user = $request->user();
        $doc = B2bTradeDocument::where(function ($q) use ($user) {
            $q->whereHas('order', function ($o) use ($user) {
                $o->where('supplier_id', $user->id);
            })->orWhere('uploader_id', $user->id);
        })->find($id);

        if (!$doc || !Storage::disk('public')->exists($doc->file_path)) {
            return $this->errorResponse('Document file not found', 404);
        }

        return response()->download(Storage::disk('public')->path($doc->file_path), $doc->file_name ?: basename($doc->file_path));
    }
}
