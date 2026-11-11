<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ImporterPaymentController extends B2bBaseController
{
    /**
     * List buyer payments.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bPayment::with(['order', 'invoice'])
            ->where('buyer_id', $user->id);

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
     * Record payment / upload payment proof for an order (BR-006).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:b2b_orders,id',
            'invoice_id' => 'nullable|exists:b2b_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:10',
            'payment_type' => 'required|in:advance,deposit,partial,balance,full',
            'payment_method' => 'required|in:bank_transfer,letter_of_credit,online,cheque,other',
            'transaction_reference' => 'required|string|max:191',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240', // max 10MB
            'exchange_rate' => 'nullable|numeric|min:0.00000001',
            'base_currency' => 'nullable|string|max:10',
            'transaction_at' => 'nullable|date',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $order = B2bOrder::where('id', $request->order_id)->where('buyer_id', $user->id)->first();
        if (!$order) {
            return $this->errorResponse('Order not found or unauthorized', 404);
        }

        $proofFilePath = null;
        if ($request->hasFile('proof_file')) {
            $file = $request->file('proof_file');
            $proofFilePath = $file->store('b2b/payments', 'public');
        }

        $paymentNumber = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        // P2-7: capture FX details so multi-currency payments can be reconciled.
        $exchangeRate = $request->filled('exchange_rate') ? (float) $request->exchange_rate : null;
        $baseCurrency = $request->filled('base_currency') ? strtoupper($request->base_currency) : null;

        $payment = B2bPayment::create([
            'payment_number' => $paymentNumber,
            'order_id' => $order->id,
            'invoice_id' => $request->invoice_id,
            'buyer_id' => $user->id,
            'supplier_id' => $order->supplier_id,
            'amount' => $request->amount,
            'currency' => strtoupper($request->currency),
            'exchange_rate' => $exchangeRate,
            'base_currency' => $baseCurrency,
            'base_amount' => $exchangeRate ? round((float) $request->amount * $exchangeRate, 2) : null,
            'transaction_at' => $request->transaction_at ?: now(),
            'payment_type' => $request->payment_type,
            'payment_method' => $request->payment_method,
            'transaction_reference' => $request->transaction_reference,
            'proof_file' => $proofFilePath,
            'due_date' => $request->due_date,
            'initiated_at' => now(),
            'reconciliation_status' => 'pending',
            'status' => 'verification_required', // BR-006: proof upload alone does not mark funds received
            'notes' => $request->notes,
        ]);

        return $this->successResponse($payment, 'Payment recorded and submitted for supplier verification', 201);
    }

    /**
     * Payment detail view.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $payment = B2bPayment::with(['order', 'invoice'])
            ->where('buyer_id', $user->id)
            ->find($id);

        if (!$payment) {
            return $this->errorResponse('Payment not found or access denied', 404);
        }

        return $this->successResponse($payment, 'Payment details retrieved successfully');
    }
}
