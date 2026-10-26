<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bHsCode;
use App\Models\Category;
use App\Services\B2B\B2bAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminCategoryMasterController extends B2bBaseController
{
    /**
     * List categories with HS code parameters & hierarchy.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()
            ->select(['id', 'parent_id', 'level', 'name', 'order_level', 'hs_code', 'standard_duty_rate', 'slug', 'featured'])
            ->with(['childrenCategories' => function ($q) {
                $q->select(['id', 'parent_id', 'level', 'name', 'order_level', 'hs_code', 'standard_duty_rate', 'slug']);
            }]);

        if ($request->has('parent_only')) {
            $query->where('parent_id', 0);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('hs_code', 'like', "%{$search}%");
            });
        }

        $categories = $query->orderBy('order_level', 'asc')->get();

        return $this->successResponse($categories, 'Master categories retrieved successfully');
    }

    /**
     * Create master trade category.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'parent_id' => 'nullable|integer',
            'hs_code' => 'nullable|string|max:50',
            'standard_duty_rate' => 'nullable|numeric|min:0|max:100',
            'order_level' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $parentId = (int) $request->get('parent_id', 0);
        $level = 0;
        if ($parentId > 0) {
            $parent = Category::find($parentId);
            if ($parent) {
                $level = $parent->level + 1;
            }
        }

        $slug = Str::slug($request->name);
        $count = Category::where('slug', 'like', "{$slug}%")->count();
        if ($count > 0) {
            $slug .= '-' . ($count + 1);
        }

        $category = Category::create([
            'name' => $request->name,
            'parent_id' => $parentId,
            'level' => $level,
            'order_level' => $request->order_level ?? 0,
            'hs_code' => $request->hs_code,
            'standard_duty_rate' => $request->standard_duty_rate ?? 0.00,
            'slug' => $slug,
        ]);

        B2bAuditService::log(
            $request->user()->id,
            $request->user()->admin_role ?: $request->user()->user_type,
            'create_category',
            'Category',
            $category->id,
            null,
            $category->toArray(),
            'Created master category'
        );

        return $this->successResponse($category, 'Category created successfully', 201);
    }

    /**
     * Update master category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);
        if (!$category) {
            return $this->errorResponse('Category not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'parent_id' => 'nullable|integer',
            'hs_code' => 'nullable|string|max:50',
            'standard_duty_rate' => 'nullable|numeric|min:0|max:100',
            'order_level' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $before = $category->only(['name', 'parent_id', 'level', 'hs_code', 'standard_duty_rate', 'order_level']);

        if ($request->filled('name')) {
            $category->name = $request->name;
        }
        if ($request->has('parent_id')) {
            $category->parent_id = (int) $request->parent_id;
            if ($category->parent_id > 0) {
                $parent = Category::find($category->parent_id);
                $category->level = $parent ? $parent->level + 1 : 0;
            } else {
                $category->level = 0;
            }
        }
        if ($request->has('hs_code')) {
            $category->hs_code = $request->hs_code;
        }
        if ($request->has('standard_duty_rate')) {
            $category->standard_duty_rate = $request->standard_duty_rate;
        }
        if ($request->has('order_level')) {
            $category->order_level = $request->order_level;
        }

        $category->save();

        $after = $category->only(['name', 'parent_id', 'level', 'hs_code', 'standard_duty_rate', 'order_level']);

        B2bAuditService::log(
            $request->user()->id,
            $request->user()->admin_role ?: $request->user()->user_type,
            'update_category',
            'Category',
            $category->id,
            $before,
            $after,
            'Updated master category'
        );

        return $this->successResponse($category, 'Category updated successfully');
    }

    /**
     * Delete master category.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);
        if (!$category) {
            return $this->errorResponse('Category not found', 404);
        }

        if ($category->categories()->count() > 0) {
            return $this->errorResponse('Cannot delete category with sub-categories. Reassign or delete sub-categories first.', 400);
        }

        $before = $category->toArray();
        $category->delete();

        B2bAuditService::log(
            $request->user()->id,
            $request->user()->admin_role ?: $request->user()->user_type,
            'delete_category',
            'Category',
            $id,
            $before,
            null,
            'Deleted master category'
        );

        return $this->successResponse(null, 'Category deleted successfully');
    }

    /**
     * List HS Code master catalog.
     */
    public function hsCodesIndex(Request $request): JsonResponse
    {
        $query = B2bHsCode::query()->with('category:id,name');

        if ($request->filled('chapter')) {
            $query->where('chapter', $request->chapter);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('hs_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 25), 100);
        $hsCodes = $query->orderBy('hs_code', 'asc')->paginate($perPage);

        return $this->successResponse($hsCodes, 'HS Code master catalog retrieved successfully');
    }

    /**
     * Create or update HS Code entry in master repository.
     */
    public function hsCodesStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hs_code' => 'required|string|max:50',
            'description' => 'required|string',
            'category_id' => 'nullable|integer',
            'chapter' => 'nullable|string|max:20',
            'heading' => 'nullable|string|max:20',
            'basic_customs_duty_pct' => 'nullable|numeric|min:0|max:100',
            'cess_pct' => 'nullable|numeric|min:0|max:100',
            'igst_pct' => 'nullable|numeric|min:0|max:100',
            'export_duty_pct' => 'nullable|numeric|min:0|max:100',
            'policy_status' => 'nullable|in:free,restricted,prohibited',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $hsCode = B2bHsCode::where('hs_code', $request->hs_code)->first();
        $before = $hsCode ? $hsCode->toArray() : null;

        if (!$hsCode) {
            $hsCode = new B2bHsCode();
            $hsCode->hs_code = $request->hs_code;
        }

        $hsCode->fill([
            'description' => $request->description,
            'category_id' => $request->category_id,
            'chapter' => $request->chapter ?? substr($request->hs_code, 0, 2),
            'heading' => $request->heading ?? substr($request->hs_code, 0, 4),
            'basic_customs_duty_pct' => $request->basic_customs_duty_pct ?? 0.00,
            'cess_pct' => $request->cess_pct ?? 0.00,
            'igst_pct' => $request->igst_pct ?? 0.00,
            'export_duty_pct' => $request->export_duty_pct ?? 0.00,
            'policy_status' => $request->policy_status ?? 'free',
            'is_active' => $request->has('is_active') ? (bool) $request->is_active : true,
        ]);

        $hsCode->save();

        B2bAuditService::log(
            $request->user()->id,
            $request->user()->admin_role ?: $request->user()->user_type,
            'save_hs_code',
            'B2bHsCode',
            $hsCode->id,
            $before,
            $hsCode->toArray(),
            'Saved HS Code master record'
        );

        return $this->successResponse($hsCode, 'HS Code saved successfully', $before ? 200 : 201);
    }
}
