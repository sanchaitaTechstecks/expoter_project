<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bAgreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ImporterAgreementController extends B2bBaseController
{
    /**
     * List buyer agreements.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $agreements = B2bAgreement::with(['order', 'supplier.company'])
            ->where('buyer_id', $user->id)
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($agreements, 'Agreements retrieved successfully');
    }

    /**
     * View agreement details.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $agreement = B2bAgreement::with(['order.items', 'supplier.company'])
            ->where('buyer_id', $user->id)
            ->find($id);

        if (!$agreement) {
            return $this->errorResponse('Agreement not found or access denied', 404);
        }

        return $this->successResponse($agreement, 'Agreement details retrieved successfully');
    }

    /**
     * Sign/Accept trade agreement.
     */
    public function sign($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $agreement = B2bAgreement::where('buyer_id', $user->id)->find($id);

        if (!$agreement) {
            return $this->errorResponse('Agreement not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'signature' => 'required|string|max:255', // Signer full name or signature hash
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $agreement->buyer_signature = $request->signature;
        $agreement->buyer_signed_at = now();

        if ($agreement->supplier_signed_at) {
            $agreement->status = 'accepted';
        } else {
            $agreement->status = 'sent_to_counterparty';
        }

        $agreement->save();

        return $this->successResponse($agreement, 'Agreement signed successfully');
    }
}
