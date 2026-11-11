<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ExporterProductController extends B2bBaseController
{
    /**
     * List all products owned by the authenticated exporter.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Product::with(['main_category', 'lots'])
            ->where('user_id', $user->id);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($products, 'Products retrieved successfully');
    }

    /**
     * Create a new B2B product.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:200',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'category_id' => 'required|exists:categories,id',
            'grade' => 'nullable|string|max:100',
            'origin_country' => 'required|string|max:100',
            'unit' => 'required|string|max:20',
            'packaging_type' => 'nullable|string|max:100',
            'specifications' => 'nullable|string',
            'description' => 'nullable|string',
            'min_qty' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'domestic_visibility' => 'nullable|boolean',
            'export_visibility' => 'nullable|boolean',
            'publication_status' => 'prohibited',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $sku = $request->sku ?: 'SKU-' . strtoupper(Str::random(8));
        $slug = Str::slug($request->name) . '-' . strtolower(Str::random(5));

        $product = Product::create([
            'name' => $request->name,
            'slug' => $slug,
            'sku' => $sku,
            'added_by' => 'seller',
            'user_id' => $user->id,
            'category_id' => $request->category_id,
            'grade' => $request->grade,
            'origin_country' => $request->origin_country,
            'unit' => $request->unit,
            'packaging_type' => $request->packaging_type,
            'specifications' => $request->specifications,
            'description' => $request->description,
            'unit_price' => $request->unit_price ?: 0,
            'min_qty' => $request->min_qty ?: 1,
            'domestic_visibility' => $request->has('domestic_visibility') ? (bool)$request->domestic_visibility : true,
            'export_visibility' => $request->has('export_visibility') ? (bool)$request->export_visibility : true,
            'publication_status' => 'pending_approval',
            'published' => 0,
            'approved' => 0,
        ]);

        return $this->successResponse(
            $product->load('main_category'),
            'Product created successfully and submitted for approval',
            201
        );
    }

    /**
     * Show product details with all lots.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $product = Product::with(['main_category', 'lots.priceHistories'])
            ->where('user_id', $user->id)
            ->find($id);

        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        return $this->successResponse($product, 'Product retrieved successfully');
    }

    /**
     * Update product details.
     */
    public function update($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $product = Product::where('user_id', $user->id)->find($id);

        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:200',
            'sku' => 'nullable|string|max:100|unique:products,sku,' . $product->id,
            'category_id' => 'sometimes|required|exists:categories,id',
            'grade' => 'nullable|string|max:100',
            'origin_country' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:20',
            'packaging_type' => 'nullable|string|max:100',
            'specifications' => 'nullable|string',
            'description' => 'nullable|string',
            'domestic_visibility' => 'nullable|boolean',
            'export_visibility' => 'nullable|boolean',
            // 'publication_status' => 'nullable|in:draft,pending_approval,active,inactive,archived',
             'publication_status' => 'prohibited',
             'published' => 'prohibited',
             'approved' => 'prohibited',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $product->update($request->only([
            'name',
            'sku',
            'category_id',
            'grade',
            'origin_country',
            'unit',
            'packaging_type',
            'specifications',
            'description',
            'domestic_visibility',
            'export_visibility',
        ]));

        return $this->successResponse($product,'Product updated successfully');
    }
}