<?php

namespace Tests\Feature;

use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bPayment;
use App\Models\B2B\B2bShipment;
use App\Models\B2B\B2bTradeDocument;
use App\Models\B2B\DocumentChecklistTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** P2-5 shipment ops, P2-6 document ops/checklists, P2-7 payment adjustments & reconciliation. */
class B2bP5ToP7OperationsTest extends TestCase
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
            'password' => Hash::make('password'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ]);
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
            'incoterm' => 'CIF',
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
            'carrier_name' => 'Maersk',
            'origin_port' => 'INNSA1',
            'destination_port' => 'AEJEA',
            'departure_date' => now()->toDateString(),
            'estimated_arrival' => now()->addDays(20)->toDateString(),
            'status' => 'in_transit',
        ]);
    }

    private function payment(User $buyer, User $seller, B2bOrder $order, array $overrides = []): B2bPayment
    {
        return B2bPayment::create(array_merge([
            'payment_number' => 'PAY-' . strtoupper(uniqid()),
            'order_id' => $order->id,
            'buyer_id' => $buyer->id,
            'supplier_id' => $seller->id,
            'amount' => 50000,
            'currency' => 'USD',
            'base_currency' => 'BDT',
            'payment_type' => 'advance',
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'paid',
        ], $overrides));
    }

    // ---------------------------------------------------------------- P2-5

    public function test_shipment_eta_revisions_vehicles_containers_and_timezones(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $order = $this->order($buyer, $seller);
        Sanctum::actingAs($seller);

        $created = $this->postJson('/api/v1/exporter/shipments', [
            'order_id' => $order->id,
            'mode' => 'sea',
            'carrier_name' => 'Maersk',
            'origin_port' => 'INNSA1',
            'destination_port' => 'AEJEA',
            'estimated_arrival' => '2026-12-10',
        ]);
        $created->assertStatus(201);
        $created->assertJsonPath('message', 'Shipment created successfully');
        $shipmentId = $created->json('data.id');

        // ETA revisions keep the full trail.
        $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/eta-revisions", [
            'new_eta' => '2026-12-18', 'reason' => 'Port congestion at Jebel Ali',
        ])->assertStatus(201)
            ->assertJsonPath('data.previous_eta', '2026-12-10')
            ->assertJsonPath('data.new_eta', '2026-12-18');

        $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/eta-revisions", [
            'new_eta' => '2026-12-24', 'reason' => 'Carrier rescheduled berth',
        ])->assertStatus(201)
            ->assertJsonPath('data.previous_eta', '2026-12-18');

        $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/eta-revisions", [
            'new_eta' => '2026-12-24',
        ])->assertStatus(422);

        $revisions = $this->getJson("/api/v1/exporter/shipments/{$shipmentId}/eta-revisions");
        $revisions->assertStatus(200);
        $revisions->assertJsonPath('data.revision_count', 2);
        $revisions->assertJsonPath('data.current_eta', '2026-12-24');
        $this->assertSame('2026-12-24', B2bShipment::find($shipmentId)->estimated_arrival->toDateString());

        // Vehicle / driver assignment.
        $vehicle = $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/vehicles", [
            'vehicle_type' => 'truck', 'vehicle_number' => 'GJ-01-AB-1234',
            'driver_name' => 'Ramesh Patel', 'driver_phone' => '+919812345678',
            'driver_license' => 'DL-GJ-2020-1122', 'capacity' => 25000, 'unit' => 'kg',
        ]);
        $vehicle->assertStatus(201);
        $vehicle->assertJsonPath('data.status', 'assigned');
        $vehicleId = $vehicle->json('data.id');

        $this->getJson("/api/v1/exporter/shipments/{$shipmentId}/vehicles")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/vehicles/{$vehicleId}/release")
            ->assertStatus(200)->assertJsonPath('data.status', 'released');
        $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/vehicles/{$vehicleId}/release")
            ->assertStatus(409);

        // Container + assignment history.
        $container = $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/containers", [
            'container_number' => 'MSKU1234567', 'container_type' => '40FT_HC', 'seal_number' => 'SEAL-99',
            'loaded_quantity' => 18000, 'unit' => 'kg', 'gross_weight' => 19500, 'net_weight' => 18000,
        ]);
        $container->assertStatus(201);
        $container->assertJsonPath('data.status', 'assigned');

        $history = $this->getJson("/api/v1/exporter/shipments/{$shipmentId}/container-history");
        $history->assertStatus(200);
        $history->assertJsonCount(1, 'data');
        $history->assertJsonPath('data.0.action', 'assigned');

        // Operational event keeps both the local wall-clock time and the UTC instant.
        $event = $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/events", [
            'event_type' => 'at_origin_port',
            'location' => 'Nhava Sheva, India',
            'event_status' => 'completed',
            'actual_at' => '2026-11-11T10:30:00',
            'timezone' => 'Asia/Dhaka',
            'source' => 'manual',
            'description' => 'Gate-in at terminal',
        ]);
        $event->assertStatus(201);
        $event->assertJsonPath('data.timezone', 'Asia/Dhaka');
        $this->assertStringContainsString('2026-11-11 10:30', (string) $event->json('data.event_time_local'));
        $this->assertStringContainsString('04:30', (string) $event->json('data.actual_at_utc'));

        // Invalid timezone is rejected.
        $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/events", [
            'event_type' => 'in_transit', 'location' => 'At sea',
            'event_status' => 'completed', 'timezone' => 'Mars/Olympus',
        ])->assertStatus(422);

        // ETA cannot change once the shipment is closed.
        B2bShipment::where('id', $shipmentId)->update(['status' => 'delivered']);
        $this->postJson("/api/v1/exporter/shipments/{$shipmentId}/eta-revisions", [
            'new_eta' => '2027-01-05', 'reason' => 'Too late',
        ])->assertStatus(400);

        // Buyer sees the same shipment and tracking timeline.
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/importer/shipments/{$shipmentId}")->assertStatus(200);
        $this->getJson("/api/v1/importer/shipments/{$shipmentId}/track")->assertStatus(200);

        // Another supplier cannot read it.
        Sanctum::actingAs($this->user('seller'));
        $this->getJson("/api/v1/exporter/shipments/{$shipmentId}")->assertStatus(404);
    }

    // ---------------------------------------------------------------- P2-6

    public function test_document_checklist_template_package_and_expiry_sweep(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $admin = $this->user('admin');
        $order = $this->order($buyer, $seller);

        // Dynamic commodity/route template (DEC-008).
        Sanctum::actingAs($admin);
        $template = $this->postJson('/api/v1/admin/document-checklist-templates', [
            'name' => 'UAE rice imports',
            'commodity' => 'Basmati Rice',
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'is_active' => true,
            'items' => [
                ['document_type' => 'commercial_invoice', 'is_mandatory' => true, 'sort_order' => 1],
                ['document_type' => 'phytosanitary_certificate', 'is_mandatory' => true, 'sort_order' => 2],
                ['document_type' => 'insurance_certificate', 'is_mandatory' => false, 'sort_order' => 3],
            ],
        ]);
        $template->assertStatus(201);
        $templateId = $template->json('data.id');

        $this->getJson('/api/v1/admin/document-checklist-templates')->assertStatus(200);
        $this->getJson("/api/v1/admin/document-checklist-templates/{$templateId}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'UAE rice imports');

        // Order items drive the commodity match.
        $order->items()->create([
            'product_name_snapshot' => 'Basmati Rice Premium',
            'quantity' => 100, 'unit' => 'kg', 'unit_price' => 1200, 'line_total' => 120000,
            'allocated_quantity' => 0, 'dispatched_quantity' => 0, 'delivered_quantity' => 0,
            'outstanding_quantity' => 100,
        ]);

        Sanctum::actingAs($seller);
        $checklist = $this->getJson("/api/v1/exporter/orders/{$order->id}/document-checklist");
        $checklist->assertStatus(200);
        $checklist->assertJsonPath('message', 'Document checklist generated successfully');
        $checklist->assertJsonPath('data.template.source', 'template');
        $checklist->assertJsonPath('data.template.id', $templateId);
        $checklist->assertJsonPath('data.summary.required', 3);
        $checklist->assertJsonPath('data.summary.missing_mandatory', 2);

        // Provide one document: file present on disk, so it can be packaged.
        Storage::disk('public')->put('documents/invoice.pdf', 'invoice-bytes');
        $invoice = B2bTradeDocument::create([
            'document_type' => 'commercial_invoice',
            'order_id' => $order->id,
            'uploader_id' => $seller->id,
            'document_number' => 'INV-2026-001',
            'title' => 'Commercial Invoice',
            'file_path' => 'documents/invoice.pdf',
            'file_name' => 'invoice.pdf',
            'file_size' => 13,
            'mime_type' => 'application/pdf',
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'status' => 'issued',
        ]);

        $checklist = $this->getJson("/api/v1/exporter/orders/{$order->id}/document-checklist");
        $checklist->assertStatus(200);
        $checklist->assertJsonPath('data.summary.missing_mandatory', 1);
        $checklist->assertJsonPath('data.summary.expired', 1);
        $checklist->assertJsonPath('data.summary.complete', false);

        // Bulk ZIP export.
        $zip = $this->get("/api/v1/exporter/orders/{$order->id}/documents/package");
        $zip->assertStatus(200);
        $this->assertStringContainsString('application/zip', (string) $zip->headers->get('content-type'));
        $this->assertStringContainsString('.zip', (string) $zip->headers->get('content-disposition'));

        // An order with no uploaded documents cannot be packaged.
        $emptyOrder = $this->order($buyer, $seller);
        $this->get("/api/v1/exporter/orders/{$emptyOrder->id}/documents/package")->assertStatus(404);

        // Automated expiry sweep marks the expired document.
        B2bTradeDocument::create([
            'document_type' => 'insurance_certificate',
            'order_id' => $order->id,
            'uploader_id' => $seller->id,
            'document_number' => 'INS-2026-002',
            'title' => 'Insurance Certificate',
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => 'issued',
        ]);

        $this->artisan('b2b:documents:expire --days=30')->assertExitCode(0);

        $this->assertSame('expired', B2bTradeDocument::find($invoice->id)->status);
        $this->assertSame('issued', B2bTradeDocument::where('document_number', 'INS-2026-002')->first()->status);

        // The buyer gets the same checklist and package.
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/importer/orders/{$order->id}/document-checklist")->assertStatus(200);
        $this->get("/api/v1/importer/orders/{$order->id}/documents/package")->assertStatus(200);

        // Unknown template id / invalid payload.
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/document-checklist-templates/999999')->assertStatus(404);
        $this->postJson('/api/v1/admin/document-checklist-templates', ['name' => 'X'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- P2-7

    public function test_credit_note_workflow_and_payment_refunds(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $order = $this->order($buyer, $seller);
        $payment = $this->payment($buyer, $seller, $order);

        Sanctum::actingAs($seller);

        $created = $this->postJson('/api/v1/exporter/credit-notes', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'type' => 'shortage_refund',
            'amount' => 15000,
            'exchange_rate' => 110,
            'reason' => 'Short shipment: 13 of 100 kg missing',
            'items' => [
                ['description' => 'Shortage 13 kg', 'quantity' => 13, 'unit' => 'kg', 'unit_price' => 1000],
            ],
        ]);
        $created->assertStatus(201);
        $created->assertJsonPath('data.status', 'draft');
        $created->assertJsonPath('data.base_amount', '1650000.00');
        $noteId = $created->json('data.id');

        $this->postJson("/api/v1/exporter/credit-notes/{$noteId}/apply")->assertStatus(409);
        $this->postJson("/api/v1/exporter/credit-notes/{$noteId}/issue")
            ->assertStatus(200)->assertJsonPath('data.status', 'issued');
        $this->postJson("/api/v1/exporter/credit-notes/{$noteId}/apply", ['applied_note' => 'Offset against next invoice'])
            ->assertStatus(200)->assertJsonPath('data.status', 'applied');
        $this->postJson("/api/v1/exporter/credit-notes/{$noteId}/void")->assertStatus(409);

        // Over-refunding the order is refused.
        $this->postJson('/api/v1/exporter/credit-notes', [
            'order_id' => $order->id, 'type' => 'refund', 'amount' => 999999, 'reason' => 'Too much',
        ])->assertStatus(422);

        // A different supplier cannot create notes on this order.
        Sanctum::actingAs($this->user('seller'));
        $this->postJson('/api/v1/exporter/credit-notes', [
            'order_id' => $order->id, 'type' => 'refund', 'amount' => 100, 'reason' => 'Not mine',
        ])->assertStatus(403);

        // Payment-level refund issues an applied-eligible credit note.
        Sanctum::actingAs($seller);
        $refund = $this->postJson("/api/v1/exporter/payments/{$payment->id}/refund", [
            'amount' => 20000, 'reason' => 'Cargo damaged in transit', 'type' => 'damage_refund',
        ]);
        $refund->assertStatus(201);
        $refund->assertJsonPath('data.status', 'issued');
        $refund->assertJsonPath('data.type', 'damage_refund');

        // Remaining refundable balance is enforced (50000 - 20000 = 30000).
        $this->postJson("/api/v1/exporter/payments/{$payment->id}/refund", [
            'amount' => 45000, 'reason' => 'Over refund',
        ])->assertStatus(422);

        // Unverified payments cannot be refunded.
        $unpaid = $this->payment($buyer, $seller, $order, ['status' => 'pending_verification']);
        $this->postJson("/api/v1/exporter/payments/{$unpaid->id}/refund", [
            'amount' => 100, 'reason' => 'Not verified yet',
        ])->assertStatus(400);

        // Buyer sees both notes but cannot create them.
        Sanctum::actingAs($buyer);
        $list = $this->getJson('/api/v1/importer/credit-notes');
        $list->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, $list->json('data.total'));
        $this->getJson("/api/v1/importer/credit-notes/{$noteId}")->assertStatus(200);
        $this->postJson('/api/v1/exporter/credit-notes', [
            'order_id' => $order->id, 'type' => 'refund', 'amount' => 10, 'reason' => 'x',
        ])->assertStatus(403);

        // Back-office can read every credit note.
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/v1/admin/credit-notes')->assertStatus(200);
    }

    public function test_bank_reconciliation_states_and_fx_capture(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $order = $this->order($buyer, $seller);
        $payment = $this->payment($buyer, $seller, $order);

        Sanctum::actingAs($seller);

        // A matched line needs a bank statement reference.
        $this->postJson("/api/v1/exporter/payments/{$payment->id}/reconcile", [
            'reconciliation_status' => 'matched',
        ])->assertStatus(422);

        $reconciled = $this->postJson("/api/v1/exporter/payments/{$payment->id}/reconcile", [
            'reconciliation_status' => 'matched',
            'bank_statement_reference' => 'STMT-2026-11-0042',
            'transaction_at' => '2026-11-09T14:15:00',
            'exchange_rate' => 110,
            'notes' => 'Matched with HSBC statement line 42',
        ]);
        $reconciled->assertStatus(200);
        $reconciled->assertJsonPath('data.reconciliation_status', 'matched');
        $reconciled->assertJsonPath('data.bank_statement_reference', 'STMT-2026-11-0042');
        $reconciled->assertJsonPath('data.base_amount', '5500000.00');
        $this->assertNotNull($reconciled->json('data.reconciled_at'));
        $this->assertNotNull($reconciled->json('data.transaction_at'));

        $this->postJson("/api/v1/exporter/payments/{$payment->id}/reconcile", [
            'reconciliation_status' => 'nonsense',
        ])->assertStatus(422);

        // Unmatched second payment shows up as an exception.
        $unmatched = $this->payment($buyer, $seller, $order);
        $this->postJson("/api/v1/exporter/payments/{$unmatched->id}/reconcile", [
            'reconciliation_status' => 'unmatched', 'notes' => 'No matching bank line',
        ])->assertStatus(200);

        $summary = $this->getJson('/api/v1/exporter/payments/reconciliation-summary');
        $summary->assertStatus(200);
        $summary->assertJsonPath('data.total_payments', 2);
        $summary->assertJsonPath('data.unmatched_count', 1);
        $summary->assertJsonPath('data.verified_amount', 100000);
        $statuses = collect($summary->json('data.by_status'))->pluck('status')->all();
        $this->assertEqualsCanonicalizing(['matched', 'unmatched'], $statuses);

        // Another supplier cannot reconcile or read this payment.
        Sanctum::actingAs($this->user('seller'));
        $this->postJson("/api/v1/exporter/payments/{$payment->id}/reconcile", [
            'reconciliation_status' => 'matched', 'bank_statement_reference' => 'X',
        ])->assertStatus(404);
        $this->getJson('/api/v1/exporter/payments/reconciliation-summary')
            ->assertStatus(200)->assertJsonPath('data.total_payments', 0);

        // Reconciliation state is visible on the payment record.
        Sanctum::actingAs($seller);
        $this->getJson("/api/v1/exporter/payments/{$payment->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.reconciliation_status', 'matched');
    }
}
