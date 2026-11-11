<?php

namespace Tests\Feature;

use App\Models\B2B\B2bAgreement;
use App\Models\B2B\B2bContainer;
use App\Models\B2B\B2bCreditNote;
use App\Models\B2B\B2bInventoryAlert;
use App\Models\B2B\B2bInvoice;
use App\Models\B2B\B2bQuotation;
use App\Models\B2B\B2bWarehouseTransfer;
use App\Models\B2B\Rfq;
use App\Models\B2B\B2bDispute;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bPayment;
use App\Models\B2B\B2bShipment;
use App\Models\B2B\B2bShipmentVehicle;
use App\Models\B2B\B2bTradeDocument;
use App\Models\B2B\DocumentChecklistTemplate;
use App\Models\B2B\ProductLot;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Calls every registered /api/v1 B2B route (importer, exporter, admin, cha) with a
 * real fixture id and fails on any 5xx. 4xx answers are legitimate guards
 * (401 auth, 403 role, 404 ownership, 409 state, 422 validation).
 */
class B2bRouteCoverageTest extends TestCase
{
    use DatabaseTransactions;

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['url']->forceRootUrl('http://localhost');
        Storage::fake('public');
        $this->seedFixtures();
    }

    private function user(string $type): User
    {
        return User::create([
            'name' => ucfirst($type) . ' ' . uniqid(),
            'email' => $type . '_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ]);
    }

    private function seedFixtures(): void
    {
        $buyer = $this->user('customer');
        $seller = $this->user('seller');
        $this->fixtures['buyer'] = $buyer;
        $this->fixtures['seller'] = $seller;

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Coverage Commodity ' . uniqid(), 'slug' => 'coverage-' . uniqid(),
            'parent_id' => 0, 'level' => 0, 'digital' => 0, 'featured' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::create([
            'name' => 'Coverage Rice ' . uniqid(), 'slug' => 'coverage-rice-' . uniqid(),
            'sku' => 'SKU-' . strtoupper(uniqid()), 'added_by' => 'seller', 'user_id' => $seller->id,
            'category_id' => $categoryId, 'origin_country' => 'India', 'unit' => 'kg',
            'unit_price' => 1200, 'min_qty' => 10, 'publication_status' => 'active',
            'published' => 1, 'approved' => 1,
        ]);
        $this->fixtures['product'] = $product;

        $lot = ProductLot::create([
            'lot_code' => 'LOT-' . strtoupper(uniqid()), 'product_id' => $product->id,
            'supplier_id' => $seller->id, 'warehouse_location' => 'Warehouse A',
            'quality_grade' => 'A', 'quantity_received' => 1000, 'quantity_reserved' => 0,
            'quantity_dispatched' => 0, 'quantity_available' => 1000, 'low_stock_threshold' => 100,
            'unit' => 'kg', 'unit_price' => 1200, 'currency' => 'USD', 'lot_status' => 'active',
            'expiry_date' => now()->addDays(10)->toDateString(),
            'manufacture_or_harvest_date' => now()->subDays(80)->toDateString(),
        ]);
        $this->fixtures['lot'] = $lot;

        $order = B2bOrder::create([
            'order_number' => 'ORD-' . strtoupper(uniqid()), 'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id, 'order_date' => now()->toDateString(),
            'order_type' => 'international', 'status' => 'confirmed', 'currency' => 'USD',
            'incoterm' => 'CIF', 'total_quantity' => 100, 'subtotal' => 120000,
            'total_amount' => 120000, 'amount_paid' => 0, 'balance_due' => 120000,
            'payment_status' => 'unpaid', 'destination_country' => 'UAE',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'lot_id' => $lot->id,
            'product_name_snapshot' => 'Coverage Rice', 'quantity' => 100, 'unit' => 'kg',
            'unit_price' => 1200, 'line_total' => 120000, 'allocated_quantity' => 0,
            'dispatched_quantity' => 0, 'delivered_quantity' => 0, 'outstanding_quantity' => 100,
        ]);
        $this->fixtures['order'] = $order;

        $shipment = B2bShipment::create([
            'shipment_number' => 'SHP-' . strtoupper(uniqid()), 'order_id' => $order->id,
            'supplier_id' => $seller->id, 'buyer_id' => $buyer->id, 'mode' => 'sea',
            'carrier_name' => 'Maersk', 'origin_port' => 'INNSA1', 'destination_port' => 'AEJEA',
            'departure_date' => now()->toDateString(),
            'estimated_arrival' => now()->addDays(20)->toDateString(), 'status' => 'in_transit',
        ]);
        $this->fixtures['shipment'] = $shipment;

        $this->fixtures['vehicle'] = B2bShipmentVehicle::create([
            'shipment_id' => $shipment->id, 'vehicle_type' => 'truck',
            'vehicle_number' => 'TRK-' . rand(1000, 9999), 'driver_name' => 'Coverage Driver',
            'status' => 'assigned', 'assigned_at' => now(), 'actor_id' => $seller->id,
        ]);

        $this->fixtures['container'] = B2bContainer::create([
            'shipment_id' => $shipment->id, 'container_number' => 'CNT' . rand(100000, 999999),
            'container_type' => '40FT', 'loaded_quantity' => 100, 'unit' => 'kg', 'status' => 'assigned',
        ]);

        $this->fixtures['payment'] = B2bPayment::create([
            'payment_number' => 'PAY-' . strtoupper(uniqid()), 'order_id' => $order->id,
            'buyer_id' => $buyer->id, 'supplier_id' => $seller->id, 'amount' => 50000,
            'currency' => 'USD', 'base_currency' => 'BDT', 'payment_type' => 'advance',
            'payment_method' => 'bank_transfer', 'transaction_reference' => 'TXN-' . uniqid(),
            'due_date' => now()->addDays(7)->toDateString(), 'status' => 'paid',
        ]);

        $this->fixtures['document'] = B2bTradeDocument::create([
            'document_type' => 'commercial_invoice', 'order_id' => $order->id,
            'shipment_id' => $shipment->id, 'uploader_id' => $seller->id,
            'document_number' => 'INV-' . rand(1000, 9999), 'title' => 'Commercial Invoice',
            'file_path' => 'documents/coverage.pdf', 'file_name' => 'coverage.pdf',
            'file_size' => 10, 'mime_type' => 'application/pdf',
            'issue_date' => now()->toDateString(), 'status' => 'issued',
        ]);
        Storage::disk('public')->put('documents/coverage.pdf', 'pdf-bytes');

        $this->fixtures['dispute'] = B2bDispute::create([
            'dispute_number' => 'DSP-' . strtoupper(uniqid()), 'order_id' => $order->id,
            'raised_by_id' => $buyer->id, 'against_party_id' => $seller->id,
            'dispute_type' => 'short_quantity', 'status' => 'opened',
            'title' => 'Coverage dispute', 'description' => 'Coverage dispute description',
            'claim_amount' => 1000, 'currency' => 'USD',
        ]);

        $this->fixtures['credit_note'] = B2bCreditNote::create([
            'credit_note_number' => 'CN-' . strtoupper(uniqid()), 'order_id' => $order->id,
            'payment_id' => $this->fixtures['payment']->id, 'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id, 'type' => 'refund', 'amount' => 1000,
            'currency' => 'USD', 'reason' => 'Coverage credit note', 'status' => 'issued',
            'issued_by' => $seller->id, 'issued_at' => now(),
        ]);

        $rfq = Rfq::create([
            'rfq_number' => 'RFQ-' . strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id,
            'product_id' => $product->id,
            'requested_quantity' => 100,
            'unit' => 'kg',
            'currency' => 'USD',
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'status' => 'submitted',
        ]);
        $this->fixtures['rfq'] = $rfq;

        $this->fixtures['quotation'] = B2bQuotation::create([
            'quotation_number' => 'QTN-' . strtoupper(uniqid()),
            'rfq_id' => $rfq->id,
            'supplier_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'version' => 1,
            'currency' => 'USD',
            'subtotal' => 120000,
            'total_amount' => 120000,
            'validity_date' => now()->addDays(15)->toDateString(),
            'status' => 'sent',
        ]);

        $this->fixtures['agreement'] = B2bAgreement::create([
            'agreement_number' => 'AGR-' . strtoupper(uniqid()),
            'order_id' => $order->id,
            'quotation_id' => $this->fixtures['quotation']->id,
            'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id,
            'version' => 1,
            'total_value' => 120000,
            'currency' => 'USD',
            'status' => 'pending_signature',
        ]);

        $this->fixtures['invoice'] = B2bInvoice::create([
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'invoice_type' => 'commercial',
            'order_id' => $order->id,
            'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'USD',
            'subtotal' => 120000,
            'total_amount' => 120000,
            'status' => 'issued',
        ]);

        $transfer = B2bWarehouseTransfer::create([
            'transfer_number' => 'TRF-' . strtoupper(uniqid()),
            'supplier_id' => $seller->id,
            'from_warehouse' => 'Warehouse A',
            'to_warehouse' => 'Warehouse B',
            'status' => 'draft',
            'requested_by' => $seller->id,
        ]);
        $transfer->items()->create(['lot_id' => $lot->id, 'quantity' => 10, 'unit' => 'kg']);
        $this->fixtures['transfer'] = $transfer;

        $this->fixtures['alert'] = B2bInventoryAlert::create([
            'lot_id' => $lot->id,
            'supplier_id' => $seller->id,
            'alert_type' => 'low_stock',
            'severity' => 'warning',
            'quantity_available' => 1000,
            'threshold' => 1200,
            'status' => 'open',
        ]);

        $this->fixtures['gallery_image'] = DB::table('product_gallery_images')->insertGetId([
            'product_id' => $product->id,
            'file_path' => 'gallery/coverage.jpg',
            'file_name' => 'coverage.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 10,
            'sort_order' => 1,
            'is_primary' => 1,
            'uploaded_by' => $seller->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $template = DocumentChecklistTemplate::create([
            'name' => 'Coverage template ' . uniqid(), 'trade_type' => 'international',
            'destination_country' => 'UAE', 'is_active' => true,
        ]);
        $template->items()->create(['document_type' => 'commercial_invoice', 'is_mandatory' => true]);
        $this->fixtures['template'] = $template;

        $this->fixtures['unit_conversion'] = DB::table('b2b_unit_conversions')->insertGetId([
            'from_unit' => 'cov' . rand(100, 999), 'to_unit' => 'kg', 'factor' => 2,
            'category' => 'weight', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->fixtures['report'] = 'sales_summary';
    }

    /** Replace route placeholders with ids of the fixture that route refers to. */
    private function resolveUri(string $uri): string
    {
        $segmentMap = [
            'orders' => 'order', 'shipments' => 'shipment', 'payments' => 'payment',
            'documents' => 'document', 'disputes' => 'dispute', 'credit-notes' => 'credit_note',
            'lots' => 'lot', 'products' => 'product', 'transfers' => 'transfer',
            'vehicles' => 'vehicle', 'containers' => 'container',
            'document-checklist-templates' => 'template', 'unit-conversions' => 'unit_conversion',
            'users' => 'user', 'categories' => 'category', 'suppliers' => 'supplier',
            'rfqs' => 'rfq', 'quotations' => 'quotation', 'agreements' => 'agreement',
            'invoices' => 'invoice', 'alerts' => 'alert', 'gallery' => 'gallery_image',
        ];

        return preg_replace_callback('/\{([^}]+)\}/', function ($match) use ($uri, $segmentMap) {
            $param = $match[1];

            if ($param === 'report') {
                return $this->fixtures['report'];
            }

            $named = [
                'orderId' => 'order', 'productId' => 'product', 'lotId' => 'lot',
                'vehicleId' => 'vehicle', 'containerId' => 'container',
                'imageId' => 'gallery_image',
            ];

            if (isset($named[$param]) && isset($this->fixtures[$named[$param]])) {
                $fixture = $this->fixtures[$named[$param]];

                return (string) (is_object($fixture) ? $fixture->id : $fixture);
            }

            // Generic {id}: resolve from the collection segment just before the placeholder.
            $segments = explode('/', $uri);
            $position = (int) array_search('{' . $param . '}', $segments, true);
            $collection = $segments[$position - 1] ?? null;

            if ($collection && isset($segmentMap[$collection])) {
                $key = $segmentMap[$collection];
                if (isset($this->fixtures[$key])) {
                    $fixture = $this->fixtures[$key];

                    return (string) (is_object($fixture) ? $fixture->id : $fixture);
                }
            }

            return '1';
        }, $uri);
    }

    public function test_every_registered_b2b_route_answers_without_a_server_error(): void
    {
        $admin = $this->user('admin');
        $cha = $this->user('cha');

        $prefixes = [
            'api/v1/admin' => $admin,
            'api/v1/cha' => $cha,
            'api/v1/importer' => $this->fixtures['buyer'],
            'api/v1/exporter' => $this->fixtures['seller'],
        ];

        $results = [];
        $failures = [];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) use ($prefixes) {
                foreach (array_keys($prefixes) as $prefix) {
                    if (str_starts_with($route->uri(), $prefix)) {
                        return true;
                    }
                }

                return false;
            })
            // Destructive routes run last so they cannot invalidate fixtures used by later calls.
            ->sortBy(fn ($route) => in_array('DELETE', $route->methods, true) ? 1 : 0)
            ->values();

        foreach ($routes as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            if (empty($methods)) {
                continue;
            }

            $method = $methods[0];
            $uri = '/' . $this->resolveUri($route->uri());

            $portal = 'importer';
            foreach (array_keys($prefixes) as $prefix) {
                if (str_starts_with($route->uri(), $prefix)) {
                    $portal = str_replace('api/v1/', '', $prefix);
                }
            }

            Sanctum::actingAs($prefixes['api/v1/' . $portal]);

            try {
                $response = $this->json($method, $uri, [], ['Accept' => 'application/json']);
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                $results[] = ['method' => $method, 'uri' => $uri, 'portal' => $portal, 'status' => 500, 'error' => $e->getMessage()];
                $failures[] = "{$method} {$uri} threw " . get_class($e) . ': ' . $e->getMessage();
                continue;
            }

            $results[] = ['method' => $method, 'uri' => $uri, 'portal' => $portal, 'status' => $status];

            if ($status >= 500) {
                $body = mb_substr((string) $response->getContent(), 0, 300);
                $failures[] = "{$method} {$uri} -> {$status} {$body}";
            }
        }

        file_put_contents(
            storage_path('app/b2b_api_check.json'),
            json_encode(['generated_at' => now()->toIso8601String(), 'routes' => $results], JSON_PRETTY_PRINT)
        );

        $this->assertGreaterThan(200, count($results), 'expected the full B2B route surface to be exercised');
        $this->assertSame([], $failures, "Endpoints returned server errors:\n" . implode("\n", $failures));
    }
}
