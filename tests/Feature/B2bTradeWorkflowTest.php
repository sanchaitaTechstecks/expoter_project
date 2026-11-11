<?php

namespace Tests\Feature;

use App\Models\B2B\Company;
use App\Models\B2B\ProductLot;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class B2bTradeWorkflowTest extends TestCase
{
    public function test_complete_importer_and_exporter_workflow()
    {
        Storage::fake('public');

        // Setup a test Category
        $category = Category::firstOrCreate(
            ['id' => 999],
            ['name' => 'Agri Commodities', 'parent_id' => 0]
        );

        // 1. Exporter Registration
        $exporterEmail = 'exporter_' . uniqid() . '@example.com';
        $exporterResp = $this->postJson('/api/v1/exporter/register', [
            'name' => 'Global Agro Exports Ltd',
            'email' => $exporterEmail,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'phone' => '+919876543210',
            'company_name' => 'Global Agro Exports Ltd',
            'country' => 'India',
            'iec_code' => 'IEC0123456789',
            'tax_number' => '27AAACG0123M1Z5',
            'bank_name' => 'Standard Chartered Bank',
            'bank_account_number' => '123456789012',
            'swift_bic' => 'SCBLINBB',
            'trade_type' => 'international',
        ]);

        $exporterResp->assertStatus(201);
        $exporterToken = $exporterResp->json('data.token');
        $exporterUser = $exporterResp->json('data.user');
        $exporterHeaders = ['Authorization' => "Bearer {$exporterToken}"];

        // 2. Importer Registration
        $importerEmail = 'importer_' . uniqid() . '@example.com';
        $importerResp = $this->postJson('/api/v1/importer/register', [
            'name' => 'EuroFoods Import GmbH',
            'email' => $importerEmail,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'phone' => '+4915123456789',
            'company_name' => 'EuroFoods Import GmbH',
            'country' => 'Germany',
            'iec_code' => 'DE987654321',
            'tax_number' => 'DE123456789',
            'trade_type' => 'international',
        ]);

        $importerResp->assertStatus(201);
        $importerToken = $importerResp->json('data.token');
        $importerUser = $importerResp->json('data.user');
        $importerHeaders = ['Authorization' => "Bearer {$importerToken}"];

        // 3. Exporter creates a Product
        $prodResp = $this->withHeaders($exporterHeaders)->postJson('/api/v1/exporter/products', [
            'name' => 'Organic Basmati Rice 1121',
            'sku' => 'RICE-1121-' . uniqid(),
            'category_id' => $category->id,
            'grade' => 'Extra Long Grain AAA',
            'origin_country' => 'India',
            'unit' => 'MT',
            'packaging_type' => '25kg PP Bags',
            'specifications' => 'Moisture < 12%, Grain length > 8.35mm, Purity 98%',
            'description' => 'Premium exported grade basmati rice',
            'domestic_visibility' => true,
            'export_visibility' => true,
        ]);

        $prodResp->assertStatus(201);
        $productId = $prodResp->json('data.id');

        // DEC-002: a new product starts as pending_approval, so the marketplace hides it until an
        // admin approves it (that moderation flow is covered by B2bP3ProductManagementTest).
        Product::where('id', $productId)->update([
            'approved' => 1,
            'published' => 1,
            'publication_status' => 'active',
        ]);

        // 4. Exporter creates a Product Lot with independent pricing & stock (BR-001)
        $lotResp = $this->withHeaders($exporterHeaders)->postJson('/api/v1/exporter/lots', [
            'product_id' => $productId,
            'warehouse_location' => 'Kandla Port Warehouse Bay 4',
            'quality_grade' => 'Grade A Premium',
            'quantity' => 100, // 100 MT
            'unit' => 'MT',
            'unit_price' => 950.00, // $950 / MT
            'currency' => 'USD',
            'incoterm' => 'FOB',
            'named_place' => 'Port of Kandla, India',
            'packaging_details' => '25kg Vacuum Bags on Pallets',
            'manufacture_or_harvest_date' => '2026-09-01',
            'expiry_date' => '2028-09-01',
        ]);

        $lotResp->assertStatus(201);
        $lotId = $lotResp->json('data.id');
        $this->assertEquals(100, (float)$lotResp->json('data.quantity_available'));

        // 5. Exporter updates Lot Price with audit trail (BR-002)
        $priceUpdateResp = $this->withHeaders($exporterHeaders)->postJson("/api/v1/exporter/lots/{$lotId}/price", [
            'new_price' => 980.00,
            'currency' => 'USD',
            'reason' => 'Market demand adjustment for Q4',
        ]);

        $priceUpdateResp->assertStatus(200);
        $this->assertEquals(980.00, (float)$priceUpdateResp->json('data.unit_price'));

        // 6. Importer browses products and views lot pricing
        $marketResp = $this->getJson("/api/v1/importer/products/{$productId}");
        $marketResp->assertStatus(200);
        $this->assertNotEmpty($marketResp->json('data.active_lots'));

        // 7. Importer submits an RFQ
        $rfqResp = $this->withHeaders($importerHeaders)->postJson('/api/v1/importer/rfqs', [
            'product_id' => $productId,
            'preferred_lot_ids' => [$lotId],
            'requested_quantity' => 20, // 20 MT
            'unit' => 'MT',
            'target_price' => 960.00,
            'quality_specification' => 'Moisture < 12%, Grain length > 8.35mm',
            'packaging_requirement' => '25kg PP Bags with custom private labelling',
            'trade_type' => 'international',
            'destination_country' => 'Germany',
            'destination_port_or_address' => 'Port of Hamburg',
            'incoterm' => 'CIF',
            'currency' => 'USD',
            'required_documents' => ['Phytosanitary Certificate', 'Certificate of Origin', 'Bill of Lading'],
            'notes' => 'Looking for prompt shipment before end of next month.',
        ]);

        $rfqResp->assertStatus(201);
        $rfqId = $rfqResp->json('data.id');

        // 8. Exporter views RFQ and sends a clarification message
        $msgResp = $this->withHeaders($exporterHeaders)->postJson("/api/v1/exporter/rfqs/{$rfqId}/messages", [
            'message' => 'We can supply 20 MT from Lot A at $975 CIF Hamburg with Phytosanitary and COO included.',
        ]);
        $msgResp->assertStatus(201);

        // 9. Exporter issues a formal Quotation
        $quoteResp = $this->withHeaders($exporterHeaders)->postJson('/api/v1/exporter/quotations', [
            'rfq_id' => $rfqId,
            'currency' => 'USD',
            'incoterm' => 'CIF',
            'named_place' => 'Port of Hamburg',
            'validity_date' => now()->addDays(14)->toDateTimeString(),
            'delivery_window' => '4 weeks from contract',
            'payment_terms' => '30% Advance, 70% against B/L copy',
            'freight_charges' => 800.00,
            'insurance_charges' => 200.00,
            'discount' => 100.00,
            'items' => [
                [
                    'product_id' => $productId,
                    'lot_id' => $lotId,
                    'quantity' => 20,
                    'unit' => 'MT',
                    'unit_price' => 975.00,
                    'packaging' => '25kg PP Bags with Pallets',
                ]
            ],
        ]);

        $quoteResp->assertStatus(201);
        $quotationId = $quoteResp->json('data.id');
        // Subtotal = 20 * 975 = 19500. Total = 19500 - 100 + 800 + 200 = 20400
        $this->assertEquals(20400.00, (float)$quoteResp->json('data.total_amount'));

        // 10. Importer accepts the Quotation -> Confirmed B2B Order + Atomic Stock Reservation (BR-003, BR-004, BR-005)
        $acceptResp = $this->withHeaders($importerHeaders)->postJson("/api/v1/importer/quotations/{$quotationId}/accept");
        $acceptResp->assertStatus(200);
        $orderId = $acceptResp->json('data.id');
        $agreementId = $acceptResp->json('data.agreement.id');

        // Verify stock reservation on Lot: 100 initial - 20 reserved = 80 available, 20 reserved
        $lotAfter = ProductLot::find($lotId);
        $this->assertEquals(80, (float)$lotAfter->quantity_available);
        $this->assertEquals(20, (float)$lotAfter->quantity_reserved);

        // 11. Importer signs trade agreement
        $signBuyerResp = $this->withHeaders($importerHeaders)->postJson("/api/v1/importer/agreements/{$agreementId}/sign", [
            'signature' => 'Dr. Klaus Mueller (Managing Director)',
        ]);
        $signBuyerResp->assertStatus(200);

        // Exporter signs trade agreement
        $signExporterResp = $this->withHeaders($exporterHeaders)->postJson("/api/v1/exporter/agreements/{$agreementId}/sign", [
            'signature' => 'Rajesh Sharma (Export Director)',
        ]);
        $signExporterResp->assertStatus(200);
        $this->assertEquals('accepted', $signExporterResp->json('data.status'));

        // 12. Exporter issues Proforma Invoice (PI)
        $invoiceResp = $this->withHeaders($exporterHeaders)->postJson('/api/v1/exporter/invoices', [
            'order_id' => $orderId,
            'invoice_type' => 'proforma',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'payment_terms' => '30% Advance ($6,120) upon PI receipt',
        ]);
        $invoiceResp->assertStatus(201);
        $invoiceId = $invoiceResp->json('data.id');

        // 13. Importer records 30% advance payment ($6,120) with proof file
        $paymentResp = $this->withHeaders($importerHeaders)->postJson('/api/v1/importer/payments', [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'amount' => 6120.00,
            'currency' => 'USD',
            'payment_type' => 'advance',
            'payment_method' => 'bank_transfer',
            'transaction_reference' => 'SWIFT-REF-' . uniqid(),
            'notes' => '30% advance TT wire transfer completed',
        ]);
        $paymentResp->assertStatus(201);
        $paymentId = $paymentResp->json('data.id');
        $this->assertEquals('verification_required', $paymentResp->json('data.status'));

        // 14. Exporter verifies payment -> Updates Order financial balance (BR-006)
        $verifyResp = $this->withHeaders($exporterHeaders)->postJson("/api/v1/exporter/payments/{$paymentId}/verify", [
            'status' => 'paid',
        ]);
        $verifyResp->assertStatus(200);

        // Verify Order financials: total $20,400, paid $6,120, balance due $14,280
        $orderAfterPayment = $this->withHeaders($importerHeaders)->getJson("/api/v1/importer/orders/{$orderId}");
        $orderAfterPayment->assertStatus(200);
        $this->assertEquals(6120.00, (float)$orderAfterPayment->json('data.amount_paid'));
        $this->assertEquals(14280.00, (float)$orderAfterPayment->json('data.balance_due'));
        $this->assertEquals('partially_paid', $orderAfterPayment->json('data.payment_status'));

        // 15. Exporter uploads trade documents (Phytosanitary Certificate & COO)
        $docResp = $this->withHeaders($exporterHeaders)->postJson('/api/v1/exporter/documents/upload', [
            'order_id' => $orderId,
            'document_type' => 'phytosanitary_certificate',
            'title' => 'Phytosanitary Health Certificate',
            'document_number' => 'PHYTO-IN-2026-9901',
            'file' => UploadedFile::fake()->create('phyto_certificate.pdf', 250, 'application/pdf'),
            'issuer' => 'Plant Quarantine Organization of India',
        ]);
        $docResp->assertStatus(201);

        // 16. Exporter creates Shipment, assigns Container, and logs tracking events
        $shipmentResp = $this->withHeaders($exporterHeaders)->postJson('/api/v1/exporter/shipments', [
            'order_id' => $orderId,
            'mode' => 'sea',
            'carrier_name' => 'Maersk Line',
            'booking_reference' => 'MSK-HAM-2026-8812',
            'tracking_number' => 'TRK-987654321',
            'vessel_name' => 'Maersk Mc-Kinney Moller',
            'voyage_number' => 'V-2609',
            'origin_port' => 'Port of Kandla, India',
            'destination_port' => 'Port of Hamburg, Germany',
            'departure_date' => now()->addDays(3)->toDateString(),
            'estimated_arrival' => now()->addDays(24)->toDateString(),
        ]);
        $shipmentResp->assertStatus(201);
        $shipmentId = $shipmentResp->json('data.id');

        // Add container
        $containerResp = $this->withHeaders($exporterHeaders)->postJson("/api/v1/exporter/shipments/{$shipmentId}/containers", [
            'container_number' => 'MSKU9021841',
            'container_type' => '20FT',
            'seal_number' => 'SEAL-IND-88219',
            'loaded_quantity' => 20,
            'unit' => 'MT',
            'gross_weight' => 21.5,
            'net_weight' => 20.0,
        ]);
        $containerResp->assertStatus(201);

        // Log milestone: Loaded onto vessel (Triggers stock dispatch from reserved - Section 5)
        $eventResp = $this->withHeaders($exporterHeaders)->postJson("/api/v1/exporter/shipments/{$shipmentId}/events", [
            'event_type' => 'loaded_onto_vessel',
            'location' => 'Port of Kandla Terminal Berth 3',
            'event_status' => 'completed',
            'actual_at' => now()->toDateTimeString(),
            'source' => 'manual',
            'description' => 'Container loaded onto vessel Maersk Mc-Kinney Moller',
        ]);
        $eventResp->assertStatus(201);

        // 17. Importer tracks shipment timeline
        $trackResp = $this->withHeaders($importerHeaders)->getJson("/api/v1/importer/shipments/{$shipmentId}/track");
        $trackResp->assertStatus(200);
        $this->assertEquals('loaded', $trackResp->json('data.status'));
        $this->assertNotEmpty($trackResp->json('data.timeline'));
        $this->assertNotEmpty($trackResp->json('data.containers'));
    }
}
