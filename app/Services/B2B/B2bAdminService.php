<?php

namespace App\Services\B2B;

use App\Models\B2B\B2bDispute;
use App\Models\B2B\B2bDisputeMessage;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\Company;
use App\Models\B2B\PriceHistory;
use App\Models\B2B\ProductLot;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class B2bAdminService
{
    /**
     * Create staff / sub-admin / developer-admin user (SRS Section 3).
     */
    public function createStaffUser(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'user_type' => 'staff',
                'admin_role' => $data['admin_role'] ?? 'sub_admin',
                'admin_permissions' => $data['admin_permissions'] ?? [],
                'email_verified_at' => now(),
            ]);

            B2bAuditService::log(
                $actor->id,
                $actor->admin_role ?: $actor->user_type,
                'create_staff_user',
                'User',
                $user->id,
                null,
                [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'admin_role' => $user->admin_role,
                    'admin_permissions' => $user->admin_permissions,
                ],
                'Created platform administrative staff account'
            );

            return $user;
        });
    }

    /**
     * Update user account status (active, suspended, banned).
     */
    public function updateUserStatus(int $userId, string $status, ?string $reason, User $actor): User
    {
        return DB::transaction(function () use ($userId, $status, $reason, $actor) {
            $user = User::findOrFail($userId);
            $before = ['banned' => $user->banned];

            if ($status === 'banned' || $status === 'suspended') {
                $user->banned = 1;
            } else {
                $user->banned = 0;
            }
            $user->save();

            $after = ['banned' => $user->banned];

            B2bAuditService::log(
                $actor->id,
                $actor->admin_role ?: $actor->user_type,
                'update_user_status',
                'User',
                $user->id,
                $before,
                $after,
                $reason ?: "User status changed to {$status}"
            );

            return $user;
        });
    }

    /**
     * Moderate Supplier Verification (Approve / Reject KYC).
     */
    public function verifySupplier(int $companyId, string $status, ?string $notes, User $actor): Company
    {
        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) {
            throw new InvalidArgumentException("Invalid verification status: {$status}");
        }

        return DB::transaction(function () use ($companyId, $status, $notes, $actor) {
            $company = Company::findOrFail($companyId);
            $before = [
                'verification_status' => $company->verification_status,
                'verification_notes' => $company->verification_notes,
            ];

            $company->verification_status = $status;
            $company->verification_notes = $notes;
            $company->save();

            $after = [
                'verification_status' => $company->verification_status,
                'verification_notes' => $company->verification_notes,
            ];

            B2bAuditService::log(
                $actor->id,
                $actor->admin_role ?: $actor->user_type,
                'supplier_verification',
                'Company',
                $company->id,
                $before,
                $after,
                $notes ?: "Supplier verification marked as {$status}"
            );

            return $company;
        });
    }

    /**
     * Moderate Product Publication (Approve, Reject, Suspend).
     */
    public function moderateProduct(int $productId, string $publicationStatus, ?string $notes, User $actor): Product
    {
        return DB::transaction(function () use ($productId, $publicationStatus, $notes, $actor) {
            $product = Product::findOrFail($productId);
            $before = [
                'publication_status' => $product->publication_status,
                'published' => $product->published,
            ];

            $product->publication_status = $publicationStatus;
            $product->published = ($publicationStatus === 'active') ? 1 : 0;
            $product->save();

            $after = [
                'publication_status' => $product->publication_status,
                'published' => $product->published,
            ];

            B2bAuditService::log(
                $actor->id,
                $actor->admin_role ?: $actor->user_type,
                'product_moderation',
                'Product',
                $product->id,
                $before,
                $after,
                $notes ?: "Product publication status changed to {$publicationStatus}"
            );

            return $product;
        });
    }

    /**
     * Moderate Lot Pricing Changes (Approve or Reject BR-002 changes).
     */
    public function approveLotPricing(int $lotId, string $decision, ?string $notes, User $actor): ProductLot
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException("Decision must be approved or rejected");
        }

        return DB::transaction(function () use ($lotId, $decision, $notes, $actor) {
            $lot = ProductLot::findOrFail($lotId);
            $pendingHistory = PriceHistory::where('lot_id', $lot->id)
                ->where('approval_status', 'pending')
                ->latest()
                ->first();

            $before = [
                'unit_price' => (float) $lot->unit_price,
                'approval_status' => $lot->approval_status,
            ];

            if ($decision === 'approved') {
                $lot->approval_status = 'approved';
                $lot->approval_notes = $notes;
                $lot->approved_by = $actor->id;
                $lot->save();

                if ($pendingHistory) {
                    $pendingHistory->approval_status = 'approved';
                    $pendingHistory->save();
                }
            } else {
                // Reject decision
                $lot->approval_status = 'rejected';
                $lot->approval_notes = $notes;
                $lot->approved_by = $actor->id;

                // Revert price to old_price if pending history exists
                if ($pendingHistory) {
                    $pendingHistory->approval_status = 'rejected';
                    $pendingHistory->save();
                    $lot->unit_price = $pendingHistory->old_price;
                }
                $lot->save();
            }

            $after = [
                'unit_price' => (float) $lot->unit_price,
                'approval_status' => $lot->approval_status,
            ];

            B2bAuditService::log(
                $actor->id,
                $actor->admin_role ?: $actor->user_type,
                'pricing_approval',
                'ProductLot',
                $lot->id,
                $before,
                $after,
                $notes ?: "Lot price {$decision}"
            );

            return $lot;
        });
    }

    /**
     * Resolve Order Dispute with binding settlement (SRS Section 19).
     */
    public function resolveDispute(
        int $disputeId,
        string $resolutionAction,
        ?float $settlementAmount,
        string $resolutionNotes,
        User $actor
    ): B2bDispute {
        return DB::transaction(function () use ($disputeId, $resolutionAction, $settlementAmount, $resolutionNotes, $actor) {
            $dispute = B2bDispute::with('order')->findOrFail($disputeId);

            $before = [
                'status' => $dispute->status,
                'resolution_action' => $dispute->resolution_action,
                'settlement_amount' => $dispute->settlement_amount,
            ];

            $newStatus = ($resolutionAction === 'claim_rejected') ? 'dismissed' : 'resolved';

            $dispute->status = $newStatus;
            $dispute->resolution_action = $resolutionAction;
            $dispute->settlement_amount = $settlementAmount;
            $dispute->resolved_by_id = $actor->id;
            $dispute->resolution_notes = $resolutionNotes;
            $dispute->resolved_at = now();
            $dispute->save();

            // Record arbitration note in dispute message thread
            B2bDisputeMessage::create([
                'dispute_id' => $dispute->id,
                'sender_id' => $actor->id,
                'message' => "Dispute resolved by platform arbitrator: [Action: {$resolutionAction}]. Resolution Notes: {$resolutionNotes}" . ($settlementAmount ? " (Settlement Amount: {$settlementAmount} {$dispute->currency})" : ""),
                'is_internal_admin_note' => false,
            ]);

            // If order cancelled, update order status
            if ($resolutionAction === 'order_cancelled' && $dispute->order) {
                $order = $dispute->order;
                $order->status = 'cancelled';
                $order->save();
            }

            $after = [
                'status' => $dispute->status,
                'resolution_action' => $dispute->resolution_action,
                'settlement_amount' => $dispute->settlement_amount,
            ];

            B2bAuditService::log(
                $actor->id,
                $actor->admin_role ?: $actor->user_type,
                'dispute_resolution',
                'B2bDispute',
                $dispute->id,
                $before,
                $after,
                $resolutionNotes
            );

            return $dispute;
        });
    }
}
