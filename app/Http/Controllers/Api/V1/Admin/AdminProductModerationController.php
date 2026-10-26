<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\ProductLot;
use App\Models\Product;
use App\Services\B2B\B2bAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminProductModerationController extends B2bBaseController
{
    public function __construct(
        protected B2bAdminService $adminService
    ) {}

    /**
     * List products with publication status filters.
     */
    public function products(Request $request): JsonResponse
    {
        $query = Product::query()->with([
            'user:id,name,email',
            'lots' => function ($q) {
                $q->select('id', 'product_id', 'lot_code', 'unit_price', 'quantity_available', 'approval_status');
            }
        ]);

        if ($request->filled('status')) {
            $query->where('publication_status', $request->status);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $products = $query->latest('id')->paginate($perPage);

        return $this->successResponse($products, 'Products retrieved successfully');
    }

    /**
     * Approve, reject, or suspend product listing.
     */
    public function approveProduct(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,rejected,suspended',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $product = Product::find($id);
        if (!$product) {
            return $this->errorResponse('Product not found', 404);
        }

        $updated = $this->adminService->moderateProduct(
            $id,
            $request->status,
            $request->notes,
            $request->user()
        );

        return $this->successResponse($updated, "Product listing status updated to {$request->status}");
    }

    /**
     * Queue of lots pending pricing approval / BR-002 price change reviews.
     */
    public function pendingLots(Request $request): JsonResponse
    {
        $query = ProductLot::query()
            ->with([
                'product:id,name,sku,category_id',
                'supplier:id,name,email',
                'priceHistories' => function ($q) {
                    $q->latest()->limit(5);
                }
            ])
            ->where(function ($q) {
                $q->where('approval_status', 'pending')
                  ->orWhereHas('priceHistories', function ($ph) {
                      $ph->where('approval_status', 'pending');
                  });
            });

        $perPage = min((int) $request->get('per_page', 20), 100);
        $lots = $query->latest('id')->paginate($perPage);

        return $this->successResponse($lots, 'Pending lot pricing moderation queue retrieved');
    }

    /**
     * Approve or reject lot pricing changes.
     */
    public function approveLotPrice(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $lot = ProductLot::find($id);
        if (!$lot) {
            return $this->errorResponse('Product lot not found', 404);
        }

        $updated = $this->adminService->approveLotPricing(
            $id,
            $request->decision,
            $request->notes,
            $request->user()
        );

        return $this->successResponse($updated, "Lot pricing {$request->decision} successfully");
    }
}
