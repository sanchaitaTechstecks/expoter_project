<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bInventoryAlert;
use App\Models\B2B\B2bUnitConversion;
use App\Models\B2B\B2bWarehouseTransfer;
use App\Models\B2B\InventoryMovement;
use App\Models\B2B\ProductLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * P2-4: inventory ledger, low-stock/ageing visibility, transfers and unit conversion.
 */
class ExporterInventoryController extends B2bBaseController
{
    private const MOVEMENT_TYPES = ['damaged', 'lost', 'returned', 'adjusted'];

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $lots = ProductLot::where('supplier_id', $user->id)->get();
        $byWarehouse = $lots->groupBy('warehouse_location')->map(function ($items, $warehouse) {
            return [
                'warehouse' => $warehouse ?: 'unassigned',
                'lot_count' => $items->count(),
                'quantity_available' => round($items->sum(fn ($l) => (float) $l->quantity_available), 3),
                'quantity_reserved' => round($items->sum(fn ($l) => (float) $l->quantity_reserved), 3),
                'quantity_dispatched' => round($items->sum(fn ($l) => (float) $l->quantity_dispatched), 3),
                'stock_value' => round($items->sum(fn ($l) => (float) $l->quantity_available * (float) $l->unit_price), 2),
            ];
        })->values();

        return $this->successResponse([
            'lot_count' => $lots->count(),
            'total_available' => round($lots->sum(fn ($l) => (float) $l->quantity_available), 3),
            'total_reserved' => round($lots->sum(fn ($l) => (float) $l->quantity_reserved), 3),
            'total_dispatched' => round($lots->sum(fn ($l) => (float) $l->quantity_dispatched), 3),
            'stock_value' => round($lots->sum(fn ($l) => (float) $l->quantity_available * (float) $l->unit_price), 2),
            'currency_mix' => $lots->groupBy('currency')->map(fn ($items, $currency) => [
                'currency' => $currency,
                'stock_value' => round($items->sum(fn ($l) => (float) $l->quantity_available * (float) $l->unit_price), 2),
            ])->values(),
            'low_stock_count' => $lots->filter(function ($lot) {
                return (float) $lot->low_stock_threshold > 0
                    && (float) $lot->quantity_available <= (float) $lot->low_stock_threshold;
            })->count(),
            'by_warehouse' => $byWarehouse,
        ], 'Inventory summary retrieved successfully');
    }

    /**
     * Lots at or below their configured threshold (or a request-supplied one).
     */
    public function lowStock(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'threshold' => 'nullable|numeric|min:0',
            'warehouse_location' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $threshold = $request->filled('threshold') ? (float) $request->threshold : null;

        $query = ProductLot::with('product:id,name,sku')
            ->where('supplier_id', $request->user()->id)
            ->where('lot_status', 'active');

        if ($request->filled('warehouse_location')) {
            $query->where('warehouse_location', $request->warehouse_location);
        }

        if ($threshold !== null) {
            $query->where('quantity_available', '<=', $threshold);
        } else {
            $query->where('low_stock_threshold', '>', 0)
                ->whereColumn('quantity_available', '<=', 'low_stock_threshold');
        }

        $lots = $query->orderBy('quantity_available')->get();

        return $this->successResponse([
            'threshold' => $threshold,
            'count' => $lots->count(),
            'lots' => $lots->map(function ($lot) {
                $lot->shortfall = round(max(0, (float) $lot->low_stock_threshold - (float) $lot->quantity_available), 3);
                return $lot;
            }),
        ], 'Low stock lots retrieved successfully');
    }

    /**
     * Shelf-life ageing buckets based on lot expiry dates.
     */
    public function aging(Request $request): JsonResponse
    {
        $lots = ProductLot::with('product:id,name,sku')
            ->where('supplier_id', $request->user()->id)
            ->where('lot_status', 'active')
            ->whereNotNull('expiry_date')
            ->get();

        $buckets = ['expired' => [], 'critical' => [], 'aging' => [], 'fresh' => []];

        foreach ($lots as $lot) {
            $info = $lot->shelf_life;
            $buckets[$info['ageing_bucket']][] = [
                'lot_id' => $lot->id,
                'lot_code' => $lot->lot_code,
                'product' => $lot->product->name ?? null,
                'warehouse_location' => $lot->warehouse_location,
                'quantity_available' => (float) $lot->quantity_available,
                'unit' => $lot->unit,
                'expiry_date' => optional($lot->expiry_date)->toDateString(),
                'shelf_life' => $info,
            ];
        }

        return $this->successResponse([
            'summary' => [
                'expired' => count($buckets['expired']),
                'critical_30d' => count($buckets['critical']),
                'aging_90d' => count($buckets['aging']),
                'fresh' => count($buckets['fresh']),
                'no_expiry_tracked' => ProductLot::where('supplier_id', $request->user()->id)->whereNull('expiry_date')->count(),
            ],
            'buckets' => $buckets,
        ], 'Inventory ageing report retrieved successfully');
    }

    /**
     * Record damage / loss / return / manual adjustment against a lot.
     */
    public function storeMovement($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $lot = ProductLot::where('supplier_id', $user->id)->find($id);

        if (!$lot) {
            return $this->errorResponse('Lot not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'movement_type' => 'required|in:' . implode(',', self::MOVEMENT_TYPES),
            'quantity' => 'required|numeric|min:0.001',
            'direction' => 'required_if:movement_type,adjusted|nullable|in:add,subtract',
            'reason' => 'required|string|max:255',
            'reference_type' => 'nullable|string|max:50',
            'reference_id' => 'nullable|integer',
            'unit' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        return DB::transaction(function () use ($lot, $request, $user) {
            $lot = ProductLot::where('id', $lot->id)->lockForUpdate()->first();
            $qty = round((float) $request->quantity, 3);
            $type = $request->movement_type;
            $available = (float) $lot->quantity_available;

            // Damage, loss and subtractive adjustments consume stock.
            $decreases = in_array($type, ['damaged', 'lost'], true)
                || ($type === 'adjusted' && $request->direction === 'subtract');

            if ($decreases) {
                if ($available < $qty) {
                    return $this->errorResponse("Cannot reduce {$qty} {$lot->unit}: only {$available} available", 400);
                }
                $lot->quantity_available = bcsub((string) $available, (string) $qty, 3);
            } else {
                $lot->quantity_available = bcadd((string) $available, (string) $qty, 3);
                if ($type === 'adjusted') {
                    $lot->quantity_received = bcadd((string) $lot->quantity_received, (string) $qty, 3);
                }
            }

            $lot->save();

            $movement = InventoryMovement::create([
                'lot_id' => $lot->id,
                'movement_type' => $type,
                'quantity' => $qty,
                'unit' => $request->unit ?: $lot->unit,
                'reference_type' => $request->reference_type ?: 'manual_adjustment',
                'reference_id' => $request->reference_id ?: $lot->id,
                'reason' => $request->reason,
                'actor_id' => $user->id,
            ]);

            return $this->successResponse([
                'movement' => $movement,
                'lot' => $lot,
                'below_threshold' => (float) $lot->low_stock_threshold > 0
                    && (float) $lot->quantity_available <= (float) $lot->low_stock_threshold,
            ], 'Inventory movement recorded successfully', 201);
        });
    }

    public function updateThreshold($id, Request $request): JsonResponse
    {
        $lot = ProductLot::where('supplier_id', $request->user()->id)->find($id);
        if (!$lot) {
            return $this->errorResponse('Lot not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'low_stock_threshold' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $lot->low_stock_threshold = $request->low_stock_threshold;
        $lot->save();

        return $this->successResponse($lot, 'Low stock threshold updated successfully');
    }

    public function transferIndex(Request $request): JsonResponse
    {
        $query = B2bWarehouseTransfer::with(['items.lot:id,lot_code,unit'])
            ->where('supplier_id', $request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_warehouse')) {
            $query->where('from_warehouse', $request->from_warehouse);
        }

        if ($request->filled('to_warehouse')) {
            $query->where('to_warehouse', $request->to_warehouse);
        }

        $transfers = $query->orderByDesc('id')->paginate($request->get('per_page', 15));

        return $this->successResponse($transfers, 'Warehouse transfers retrieved successfully');
    }

    public function transferStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_warehouse' => 'required|string|max:191',
            'to_warehouse' => 'required|string|max:191|different:from_warehouse',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.lot_id' => 'required|integer|distinct|exists:product_lots,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $user = $request->user();

        $lots = ProductLot::whereIn('id', array_column($request->items, 'lot_id'))
            ->where('supplier_id', $user->id)
            ->get()
            ->keyBy('id');

        foreach ($request->items as $item) {
            $lot = $lots->get($item['lot_id']);
            if (!$lot) {
                return $this->errorResponse("Lot {$item['lot_id']} not found or access denied", 404);
            }
            if ((float) $lot->quantity_available < (float) $item['quantity']) {
                return $this->errorResponse(
                    "Lot {$lot->lot_code} has only {$lot->quantity_available} {$lot->unit} available",
                    400
                );
            }
        }

        $transfer = DB::transaction(function () use ($request, $user, $lots) {
            $transfer = B2bWarehouseTransfer::create([
                'transfer_number' => 'TRF-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                'supplier_id' => $user->id,
                'from_warehouse' => $request->from_warehouse,
                'to_warehouse' => $request->to_warehouse,
                'status' => 'draft',
                'notes' => $request->notes,
                'requested_by' => $user->id,
            ]);

            foreach ($request->items as $item) {
                $transfer->items()->create([
                    'lot_id' => $item['lot_id'],
                    'quantity' => $item['quantity'],
                    'unit' => $lots->get($item['lot_id'])->unit,
                ]);
            }

            return $transfer;
        });

        return $this->successResponse($transfer->load('items.lot:id,lot_code,unit'), 'Warehouse transfer created successfully', 201);
    }

    public function transferShow($id, Request $request): JsonResponse
    {
        $transfer = B2bWarehouseTransfer::with(['items.lot'])
            ->where('supplier_id', $request->user()->id)
            ->find($id);

        if (!$transfer) {
            return $this->errorResponse('Transfer not found or access denied', 404);
        }

        return $this->successResponse($transfer, 'Warehouse transfer retrieved successfully');
    }

    /**
     * Complete a transfer: the lots are moved to the destination warehouse.
     */
    public function transferComplete($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $transfer = B2bWarehouseTransfer::where('supplier_id', $user->id)->find($id);

        if (!$transfer) {
            return $this->errorResponse('Transfer not found or access denied', 404);
        }

        if ($transfer->status !== 'draft') {
            return $this->errorResponse("Transfer cannot be completed from '{$transfer->status}' state", 409);
        }

        $result = DB::transaction(function () use ($transfer, $user) {
            $transfer->load('items.lot');

            foreach ($transfer->items as $item) {
                $lot = ProductLot::where('id', $item->lot_id)->lockForUpdate()->first();
                if (!$lot) {
                    continue;
                }

                if ((float) $lot->quantity_available < (float) $item->quantity) {
                    throw new \RuntimeException(
                        "Lot {$lot->lot_code} no longer has {$item->quantity} {$lot->unit} available"
                    );
                }

                $lot->warehouse_location = $transfer->to_warehouse;
                $lot->save();

                InventoryMovement::create([
                    'lot_id' => $lot->id,
                    'movement_type' => 'transferred',
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'reference_type' => 'warehouse_transfer',
                    'reference_id' => $transfer->id,
                    'reason' => "Transferred {$transfer->from_warehouse} -> {$transfer->to_warehouse}",
                    'actor_id' => $user->id,
                ]);
            }

            $transfer->status = 'completed';
            $transfer->completed_by = $user->id;
            $transfer->completed_at = now();
            $transfer->save();

            return $transfer;
        });

        return $this->successResponse($result->load('items.lot'), 'Warehouse transfer completed successfully');
    }

    public function transferCancel($id, Request $request): JsonResponse
    {
        $transfer = B2bWarehouseTransfer::where('supplier_id', $request->user()->id)->find($id);
        if (!$transfer) {
            return $this->errorResponse('Transfer not found or access denied', 404);
        }

        if ($transfer->status !== 'draft') {
            return $this->errorResponse("Only draft transfers can be cancelled", 409);
        }

        $transfer->status = 'cancelled';
        $transfer->save();

        return $this->successResponse($transfer, 'Warehouse transfer cancelled');
    }

    /**
     * Movement ledger across every lot owned by the supplier.
     */
    public function movements(Request $request): JsonResponse
    {
        $types = array_merge(self::MOVEMENT_TYPES, ['transferred', 'received', 'dispatched']);

        $validator = Validator::make($request->all(), [
            'movement_type' => 'nullable|in:' . implode(',', $types),
            'lot_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $query = InventoryMovement::with(['lot:id,lot_code,product_id,warehouse_location,unit', 'actor:id,name'])
            ->whereHas('lot', function ($q) use ($request) {
                $q->where('supplier_id', $request->user()->id);
            });

        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        if ($request->filled('lot_id')) {
            $query->where('lot_id', $request->lot_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $summary = (clone $query)->reorder()
            ->selectRaw('movement_type, COUNT(*) as movements, SUM(quantity) as quantity')
            ->groupBy('movement_type')
            ->get();

        $movements = $query->orderByDesc('id')->paginate($request->get('per_page', 20));

        return $this->successResponse([
            'summary' => $summary,
            'movements' => $movements,
        ], 'Inventory movements retrieved successfully');
    }

    /**
     * Open / acknowledged low-stock and expiry alerts.
     */
    public function alertsIndex(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:open,acknowledged,resolved',
            'alert_type' => 'nullable|in:low_stock,expiring_soon,expired',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $query = B2bInventoryAlert::with('lot:id,lot_code,product_id,warehouse_location,unit,quantity_available')
            ->where('supplier_id', $request->user()->id);

        $query->where('status', $request->get('status', 'open'));

        if ($request->filled('alert_type')) {
            $query->where('alert_type', $request->alert_type);
        }

        $alerts = $query->orderByDesc('id')->get();

        return $this->successResponse([
            'count' => $alerts->count(),
            'alerts' => $alerts,
        ], 'Inventory alerts retrieved successfully');
    }

    /**
     * Scan lots and raise low-stock / expiry alerts (idempotent per open alert).
     */
    public function alertsScan(Request $request): JsonResponse
    {
        $user = $request->user();
        $lots = ProductLot::where('supplier_id', $user->id)->where('lot_status', 'active')->get();

        $created = [];
        $existing = 0;
        $today = now()->startOfDay();

        foreach ($lots as $lot) {
            $available = (float) $lot->quantity_available;
            $threshold = (float) $lot->low_stock_threshold;

            if ($threshold > 0 && $available <= $threshold) {
                $alert = B2bInventoryAlert::firstOrCreate(
                    ['lot_id' => $lot->id, 'alert_type' => 'low_stock', 'status' => 'open'],
                    [
                        'supplier_id' => $user->id,
                        'severity' => $available <= 0 ? 'critical' : 'warning',
                        'quantity_available' => $available,
                        'threshold' => $threshold,
                        'message' => "Lot {$lot->lot_code} has {$available} {$lot->unit} left (threshold {$threshold}).",
                    ]
                );
                $alert->wasRecentlyCreated ? $created[] = $alert->id : $existing++;
            }

            if ($lot->expiry_date) {
                $days = (int) $today->diffInDays($lot->expiry_date, false);

                if ($days <= 30) {
                    $type = $days < 0 ? 'expired' : 'expiring_soon';
                    $alert = B2bInventoryAlert::firstOrCreate(
                        ['lot_id' => $lot->id, 'alert_type' => $type, 'status' => 'open'],
                        [
                            'supplier_id' => $user->id,
                            'severity' => $days < 0 ? 'critical' : 'warning',
                            'quantity_available' => $available,
                            'threshold' => $threshold ?: null,
                            'expiry_date' => $lot->expiry_date,
                            'message' => $days < 0
                                ? "Lot {$lot->lot_code} expired on " . $lot->expiry_date->toDateString() . '.'
                                : "Lot {$lot->lot_code} expires in {$days} day(s).",
                        ]
                    );
                    $alert->wasRecentlyCreated ? $created[] = $alert->id : $existing++;
                }
            }
        }

        return $this->successResponse([
            'created' => count($created),
            'already_open' => $existing,
            'alert_ids' => $created,
            'open_total' => B2bInventoryAlert::where('supplier_id', $user->id)->where('status', 'open')->count(),
        ], 'Inventory alert scan completed');
    }

    public function acknowledgeAlert($id, Request $request): JsonResponse
    {
        $alert = B2bInventoryAlert::where('supplier_id', $request->user()->id)->find($id);

        if (!$alert) {
            return $this->errorResponse('Inventory alert not found or access denied', 404);
        }

        if ($alert->status !== 'open') {
            return $this->errorResponse("Alert is already {$alert->status}", 409);
        }

        $alert->status = 'acknowledged';
        $alert->acknowledged_at = now();
        $alert->acknowledged_by = $request->user()->id;
        $alert->save();

        return $this->successResponse($alert->fresh()->load('lot:id,lot_code,unit'), 'Inventory alert acknowledged');
    }

    public function unitConversions(Request $request): JsonResponse
    {
        $query = B2bUnitConversion::where('is_active', true);

        if ($request->filled('from_unit')) {
            $query->where('from_unit', strtolower($request->from_unit));
        }

        if ($request->filled('to_unit')) {
            $query->where('to_unit', strtolower($request->to_unit));
        }

        return $this->successResponse($query->orderBy('category')->orderBy('from_unit')->get(), 'Unit conversions retrieved successfully');
    }

    /**
     * Convert a quantity between units using the configured factor table.
     */
    public function convert(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_unit' => 'required|string|max:20',
            'to_unit' => 'required|string|max:20',
            'quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $from = strtolower($request->from_unit);
        $to = strtolower($request->to_unit);
        $quantity = (float) $request->quantity;

        if ($from === $to) {
            return $this->successResponse([
                'from_unit' => $from,
                'to_unit' => $to,
                'quantity' => $quantity,
                'converted' => $quantity,
                'factor' => 1,
            ], 'Unit conversion completed');
        }

        $conversion = B2bUnitConversion::where('is_active', true)
            ->where('from_unit', $from)
            ->where('to_unit', $to)
            ->first();

        $factor = $conversion ? (float) $conversion->factor : null;

        if ($factor === null) {
            $reverse = B2bUnitConversion::where('is_active', true)
                ->where('from_unit', $to)
                ->where('to_unit', $from)
                ->first();

            if ($reverse && (float) $reverse->factor > 0) {
                $factor = 1 / (float) $reverse->factor;
            }
        }

        if ($factor === null) {
            return $this->errorResponse("No conversion configured between '{$from}' and '{$to}'", 404);
        }

        return $this->successResponse([
            'from_unit' => $from,
            'to_unit' => $to,
            'quantity' => $quantity,
            'factor' => $factor,
            'converted' => round($quantity * $factor, 4),
        ], 'Unit conversion completed');
    }
}
