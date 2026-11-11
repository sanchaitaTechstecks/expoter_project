<?php

namespace Tests\Feature;

use App\Models\B2B\B2bInventoryAlert;
use App\Models\B2B\B2bWarehouseTransfer;
use App\Models\B2B\InventoryMovement;
use App\Models\B2B\ProductLot;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** P2-4: inventory movements, low-stock/expiry alerts, shelf-life ageing, transfers, unit conversion. */
class B2bP4InventoryOperationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['url']->forceRootUrl('http://localhost');
    }

    private function user(string $type): User
    {
        return User::create([
            'name' => ucfirst($type) . ' ' . uniqid(),
            'email' => $type . '_' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ]);
    }

    private function product(User $seller): Product
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Inventory Test ' . uniqid(), 'slug' => 'inv-' . uniqid(), 'parent_id' => 0,
            'level' => 0, 'digital' => 0, 'featured' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Product::create([
            'name' => 'Inventory Product ' . uniqid(), 'slug' => 'inv-' . uniqid(),
            'sku' => 'SKU-' . strtoupper(uniqid()), 'added_by' => 'seller', 'user_id' => $seller->id,
            'category_id' => $categoryId, 'origin_country' => 'India', 'unit' => 'kg',
            'unit_price' => 100, 'min_qty' => 1, 'publication_status' => 'active',
            'published' => 1, 'approved' => 1,
        ]);
    }

    private function lot(User $seller, array $overrides = []): ProductLot
    {
        return ProductLot::create(array_merge([
            'lot_code' => 'LOT-' . strtoupper(uniqid()),
            'product_id' => $this->product($seller)->id,
            'supplier_id' => $seller->id,
            'warehouse_location' => 'Warehouse A',
            'quality_grade' => 'A',
            'quantity_received' => 100,
            'quantity_reserved' => 0,
            'quantity_dispatched' => 0,
            'quantity_available' => 100,
            'low_stock_threshold' => 150,
            'unit' => 'kg',
            'unit_price' => 100,
            'currency' => 'USD',
            'lot_status' => 'active',
        ], $overrides));
    }

    public function test_movement_ledger_damage_return_and_guards(): void
    {
        $seller = $this->user('seller');
        $lot = $this->lot($seller);
        Sanctum::actingAs($seller);

        $damage = $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'damaged',
            'quantity' => 30,
            'reason' => 'Water damage in bay 4',
        ]);
        $damage->assertStatus(201);
        $damage->assertJsonPath('message', 'Inventory movement recorded successfully');
        $damage->assertJsonPath('data.movement.movement_type', 'damaged');
        $damage->assertJsonPath('data.lot.quantity_available', '70.000');
        $damage->assertJsonPath('data.below_threshold', true);

        // A return adds stock back.
        $returned = $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'returned',
            'quantity' => 10,
            'reason' => 'Buyer returned 10 kg',
        ]);
        $returned->assertStatus(201);
        $returned->assertJsonPath('data.lot.quantity_available', '80.000');

        // Loss is recorded as well.
        $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'lost',
            'quantity' => 5,
            'reason' => 'Missing during stock count',
        ])->assertStatus(201);

        // Manual adjustment downwards needs an explicit direction.
        $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'adjusted',
            'quantity' => 5,
        ])->assertStatus(422);

        $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'adjusted',
            'direction' => 'subtract',
            'quantity' => 5,
            'reason' => 'Cycle count correction',
        ])->assertStatus(201)->assertJsonPath('data.lot.quantity_available', '70.000');

        // Over-consumption and unknown types are blocked.
        $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'lost',
            'quantity' => 5000,
            'reason' => 'Impossible loss',
        ])->assertStatus(400);

        $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'shrinkage',
            'quantity' => 1,
            'reason' => 'Not a supported type',
        ])->assertStatus(422);

        // Ledger + summary.
        $ledger = $this->getJson('/api/v1/exporter/inventory/movements?per_page=20');
        $ledger->assertStatus(200);
        $ledger->assertJsonPath('message', 'Inventory movements retrieved successfully');
        $this->assertSame(4, $ledger->json('data.movements.total'));
        $types = collect($ledger->json('data.summary'))->pluck('movement_type')->all();
        $this->assertEqualsCanonicalizing(['damaged', 'returned', 'lost', 'adjusted'], $types);

        $filtered = $this->getJson('/api/v1/exporter/inventory/movements?movement_type=damaged');
        $filtered->assertStatus(200);
        $filtered->assertJsonPath('data.movements.total', 1);

        // History endpoint per lot still works.
        $this->getJson("/api/v1/exporter/lots/{$lot->id}/movements")
            ->assertStatus(200);

        // Another supplier cannot touch the lot.
        $other = $this->user('seller');
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'damaged', 'quantity' => 1, 'reason' => 'Not mine',
        ])->assertStatus(404);
        $this->getJson('/api/v1/exporter/inventory/movements')->assertStatus(200)
            ->assertJsonPath('data.movements.total', 0);
    }

    public function test_low_stock_alert_scan_dedupe_and_acknowledge(): void
    {
        $seller = $this->user('seller');
        $lot = $this->lot($seller); // 100 available, threshold 150
        Sanctum::actingAs($seller);

        $scan = $this->postJson('/api/v1/exporter/inventory/alerts/scan');
        $scan->assertStatus(200);
        $scan->assertJsonPath('message', 'Inventory alert scan completed');
        $scan->assertJsonPath('data.created', 1);
        $scan->assertJsonPath('data.open_total', 1);

        // Re-scanning does not duplicate the open alert.
        $again = $this->postJson('/api/v1/exporter/inventory/alerts/scan');
        $again->assertStatus(200);
        $again->assertJsonPath('data.created', 0);
        $again->assertJsonPath('data.already_open', 1);

        $this->assertSame(1, B2bInventoryAlert::where('lot_id', $lot->id)->where('status', 'open')->count());

        $list = $this->getJson('/api/v1/exporter/inventory/alerts');
        $list->assertStatus(200);
        $list->assertJsonPath('data.count', 1);
        $list->assertJsonPath('data.alerts.0.alert_type', 'low_stock');
        $list->assertJsonPath('data.alerts.0.severity', 'warning');

        $alertId = $list->json('data.alerts.0.id');
        $this->postJson("/api/v1/exporter/inventory/alerts/{$alertId}/acknowledge")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'acknowledged');

        // Acknowledging twice is a conflict.
        $this->postJson("/api/v1/exporter/inventory/alerts/{$alertId}/acknowledge")->assertStatus(409);

        // A fresh scan opens a new alert after acknowledgement.
        $this->postJson('/api/v1/exporter/inventory/alerts/scan')
            ->assertStatus(200)
            ->assertJsonPath('data.created', 1);

        // Alerts are private to the owning supplier.
        $other = $this->user('seller');
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/exporter/inventory/alerts')->assertStatus(200)
            ->assertJsonPath('data.count', 0);
        $this->postJson("/api/v1/exporter/inventory/alerts/{$alertId}/acknowledge")->assertStatus(404);
    }

    public function test_shelf_life_aging_and_expiry_alerts(): void
    {
        $seller = $this->user('seller');

        $expired = $this->lot($seller, [
            'lot_code' => 'LOT-EXPIRED',
            'manufacture_or_harvest_date' => now()->subDays(200)->toDateString(),
            'expiry_date' => now()->subDays(10)->toDateString(),
        ]);
        $critical = $this->lot($seller, [
            'lot_code' => 'LOT-CRITICAL',
            'manufacture_or_harvest_date' => now()->subDays(80)->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);
        $fresh = $this->lot($seller, [
            'lot_code' => 'LOT-FRESH',
            'manufacture_or_harvest_date' => now()->subDays(10)->toDateString(),
            'expiry_date' => now()->addDays(200)->toDateString(),
        ]);

        Sanctum::actingAs($seller);

        $aging = $this->getJson('/api/v1/exporter/inventory/aging');
        $aging->assertStatus(200);
        $aging->assertJsonPath('data.summary.expired', 1);
        $aging->assertJsonPath('data.summary.critical_30d', 1);
        $aging->assertJsonPath('data.summary.fresh', 1);

        $criticalBucket = collect($aging->json('data.buckets.critical'))->firstWhere('lot_id', $critical->id);
        $this->assertNotNull($criticalBucket);
        $this->assertSame(10, $criticalBucket['shelf_life']['days_remaining']);
        $this->assertGreaterThan(0, $criticalBucket['shelf_life']['pct_elapsed']);

        // Scan raises expiry alerts.
        $scan = $this->postJson('/api/v1/exporter/inventory/alerts/scan');
        $scan->assertStatus(200);

        $this->assertDatabaseHas('inventory_alerts', [
            'lot_id' => $expired->id, 'alert_type' => 'expired', 'severity' => 'critical', 'status' => 'open',
        ]);
        $this->assertDatabaseHas('inventory_alerts', [
            'lot_id' => $critical->id, 'alert_type' => 'expiring_soon', 'status' => 'open',
        ]);
        $this->assertDatabaseMissing('inventory_alerts', [
            'lot_id' => $fresh->id, 'alert_type' => 'expiring_soon',
        ]);
    }

    public function test_warehouse_transfer_lifecycle_and_threshold(): void
    {
        $seller = $this->user('seller');
        $lot = $this->lot($seller, ['quantity_available' => 1000, 'low_stock_threshold' => 100]);
        Sanctum::actingAs($seller);

        $this->postJson("/api/v1/exporter/lots/{$lot->id}/low-stock-threshold", ['low_stock_threshold' => 900])
            ->assertStatus(200)
            ->assertJsonPath('data.low_stock_threshold', '900.000');

        $this->getJson('/api/v1/exporter/inventory/low-stock')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 0);

        $this->postJson("/api/v1/exporter/lots/{$lot->id}/low-stock-threshold", ['low_stock_threshold' => 1200])
            ->assertStatus(200);
        $this->getJson('/api/v1/exporter/inventory/low-stock')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.lots.0.shortfall', 200);

        $create = $this->postJson('/api/v1/exporter/inventory/transfers', [
            'from_warehouse' => 'Warehouse A',
            'to_warehouse' => 'Warehouse B',
            'notes' => 'Rebalancing stock',
            'items' => [['lot_id' => $lot->id, 'quantity' => 400]],
        ]);
        $create->assertStatus(201);
        $create->assertJsonPath('message', 'Warehouse transfer created successfully');
        $create->assertJsonPath('data.status', 'draft');
        $transferId = $create->json('data.id');

        $this->postJson("/api/v1/exporter/inventory/transfers/{$transferId}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('product_lots', ['id' => $lot->id, 'warehouse_location' => 'Warehouse B']);
        $this->assertDatabaseHas('inventory_movements', [
            'lot_id' => $lot->id, 'movement_type' => 'transferred', 'reference_type' => 'warehouse_transfer',
        ]);

        // Completing again and cancelling a completed transfer are conflicts.
        $this->postJson("/api/v1/exporter/inventory/transfers/{$transferId}/complete")->assertStatus(409);
        $this->postJson("/api/v1/exporter/inventory/transfers/{$transferId}/cancel")->assertStatus(409);

        // A draft transfer can be cancelled.
        $draft = $this->postJson('/api/v1/exporter/inventory/transfers', [
            'from_warehouse' => 'Warehouse B',
            'to_warehouse' => 'Warehouse C',
            'items' => [['lot_id' => $lot->id, 'quantity' => 10]],
        ]);
        $draft->assertStatus(201);
        $this->postJson("/api/v1/exporter/inventory/transfers/{$draft->json('data.id')}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        // Over-quantity transfers are refused.
        $this->postJson('/api/v1/exporter/inventory/transfers', [
            'from_warehouse' => 'Warehouse B',
            'to_warehouse' => 'Warehouse D',
            'items' => [['lot_id' => $lot->id, 'quantity' => 999999]],
        ])->assertStatus(400);

        $this->getJson('/api/v1/exporter/inventory/summary')->assertStatus(200);
        $this->getJson('/api/v1/exporter/inventory/transfers?status=completed')->assertStatus(200);
    }

    public function test_unit_conversion_master_and_convert(): void
    {
        $seller = $this->user('seller');
        $admin = $this->user('admin');
        Sanctum::actingAs($seller);

        $master = $this->getJson('/api/v1/exporter/units/conversions');
        $master->assertStatus(200);
        $master->assertJsonPath('message', 'Unit conversions retrieved successfully');
        $this->assertGreaterThan(0, count($master->json('data')));

        $this->postJson('/api/v1/exporter/units/convert', [
            'from_unit' => 'kg', 'to_unit' => 'mt', 'quantity' => 1000,
        ])->assertStatus(200)
            ->assertJsonPath('data.converted', 1)
            ->assertJsonPath('data.factor', 0.001); // kg -> mt (reverse of the seeded mt -> kg 1000 factor)

        $this->postJson('/api/v1/exporter/units/convert', [
            'from_unit' => 'kg', 'to_unit' => 'g', 'quantity' => 2,
        ])->assertStatus(200)->assertJsonPath('data.converted', 2000);

        // Same unit is a no-op.
        $this->postJson('/api/v1/exporter/units/convert', [
            'from_unit' => 'kg', 'to_unit' => 'kg', 'quantity' => 5,
        ])->assertStatus(200)->assertJsonPath('data.factor', 1);

        // Unknown pair and bad payload.
        $this->postJson('/api/v1/exporter/units/convert', [
            'from_unit' => 'kg', 'to_unit' => 'parsec', 'quantity' => 1,
        ])->assertStatus(404);
        $this->postJson('/api/v1/exporter/units/convert', ['from_unit' => 'kg'])->assertStatus(422);

        // Admin keeps the master data.
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/unit-conversions')->assertStatus(200);
        $created = $this->postJson('/api/v1/admin/unit-conversions', [
            'from_unit' => 'sack', 'to_unit' => 'kg', 'factor' => 50, 'category' => 'weight',
        ]);
        $created->assertStatus(201);

        // Conversion now resolves using the new master row.
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/exporter/units/convert', [
            'from_unit' => 'sack', 'to_unit' => 'kg', 'quantity' => 2,
        ])->assertStatus(200)->assertJsonPath('data.converted', 100);

        // Suppliers cannot manage the master.
        $this->postJson('/api/v1/admin/unit-conversions', [
            'from_unit' => 'x', 'to_unit' => 'y', 'factor' => 2,
        ])->assertStatus(403);
    }
}
