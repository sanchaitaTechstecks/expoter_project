<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\Rfq;
use App\Models\B2B\RfqMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExporterRfqController extends B2bBaseController
{
    /**
     * List incoming RFQs for this exporter.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // RFQs specifically assigned to this exporter OR open for exporter's products
        $query = Rfq::with(['buyer.company', 'product', 'quotations'])
            ->where(function ($q) use ($user) {
                $q->where('supplier_id', $user->id)
                  ->orWhereHas('product', function ($p) use ($user) {
                      $p->where('user_id', $user->id);
                  });
            });

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $rfqs = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($rfqs, 'Incoming RFQs retrieved successfully');
    }

    /**
     * View RFQ details.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = Rfq::with([
            'buyer.company',
            'product.lots',
            'quotations.items',
            'messages.sender',
        ])
        ->where(function ($q) use ($user) {
            $q->where('supplier_id', $user->id)
              ->orWhereHas('product', function ($p) use ($user) {
                  $p->where('user_id', $user->id);
              });
        })
        ->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found or access denied', 404);
        }

        return $this->successResponse($rfq, 'RFQ details retrieved successfully');
    }

    /**
     * Send clarification message to buyer.
     */
    public function sendMessage($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = Rfq::where(function ($q) use ($user) {
            $q->where('supplier_id', $user->id)
              ->orWhereHas('product', function ($p) use ($user) {
                  $p->where('user_id', $user->id);
              });
        })->find($id);

        if (!$rfq) {
            return $this->errorResponse('RFQ not found or access denied', 404);
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

        $rfq->status = 'supplier_responding';
        $rfq->save();

        return $this->successResponse($msg->load('sender'), 'Message sent successfully', 201);
    }
}
