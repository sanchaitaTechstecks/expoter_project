<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bPayment;
use App\Services\B2B\B2bTradeService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExporterPaymentController extends B2bBaseController
{
    protected B2bTradeService $tradeService;

    public function __construct(B2bTradeService $tradeService)
    {
        $this->tradeService = $tradeService;
    }

    /**
     * List payment submissions for exporter's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bPayment::with(['order', 'invoice', 'buyer.company'])
            ->where('supplier_id', $user->id);

        if ($request->has('order_id') && !empty($request->order_id)) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $payments = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($payments, 'Payments retrieved successfully');
    }

    /**
     * Verify / Approve / Reject payment proof (BR-006).
     */
    public function verify($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $payment = B2bPayment::where('supplier_id', $user->id)->find($id);

        if (!$payment) {
            return $this->errorResponse('Payment not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:paid,rejected',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        try {
            $verifiedPayment = $this->tradeService->verifyPayment(
                $payment,
                $user->id,
                $request->status,
                $request->rejection_reason
            );

            return $this->successResponse($verifiedPayment->load('order'), "Payment status updated to '{$request->status}'");
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * View payment details.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $payment = B2bPayment::with(['order', 'invoice', 'buyer.company'])
            ->where('supplier_id', $user->id)
            ->find($id);

        if (!$payment) {
            return $this->errorResponse('Payment not found or access denied', 404);
        }

        return $this->successResponse($payment, 'Payment details retrieved successfully');
    }

    /**
     * P2-7: Bank statement reconciliation for a payment.
     */
    public function reconcile($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $payment = B2bPayment::where('supplier_id', $user->id)->find($id);

        if (!$payment) {
            return $this->errorResponse('Payment not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'reconciliation_status' => 'required|in:matched,unmatched,disputed,not_required',
            'bank_statement_reference' => 'required_if:reconciliation_status,matched|nullable|string|max:191',
            'transaction_at' => 'nullable|date',
            'exchange_rate' => 'nullable|numeric|min:0.00000001',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if ($request->filled('exchange_rate')) {
            $payment->exchange_rate = $request->exchange_rate;
            $payment->base_amount = round((float) $payment->amount * (float) $request->exchange_rate, 2);
        }

        if ($request->filled('transaction_at')) {
            $payment->transaction_at = $request->transaction_at;
        }

        $payment->reconciliation_status = $request->reconciliation_status;
        $payment->bank_statement_reference = $request->bank_statement_reference;
        $payment->reconciled_by = $user->id;
        $payment->reconciled_at = now();

        if ($request->filled('notes')) {
            $payment->notes = trim(($payment->notes ? $payment->notes . "\n" : '') . '[Reconciliation] ' . $request->notes);
        }

        $payment->save();

        return $this->successResponse($payment->load('order'), 'Payment reconciliation updated successfully');
    }

    /**
     * P2-7: Reconciliation dashboard for the supplier's payments.
     */
    public function reconciliationSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = B2bPayment::where('supplier_id', $user->id);
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        $payments = $query->get();

        $byStatus = $payments->groupBy(function ($payment) {
            return $payment->reconciliation_status ?: 'pending';
        })->map(function ($items, $status) {
            return [
                'status' => $status,
                'count' => $items->count(),
                'amount' => round($items->sum(fn ($p) => (float) $p->amount), 2),
            ];
        })->values();

        $unmatched = $payments->filter(function ($payment) {
            return in_array($payment->reconciliation_status, ['pending', 'unmatched'], true);
        });

        $staleDays = (int) $request->get('stale_days', 7);
        $stale = $unmatched->filter(function ($payment) use ($staleDays) {
            return $payment->created_at && $payment->created_at->lt(now()->subDays($staleDays));
        });

        return $this->successResponse([
            'total_payments' => $payments->count(),
            'total_amount' => round($payments->sum(fn ($p) => (float) $p->amount), 2),
            'verified_amount' => round($payments->where('status', 'paid')->sum(fn ($p) => (float) $p->amount), 2),
            'credit_note_total' => round(
                \App\Models\B2B\B2bCreditNote::where('supplier_id', $user->id)
                    ->whereIn('status', ['issued', 'applied'])
                    ->sum('amount'),
                2
            ),
            'by_status' => $byStatus,
            'unmatched_count' => $unmatched->count(),
            'unmatched_amount' => round($unmatched->sum(fn ($p) => (float) $p->amount), 2),
            'stale_unmatched_count' => $stale->count(),
            'stale_days_threshold' => $staleDays,
        ], 'Payment reconciliation summary retrieved successfully');
    }

    /**
     * P2-7: Refund (fully or partially) a verified payment via a credit note.
     */
    public function refund($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $payment = B2bPayment::where('supplier_id', $user->id)->find($id);

        if (!$payment) {
            return $this->errorResponse('Payment not found or access denied', 404);
        }

        if ($payment->status !== 'paid') {
            return $this->errorResponse('Only verified (paid) payments can be refunded', 400);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:1000',
            'type' => 'nullable|in:refund,damage_refund,shortage_refund,fx_adjustment,adjustment',
            'items' => 'nullable|array',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $alreadyRefunded = (float) \App\Models\B2B\B2bCreditNote::where('payment_id', $payment->id)
            ->whereIn('status', ['issued', 'applied'])
            ->sum('amount');

        if ($alreadyRefunded + (float) $request->amount > (float) $payment->amount) {
            return $this->errorResponse(
                'Refund total would exceed the payment amount. Already refunded: ' . $alreadyRefunded,
                422
            );
        }

        $note = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $payment, $user) {
            $note = \App\Models\B2B\B2bCreditNote::create([
                'credit_note_number' => 'CN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)),
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'buyer_id' => $payment->buyer_id,
                'supplier_id' => $payment->supplier_id,
                'type' => $request->get('type', 'refund'),
                'amount' => $request->amount,
                'currency' => $payment->currency,
                'exchange_rate' => $payment->exchange_rate,
                'base_currency' => $payment->base_currency,
                'base_amount' => $payment->exchange_rate
                    ? round((float) $request->amount * (float) $payment->exchange_rate, 2)
                    : null,
                'reason' => $request->reason,
                'status' => 'issued',
                'issued_by' => $user->id,
                'issued_at' => now(),
            ]);

            foreach ($request->get('items', []) as $item) {
                $note->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? null,
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $item['unit_price'] ?? null,
                    'line_total' => round((float) ($item['quantity'] ?? 1) * (float) ($item['unit_price'] ?? 0), 2),
                ]);
            }

            return $note;
        });

        return $this->successResponse($note->load('items'), 'Refund credit note issued successfully', 201);
    }
}
