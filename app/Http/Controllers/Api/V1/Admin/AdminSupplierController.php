<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\Company;
use App\Services\B2B\B2bAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminSupplierController extends B2bBaseController
{
    public function __construct(
        protected B2bAdminService $adminService
    ) {}

    /**
     * List suppliers queue with verification filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Company::query()
            ->with(['user:id,name,email,phone'])
            ->whereIn('company_type', ['exporter', 'both']);

        if ($request->filled('status')) {
            $query->where('verification_status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('legal_name', 'like', "%{$search}%")
                  ->orWhere('iec_code', 'like', "%{$search}%")
                  ->orWhere('tax_number', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $suppliers = $query->latest('id')->paginate($perPage);

        return $this->successResponse($suppliers, 'Supplier verification queue retrieved successfully');
    }

    /**
     * Detailed supplier profile & KYC data.
     */
    public function show(int $id): JsonResponse
    {
        $supplier = Company::with(['user:id,name,email,phone,created_at'])->find($id);

        if (!$supplier) {
            return $this->errorResponse('Supplier company not found', 404);
        }

        return $this->successResponse($supplier, 'Supplier details retrieved successfully');
    }

    /**
     * Approve or reject supplier verification.
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:verified,rejected,pending',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $company = Company::find($id);
        if (!$company) {
            return $this->errorResponse('Supplier company not found', 404);
        }

        $updated = $this->adminService->verifySupplier(
            $id,
            $request->status,
            $request->notes,
            $request->user()
        );

        return $this->successResponse($updated, "Supplier status updated to {$request->status}");
    }
}
