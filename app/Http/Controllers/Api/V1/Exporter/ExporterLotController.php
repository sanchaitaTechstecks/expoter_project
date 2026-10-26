<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\InventoryMovement;
use App\Models\B2B\PriceHistory;
use App\Models\B2B\ProductLot;
use App\Models\Product;
use App\Services\B2B\B2bTradeService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ExporterLotController extends B2bBaseController
{
    protected B2bTradeService $tradeService;

    public function __construct(B2bTradeService $tradeService)
    {
        $this->tradeService = $tradeService;
    }

    /**
     * Create a new product lot (BR-001: independent stock, quality, and pricing).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'warehouse_location' => 'required|string|max:191',
            'quality_grade' => 'required|string|max:100',
            'quantity' => 'required|numeric|min:0.001',
            'unit' => 'required|string|max:20',
            'unit_price' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:10',
            'domestic_price' => 'nullable|numeric|min:0',
            'export_price' => 'nullable|numeric|min:0',
            'incoterm' => 'nullable|string|max:20', // FOB, CIF, CFR, EXW
            'named_place' => 'nullable|string|max:191',
            'packaging_details' => 'nullable|string|max:191',
            'manufacture_or_harvest_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'certificates' => 'nullable|array',
            'photos' => 'nullable|array',
            'lot_code' => 'nullable|string|max:100|unique:product_lots,lot_code',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $product = Product::where('id', $request->product_id)->where('user_id', $user->id)->first();
        if (!$product) {
            return $this->errorResponse('Product not found or unauthorized', 404);
        }

        return DB::transaction(function () use ($request, $user, $product) {
            $lotCode = $request->lot_code ?: 'LOT-' . date('Ymd') . '-' . strtoupper(Str::random(4));

            $lot = ProductLot::create([
                'lot_code' => $lotCode,
                'product_id' => $product->id,
                'supplier_id' => $user->id,
                'warehouse_location' => $request->warehouse_location,
                'quality_grade' => $request->quality_grade,
                'inspection_status' => 'inspected',
                'quantity_received' => $request->quantity,
                'quantity_reserved' => 0,
                'quantity_dispatched' => 0,
                'quantity_available' => $request->quantity,
                'unit' => $request->unit,
                'unit_price' => $request->unit_price,
                'currency' => strtoupper($request->currency),
                'domestic_price' => $request->domestic_price,
                'export_price' => $request->export_price,
                'incoterm' => $request->incoterm,
                'named_place' => $request->named_place,
                'packaging_details' => $request->packaging_details,
                'manufacture_or_harvest_date' => $request->manufacture_or_harvest_date,
                'expiry_date' => $request->expiry_date,
                'certificates' => $request->certificates,
                'photos' => $request->photos,
                'lot_status' => 'active',
                'uploaded_at' => now(),
                'effective_at' => now(),
            ]);

            // Initial price history record (BR-002)
            PriceHistory::create([
                'lot_id' => $lot->id,
                'old_price' => $request->unit_price,
                'new_price' => $request->unit_price,
                'currency' => $lot->currency,
                'reason' => 'Initial lot creation',
                'actor_id' => $user->id,
                'effective_at' => now(),
                'approval_status' => 'approved',
            ]);

            // Initial stock intake inventory movement (Section 5)
            InventoryMovement::create([
                'lot_id' => $lot->id,
                'movement_type' => 'received',
                'quantity' => $request->quantity,
                'unit' => $request->unit,
                'reference_type' => 'intake',
                'reference_id' => $lot->id,
                'reason' => 'Initial stock intake into lot ' . $lot->lot_code,
                'actor_id' => $user->id,
            ]);

            return $this->successResponse($lot, 'Product lot created successfully with independent pricing and stock', 201);
        });
    }

    /**
     * List all lots for a specific product.
     */
    public function index($productId, Request $request): JsonResponse
    {
        $user = $request->user();
        $lots = ProductLot::where('product_id', $productId)
            ->where('supplier_id', $user->id)
            ->with('priceHistories')
            ->orderBy('id', 'desc')
            ->get();

        return $this->successResponse($lots, 'Lots retrieved successfully');
    }

    /**
     * Show lot details with stock breakdown and history.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $lot = ProductLot::with(['product', 'priceHistories', 'inventoryMovements.actor'])
            ->where('supplier_id', $user->id)
            ->find($id);

        if (!$lot) {
            return $this->errorResponse('Lot not found or access denied', 404);
        }

        return $this->successResponse($lot, 'Lot details retrieved successfully');
    }

    /**
     * Update lot price with auditable price history (BR-002).
     */
    public function updatePrice($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $lot = ProductLot::where('supplier_id', $user->id)->find($id);

        if (!$lot) {
            return $this->errorResponse('Lot not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'new_price' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'reason' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $currency = $request->currency ? strtoupper($request->currency) : $lot->currency;

        try {
            $updatedLot = $this->tradeService->updateLotPrice(
                $lot->id,
                (float)$request->new_price,
                $currency,
                $user->id,
                $request->reason
            );

            return $this->successResponse($updatedLot->load('priceHistories'), 'Lot price updated and audited in price history');
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Adjust stock for a lot with inventory ledger tracking (BR-003).
     */
    public function adjustStock($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $lot = ProductLot::where('supplier_id', $user->id)->find($id);

        if (!$lot) {
            return $this->errorResponse('Lot not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'adjustment_type' => 'required|in:add,subtract',
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        return DB::transaction(function () use ($lot, $request, $user) {
            $lot = ProductLot::where('id', $lot->id)->lockForUpdate()->first();
            $qty = (float)$request->quantity;

            if ($request->adjustment_type === 'subtract') {
                if ($lot->quantity_available < $qty) {
                    return $this->errorResponse("Cannot subtract {$qty}. Only {$lot->quantity_available} available.", 400);
                }
                $lot->quantity_available = bcsub((string)$lot->quantity_available, (string)$qty, 3);
                $mType = 'adjusted';
            } else {
                $lot->quantity_available = bcadd((string)$lot->quantity_available, (string)$qty, 3);
                $lot->quantity_received = bcadd((string)$lot->quantity_received, (string)$qty, 3);
                $mType = 'received';
            }

            $lot->save();

            InventoryMovement::create([
                'lot_id' => $lot->id,
                'movement_type' => $mType,
                'quantity' => $qty,
                'unit' => $lot->unit,
                'reference_type' => 'manual_adjustment',
                'reference_id' => $lot->id,
                'reason' => $request->reason,
                'actor_id' => $user->id,
            ]);

            return $this->successResponse($lot, 'Stock adjusted successfully');
        });
    }

    /**
     * Inventory movements ledger for a lot.
     */
    public function movements($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $lot = ProductLot::where('supplier_id', $user->id)->find($id);

        if (!$lot) {
            return $this->errorResponse('Lot not found or access denied', 404);
        }

        $movements = InventoryMovement::with('actor')
            ->where('lot_id', $lot->id)
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 20));

        return $this->successResponse($movements, 'Inventory movements retrieved successfully');
    }
}
