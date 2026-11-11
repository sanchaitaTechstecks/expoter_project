<?php

namespace App\Http\Controllers\Api\V1\B2B;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bCreditNote;
use App\Models\B2B\B2bOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * P2-7: credit notes / refunds visible to both trade parties.
 */
class B2bCreditNoteController extends B2bBaseController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bCreditNote::with(['order:id,order_number,currency', 'items']);

        if ($user->user_type === 'customer') {
            $query->where('buyer_id', $user->id);
        } elseif ($user->user_type === 'seller') {
            $query->where('supplier_id', $user->id);
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [$request->from, $request->to]);
        }

        $notes = $query->orderByDesc('id')->paginate($request->get('per_page', 15));

        return $this->successResponse($notes, 'Credit notes retrieved successfully');
    }

    public function show($id, Request $request): JsonResponse
    {
        $note = $this->accessible($id, $request);

        if (!$note) {
            return $this->errorResponse('Credit note not found or access denied', 404);
        }

        return $this->successResponse($note, 'Credit note retrieved successfully');
    }

    /**
     * Create a credit note against an order (supplier or back-office).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:b2b_orders,id',
            'dispute_id' => 'nullable|exists:b2b_disputes,id',
            'payment_id' => 'nullable|exists:b2b_payments,id',
            'type' => 'required|in:credit,refund,damage_refund,shortage_refund,fx_adjustment,adjustment',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'exchange_rate' => 'nullable|numeric|min:0.00000001',
            'reason' => 'required|string|max:1000',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $order = B2bOrder::find($request->order_id);
        if (!$order) {
            return $this->errorResponse('Order not found', 404);
        }

        if (!in_array($user->user_type, ['admin', 'staff'], true) && (int) $order->supplier_id !== (int) $user->id) {
            return $this->errorResponse('Only the supplier can issue a credit note for this order', 403);
        }

        $issued = (float) B2bCreditNote::where('order_id', $order->id)
            ->whereIn('status', ['issued', 'applied'])
            ->sum('amount');

        if ($issued + (float) $request->amount > (float) $order->total_amount) {
            return $this->errorResponse(
                'Credit note total would exceed the order value. Already issued: ' . $issued,
                422
            );
        }

        $exchangeRate = $request->filled('exchange_rate') ? (float) $request->exchange_rate : null;

        $note = B2bCreditNote::create([
            'credit_note_number' => 'CN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)),
            'order_id' => $order->id,
            'dispute_id' => $request->dispute_id,
            'payment_id' => $request->payment_id,
            'buyer_id' => $order->buyer_id,
            'supplier_id' => $order->supplier_id,
            'type' => $request->type,
            'amount' => $request->amount,
            'currency' => strtoupper($request->currency ?: $order->currency),
            'exchange_rate' => $exchangeRate,
            'base_currency' => $request->filled('base_currency') ? strtoupper($request->base_currency) : null,
            'base_amount' => $exchangeRate ? round((float) $request->amount * $exchangeRate, 2) : null,
            'reason' => $request->reason,
            'status' => 'draft',
            'notes' => $request->notes,
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

        return $this->successResponse($note->load('items', 'order:id,order_number'), 'Credit note created successfully', 201);
    }

    public function issue($id, Request $request): JsonResponse
    {
        $note = $this->accessible($id, $request);
        if (!$note) {
            return $this->errorResponse('Credit note not found or access denied', 404);
        }

        if ($note->status !== 'draft') {
            return $this->errorResponse("Only draft credit notes can be issued", 409);
        }

        $note->status = 'issued';
        $note->issued_by = $request->user()->id;
        $note->issued_at = now();
        $note->save();

        return $this->successResponse($note, 'Credit note issued successfully');
    }

    public function apply($id, Request $request): JsonResponse
    {
        $note = $this->accessible($id, $request);
        if (!$note) {
            return $this->errorResponse('Credit note not found or access denied', 404);
        }

        if (!in_array($note->status, ['issued'], true)) {
            return $this->errorResponse('Only issued credit notes can be applied', 409);
        }

        $validator = Validator::make($request->all(), [
            'payment_id' => 'nullable|exists:b2b_payments,id',
            'applied_note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $note->status = 'applied';
        $note->applied_at = now();
        if ($request->filled('payment_id')) {
            $note->payment_id = $request->payment_id;
        }
        if ($request->filled('applied_note')) {
            $note->notes = trim(($note->notes ?? '') . "\n[Applied] " . $request->applied_note);
        }
        $note->save();

        return $this->successResponse($note, 'Credit note applied successfully');
    }

    public function void($id, Request $request): JsonResponse
    {
        $note = $this->accessible($id, $request);
        if (!$note) {
            return $this->errorResponse('Credit note not found or access denied', 404);
        }

        if ($note->status === 'applied') {
            return $this->errorResponse('Applied credit notes cannot be voided', 409);
        }

        $note->status = 'void';
        $note->save();

        return $this->successResponse($note, 'Credit note voided successfully');
    }

    private function accessible($id, Request $request): ?B2bCreditNote
    {
        $user = $request->user();
        $query = B2bCreditNote::with(['order:id,order_number,currency,total_amount', 'items', 'supplier:id,name', 'buyer:id,name']);

        if ($user->user_type === 'customer') {
            $query->where('buyer_id', $user->id);
        } elseif ($user->user_type === 'seller') {
            $query->where('supplier_id', $user->id);
        }

        return $query->find($id);
    }
}
