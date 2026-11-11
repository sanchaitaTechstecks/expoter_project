<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bUnitConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * P2-4: unit conversion master data used by inventory and RFQ quantities.
 */
class AdminUnitConversionController extends B2bBaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = B2bUnitConversion::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->successResponse($query->orderBy('category')->orderBy('from_unit')->get(), 'Unit conversions retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_unit' => 'required|string|max:20',
            'to_unit' => 'required|string|max:20|different:from_unit',
            'factor' => 'required|numeric|gt:0',
            'category' => 'nullable|in:weight,volume,count,length,area,other',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $from = strtolower(trim($request->from_unit));
        $to = strtolower(trim($request->to_unit));

        if (B2bUnitConversion::where('from_unit', $from)->where('to_unit', $to)->exists()) {
            return $this->errorResponse("A conversion from {$from} to {$to} already exists", 409);
        }

        $conversion = B2bUnitConversion::create([
            'from_unit' => $from,
            'to_unit' => $to,
            'factor' => $request->factor,
            'category' => $request->category,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ]);

        return $this->successResponse($conversion, 'Unit conversion created successfully', 201);
    }

    public function update($id, Request $request): JsonResponse
    {
        $conversion = B2bUnitConversion::find($id);
        if (!$conversion) {
            return $this->errorResponse('Unit conversion not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'factor' => 'sometimes|required|numeric|gt:0',
            'category' => 'nullable|in:weight,volume,count,length,area,other',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        if ($request->filled('factor')) {
            $conversion->factor = $request->factor;
        }
        if ($request->has('category')) {
            $conversion->category = $request->category;
        }
        if ($request->has('is_active')) {
            $conversion->is_active = $request->boolean('is_active');
        }
        $conversion->save();

        return $this->successResponse($conversion, 'Unit conversion updated successfully');
    }

    public function destroy($id): JsonResponse
    {
        $conversion = B2bUnitConversion::find($id);
        if (!$conversion) {
            return $this->errorResponse('Unit conversion not found', 404);
        }

        $conversion->delete();

        return $this->successResponse(null, 'Unit conversion deleted successfully');
    }

    /**
     * Seed the standard metric trade conversions (idempotent).
     */
    public function seedDefaults(): JsonResponse
    {
        $defaults = [
            ['kg', 'g', 1000, 'weight'],
            ['g', 'kg', 0.001, 'weight'],
            ['mt', 'kg', 1000, 'weight'],
            ['kg', 'mt', 0.001, 'weight'],
            ['lb', 'kg', 0.45359237, 'weight'],
            ['kg', 'lb', 2.20462262, 'weight'],
            ['l', 'ml', 1000, 'volume'],
            ['ml', 'l', 0.001, 'volume'],
            ['m3', 'l', 1000, 'volume'],
            ['dozen', 'pcs', 12, 'count'],
            ['pcs', 'dozen', 0.08333333, 'count'],
            ['quintal', 'kg', 100, 'weight'],
            ['kg', 'quintal', 0.01, 'weight'],
            ['ft', 'm', 0.3048, 'length'],
            ['m', 'ft', 3.2808399, 'length'],
        ];

        $created = 0;
        foreach ($defaults as [$from, $to, $factor, $category]) {
            $conversion = B2bUnitConversion::firstOrCreate(
                ['from_unit' => $from, 'to_unit' => $to],
                ['factor' => $factor, 'category' => $category, 'is_active' => true]
            );
            if ($conversion->wasRecentlyCreated) {
                $created++;
            }
        }

        return $this->successResponse(['created' => $created, 'total' => B2bUnitConversion::count()], 'Default unit conversions seeded');
    }
}
