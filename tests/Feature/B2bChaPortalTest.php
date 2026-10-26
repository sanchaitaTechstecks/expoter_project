<?php

namespace Tests\Feature;

use App\Models\B2B\B2bContainer;
use App\Models\B2B\B2bCustomsDeclaration;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bShipment;
use App\Models\B2B\B2bTradeDocument;
use App\Models\B2B\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bChaPortalTest extends TestCase
{
    protected function createChaUser(): User
    {
        return User::create([
            'name' => 'Customs Broker ' . uniqid(),
            'email' => 'cha_' . uniqid() . '@customsbroker.com',
            'password' => Hash::make('password123'),
            'user_type' => 'cha',
            'phone' => '+919876543210',
            'country' => 'India',
            'email_verified_at' => now(),
        ]);
    }

    protected function createShipment(User $chaUser): B2bShipment
    {
        $supplier = User::create([
            'name' => 'Exporter ' . uniqid(),
            'email' => 'sup_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'user_type' => 'seller',
        ]);
        Company::create([
            'user_id' => $supplier->id,
            'name' => 'Agro Exporters Ltd',
            'company_type' => 'exporter',
            'country' => 'India',
            'iec_code' => 'IEC' . rand(100000, 999999),
        ]);

        $buyer = User::create([
            'name' => 'Importer ' . uniqid(),
            'email' => 'buy_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'user_type' => 'customer',
        ]);

        $order = B2bOrder::create([
            'order_number' => 'ORD-' . uniqid(),
            'buyer_id' => $buyer->id,
            'supplier_id' => $supplier->id,
            'total_amount' => 45000.00,
            'currency' => 'USD',
            'status' => 'confirmed',
        ]);

        $shipment = B2bShipment::create([
            'shipment_number' => 'SHP-' . uniqid(),
            'order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'buyer_id' => $buyer->id,
            'mode' => 'sea',
            'carrier_name' => 'Maersk Line',
            'booking_reference' => 'BKG-' . rand(10000, 99999),
            'vessel_name' => 'Maersk Mc-Kinney Moller',
            'voyage_number' => 'V-2026A',
            'origin_port' => 'Nhava Sheva (INNSA1)',
            'destination_port' => 'Jebel Ali (AEJEA)',
            'departure_date' => now()->addDays(2),
            'estimated_arrival' => now()->addDays(12),
            'status' => 'planned',
            'assigned_cha_id' => $chaUser->id,
        ]);

        B2bContainer::create([
            'shipment_id' => $shipment->id,
            'container_number' => 'MSKU' . rand(1000000, 9999999),
            'container_type' => '40FT',
            'seal_number' => 'SEAL-' . rand(1000, 9999),
            'loaded_quantity' => 25.000,
            'unit' => 'MT',
            'status' => 'assigned',
        ]);

        B2bTradeDocument::create([
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'uploader_id' => $supplier->id,
            'document_type' => 'commercial_invoice',
            'document_name' => 'Commercial Invoice #001',
            'file_path' => 'documents/commercial_invoice.pdf',
            'status' => 'approved',
        ]);

        B2bTradeDocument::create([
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'uploader_id' => $supplier->id,
            'document_type' => 'packing_list',
            'document_name' => 'Packing List #001',
            'file_path' => 'documents/packing_list.pdf',
            'status' => 'approved',
        ]);

        return $shipment;
    }

    public function test_cha_authentication_and_unauthorized_rejection()
    {
        $chaUser = $this->createChaUser();
        $buyer = User::create([
            'name' => 'Regular Buyer ' . uniqid(),
            'email' => 'buyer_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'user_type' => 'customer',
        ]);

        // 1. Regular buyer rejected from CHA login
        $badResp = $this->postJson('/api/v1/cha/login', [
            'email' => $buyer->email,
            'password' => 'password123',
        ]);
        $badResp->assertStatus(403);
        $this->assertFalse($badResp->json('success'));

        // 2. CHA user authenticates successfully
        $loginResp = $this->postJson('/api/v1/cha/login', [
            'email' => $chaUser->email,
            'password' => 'password123',
        ]);
        $loginResp->assertStatus(200);
        $this->assertTrue($loginResp->json('success'));
        $token = $loginResp->json('data.token');
        $this->assertNotEmpty($token);

        $headers = ['Authorization' => "Bearer {$token}"];

        // 3. Get CHA profile & workload
        $profileResp = $this->getJson('/api/v1/cha/profile', $headers);
        $profileResp->assertStatus(200);
        $this->assertEquals($chaUser->email, $profileResp->json('data.user.email'));
        $this->assertArrayHasKey('workload', $profileResp->json('data'));

        // 4. Update CHA profile
        $updateResp = $this->postJson('/api/v1/cha/profile', [
            'name' => 'Senior Customs Broker Ltd',
            'phone' => '+919988776655',
        ], $headers);
        $updateResp->assertStatus(200);
        $this->assertEquals('Senior Customs Broker Ltd', $updateResp->json('data.name'));

        // 5. Logout
        $logoutResp = $this->postJson('/api/v1/cha/logout', [], $headers);
        $logoutResp->assertStatus(200);
    }

    public function test_cha_assigned_shipments_queue_and_dossier()
    {
        $chaUser = $this->createChaUser();
        $shipment = $this->createShipment($chaUser);

        Sanctum::actingAs($chaUser);

        // 1. Query assigned shipments queue
        $queueResp = $this->getJson('/api/v1/cha/shipments');
        $queueResp->assertStatus(200);
        $this->assertTrue($queueResp->json('success'));
        $this->assertGreaterThanOrEqual(1, count($queueResp->json('data.data')));

        // 2. View complete customs shipment dossier
        $dossierResp = $this->getJson("/api/v1/cha/shipments/{$shipment->id}");
        $dossierResp->assertStatus(200);
        $this->assertTrue($dossierResp->json('success'));
        $this->assertEquals($shipment->shipment_number, $dossierResp->json('data.shipment.shipment_number'));
        $this->assertArrayHasKey('customs_checklist', $dossierResp->json('data'));
    }

    public function test_customs_document_checklist()
    {
        $chaUser = $this->createChaUser();
        $shipment = $this->createShipment($chaUser);

        Sanctum::actingAs($chaUser);

        $checklistResp = $this->getJson("/api/v1/cha/shipments/{$shipment->id}/checklist");
        $checklistResp->assertStatus(200);
        $this->assertTrue($checklistResp->json('success'));

        $items = collect($checklistResp->json('data.checklist'))->keyBy('code');
        $this->assertTrue($items->has('commercial_invoice'));
        $this->assertTrue($items->has('packing_list'));
        $this->assertTrue($items->has('customs_declaration'));
        $this->assertEquals('verified', $items->get('commercial_invoice')['status']);
    }

    public function test_customs_filing_and_duty_calculation_workflow()
    {
        $chaUser = $this->createChaUser();
        $shipment = $this->createShipment($chaUser);

        Sanctum::actingAs($chaUser);

        // 1. Record Shipping Bill Filing (Section 15)
        $sbNumber = 'SB-2026-' . rand(100000, 999999);
        $filingResp = $this->postJson("/api/v1/cha/shipments/{$shipment->id}/filing", [
            'declaration_number' => $sbNumber,
            'filing_type' => 'shipping_bill',
            'port_code' => 'INNSA1',
            'filing_date' => now()->toDateString(),
            'cha_license_number' => 'CHA-LIC-MUM-8821',
            'iec_code' => 'IEC0123456789',
            'assessable_value' => 45000.00,
            'currency' => 'USD',
        ]);
        $filingResp->assertStatus(201);
        $this->assertTrue($filingResp->json('success'));
        $this->assertEquals($sbNumber, $filingResp->json('data.declaration_number'));

        // Verify shipment status shifted to customs_processing
        $shipment->refresh();
        $this->assertEquals('customs_processing', $shipment->status);
        $this->assertEquals('filed', $shipment->customs_status);

        // 2. Record Customs Duty Assessment
        $dutyResp = $this->postJson("/api/v1/cha/shipments/{$shipment->id}/duty-calculation", [
            'assessable_value' => 45000.00,
            'basic_customs_duty' => 2250.00,
            'igst_amount' => 2250.00,
            'cess_amount' => 225.00,
            'duty_payment_status' => 'paid',
            'duty_challan_number' => 'ICEGATE-CHAL-998811',
        ]);
        $dutyResp->assertStatus(200);
        $this->assertTrue($dutyResp->json('success'));
        $this->assertEquals(4725.00, (float) $dutyResp->json('data.total_duty_payable'));
        $this->assertEquals('paid', $dutyResp->json('data.duty_payment_status'));

        $shipment->refresh();
        $this->assertEquals('duty_paid', $shipment->customs_status);
    }

    public function test_customs_examination_hold_and_clearance_release()
    {
        $chaUser = $this->createChaUser();
        $shipment = $this->createShipment($chaUser);

        Sanctum::actingAs($chaUser);

        // First file declaration
        $this->postJson("/api/v1/cha/shipments/{$shipment->id}/filing", [
            'declaration_number' => 'SB-2026-' . rand(100000, 999999),
            'port_code' => 'INNSA1',
        ])->assertStatus(201);

        // 1. Customs Hold / Query raised during examination
        $examResp = $this->postJson("/api/v1/cha/shipments/{$shipment->id}/examination", [
            'examination_status' => 'customs_hold',
            'examination_remarks' => 'Plant quarantine sample requested for laboratory testing.',
            'examination_officer' => 'Inspector R. Sharma, Appraising Officer',
            'query_description' => 'Discrepancy in moisture percentage specification.',
        ]);
        $examResp->assertStatus(200);
        $this->assertEquals('customs_hold', $examResp->json('data.examination_status'));

        $shipment->refresh();
        $this->assertEquals('customs_hold', $shipment->status);
        $this->assertEquals('customs_hold', $shipment->customs_status);

        // 2. Resubmit examination after lab test passed
        $passExamResp = $this->postJson("/api/v1/cha/shipments/{$shipment->id}/examination", [
            'examination_status' => 'passed',
            'examination_remarks' => 'Lab certificate verified. Consignment cleared for export.',
            'query_response' => 'Laboratory test report #TR-441 submitted and accepted by AO.',
        ]);
        $passExamResp->assertStatus(200);
        $this->assertEquals('passed', $passExamResp->json('data.examination_status'));

        // 3. Grant Final Customs Release (Let Export Order / LEO)
        $leoNumber = 'LEO-2026-INNSA1-' . rand(10000, 99999);
        $releaseResp = $this->postJson("/api/v1/cha/shipments/{$shipment->id}/release", [
            'release_order_number' => $leoNumber,
            'release_notes' => 'Let Export Order granted. Containers cleared for vessel loading.',
        ]);
        $releaseResp->assertStatus(200);
        $this->assertTrue($releaseResp->json('success'));
        $this->assertEquals('cleared', $releaseResp->json('data.clearance_status'));
        $this->assertEquals($leoNumber, $releaseResp->json('data.release_order_number'));

        // Assert shipment is marked cleared
        $shipment->refresh();
        $this->assertEquals('cleared', $shipment->status);
        $this->assertEquals('cleared', $shipment->customs_status);

        // Assert tracking event logged
        $this->assertDatabaseHas('b2b_tracking_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'customs_cleared',
        ]);
    }
}
