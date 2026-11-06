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
     * Add multi-supplier DEC-013 export offers to an RFQ (Section 6.1 / DEC-013).
     */
    public function addOffers($id, Request $request): JsonResponse
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

        if (!in_array($rfq->status, ['submitted', 'under_review', 'clarification_needed', 'supplier_responding', 'draft'])) {
            return $this->errorResponse("Offers cannot be added to an RFQ in '{$rfq->status}' status", 400);
        }

        $validator = Validator::make($request->all(), [
            'offers' => 'required|array|min:1',
            'offers.*.price' => 'nullable|numeric|min:0',
            'offers.*.validity_date' => 'nullable|date|after:now',
            'offers.*.incoterm' => 'nullable|string|max:20', // FOB, CIF, CFR, EXW, DAP, FCA, CPT, CIP
            'offers.*.named_place' => 'nullable|string|max:191',
            'offers.*.lead_time_days' => 'nullable|integer|min:0',
            'offers.*.payment_terms' => 'nullable|string|max:255',
            'offers.*.packaging' => 'nullable|string|max:100',
            'offers.*.notes' => 'nullable|string',
            'offers.*.external_id' => 'nullable|string|max:100', // DEC-013: supplier/marketplace external offer reference
            'offers.*.attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $offerCount = count($request->offers);
        $offers = $rfq->dec013_export_offers ?? [];

        foreach ($request->offers as $offer) {
            $offers[] = [
                'price' => $offer['price'] ?? null,
                'validity_date' => $offer['validity_date'] ?? null,
                'incoterm' => $offer['incoterm'] ?? null,
                'named_place' => $offer['named_place'] ?? null,
                'lead_time_days' => $offer['lead_time_days'] ?? null,
                'payment_terms' => $offer['payment_terms'] ?? null,
                'packaging' => $offer['packaging'] ?? null,
                'notes' => $offer['notes'] ?? null,
                'external_id' => $offer['external_id'] ?? null,
                'attachments' => $offer['attachments'] ?? null,
            ];
        }

        $rfq->dec013_export_offers = $offers;
        $rfq->offer_count = $offerCount;
        $rfq->offer_by_supplier = ($rfq->offer_by_supplier ?? 0) + $offerCount;
        $rfq->dec013_external_id = $request->dec013_external_id ?? $rfq->dec013_external_id;
        $rfq->save();

        return $this->successResponse($rfq->load('product'), 'DEC-013 export offers added successfully', 200);
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
