<?php

namespace Tests\Feature;

use App\Models\B2B\B2bQuotation;
use App\Models\B2B\B2bQuotationItem;
use App\Models\B2B\Company;
use App\Models\B2B\ProductLot;
use App\Models\B2B\Rfq;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bBusinessRulesTest extends TestCase
{
    protected function createExporter(): User
    {
        $user = User::create([
            'name' => 'Supplier ' . uniqid(),
            'email' => 'sup_' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'seller',
        ]);
        Company::create([
            'user_id' => $user->id,
            'name' => 'Supplier Co ' . uniqid(),
            'company_type' => 'exporter',
            'trade_type' => 'both',
            'country' => 'India',
            'iec_code' => 'IEC' . rand(100000, 999999),
        ]);
        return $user;
    }

    protected function createImporter(): User
    {
        $user = User::create([
            'name' => 'Buyer ' . uniqid(),
            'email' => 'buy_' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'customer',
        ]);
        Company::create([
            'user_id' => $user->id,
            'name' => 'Buyer Co ' . uniqid(),
            'company_type' => 'importer',
            'trade_type' => 'both',
            'country' => 'UAE',
        ]);
        return $user;
    }

    public function test_insufficient_stock_prevents_quotation_acceptance_br003()
    {
        $exporter = $this->createExporter();
        $importer = $this->createImporter();

        $category = Category::firstOrCreate(['id' => 999], ['name' => 'Agri']);

        $product = Product::create([
            'name' => 'Saffron ' . uniqid(),
            'user_id' => $exporter->id,
            'category_id' => $category->id,
            'unit' => 'kg',
            'unit_price' => 2000,
            'published' => 1,
            'publication_status' => 'active',
        ]);

        // Lot with only 5 kg available
        $lot = ProductLot::create([
            'lot_code' => 'LOT-SAF-' . uniqid(),
            'product_id' => $product->id,
            'supplier_id' => $exporter->id,
            'warehouse_location' => 'Kashmir Hub',
            'quality_grade' => 'Super Negin',
            'quantity_received' => 5,
            'quantity_reserved' => 0,
            'quantity_dispatched' => 0,
            'quantity_available' => 5, // only 5 kg!
            'unit' => 'kg',
            'unit_price' => 2000,
            'currency' => 'USD',
            'lot_status' => 'active',
        ]);

        $rfq = Rfq::create([
            'rfq_number' => 'RFQ-' . uniqid(),
            'buyer_id' => $importer->id,
            'supplier_id' => $exporter->id,
            'product_id' => $product->id,
            'requested_quantity' => 10,
            'unit' => 'kg',
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'destination_port_or_address' => 'Dubai Port',
            'status' => 'submitted',
        ]);

        // Quotation asks for 10 kg from lot that only has 5 kg
        $quotation = B2bQuotation::create([
            'quotation_number' => 'QT-' . uniqid(),
            'rfq_id' => $rfq->id,
            'supplier_id' => $exporter->id,
            'buyer_id' => $importer->id,
            'version' => 1,
            'currency' => 'USD',
            'subtotal' => 20000,
            'total_amount' => 20000,
            'validity_date' => now()->addDays(5),
            'status' => 'issued',
        ]);

        B2bQuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'product_name_snapshot' => $product->name,
            'quantity' => 10, // exceeds 5 kg available!
            'unit' => 'kg',
            'unit_price' => 2000,
            'line_total' => 20000,
        ]);

        // Importer tries to accept -> should return 400 with Insufficient stock
        Sanctum::actingAs($importer);
        $resp = $this->postJson("/api/v1/importer/quotations/{$quotation->id}/accept");
        $resp->assertStatus(400);
        $this->assertStringContainsString('Insufficient available stock', $resp->json('message'));
    }

    public function test_expired_quotation_cannot_be_accepted_br004()
    {
        $exporter = $this->createExporter();
        $importer = $this->createImporter();

        $category = Category::firstOrCreate(['id' => 999], ['name' => 'Agri']);

        $product = Product::create([
            'name' => 'Cardamom ' . uniqid(),
            'user_id' => $exporter->id,
            'category_id' => $category->id,
            'unit' => 'kg',
            'unit_price' => 30,
            'published' => 1,
            'publication_status' => 'active',
        ]);

        $lot = ProductLot::create([
            'lot_code' => 'LOT-CARD-' . uniqid(),
            'product_id' => $product->id,
            'supplier_id' => $exporter->id,
            'quantity_received' => 100,
            'quantity_reserved' => 0,
            'quantity_dispatched' => 0,
            'quantity_available' => 100,
            'unit' => 'kg',
            'unit_price' => 30,
            'currency' => 'USD',
            'lot_status' => 'active',
        ]);

        $rfq = Rfq::create([
            'rfq_number' => 'RFQ-' . uniqid(),
            'buyer_id' => $importer->id,
            'supplier_id' => $exporter->id,
            'product_id' => $product->id,
            'requested_quantity' => 10,
            'unit' => 'kg',
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'destination_port_or_address' => 'Dubai Port',
            'status' => 'submitted',
        ]);

        // Quotation expired yesterday
        $quotation = B2bQuotation::create([
            'quotation_number' => 'QT-' . uniqid(),
            'rfq_id' => $rfq->id,
            'supplier_id' => $exporter->id,
            'buyer_id' => $importer->id,
            'version' => 1,
            'currency' => 'USD',
            'subtotal' => 300,
            'total_amount' => 300,
            'validity_date' => now()->subDay(), // EXPIRED!
            'status' => 'issued',
        ]);

        B2bQuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'product_name_snapshot' => $product->name,
            'quantity' => 10,
            'unit' => 'kg',
            'unit_price' => 30,
            'line_total' => 300,
        ]);

        Sanctum::actingAs($importer);
        $resp = $this->postJson("/api/v1/importer/quotations/{$quotation->id}/accept");
        $resp->assertStatus(400);
        $this->assertStringContainsString('expired', $resp->json('message'));
    }

    public function test_price_snapshot_preserved_after_catalogue_price_change_br005()
    {
        $exporter = $this->createExporter();
        $importer = $this->createImporter();

        $category = Category::firstOrCreate(['id' => 999], ['name' => 'Agri']);

        $product = Product::create([
            'name' => 'Turmeric ' . uniqid(),
            'user_id' => $exporter->id,
            'category_id' => $category->id,
            'unit' => 'MT',
            'unit_price' => 1200,
            'published' => 1,
            'publication_status' => 'active',
        ]);

        $lot = ProductLot::create([
            'lot_code' => 'LOT-TUR-' . uniqid(),
            'product_id' => $product->id,
            'supplier_id' => $exporter->id,
            'warehouse_location' => 'Mumbai Hub',
            'quality_grade' => 'Salem Grade 1',
            'quantity_received' => 50,
            'quantity_reserved' => 0,
            'quantity_dispatched' => 0,
            'quantity_available' => 50,
            'unit' => 'MT',
            'unit_price' => 1200,
            'currency' => 'USD',
            'lot_status' => 'active',
        ]);

        $rfq = Rfq::create([
            'rfq_number' => 'RFQ-' . uniqid(),
            'buyer_id' => $importer->id,
            'supplier_id' => $exporter->id,
            'product_id' => $product->id,
            'requested_quantity' => 10,
            'unit' => 'MT',
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'destination_port_or_address' => 'Dubai Port',
            'status' => 'submitted',
        ]);

        $quotation = B2bQuotation::create([
            'quotation_number' => 'QT-' . uniqid(),
            'rfq_id' => $rfq->id,
            'supplier_id' => $exporter->id,
            'buyer_id' => $importer->id,
            'version' => 1,
            'currency' => 'USD',
            'subtotal' => 12000,
            'total_amount' => 12000,
            'validity_date' => now()->addDays(7),
            'status' => 'issued',
        ]);

        B2bQuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'product_name_snapshot' => $product->name,
            'quantity' => 10,
            'unit' => 'MT',
            'unit_price' => 1200,
            'line_total' => 12000,
        ]);

        // Accept quotation to form order
        Sanctum::actingAs($importer);
        $resp = $this->postJson("/api/v1/importer/quotations/{$quotation->id}/accept");
        $resp->assertStatus(200);
        $orderId = $resp->json('data.id');

        // Exporter now doubles the price of the lot to $2400
        Sanctum::actingAs($exporter);
        $priceResp = $this->postJson("/api/v1/exporter/lots/{$lot->id}/price", [
            'new_price' => 2400,
            'reason' => 'Global shortage',
        ]);
        $priceResp->assertStatus(200);

        // Check that the confirmed order STILL preserves the agreed price of $1200 / MT (BR-005)
        Sanctum::actingAs($importer);
        $orderResp = $this->getJson("/api/v1/importer/orders/{$orderId}");
        $orderResp->assertStatus(200);
        $orderItem = $orderResp->json('data.items.0');
        $this->assertEquals(1200.00, (float)$orderItem['unit_price']);
        $this->assertEquals(12000.00, (float)$orderResp->json('data.total_amount'));
    }

    public function test_cross_tenant_access_is_prevented_br011()
    {
        $exporter1 = $this->createExporter();
        $importer1 = $this->createImporter();
        $importer2 = $this->createImporter();

        $category = Category::firstOrCreate(['id' => 999], ['name' => 'Agri']);

        $product = Product::create([
            'name' => 'Pepper ' . uniqid(),
            'user_id' => $exporter1->id,
            'category_id' => $category->id,
            'unit' => 'kg',
            'unit_price' => 15,
            'published' => 1,
            'publication_status' => 'active',
        ]);

        $rfq = Rfq::create([
            'rfq_number' => 'RFQ-' . uniqid(),
            'buyer_id' => $importer1->id, // Belongs to Importer 1
            'supplier_id' => $exporter1->id,
            'product_id' => $product->id,
            'requested_quantity' => 50,
            'unit' => 'kg',
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'destination_port_or_address' => 'Dubai Port',
            'status' => 'submitted',
        ]);

        // Importer 2 tries to view Importer 1's RFQ -> Expect 404
        Sanctum::actingAs($importer2);
        $resp = $this->getJson("/api/v1/importer/rfqs/{$rfq->id}");
        $resp->assertStatus(404);
    }
}
