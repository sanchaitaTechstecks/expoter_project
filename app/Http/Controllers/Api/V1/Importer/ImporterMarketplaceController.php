<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\ProductLot;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImporterMarketplaceController extends B2bBaseController
{
    /**
     * List all categories for B2B catalog browsing.
     */
    public function categories(): JsonResponse
    {
        $categories = Category::select('id', 'name', 'parent_id', 'icon', 'banner', 'order_level')
            ->orderBy('order_level', 'desc')
            ->get();

        return $this->successResponse($categories, 'Categories retrieved successfully');
    }

    /**
     * Browse and search products with lot-wise availability filters.
     */
    public function products(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('published', 1)
            ->where('publication_status', 'active');

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('category_id') && !empty($request->category_id)) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('trade_type')) {
            if ($request->trade_type === 'domestic') {
                $query->where('domestic_visibility', true);
            } elseif ($request->trade_type === 'international') {
                $query->where('export_visibility', true);
            }
        }

        if ($request->has('origin_country') && !empty($request->origin_country)) {
            $query->where('origin_country', $request->origin_country);
        }

        // Include active lots count and price range
        $products = $query->with(['activeLots' => function ($q) {
            $q->select('id', 'product_id', 'lot_code', 'quality_grade', 'unit_price', 'currency', 'quantity_available', 'unit', 'incoterm', 'named_place');
        }])
        ->paginate($request->get('per_page', 15));

        return $this->successResponse($products, 'Products retrieved successfully');
    }

    /**
     * Product detail with active lots and independent lot pricing (BR-001).
     */
    public function productDetails($id): JsonResponse
    {
        $product = Product::with([
            'main_category',
            'user.company',
            'activeLots' => function ($q) {
                $q->where('quantity_available', '>', 0)
                  ->select(
                      'id', 'lot_code', 'product_id', 'supplier_id', 'warehouse_location',
                      'quality_grade', 'inspection_status', 'quantity_available', 'quantity_reserved',
                      'unit', 'unit_price', 'currency', 'domestic_price', 'export_price',
                      'incoterm', 'named_place', 'packaging_details', 'manufacture_or_harvest_date',
                      'expiry_date', 'photos'
                  );
            }
        ])->find($id);

        if (!$product) {
            return $this->errorResponse('Product not found', 404);
        }

        return $this->successResponse($product, 'Product details retrieved successfully');
    }

    /**
     * Lot detail view.
     */
    public function lotDetails($id): JsonResponse
    {
        $lot = ProductLot::with(['product', 'supplier.company'])->find($id);

        if (!$lot) {
            return $this->errorResponse('Product lot not found', 404);
        }

        return $this->successResponse($lot, 'Lot details retrieved successfully');
    }
}
