<?php

namespace App\Http\Controllers\Api\V1\Importer;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\ProductLot;
use App\Models\Category;
use App\Models\Product;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ImporterMarketplaceController extends B2bBaseController
{
    private const MAX_PER_PAGE = 100;

    /**
     * Columns exposed for marketplace lot rows / lot details.
     */
    private const LOT_COLUMNS = [
        'id',
        'lot_code',
        'product_id',
        'supplier_id',
        'warehouse_location',
        'quality_grade',
        'destination_eligibility',
        'inspection_status',
        'quantity_available',
        'quantity_reserved',
        'unit',
        'unit_price',
        'currency',
        'domestic_price',
        'export_price',
        'incoterm',
        'named_place',
        'packaging_details',
        'manufacture_or_harvest_date',
        'expiry_date',
        'certificates',
        'photos',
    ];

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
     *
     * Supported filters: search, category_id, trade_type, origin_country,
     * min_price, max_price, grade, min_quantity, unit, supplier_id,
     * destination_country, per_page, page.
     */
    public function products(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), array_merge($this->filterRules(), [
            'search' => 'nullable|string|max:191',
            'category_id' => 'nullable|integer|min:1',
            'trade_type' => 'nullable|in:domestic,international',
            'origin_country' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
            'page' => 'nullable|integer|min:1',
        ]));

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if (($rangeError = $this->priceRangeError($validator->validated())) !== null) {
            return $this->errorResponse('Validation failed', 422, ['max_price' => [$rangeError]]);
        }

        $filters = $this->filledFilters($validator->validated());
        $lotConstraint = $this->lotConstraint($filters);

        $query = Product::query()
            ->where('published', 1)
            ->where('publication_status', 'active');

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['trade_type'])) {
            if ($filters['trade_type'] === 'domestic') {
                $query->where('domestic_visibility', true);
            } elseif ($filters['trade_type'] === 'international') {
                $query->where('export_visibility', true);
            }
        }

        if (isset($filters['origin_country'])) {
            $query->where('origin_country', $filters['origin_country']);
        }

        // Only products that actually have a lot matching every lot-level filter.
        if ($lotConstraint !== null) {
            $query->whereHas('activeLots', $lotConstraint);
        }

        $products = $query
            ->with([
                'main_category:id,name',
                'user' => function ($q) {
                    $q->select('id', 'name');
                },
                'user.company' => function ($q) {
                    $q->select('id', 'user_id', 'name', 'country', 'company_type', 'verification_status');
                },
                'activeLots' => function ($q) use ($lotConstraint) {
                    $q->select(self::LOT_COLUMNS)->orderBy('unit_price');
                    if ($lotConstraint !== null) {
                        $lotConstraint($q);
                    }
                },
            ])
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->successResponse($products, 'Products retrieved successfully');
    }

    /**
     * Product detail with paginated active lots and independent lot pricing (BR-001).
     *
     * Unpublished / non-active products are never returned, even by direct id.
     */
    public function productDetails($id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), array_merge($this->filterRules(), [
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
            'page' => 'nullable|integer|min:1',
        ]));

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if (($rangeError = $this->priceRangeError($validator->validated())) !== null) {
            return $this->errorResponse('Validation failed', 422, ['max_price' => [$rangeError]]);
        }

        $product = Product::query()
            ->where('published', 1)
            ->where('publication_status', 'active')
            ->with([
                'main_category:id,name',
                'user' => function ($q) {
                    $q->select('id', 'name');
                },
                'user.company' => function ($q) {
                    $q->select('id', 'user_id', 'name', 'country', 'company_type', 'verification_status');
                },
            ])
            ->find($id);

        if (!$product) {
            return $this->errorResponse('Product not found', 404);
        }

        $filters = $this->filledFilters($validator->validated());
        $lotConstraint = $this->lotConstraint($filters);

        $lotsQuery = $product->activeLots()
            ->where('quantity_available', '>', 0)
            ->select(self::LOT_COLUMNS)
            ->orderBy('unit_price');

        if ($lotConstraint !== null) {
            $lotConstraint($lotsQuery);
        }

        $lots = $lotsQuery->paginate($this->perPage($request, 15), ['*'], 'lots_page');

        $data = $product->toArray();
        $data['active_lots'] = $lots;

        return $this->successResponse($data, 'Product details retrieved successfully');
    }

    /**
     * Lot detail view with paginated price history and inventory ledger.
     *
     * Lots of unpublished / non-active products, and non-active lots
     * (draft, pending approval, blocked, depleted, archived), are not returned.
     */
    public function lotDetails($id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
            'history_page' => 'nullable|integer|min:1',
            'movements_page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $lot = ProductLot::query()
            ->where('lot_status', 'active')
            ->whereHas('product', function ($q) {
                $q->where('published', 1)->where('publication_status', 'active');
            })
            ->with(['product', 'supplier.company'])
            ->find($id);

        if (!$lot) {
            return $this->errorResponse('Product lot not found', 404);
        }

        $perPage = $this->perPage($request, 20);

        $data = $lot->toArray();
        $data['price_histories'] = $lot->priceHistories()
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'history_page');
        $data['inventory_movements'] = $lot->inventoryMovements()
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'movements_page');

        return $this->successResponse($data, 'Lot details retrieved successfully');
    }

    /**
     * Validation rules shared by the list and detail endpoints.
     */
    private function filterRules(): array
    {
        return [
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'grade' => 'nullable|string|max:100',
            'min_quantity' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:20',
            'supplier_id' => 'nullable|integer|min:1',
            'destination_country' => 'nullable|string|max:100',
        ];
    }

    /**
     * Drop null / empty-string filters while keeping meaningful zeroes.
     */
    private function filledFilters(array $validated): array
    {
        return array_filter($validated, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    private function priceRangeError(array $validated): ?string
    {
        if (
            isset($validated['min_price'], $validated['max_price']) &&
            (float) $validated['max_price'] < (float) $validated['min_price']
        ) {
            return 'The max price must be greater than or equal to the min price.';
        }

        return null;
    }

    /**
     * Build the constraint applied to a single lot row, so every lot-level
     * filter must match the same lot of a product.
     */
    private function lotConstraint(array $filters): ?Closure
    {
        $lotFilterKeys = ['min_price', 'max_price', 'grade', 'min_quantity', 'unit', 'supplier_id', 'destination_country'];

        $hasLotFilter = false;
        foreach ($lotFilterKeys as $key) {
            if (array_key_exists($key, $filters)) {
                $hasLotFilter = true;
                break;
            }
        }

        if (!$hasLotFilter) {
            return null;
        }

        return function ($query) use ($filters) {
            if (array_key_exists('min_price', $filters)) {
                $query->where('unit_price', '>=', $filters['min_price']);
            }

            if (array_key_exists('max_price', $filters)) {
                $query->where('unit_price', '<=', $filters['max_price']);
            }

            if (array_key_exists('min_quantity', $filters)) {
                $query->where('quantity_available', '>=', $filters['min_quantity']);
            }

            if (array_key_exists('grade', $filters)) {
                $query->whereRaw('LOWER(quality_grade) = ?', [mb_strtolower($filters['grade'])]);
            }

            if (array_key_exists('unit', $filters)) {
                $query->whereRaw('LOWER(unit) = ?', [mb_strtolower($filters['unit'])]);
            }

            if (array_key_exists('supplier_id', $filters)) {
                $query->where('supplier_id', $filters['supplier_id']);
            }

            if (array_key_exists('destination_country', $filters)) {
                // Matches an element of the JSON array case-insensitively.
                // NULL or [] means the lot has no destination restriction.
                $pattern = '%"' . addcslashes(mb_strtolower($filters['destination_country']), '\\%_') . '"%';

                $query->where(function ($destinationQuery) use ($pattern) {
                    $destinationQuery->whereNull('destination_eligibility')
                        ->orWhere('destination_eligibility', '[]')
                        ->orWhereRaw('LOWER(destination_eligibility) LIKE ?', [$pattern]);
                });
            }
        };
    }

    private function perPage(Request $request, int $default = 15): int
    {
        $perPage = (int) $request->get('per_page', $default);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
