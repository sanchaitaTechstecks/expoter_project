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
}
