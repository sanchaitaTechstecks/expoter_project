<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bDispute;
use App\Models\B2B\B2bDisputeMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Supplier / Exporter side of the order dispute workflow (P2-BUG-03).
 *
 * Exporters see disputes raised against their orders, reply / counter-claim,
 * exchange messages with the buyer and the arbitrator, and upload evidence.
 */
class ExporterDisputeController extends B2bBaseController
{
    /** Statuses in which parties may still post messages / evidence. */
    private const OPEN_STATUSES = ['opened', 'under_investigation', 'arbitration', 'escalated'];

    /**
     * List disputes received on this exporter's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = B2bDispute::with([
            'order:id,order_number,status,total_amount,currency',
            'raisedBy:id,name,email,user_type',
        ])
            ->where(function ($q) use ($user) {
                $q->where('against_party_id', $user->id)
                    ->orWhereHas('order', fn($o) => $o->where('supplier_id', $user->id));
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
     * Submit the supplier's formal reply / counter-claim.
     */
    public function reply(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:5000',
            'counter_claim_amount' => 'nullable|numeric|min:0',
            'counter_claim_notes' => 'nullable|string|max:2000',
            'evidence' => 'nullable|array|max:10',
            'evidence.*' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx,xlsx|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $dispute = $this->findAccessibleDispute($id, $user, false);

        if (!$dispute) {
            return $this->errorResponse('Dispute not found or access denied', 404);
        }

        if (!$this->isOpen($dispute)) {
            return $this->errorResponse("Dispute is already {$dispute->status}; replies are closed", 400);
        }

        $evidence = $this->storeEvidenceFiles($request->file('evidence', []), $dispute->id);

        $body = $request->message;

        if ($request->filled('counter_claim_amount')) {
            $body .= "\n\nCounter-claim: {$request->counter_claim_amount} {$dispute->currency}";
        }

        if ($request->filled('counter_claim_notes')) {
            $body .= "\nCounter-claim notes: {$request->counter_claim_notes}";
        }

        $message = B2bDisputeMessage::create([
            'dispute_id' => $dispute->id,
            'sender_id' => $user->id,
            'message' => $body,
            'attachments' => $evidence ?: null,
            'is_internal_admin_note' => false,
        ]);

        if (!empty($evidence)) {
            $existing = is_array($dispute->evidence_attachments) ? $dispute->evidence_attachments : [];
            $dispute->evidence_attachments = array_values(array_merge($existing, $evidence));
        }

        // First supplier response moves the case into investigation.
        if ($dispute->status === 'opened') {
            $dispute->status = 'under_investigation';
        }

        $dispute->save();

        return $this->successResponse([
            'dispute' => $dispute->fresh()->load($this->detailRelations()),
            'message' => $message->load('sender:id,name,user_type'),
        ], 'Reply submitted successfully', 201);
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
            'message' => $request->note ?: 'Evidence submitted by supplier',
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
                $q->where('against_party_id', $user->id)
                    ->orWhereHas('order', fn($o) => $o->where('supplier_id', $user->id));
            })
            ->first();
    }

    /**
     * Relations exposed to the supplier. Internal admin notes are filtered out.
     */
    private function detailRelations(): array
    {
        return [
            'order:id,order_number,status,currency,total_amount,amount_paid,balance_due,destination_country',
            'raisedBy:id,name,email,user_type',
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
