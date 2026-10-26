<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bTradeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImporterDocumentController extends B2bBaseController
{
    /**
     * List trade documents for buyer orders (Section 11).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = B2bTradeDocument::whereHas('order', function ($q) use ($user) {
            $q->where('buyer_id', $user->id);
        })->orWhere('uploader_id', $user->id);

        if ($request->has('order_id') && !empty($request->order_id)) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('document_type') && !empty($request->document_type)) {
            $query->where('document_type', $request->document_type);
        }

        $documents = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($documents, 'Trade documents retrieved successfully');
    }

    /**
     * Upload buyer document (e.g., Import Permit, Tax Registration, Bank LC).
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'order_id' => 'nullable|exists:b2b_orders,id',
            'document_type' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:15360', // max 15MB
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'issuer' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if ($request->order_id) {
            $order = B2bOrder::where('id', $request->order_id)->where('buyer_id', $user->id)->first();
            if (!$order) {
                return $this->errorResponse('Order not found or access denied', 404);
            }
        }

        $file = $request->file('file');
        $filePath = $file->store('b2b/documents', 'public');

        $doc = B2bTradeDocument::create([
            'document_type' => $request->document_type,
            'order_id' => $request->order_id,
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
            'status' => 'uploaded',
        ]);

        return $this->successResponse($doc, 'Document uploaded successfully', 201);
    }

    /**
     * View document metadata.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $doc = B2bTradeDocument::where(function ($q) use ($user) {
            $q->whereHas('order', function ($o) use ($user) {
                $o->where('buyer_id', $user->id);
            })->orWhere('uploader_id', $user->id);
        })->find($id);

        if (!$doc) {
            return $this->errorResponse('Document not found or access denied', 404);
        }

        return $this->successResponse($doc, 'Document retrieved successfully');
    }

    /**
     * Download trade document file.
     */
    public function download($id, Request $request)
    {
        $user = $request->user();
        $doc = B2bTradeDocument::where(function ($q) use ($user) {
            $q->whereHas('order', function ($o) use ($user) {
                $o->where('buyer_id', $user->id);
            })->orWhere('uploader_id', $user->id);
        })->find($id);

        if (!$doc || !Storage::disk('public')->exists($doc->file_path)) {
            return $this->errorResponse('Document file not found', 404);
        }

        return response()->download(Storage::disk('public')->path($doc->file_path), $doc->file_name ?: basename($doc->file_path));
    }
}
