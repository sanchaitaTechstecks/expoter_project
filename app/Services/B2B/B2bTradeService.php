<?php

namespace App\Services\B2B;

use App\Models\B2B\B2bAgreement;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bOrderItem;
use App\Models\B2B\B2bPayment;
use App\Models\B2B\B2bQuotation;
use App\Models\B2B\InventoryMovement;
use App\Models\B2B\PriceHistory;
use App\Models\B2B\ProductLot;
use App\Models\B2B\Rfq;
use Exception;
use Illuminate\Support\Facades\DB;

class B2bTradeService
{
    /**
     * Atomically reserve stock for a lot. (BR-003)
     */
    public function reserveLotStock(int $lotId, float $quantity, string $refType, int $refId, int $actorId, ?string $reason = null): ProductLot
    {
        return DB::transaction(function () use ($lotId, $quantity, $refType, $refId, $actorId, $reason) {
            $lot = ProductLot::where('id', $lotId)->lockForUpdate()->firstOrFail();

            if ($lot->quantity_available < $quantity) {
                throw new Exception("Insufficient available stock in lot {$lot->lot_code}. Available: {$lot->quantity_available}, Requested: {$quantity}");
            }

            $lot->quantity_available = bcsub((string)$lot->quantity_available, (string)$quantity, 3);
            $lot->quantity_reserved = bcadd((string)$lot->quantity_reserved, (string)$quantity, 3);
            $lot->save();

            InventoryMovement::create([
                'lot_id' => $lot->id,
                'movement_type' => 'reserved',
                'quantity' => $quantity,
                'unit' => $lot->unit,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reason' => $reason ?: "Reserved for {$refType} #{$refId}",
                'actor_id' => $actorId,
            ]);

            return $lot;
        });
    }

    /**
     * Atomically release reserved stock. (BR-017)
     */
    public function releaseLotStock(int $lotId, float $quantity, string $refType, int $refId, int $actorId, ?string $reason = null): ProductLot
    {
        return DB::transaction(function () use ($lotId, $quantity, $refType, $refId, $actorId, $reason) {
            $lot = ProductLot::where('id', $lotId)->lockForUpdate()->firstOrFail();

            $releaseQty = min((float)$lot->quantity_reserved, $quantity);
            $lot->quantity_reserved = bcsub((string)$lot->quantity_reserved, (string)$releaseQty, 3);
            $lot->quantity_available = bcadd((string)$lot->quantity_available, (string)$releaseQty, 3);
            $lot->save();

            InventoryMovement::create([
                'lot_id' => $lot->id,
                'movement_type' => 'released',
                'quantity' => $releaseQty,
                'unit' => $lot->unit,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reason' => $reason ?: "Released from {$refType} #{$refId}",
                'actor_id' => $actorId,
            ]);

            return $lot;
        });
    }

    /**
     * Atomically dispatch stock.
     */
    public function dispatchLotStock(int $lotId, float $quantity, string $refType, int $refId, int $actorId, ?string $reason = null): ProductLot
    {
        return DB::transaction(function () use ($lotId, $quantity, $refType, $refId, $actorId, $reason) {
            $lot = ProductLot::where('id', $lotId)->lockForUpdate()->firstOrFail();

            $dispatchFromReserved = min((float)$lot->quantity_reserved, $quantity);
            $lot->quantity_reserved = bcsub((string)$lot->quantity_reserved, (string)$dispatchFromReserved, 3);
            $lot->quantity_dispatched = bcadd((string)$lot->quantity_dispatched, (string)$quantity, 3);
            $lot->save();

            InventoryMovement::create([
                'lot_id' => $lot->id,
                'movement_type' => 'dispatched',
                'quantity' => $quantity,
                'unit' => $lot->unit,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reason' => $reason ?: "Dispatched for {$refType} #{$refId}",
                'actor_id' => $actorId,
            ]);

            return $lot;
        });
    }

    /**
     * Update lot price with audit trail. (BR-002)
     */
    public function updateLotPrice(int $lotId, float $newPrice, string $currency, int $actorId, ?string $reason = null): ProductLot
    {
        return DB::transaction(function () use ($lotId, $newPrice, $currency, $actorId, $reason) {
            $lot = ProductLot::where('id', $lotId)->lockForUpdate()->firstOrFail();
            $oldPrice = (float)$lot->unit_price;

            $lot->unit_price = $newPrice;
            $lot->currency = $currency;
            $lot->save();

            PriceHistory::create([
                'lot_id' => $lot->id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'currency' => $currency,
                'reason' => $reason ?: 'Price update',
                'actor_id' => $actorId,
                'effective_at' => now(),
                'approval_status' => 'approved',
            ]);

            return $lot;
        });
    }

    /**
     * Convert an accepted Quotation into a confirmed B2B Order. (BR-004, BR-005)
     */
    public function acceptQuotationAndCreateOrder(B2bQuotation $quotation, int $buyerId): B2bOrder
    {
        return DB::transaction(function () use ($quotation, $buyerId) {
            if ($quotation->buyer_id != $buyerId) {
                throw new Exception("Unauthorized: You can only accept quotations addressed to you.");
            }

            if (!in_array($quotation->status, ['issued', 'pending_approval'])) {
                throw new Exception("Quotation is not in an acceptable state. Current status: {$quotation->status}");
            }

            if ($quotation->validity_date && now()->gt($quotation->validity_date)) {
                $quotation->status = 'expired';
                $quotation->save();
                throw new Exception("This quotation has expired on {$quotation->validity_date->format('Y-m-d H:i')}. Please request a renewed quotation.");
            }

            // Reserve stock for each item in the quotation
            $quotation->load('items.lot', 'items.product', 'rfq');

            foreach ($quotation->items as $item) {
                if ($item->lot_id) {
                    $this->reserveLotStock(
                        $item->lot_id,
                        (float)$item->quantity,
                        'quotation',
                        $quotation->id,
                        $buyerId,
                        "Stock reservation for accepted quotation #{$quotation->quotation_number}"
                    );
                }
            }

            // Generate unique Order Number
            $orderNumber = 'ORD-B2B-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

            $order = B2bOrder::create([
                'order_number' => $orderNumber,
                'buyer_id' => $quotation->buyer_id,
                'supplier_id' => $quotation->supplier_id,
                'source_rfq_id' => $quotation->rfq_id,
                'accepted_quotation_id' => $quotation->id,
                'order_date' => now()->toDateString(),
                'order_type' => $quotation->rfq ? $quotation->rfq->trade_type : 'international',
                'status' => 'confirmed',
                'currency' => $quotation->currency,
                'incoterm' => $quotation->incoterm,
                'named_place' => $quotation->named_place,
                'total_quantity' => $quotation->items->sum('quantity'),
                'subtotal' => $quotation->subtotal,
                'discounts' => $quotation->discount,
                'taxes' => $quotation->tax,
                'freight' => $quotation->freight_charges,
                'insurance' => $quotation->insurance_charges,
                'total_amount' => $quotation->total_amount,
                'amount_paid' => 0,
                'balance_due' => $quotation->total_amount,
                'payment_status' => 'unpaid',
                'expected_delivery' => null,
                'destination_country' => $quotation->rfq ? $quotation->rfq->destination_country : null,
                'destination_port_or_address' => $quotation->rfq ? $quotation->rfq->destination_port_or_address : null,
                'notes' => $quotation->notes,
            ]);

            // Create immutable order items snapshot (BR-005)
            foreach ($quotation->items as $item) {
                B2bOrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'lot_id' => $item->lot_id,
                    'product_name_snapshot' => $item->product_name_snapshot,
                    'specification_snapshot' => $item->specification_snapshot,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'allocated_quantity' => $item->quantity,
                    'dispatched_quantity' => 0,
                    'delivered_quantity' => 0,
                    'outstanding_quantity' => $item->quantity,
                ]);
            }

            // Create initial Trade Agreement record
            $agreementNumber = 'AGR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
            $agreement = B2bAgreement::create([
                'agreement_number' => $agreementNumber,
                'order_id' => $order->id,
                'quotation_id' => $quotation->id,
                'buyer_id' => $quotation->buyer_id,
                'supplier_id' => $quotation->supplier_id,
                'version' => 1,
                'terms_and_conditions' => $quotation->payment_terms ?: "Standard trade contract terms as per accepted quotation {$quotation->quotation_number}.",
                'incoterm' => $quotation->incoterm,
                'named_place' => $quotation->named_place,
                'total_value' => $quotation->total_amount,
                'currency' => $quotation->currency,
                'payment_schedule' => $quotation->payment_terms,
                'status' => 'sent_to_counterparty',
            ]);

            $order->agreement_id = $agreement->id;
            $order->save();

            // Mark quotation accepted
            $quotation->status = 'accepted';
            $quotation->accepted_at = now();
            $quotation->save();

            // Update RFQ status
            if ($quotation->rfq) {
                $quotation->rfq->status = 'converted';
                $quotation->rfq->save();
            }

            return $order->load('items', 'agreement');
        });
    }

    /**
     * Verify payment and update order balance. (BR-006)
     */
    public function verifyPayment(B2bPayment $payment, int $verifierId, string $status, ?string $rejectionReason = null): B2bPayment
    {
        return DB::transaction(function () use ($payment, $verifierId, $status, $rejectionReason) {
            $payment = B2bPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if (!in_array($status, ['paid', 'rejected'])) {
                throw new Exception("Status must be either 'paid' or 'rejected'.");
            }

            $payment->status = $status;
            $payment->verified_by = $verifierId;
            $payment->verified_at = now();

            if ($status === 'rejected') {
                $payment->rejection_reason = $rejectionReason;
                $payment->save();
                return $payment;
            }

            // If verified paid, update order financial record
            $payment->received_at = now();
            $payment->save();

            $order = B2bOrder::where('id', $payment->order_id)->lockForUpdate()->firstOrFail();
            $newPaid = bcadd((string)$order->amount_paid, (string)$payment->amount, 2);
            $newBalance = bcsub((string)$order->total_amount, (string)$newPaid, 2);

            $order->amount_paid = $newPaid;
            $order->balance_due = max(0, (float)$newBalance);

            if ((float)$order->balance_due <= 0) {
                $order->payment_status = 'paid';
            } else {
                $order->payment_status = 'partially_paid';
            }
            $order->save();

            return $payment;
        });
    }
}
