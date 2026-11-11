<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\Rfq;
use App\Models\B2B\RfqInvitation;
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
                  ->orWhereHas('invitations', function ($i) use ($user) {
                      $i->where('supplier_id', $user->id);
                  })
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
              ->orWhereHas('invitations', function ($i) use ($user) {
                  $i->where('supplier_id', $user->id);
              })
              ->orWhereHas('product', function ($p) use ($user) {
                  $p->where('user_id', $user->id);
              });
        })
        ->find($id);

        if (!$rfq) {
            return $this->rfqNotFoundResponse($id);
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
              ->orWhereHas('invitations', function ($i) use ($user) {
                  $i->where('supplier_id', $user->id);
              })
              ->orWhereHas('product', function ($p) use ($user) {
                  $p->where('user_id', $user->id);
              });
        })->find($id);

        if (!$rfq) {
            return $this->rfqNotFoundResponse($id);
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
                'supplier_id' => $user->id,
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

        // A supplier that answered a broadcast counts as responded.
        $invitation = RfqInvitation::where('rfq_id', $rfq->id)
            ->where('supplier_id', $user->id)
            ->first();
        if ($invitation && !in_array($invitation->status, ['responded', 'declined'], true)) {
            $invitation->status = 'responded';
            $invitation->responded_at = now();
            $invitation->save();
        }

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
              ->orWhereHas('invitations', function ($i) use ($user) {
                  $i->where('supplier_id', $user->id);
              })
              ->orWhereHas('product', function ($p) use ($user) {
                  $p->where('user_id', $user->id);
              });
        })->find($id);

        if (!$rfq) {
            return $this->rfqNotFoundResponse($id);
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

    /**
     * P2-2: Reject a buyer RFQ with a machine-readable reason code.
     */
    public function reject($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $rfq = $this->findSupplierRfq($id, $user->id);

        if (!$rfq) {
            return $this->rfqNotFoundResponse($id);
        }

        // Accept both the detailed {reason_code, reason} pair and the simple {rejection_reason} payload.
        $payload = $request->all();
        $payload['reason_code'] = $payload['reason_code'] ?? 'other';
        $payload['reason'] = $payload['reason'] ?? $payload['rejection_reason'] ?? null;

        $validator = Validator::make($payload, [
            'reason_code' => 'required|in:out_of_stock,price_mismatch,specification_not_possible,certification_unavailable,lead_time_unavailable,minimum_quantity_not_met,destination_ineligible,other',
            'reason' => 'required|string|max:1000',
            'rejection_reason' => 'nullable|string|max:1000',
        ], [
            'reason.required' => 'Send a JSON body with a reason, e.g. {"rejection_reason": "Unable to supply the requested quantity"}.',
            'reason_code.in' => 'reason_code must be one of: out_of_stock, price_mismatch, specification_not_possible, certification_unavailable, lead_time_unavailable, minimum_quantity_not_met, destination_ineligible, other.',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if (in_array($rfq->status, ['rejected', 'accepted', 'closed', 'cancelled'], true)) {
            return $this->errorResponse("RFQ cannot be rejected while its status is {$rfq->status}", 400);
        }

        // Record the decline on this supplier's own invitation, if there is one.
        $invitation = RfqInvitation::where('rfq_id', $rfq->id)
            ->where('supplier_id', $user->id)
            ->first();

        if ($invitation) {
            if ($invitation->status === 'declined') {
                return $this->errorResponse('Invitation has already been declined', 409);
            }

            $invitation->status = 'declined';
            $invitation->decline_reason = $payload['reason'];
            $invitation->responded_at = now();
            $invitation->save();
        }

        // An RFQ without an explicit supplier is owned by the product's seller.
        $designatedSupplierId = $rfq->supplier_id ?: optional($rfq->product)->user_id;
        $isDesignatedSupplier = (int) $designatedSupplierId === (int) $user->id;
        $otherInvitations = RfqInvitation::where('rfq_id', $rfq->id)
            ->where('supplier_id', '!=', $user->id)
            ->count();

        $rfq->rejection_code = $payload['reason_code'];
        $rfq->rejection_reason = $payload['reason'];

        // One supplier declining a broadcast must not cancel the RFQ for every other supplier.
        if ($isDesignatedSupplier && $otherInvitations === 0) {
            $rfq->status = 'rejected';
            $rfq->save();

            return $this->successResponse($rfq->fresh()->load('product'), 'RFQ rejected successfully');
        }

        $rfq->save();

        return $this->successResponse($rfq->fresh()->load('product'), 'RFQ declined by supplier');
    }

    /**
     * P2-2: Broadcast invitations addressed to this supplier.
     */
    public function invitations(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = RfqInvitation::with(['rfq.product', 'rfq.buyer'])
            ->where('supplier_id', $user->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $invitations = $query->orderByDesc('id')->paginate($request->get('per_page', 15));

        return $this->successResponse($invitations, 'RFQ invitations retrieved successfully');
    }

    /**
     * P2-2: Supplier accepts (view/respond) or declines a broadcast invitation.
     */
    public function respondInvitation($id, Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:view,accept,decline',
            'reason' => 'required_if:action,decline|nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $invitation = RfqInvitation::with('rfq')
            ->where('supplier_id', $user->id)
            ->find($id);

        if (!$invitation) {
            return $this->errorResponse('Invitation not found or access denied', 404);
        }

        if (in_array($invitation->status, ['responded', 'declined'], true)) {
            return $this->errorResponse('Invitation has already been answered', 409);
        }

        if ($request->action === 'view') {
            if ($invitation->status === 'invited') {
                $invitation->status = 'viewed';
                $invitation->save();
            }

            return $this->successResponse($invitation->fresh()->load('rfq.product'), 'Invitation marked as viewed');
        }

        if ($request->action === 'decline') {
            $invitation->status = 'declined';
            $invitation->decline_reason = $request->reason;
            $invitation->responded_at = now();
            $invitation->save();

            return $this->successResponse($invitation->fresh(), 'Invitation declined');
        }

        // Accepting keeps the RFQ shared: a broadcast can have several responding suppliers,
        // so the original supplier_id must not be overwritten here.
        $invitation->status = 'responded';
        $invitation->responded_at = now();
        $invitation->save();

        return $this->successResponse($invitation->fresh()->load('rfq.product'), 'Invitation accepted, you can now submit an offer');
    }

    /**
     * Tell the caller whether the RFQ is missing or simply not theirs.
     */
    private function rfqNotFoundResponse($id): JsonResponse
    {
        if (Rfq::whereKey($id)->exists()) {
            return $this->errorResponse(
                "You are not a supplier on RFQ {$id}: sign in as its supplier, product owner or an invited supplier",
                403
            );
        }

        return $this->errorResponse("RFQ not found: no RFQ with id {$id}", 404);
    }

    private function findSupplierRfq($id, int $supplierId): ?Rfq
    {
        return Rfq::where(function ($q) use ($supplierId) {
            $q->where('supplier_id', $supplierId)
                ->orWhereHas('invitations', function ($i) use ($supplierId) {
                    $i->where('supplier_id', $supplierId);
                })
                ->orWhereHas('product', function ($p) use ($supplierId) {
                    $p->where('user_id', $supplierId);
                });
        })->find($id);
    }
}
