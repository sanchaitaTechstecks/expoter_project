<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bDispute;
use App\Models\B2B\B2bDisputeMessage;
use App\Models\B2B\B2bOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Buyer / Importer side of the order dispute workflow (P2-BUG-03).
 *
 * Buyers can raise a dispute against an order they purchased, exchange messages
 * with the supplier and the platform arbitrator, and upload evidence.
 */
class ImporterDisputeController extends B2bBaseController
{
    private const DISPUTE_TYPES = [
        'quality_mismatch',
        'short_quantity',
        'shipment_delay',
        'payment_delay',
        'damaged_goods',
        'document_defect',
        'other',
    ];

    /** Statuses in which parties may still post messages / evidence. */
    private const OPEN_STATUSES = ['opened', 'under_investigation', 'arbitration', 'escalated'];

    /**
     * Raise a dispute against a confirmed order.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:b2b_orders,id',
            'dispute_type' => 'required|in:' . implode(',', self::DISPUTE_TYPES),
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'claim_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'evidence' => 'nullable|array|max:10',
            'evidence.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx,xlsx|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $order = B2bOrder::where('id', $request->order_id)->where('buyer_id', $user->id)->first();

        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        if (in_array($order->status, ['cancelled', 'draft'], true)) {
            return $this->errorResponse("Cannot raise a dispute against an order in '{$order->status}' status", 400);
        }

        // One live dispute per order + type, so a buyer cannot spam duplicates.
        $existing = B2bDispute::where('order_id', $order->id)
            ->where('dispute_type', $request->dispute_type)
            ->whereIn('status', self::OPEN_STATUSES)
            ->first();

        if ($existing) {
            return $this->errorResponse(
                "An open dispute of this type already exists for this order ({$existing->dispute_number})",
                409
            );
        }

        $dispute = B2bDispute::create([
            'dispute_number' => 'DSP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
            'order_id' => $order->id,
            'raised_by_id' => $user->id,
            'against_party_id' => $order->supplier_id,
            'dispute_type' => $request->dispute_type,
            'status' => 'opened',
            'title' => $request->title,
            'description' => $request->description,
            'claim_amount' => $request->claim_amount ?? 0,
            'currency' => $request->currency ?: ($order->currency ?: 'USD'),
            'evidence_attachments' => null,
        ]);

        $evidence = $this->storeEvidenceFiles($request->file('evidence', []), $dispute->id);

        if (!empty($evidence)) {
            $dispute->evidence_attachments = $evidence;
            $dispute->save();
        }

        B2bDisputeMessage::create([
            'dispute_id' => $dispute->id,
            'sender_id' => $user->id,
            'message' => "Dispute raised: {$dispute->title}",
            'attachments' => $evidence,
            'is_internal_admin_note' => false,
        ]);

        return $this->successResponse($dispute->load($this->detailRelations()), 'Dispute raised successfully', 201);
    }

    /**
     * List disputes filed by this buyer (and disputes raised against them on own orders).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = B2bDispute::with([
            'order:id,order_number,status,total_amount,currency',
            'againstParty:id,name,email,user_type',
        ])
            ->where(function ($q) use ($user) {
                $q->where('raised_by_id', $user->id)
                    ->orWhereHas('order', fn($o) => $o->where('buyer_id', $user->id));
            });

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('dispute_type')) {
            $query->where('dispute_type', $request->dispute_type);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $disputes = $query->latest('id')->paginate($perPage);

        return $this->successResponse($disputes, 'Disputes retrieved successfully');
    }

    /**
     * Dispute detail with the public (non-internal) message thread.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $dispute = $this->findAccessibleDispute($id, $request->user());

        if (!$dispute) {
            return $this->errorResponse('Dispute not found or access denied', 404);
        }

        return $this->successResponse($dispute, 'Dispute details retrieved successfully');
    }

    /**
     * Post a message on the dispute thread.
     */
    public function storeMessage(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $dispute = $this->findAccessibleDispute($id, $user, false);

        if (!$dispute) {
            return $this->errorResponse('Dispute not found or access denied', 404);
        }

        if (!$this->isOpen($dispute)) {
            return $this->errorResponse("Dispute is already {$dispute->status}; the thread is closed", 400);
        }

        $message = B2bDisputeMessage::create([
            'dispute_id' => $dispute->id,
            'sender_id' => $user->id,
            'message' => $request->message,
            'is_internal_admin_note' => false,
        ]);

        return $this->successResponse($message->load('sender:id,name,user_type'), 'Message posted successfully', 201);
    }

    /**
     * Upload evidence files and attach them to the dispute.
     */
    public function uploadEvidence(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'files' => 'required|array|max:10',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx,xlsx|max:10240',
            'note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $dispute = $this->findAccessibleDispute($id, $user, false);

        if (!$dispute) {
            return $this->errorResponse('Dispute not found or access denied', 404);
        }

        if (!$this->isOpen($dispute)) {
            return $this->errorResponse("Dispute is already {$dispute->status}; evidence can no longer be submitted", 400);
        }

        $newEvidence = $this->storeEvidenceFiles($request->file('files', []), $dispute->id);
        $existing = is_array($dispute->evidence_attachments) ? $dispute->evidence_attachments : [];

        $dispute->evidence_attachments = array_values(array_merge($existing, $newEvidence));
        $dispute->save();

        B2bDisputeMessage::create([
            'dispute_id' => $dispute->id,
            'sender_id' => $user->id,
            'message' => $request->note ?: 'Evidence submitted by buyer',
            'attachments' => $newEvidence,
            'is_internal_admin_note' => false,
        ]);

        return $this->successResponse([
            'dispute' => $dispute->fresh()->load($this->detailRelations()),
            'evidence' => $newEvidence,
        ], 'Evidence uploaded successfully', 201);
    }

    private function findAccessibleDispute(int $id, $user, bool $withRelations = true): ?B2bDispute
    {
        $query = B2bDispute::query();

        if ($withRelations) {
            $query->with($this->detailRelations());
        }

        return $query->where('id', $id)
            ->where(function ($q) use ($user) {
                $q->where('raised_by_id', $user->id)
                    ->orWhereHas('order', fn($o) => $o->where('buyer_id', $user->id));
            })
            ->first();
    }

    /**
     * Relations exposed to the buyer. Internal admin notes are filtered out.
     */
    private function detailRelations(): array
    {
        return [
            'order:id,order_number,status,currency,total_amount,amount_paid,balance_due,destination_country',
            'againstParty:id,name,email,user_type',
            'messages' => function ($query) {
                $query->where('is_internal_admin_note', false)
                    ->with('sender:id,name,user_type')
                    ->orderBy('id');
            },
        ];
    }

    private function isOpen(B2bDispute $dispute): bool
    {
        return in_array($dispute->status, self::OPEN_STATUSES, true);
    }

    private function storeEvidenceFiles(array $files, ?int $disputeId): array
    {
        $paths = [];
        $folder = 'b2b/disputes/' . ($disputeId ?: 'pending');

        foreach ($files as $file) {
            if (!$file) {
                continue;
            }

            $paths[] = [
                'path' => $file->store($folder, 'public'),
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        return $paths;
    }
}
