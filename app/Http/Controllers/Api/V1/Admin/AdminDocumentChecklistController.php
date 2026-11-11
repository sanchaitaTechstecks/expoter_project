<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\DocumentChecklistTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * P2-6: document checklist templates per commodity / trade route.
 */
class AdminDocumentChecklistController extends B2bBaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = DocumentChecklistTemplate::with('items');

        if ($request->filled('trade_type')) {
            $query->where('trade_type', $request->trade_type);
        }

        if ($request->filled('destination_country')) {
            $query->where('destination_country', $request->destination_country);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return $this->successResponse($query->orderBy('name')->get(), 'Checklist templates retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'commodity' => 'nullable|string|max:191',
            'trade_type' => 'required|in:domestic,international,both',
            'origin_country' => 'nullable|string|max:100',
            'destination_country' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.document_type' => 'required|string|max:100',
            'items.*.is_mandatory' => 'nullable|boolean',
            'items.*.sort_order' => 'nullable|integer|min:0',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $template = DB::transaction(function () use ($request) {
            $template = DocumentChecklistTemplate::create([
                'name' => $request->name,
                'commodity' => $request->commodity,
                'trade_type' => $request->trade_type,
                'origin_country' => $request->origin_country,
                'destination_country' => $request->destination_country,
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
            ]);

            $this->syncItems($template, $request->items);

            return $template;
        });

        return $this->successResponse($template->load('items'), 'Checklist template created successfully', 201);
    }

    public function show($id): JsonResponse
    {
        $template = DocumentChecklistTemplate::with('items')->find($id);
        if (!$template) {
            return $this->errorResponse('Checklist template not found', 404);
        }

        return $this->successResponse($template, 'Checklist template retrieved successfully');
    }

    public function update($id, Request $request): JsonResponse
    {
        $template = DocumentChecklistTemplate::find($id);
        if (!$template) {
            return $this->errorResponse('Checklist template not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'commodity' => 'nullable|string|max:191',
            'trade_type' => 'sometimes|required|in:domestic,international,both',
            'origin_country' => 'nullable|string|max:100',
            'destination_country' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'items' => 'sometimes|array|min:1',
            'items.*.document_type' => 'required_with:items|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        DB::transaction(function () use ($template, $request) {
            $template->fill($request->only([
                'name', 'commodity', 'trade_type', 'origin_country', 'destination_country',
            ]));

            if ($request->has('is_active')) {
                $template->is_active = $request->boolean('is_active');
            }

            $template->save();

            if ($request->has('items')) {
                $template->items()->delete();
                $this->syncItems($template, $request->items);
            }
        });

        return $this->successResponse($template->fresh()->load('items'), 'Checklist template updated successfully');
    }

    public function destroy($id): JsonResponse
    {
        $template = DocumentChecklistTemplate::find($id);
        if (!$template) {
            return $this->errorResponse('Checklist template not found', 404);
        }

        DB::transaction(function () use ($template) {
            $template->items()->delete();
            $template->delete();
        });

        return $this->successResponse(null, 'Checklist template deleted successfully');
    }

    private function syncItems(DocumentChecklistTemplate $template, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            $template->items()->create([
                'document_type' => $item['document_type'],
                'is_mandatory' => array_key_exists('is_mandatory', $item) ? (bool) $item['is_mandatory'] : true,
                'sort_order' => $item['sort_order'] ?? $index,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }
}
