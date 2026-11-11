<?php

namespace Tests\Feature;

use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bShipment;
use App\Models\B2B\ProductLot;
use App\Models\B2B\Rfq;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** P2-1 .. P2-9, P2-10: RFQ broadcast, product management, inventory, shipment ops, documents, payments, account security, dashboards and reports. */
class B2bP2OperationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['url']->forceRootUrl('http://localhost');
        Storage::fake('public');
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

    private function category(): int
    {
        return DB::table('categories')->insertGetId([
            'name' => 'Test Commodity ' . uniqid(),
            'slug' => 'test-commodity-' . uniqid(),
            'parent_id' => 0,
            'level' => 0,
            'digital' => 0,
            'featured' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(User $seller, ?int $categoryId = null): Product
    {
        return Product::create([
            'name' => 'Basmati Rice ' . uniqid(),
            'slug' => 'basmati-rice-' . uniqid(),
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'added_by' => 'seller',
            'user_id' => $seller->id,
            'category_id' => $categoryId ?: $this->category(),
            'origin_country' => 'India',
            'unit' => 'kg',
            'unit_price' => 1200,
            'min_qty' => 100,
            'publication_status' => 'active',
            'published' => 1,
            'approved' => 1,
        ]);
    }

    private function lot(User $seller, Product $product, array $overrides = []): ProductLot
    {
        return ProductLot::create(array_merge([
            'lot_code' => 'LOT-' . strtoupper(uniqid()),
            'product_id' => $product->id,
            'supplier_id' => $seller->id,
            'warehouse_location' => 'Warehouse A',
            'quality_grade' => 'A',
            'quantity_received' => 1000,
            'quantity_reserved' => 0,
            'quantity_dispatched' => 0,
            'quantity_available' => 1000,
            'low_stock_threshold' => 150,
            'unit' => 'kg',
            'unit_price' => 1200,
            'currency' => 'USD',
            'lot_status' => 'active',
        ], $overrides));
    }

    private function order(User $buyer, User $seller, array $overrides = []): B2bOrder
    {
        return B2bOrder::create(array_merge([
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id,
            'order_date' => now()->toDateString(),
            'order_type' => 'international',
            'status' => 'confirmed',
            'currency' => 'USD',
            'total_quantity' => 100,
            'subtotal' => 120000,
            'total_amount' => 120000,
            'amount_paid' => 0,
            'balance_due' => 120000,
            'payment_status' => 'unpaid',
            'destination_country' => 'UAE',
        ], $overrides));
    }

    private function shipment(User $seller, B2bOrder $order): B2bShipment
    {
        return B2bShipment::create([
            'shipment_number' => 'SHP-' . strtoupper(uniqid()),
            'order_id' => $order->id,
            'supplier_id' => $seller->id,
            'buyer_id' => $order->buyer_id,
            'mode' => 'sea',
            'origin_port' => 'Nhava Sheva',
            'destination_port' => 'Jebel Ali',
            'estimated_arrival' => now()->addDays(20)->toDateString(),
            'status' => 'in_transit',
        ]);
    }

    public function test_product_gallery_archive_and_bulk_import(): void
    {
        $seller = $this->user('seller');
        $product = $this->product($seller);

        Sanctum::actingAs($seller);

        $upload = $this->postJson("/api/v1/exporter/products/{$product->id}/gallery", [
            'images' => [UploadedFile::fake()->create('front.jpg', 80, 'image/jpeg')],
        ]);
        $upload->assertStatus(201);
        $upload->assertJsonPath('data.uploaded', 1);

        $list = $this->getJson("/api/v1/exporter/products/{$product->id}/gallery");
        $list->assertStatus(200);
        $list->assertJsonPath('data.count', 1);
        $list->assertJsonPath('data.images.0.is_primary', true);

        $this->postJson("/api/v1/exporter/products/{$product->id}/archive")
            ->assertStatus(200)
            ->assertJsonPath('data.publication_status', 'archived');

        $csv = "name,sku,category_id,grade,origin_country,unit,packaging_type,min_qty,unit_price,description\n"
            . "Bulk Rice One,SKU-BULK-1,{$product->category_id},A,India,kg,25kg bag,100,1250,First row\n"
            . "Bulk Rice Two,,{$product->category_id},B,India,kg,50kg bag,200,1100,Second row\n"
            . "Broken Row,,999999,A,India,kg,,10,10,Invalid category\n";

        $preview = $this->postJson('/api/v1/exporter/products/bulk/preview', [
            'file' => UploadedFile::fake()->createWithContent('bulk.csv', $csv),
        ]);
        $preview->assertStatus(200);
        $preview->assertJsonPath('data.total_rows', 3);
        $preview->assertJsonPath('data.valid_rows', 2);
        $preview->assertJsonPath('data.invalid_rows', 1);

        $confirm = $this->postJson('/api/v1/exporter/products/bulk/confirm', [
            'token' => $preview->json('data.token'),
        ]);
        $confirm->assertStatus(201);
        $confirm->assertJsonPath('data.created_count', 2);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-BULK-1', 'publication_status' => 'pending_approval']);

        // Re-confirming the same batch is rejected.
        $this->postJson('/api/v1/exporter/products/bulk/confirm', ['token' => $preview->json('data.token')])
            ->assertStatus(409);
    }

    public function test_remaining_admin_and_exporter_endpoints_smoke(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        $product = $this->product($seller);

        // ---------- Admin: unit conversion master ----------
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/unit-conversions')->assertStatus(200);
        $this->postJson('/api/v1/admin/unit-conversions/seed-defaults')->assertStatus(200);

        $conversion = $this->postJson('/api/v1/admin/unit-conversions', [
            'from_unit' => 'box',
            'to_unit' => 'pcs',
            'factor' => 24,
            'category' => 'count',
        ])->assertStatus(201);
        $conversionId = $conversion->json('data.id');

        $this->postJson("/api/v1/admin/unit-conversions/{$conversionId}", ['factor' => 12])
            ->assertStatus(200);
        $this->deleteJson("/api/v1/admin/unit-conversions/{$conversionId}")->assertStatus(200);

        // ---------- Admin: checklist templates ----------
        $template = $this->postJson('/api/v1/admin/document-checklist-templates', [
            'name' => 'Rice shipment to UAE',
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'items' => [
                ['document_type' => 'commercial_invoice', 'is_mandatory' => true],
                ['document_type' => 'certificate_of_origin', 'is_mandatory' => true],
            ],
        ])->assertStatus(201);
        $templateId = $template->json('data.id');

        $this->getJson('/api/v1/admin/document-checklist-templates')->assertStatus(200);
        $this->getJson("/api/v1/admin/document-checklist-templates/{$templateId}")->assertStatus(200);
        $this->putJson("/api/v1/admin/document-checklist-templates/{$templateId}", [
            'name' => 'Rice shipment to UAE (revised)',
            'items' => [['document_type' => 'bill_of_lading', 'is_mandatory' => true]],
        ])->assertStatus(200);
        $this->deleteJson("/api/v1/admin/document-checklist-templates/{$templateId}")->assertStatus(200);

        // ---------- Exporter: gallery primary/delete, archive cycle, template ----------
        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/exporter/products/{$product->id}/gallery", [
            'images' => [
                UploadedFile::fake()->create('front.jpg', 40, 'image/jpeg'),
                UploadedFile::fake()->create('back.jpg', 40, 'image/jpeg'),
            ],
        ])->assertStatus(201);

        $images = $this->getJson("/api/v1/exporter/products/{$product->id}/gallery")
            ->assertStatus(200)
            ->json('data.images');
        $secondId = $images[1]['id'];

        $this->postJson("/api/v1/exporter/products/{$product->id}/gallery/{$secondId}/primary")
            ->assertStatus(200);
        $this->deleteJson("/api/v1/exporter/products/{$product->id}/gallery/{$secondId}")
            ->assertStatus(200);

        $this->postJson("/api/v1/exporter/products/{$product->id}/archive")
            ->assertStatus(200)
            ->assertJsonPath('data.publication_status', 'archived');
        $this->postJson("/api/v1/exporter/products/{$product->id}/unarchive")
            ->assertStatus(200)
            ->assertJsonPath('data.publication_status', 'pending_approval');

        $this->get('/api/v1/exporter/products/bulk/template')
            ->assertStatus(200)
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->getJson('/api/v1/exporter/units/conversions')->assertStatus(200);
        $this->postJson('/api/v1/exporter/units/convert', [
            'from_unit' => 'mt',
            'to_unit' => 'kg',
            'quantity' => 2.5,
        ])->assertStatus(200)->assertJsonPath('data.converted', 2500);

        $this->getJson('/api/v1/exporter/inventory/transfers')->assertStatus(200);

        $lot = $this->lot($seller, $product);
        $this->postJson("/api/v1/exporter/lots/{$lot->id}/low-stock-threshold", ['low_stock_threshold' => 250])
            ->assertStatus(200);

        $order = $this->order($buyer, $seller);
        $shipment = $this->shipment($seller, $order);
        $this->getJson("/api/v1/exporter/shipments/{$shipment->id}/container-history")->assertStatus(200);
        $this->getJson("/api/v1/exporter/shipments/{$shipment->id}/vehicles")->assertStatus(200);

        // ---------- Importer: marketplace visibility (P2-1) ----------
        Sanctum::actingAs($buyer);
        $draftProduct = $this->product($seller);
        $draftProduct->forceFill(['published' => 0, 'publication_status' => 'draft', 'approved' => 0])->save();

        $listing = $this->getJson('/api/v1/importer/products?per_page=100');
        $listing->assertStatus(200);
        $listedIds = collect($listing->json('data.data') ?? $listing->json('data') ?? [])->pluck('id');
        $this->assertFalse($listedIds->contains($draftProduct->id), 'Unpublished product leaked into the marketplace listing.');

        $draftResponse = $this->getJson("/api/v1/importer/products/{$draftProduct->id}");
        $draftResponse->assertStatus(404);

        $visibleProduct = $this->product($seller);
        $this->getJson("/api/v1/importer/products/{$visibleProduct->id}")->assertStatus(200);

        // NOTE: the marketplace only ever returns published + active products and exposes no
        // `published` / `publication_status` request filters, so only the scope is asserted here.

        // ---------- Importer: checklist + package, RFQ draft/cancel ----------
        $this->getJson("/api/v1/importer/orders/{$order->id}/document-checklist")->assertStatus(200);

        // No documents yet: package export explains the 404 instead of returning an empty ZIP.
        $this->get("/api/v1/importer/orders/{$order->id}/documents/package")->assertStatus(404);

        Storage::disk('public')->put('b2b-test/commercial-invoice.pdf', '%PDF-1.4 test');
        \App\Models\B2B\B2bTradeDocument::create([
            'document_type' => 'commercial_invoice',
            'order_id' => $order->id,
            'uploader_id' => $seller->id,
            'file_path' => 'b2b-test/commercial-invoice.pdf',
            'file_name' => 'commercial-invoice.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'status' => 'approved',
        ]);

        $package = $this->get("/api/v1/importer/orders/{$order->id}/documents/package");
        $package->assertStatus(200);
        $this->assertStringContainsString('zip', (string) $package->headers->get('content-type'));

        $rfq = Rfq::create([
            'rfq_number' => 'RFQ-' . strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'requested_quantity' => 300,
            'unit' => 'kg',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/save-draft", [
            'requested_quantity' => 450,
            'target_price' => 1180,
            'notes' => 'Draft update from buyer',
        ])->assertStatus(200);
        $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'closed');

        // ---------- Admin: RFQ detail + unassign ----------
        Sanctum::actingAs($admin);
        $openRfq = Rfq::create([
            'rfq_number' => 'RFQ-' . strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'requested_quantity' => 100,
            'unit' => 'kg',
            'currency' => 'USD',
            'status' => 'submitted',
        ]);
        $this->postJson("/api/v1/admin/rfqs/{$openRfq->id}/assign", ['assigned_to' => $staff->id])
            ->assertStatus(200);
        $this->getJson("/api/v1/admin/rfqs/{$openRfq->id}")->assertStatus(200);
        $this->postJson("/api/v1/admin/rfqs/{$openRfq->id}/unassign")
            ->assertStatus(200)
            ->assertJsonPath('data.assigned_to', null);

        // ---------- P2-2 supplier declines the RFQ ----------
        // The RFQ has no explicit supplier_id, so the product owner acts as supplier.
        Sanctum::actingAs($seller);
        $decline = $this->postJson("/api/v1/exporter/rfqs/{$openRfq->id}/reject", [
            'reason_code' => 'specification_not_possible',
            'reason' => 'The spec requires organic but local supplier cannot certify.',
        ]);
        $decline->assertStatus(200)
            ->assertJsonPath('data.rejection_code', 'specification_not_possible')
            ->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('rfqs', ['id' => $openRfq->id, 'status' => 'rejected']);
    }

    public function test_rfq_broadcast_supplier_response_rejection_and_admin_assignment(): void
    {
        $buyer = $this->user('customer');
        $supplierA = $this->user('seller');
        $supplierB = $this->user('seller');
        $admin = $this->user('admin');
        $staff = $this->user('staff');

        $rfq = Rfq::create([
            'rfq_number' => 'RFQ-' . strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'product_id' => $this->product($supplierA)->id,
            'requested_quantity' => 500,
            'unit' => 'kg',
            'currency' => 'USD',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($buyer);
        $broadcast = $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/broadcast", [
            'supplier_ids' => [$supplierA->id, $supplierB->id],
            'message' => 'Please quote your best FOB price.',
        ]);
        $broadcast->assertStatus(200);
        $broadcast->assertJsonPath('data.invitation_count', 2);

        $status = $this->getJson("/api/v1/importer/rfqs/{$rfq->id}/invitations");
        $status->assertStatus(200);
        $status->assertJsonPath('data.summary.invited', 2);

        // Supplier B accepts the invitation.
        Sanctum::actingAs($supplierB);
        $invitationId = $this->getJson('/api/v1/exporter/rfq-invitations')
            ->assertStatus(200)
            ->json('data.data.0.id');

        $this->postJson("/api/v1/exporter/rfq-invitations/{$invitationId}/respond", ['action' => 'accept'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'responded');

        // Supplier B rejects the RFQ with a structured reason.
        $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/reject", [
            'reason_code' => 'minimum_quantity_not_met',
            'reason' => 'Our minimum lot is 2 MT.',
        ])->assertStatus(200)->assertJsonPath('data.rejection_code', 'minimum_quantity_not_met');

        // Back-office assigns the RFQ to a staff member.
        Sanctum::actingAs($admin);
        $assign = $this->postJson("/api/v1/admin/rfqs/{$rfq->id}/assign", [
            'assigned_to' => $staff->id,
            'note' => 'Please follow up with the buyer.',
        ]);
        $assign->assertStatus(200);
        $assign->assertJsonPath('data.assigned_to', $staff->id);

        $this->getJson('/api/v1/admin/rfqs')->assertStatus(200);
        $this->getJson('/api/v1/admin/rfqs/stats')->assertStatus(200);
    }

    public function test_inventory_movements_low_stock_and_transfer(): void
    {
        $seller = $this->user('seller');
        $product = $this->product($seller);
        $lot = $this->lot($seller, $product);

        Sanctum::actingAs($seller);

        $movement = $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'damaged',
            'quantity' => 900,
            'reason' => 'Water damage in bay 4',
        ]);
        $movement->assertStatus(201);
        $this->assertEquals(100.0, (float) $movement->json('data.lot.quantity_available'));
        $movement->assertJsonPath('data.below_threshold', true);

        $lowStock = $this->getJson('/api/v1/exporter/inventory/low-stock');
        $lowStock->assertStatus(200);
        $lowStock->assertJsonPath('data.count', 1);
        $lowStock->assertJsonPath('data.lots.0.id', $lot->id);

        // Over-consumption is blocked.
        $this->postJson("/api/v1/exporter/lots/{$lot->id}/movements", [
            'movement_type' => 'lost',
            'quantity' => 5000,
            'reason' => 'Impossible loss',
        ])->assertStatus(400);

        $aging = $this->getJson('/api/v1/exporter/inventory/aging');
        $aging->assertStatus(200);

        // Transfer the lot to another warehouse.
        $transfer = $this->postJson('/api/v1/exporter/inventory/transfers', [
            'from_warehouse' => 'Warehouse A',
            'to_warehouse' => 'Warehouse B',
            'items' => [['lot_id' => $lot->id, 'quantity' => 50]],
        ]);
        $transfer->assertStatus(201);

        $this->postJson("/api/v1/exporter/inventory/transfers/{$transfer->json('data.id')}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('product_lots', ['id' => $lot->id, 'warehouse_location' => 'Warehouse B']);
        $this->assertDatabaseHas('inventory_movements', ['lot_id' => $lot->id, 'movement_type' => 'transferred']);

        $this->getJson('/api/v1/exporter/inventory/summary')->assertStatus(200);
    }

    public function test_shipment_eta_revisions_and_vehicle_assignment(): void
    {
        $buyer = $this->user('customer');
        $seller = $this->user('seller');
        $order = $this->order($buyer, $seller);
        $shipment = $this->shipment($seller, $order);

        Sanctum::actingAs($seller);

        $revision = $this->postJson("/api/v1/exporter/shipments/{$shipment->id}/eta-revisions", [
            'new_eta' => now()->addDays(35)->toDateString(),
            'reason' => 'Port congestion at Jebel Ali',
        ]);
        $revision->assertStatus(201);
        $this->assertStringStartsWith(now()->addDays(35)->toDateString(), (string) $revision->json('data.new_eta'));

        $this->getJson("/api/v1/exporter/shipments/{$shipment->id}/eta-revisions")
            ->assertStatus(200)
            ->assertJsonPath('data.revision_count', 1);

        $vehicle = $this->postJson("/api/v1/exporter/shipments/{$shipment->id}/vehicles", [
            'vehicle_type' => 'truck',
            'vehicle_number' => 'MH-12-AB-1234',
            'driver_name' => 'Ramesh',
            'driver_phone' => '+91 90000 00000',
            'capacity' => 20000,
            'unit' => 'kg',
        ]);
        $vehicle->assertStatus(201);

        $this->postJson("/api/v1/exporter/shipments/{$shipment->id}/vehicles/{$vehicle->json('data.id')}/release")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'released');

        // Tracking event now stores timezone + UTC conversion.
        $event = $this->postJson("/api/v1/exporter/shipments/{$shipment->id}/events", [
            'event_type' => 'in_transit',
            'location' => 'Arabian Sea',
            'event_status' => 'in_progress',
            'timezone' => 'Asia/Kolkata',
        ]);
        $event->assertStatus(201);
        $event->assertJsonPath('data.timezone', 'Asia/Kolkata');
        $this->assertNotNull($event->json('data.actual_at_utc'));
    }

    public function test_document_checklist_credit_note_and_payment_reconciliation(): void
    {
        $buyer = $this->user('customer');
        $seller = $this->user('seller');
        $order = $this->order($buyer, $seller);
        $order->items()->create([
            'product_id' => $this->product($seller)->id,
            'product_name_snapshot' => 'Basmati Rice 1121',
            'quantity' => 100,
            'unit' => 'kg',
            'unit_price' => 1200,
            'line_total' => 120000,
        ]);

        Sanctum::actingAs($seller);

        $checklist = $this->getJson("/api/v1/exporter/orders/{$order->id}/document-checklist");
        $checklist->assertStatus(200);
        $checklist->assertJsonPath('data.order_id', $order->id);
        $this->assertGreaterThan(0, $checklist->json('data.summary.required'));
        $this->assertSame('default', $checklist->json('data.template.source'));

        $note = $this->postJson('/api/v1/exporter/credit-notes', [
            'order_id' => $order->id,
            'type' => 'shortage_refund',
            'amount' => 5000,
            'reason' => 'Short shipment of 4 bags',
            'items' => [['description' => 'Short shipped bags', 'quantity' => 4, 'unit_price' => 1250]],
        ]);
        $note->assertStatus(201);
        $note->assertJsonPath('data.status', 'draft');

        $noteId = $note->json('data.id');
        $this->postJson("/api/v1/exporter/credit-notes/{$noteId}/issue")->assertStatus(200);
        $this->postJson("/api/v1/exporter/credit-notes/{$noteId}/apply", [])->assertStatus(200);

        // Buyer can see the credit note, an outsider cannot.
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/importer/credit-notes/{$noteId}")->assertStatus(200);

        $outsider = $this->user('customer');
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/importer/credit-notes/{$noteId}")->assertStatus(404);

        // Payment with FX capture + reconciliation by the supplier.
        Sanctum::actingAs($buyer);
        $payment = $this->postJson('/api/v1/importer/payments', [
            'order_id' => $order->id,
            'amount' => 10000,
            'currency' => 'AED',
            'exchange_rate' => 0.2723,
            'base_currency' => 'USD',
            'payment_type' => 'advance',
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'TRX-' . uniqid(),
        ]);
        $payment->assertStatus(201);
        $payment->assertJsonPath('data.base_amount', '2723.00');

        Sanctum::actingAs($seller);
        $reconcile = $this->postJson("/api/v1/exporter/payments/{$payment->json('data.id')}/reconcile", [
            'reconciliation_status' => 'matched',
            'bank_statement_reference' => 'STMT-7781',
        ]);
        $reconcile->assertStatus(200);
        $reconcile->assertJsonPath('data.reconciliation_status', 'matched');

        $this->getJson('/api/v1/exporter/payments/reconciliation-summary')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['by_status', 'unmatched_count', 'credit_note_total']]);
    }

    public function test_email_verification_and_two_factor_login(): void
    {
        $email = 'importer_' . uniqid() . '@example.com';

        $register = $this->postJson('/api/v1/importer/register', [
            'name' => 'Fresh Importer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'Fresh Imports LLC',
            'country' => 'UAE',
        ]);
        $register->assertStatus(201);
        $register->assertJsonPath('data.email_verified', false);
        $register->assertJsonPath('data.verification_pending', true);
        $this->assertNull(User::where('email', $email)->value('email_verified_at'));

        $token = $register->json('data.verification_token');
        $this->assertNotEmpty($token);

        $this->postJson('/api/v1/importer/email/verify', ['token' => $token])
            ->assertStatus(200)
            ->assertJsonPath('data.email_verified', true);

        // Token is single-use.
        $this->postJson('/api/v1/importer/email/verify', ['token' => $token])->assertStatus(400);

        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        $setup = $this->postJson('/api/v1/importer/two-factor/setup');
        $setup->assertStatus(200);
        $secret = $setup->json('data.secret');
        $this->assertNotEmpty($secret);

        $code = $this->totp($secret);
        $this->postJson('/api/v1/importer/two-factor/enable', ['code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', true);

        // Login without the second factor is refused.
        $this->postJson('/api/v1/importer/login', ['email' => $email, 'password' => 'password123'])
            ->assertStatus(401)
            ->assertJsonPath('errors.two_factor_required', true);

        $this->postJson('/api/v1/importer/login', [
            'email' => $email,
            'password' => 'password123',
            'two_factor_code' => $this->totp($secret),
        ])->assertStatus(200)->assertJsonPath('data.two_factor_enabled', true);
    }

    public function test_dashboards_and_reports(): void
    {
        $buyer = $this->user('customer');
        $seller = $this->user('seller');
        $order = $this->order($buyer, $seller);
        $this->lot($seller, $this->product($seller));

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/importer/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.portal', 'importer');

        $catalogue = $this->getJson('/api/v1/importer/reports');
        $catalogue->assertStatus(200);
        $this->assertCount(14, $catalogue->json('data.reports'));

        $this->getJson('/api/v1/importer/reports/order_pipeline')->assertStatus(200);
        $this->getJson('/api/v1/importer/reports/unknown_report')->assertStatus(404);

        $csv = $this->get('/api/v1/importer/reports/purchase_summary/export?format=csv');
        $csv->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $xlsx = $this->get('/api/v1/importer/reports/order_pipeline/export?format=xlsx');
        $xlsx->assertStatus(200);

        $pdf = $this->get('/api/v1/importer/reports/order_pipeline/export?format=pdf');
        $pdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));

        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/exporter/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.portal', 'exporter');
        $this->getJson('/api/v1/exporter/reports/inventory_valuation')->assertStatus(200);

        // An importer cannot open the admin dashboard.
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);
    }

    /** Minimal RFC 6238 TOTP generator matching the service implementation. */
    private function totp(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }

        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }

        $counter = (int) floor(time() / 30);
        $binary = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0xf;

        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
