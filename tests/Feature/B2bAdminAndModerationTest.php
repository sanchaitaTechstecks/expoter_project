<?php

namespace Tests\Feature;

use App\Models\B2B\B2bAuditLog;
use App\Models\B2B\B2bDispute;
use App\Models\B2B\B2bHsCode;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\Company;
use App\Models\B2B\PriceHistory;
use App\Models\B2B\ProductLot;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bAdminAndModerationTest extends TestCase
{
    protected function createSuperAdmin(): User
    {
        return User::create([
            'name' => 'Super Admin ' . uniqid(),
            'email' => 'admin_' . uniqid() . '@tradeplatform.com',
            'password' => Hash::make('password123'),
            'user_type' => 'admin',
            'admin_role' => 'super_admin',
            'email_verified_at' => now(),
        ]);
    }

    protected function createRegularUser(string $type = 'customer'): User
    {
        return User::create([
            'name' => 'Regular User ' . uniqid(),
            'email' => 'user_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ]);
    }

    public function test_admin_login_profile_and_non_admin_rejection()
    {
        $admin = $this->createSuperAdmin();
        $regularUser = $this->createRegularUser('customer');

        // 1. Regular buyer user attempts to log in to admin portal -> 403 Forbidden
        $badResp = $this->postJson('/api/v1/admin/login', [
            'email' => $regularUser->email,
            'password' => 'password123',
        ]);
        $badResp->assertStatus(403);
        $this->assertFalse($badResp->json('success'));

        // 2. Admin logs in -> 200 OK with Bearer token
        $loginResp = $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);
        $loginResp->assertStatus(200);
        $this->assertTrue($loginResp->json('success'));
        $token = $loginResp->json('data.token');
        $this->assertNotEmpty($token);

        $headers = ['Authorization' => "Bearer {$token}"];

        // 3. Get profile
        $profileResp = $this->getJson('/api/v1/admin/profile', $headers);
        $profileResp->assertStatus(200);
        $this->assertEquals($admin->email, $profileResp->json('data.email'));

        // 4. Update profile
        $updateResp = $this->postJson('/api/v1/admin/profile', [
            'name' => 'Master Administrator',
            'phone' => '+1999888777',
        ], $headers);
        $updateResp->assertStatus(200);
        $this->assertEquals('Master Administrator', $updateResp->json('data.name'));

        // 5. Logout
        $logoutResp = $this->postJson('/api/v1/admin/logout', [], $headers);
        $logoutResp->assertStatus(200);
    }

    public function test_staff_user_management_and_account_suspension()
    {
        $admin = $this->createSuperAdmin();
        Sanctum::actingAs($admin);

        // 1. Create Sub-Admin
        $staffEmail = 'subadmin_' . uniqid() . '@tradeplatform.com';
        $createResp = $this->postJson('/api/v1/admin/users', [
            'name' => 'Logistics Moderator',
            'email' => $staffEmail,
            'password' => 'staffPass123!',
            'phone' => '+1122334455',
            'admin_role' => 'sub_admin',
            'admin_permissions' => ['suppliers', 'disputes'],
        ]);
        $createResp->assertStatus(201);
        $this->assertTrue($createResp->json('success'));
        $staffId = $createResp->json('data.id');

        // 2. List users with role filter
        $listResp = $this->getJson('/api/v1/admin/users?role=sub_admin');
        $listResp->assertStatus(200);
        $this->assertTrue($listResp->json('success'));
        $this->assertGreaterThanOrEqual(1, count($listResp->json('data.data')));

        // 3. Suspend staff member
        $statusResp = $this->postJson("/api/v1/admin/users/{$staffId}/status", [
            'status' => 'suspended',
            'reason' => 'Security review pending',
        ]);
        $statusResp->assertStatus(200);
        $this->assertEquals(1, $statusResp->json('data.banned'));

        // 4. Verify suspended staff cannot access admin endpoints
        $staffUser = User::find($staffId);
        Sanctum::actingAs($staffUser);
        $deniedResp = $this->getJson('/api/v1/admin/profile');
        $deniedResp->assertStatus(403);
    }

    public function test_supplier_verification_approval_and_kyc_workflow()
    {
        $admin = $this->createSuperAdmin();
        Sanctum::actingAs($admin);

        // Setup supplier user and company
        $supplier = $this->createRegularUser('seller');
        $company = Company::create([
            'user_id' => $supplier->id,
            'name' => 'Royal Spice Exports Ltd',
            'company_type' => 'exporter',
            'trade_type' => 'international',
            'country' => 'India',
            'iec_code' => 'IEC' . rand(100000, 999999),
            'tax_number' => '27AAACR1234F1Z1',
            'verification_status' => 'pending',
            'verification_notes' => 'Awaiting GST and IEC document check',
        ]);

        // 1. List supplier moderation queue
        $queueResp = $this->getJson('/api/v1/admin/suppliers?status=pending');
        $queueResp->assertStatus(200);
        $this->assertTrue($queueResp->json('success'));

        // 2. View supplier dossier
        $detailResp = $this->getJson("/api/v1/admin/suppliers/{$company->id}");
        $detailResp->assertStatus(200);
        $this->assertEquals('Royal Spice Exports Ltd', $detailResp->json('data.name'));

        // 3. Approve supplier verification
        $verifyResp = $this->postJson("/api/v1/admin/suppliers/{$company->id}/verify", [
            'status' => 'verified',
            'notes' => 'All trade licenses, IEC, and GST verified against customs directory',
        ]);
        $verifyResp->assertStatus(200);
        $this->assertEquals('verified', $verifyResp->json('data.verification_status'));

        // 4. Audit log check
        $this->assertDatabaseHas('b2b_audit_logs', [
            'action' => 'supplier_verification',
            'entity_type' => 'Company',
            'entity_id' => (string) $company->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_lot_pricing_approval_queue_and_moderation()
    {
        $admin = $this->createSuperAdmin();
        Sanctum::actingAs($admin);

        $supplier = $this->createRegularUser('seller');
        $category = Category::firstOrCreate(['id' => 999], ['name' => 'Agri']);

        $product = Product::create([
            'name' => 'Organic Turmeric ' . uniqid(),
            'user_id' => $supplier->id,
            'category_id' => $category->id,
            'unit' => 'MT',
            'unit_price' => 1200,
            'published' => 1,
            'publication_status' => 'active',
        ]);

        $lot = ProductLot::create([
            'lot_code' => 'LOT-MOD-' . uniqid(),
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'warehouse_location' => 'Kochi Hub',
            'quantity_received' => 100,
            'quantity_reserved' => 0,
            'quantity_dispatched' => 0,
            'quantity_available' => 100,
            'unit' => 'MT',
            'unit_price' => 1500, // Proposed price hike
            'currency' => 'USD',
            'approval_status' => 'pending', // Pending admin approval
        ]);

        PriceHistory::create([
            'lot_id' => $lot->id,
            'old_price' => 1200,
            'new_price' => 1500,
            'currency' => 'USD',
            'reason' => 'Increased export tariffs',
            'actor_id' => $supplier->id,
            'approval_status' => 'pending',
        ]);

        // 1. Pending pricing queue
        $queueResp = $this->getJson('/api/v1/admin/lots/pending-approval');
        $queueResp->assertStatus(200);
        $this->assertTrue($queueResp->json('success'));

        // 2. Approve lot price
        $approveResp = $this->postJson("/api/v1/admin/lots/{$lot->id}/approval", [
            'decision' => 'approved',
            'notes' => 'Price hike aligned with global commodity trends',
        ]);
        $approveResp->assertStatus(200);
        $this->assertEquals('approved', $approveResp->json('data.approval_status'));

        // 3. Product listing moderation
        $prodModResp = $this->postJson("/api/v1/admin/products/{$product->id}/approval", [
            'status' => 'active',
            'notes' => 'Product approved for domestic and international catalog display',
        ]);
        $prodModResp->assertStatus(200);
        $this->assertEquals('active', $prodModResp->json('data.publication_status'));
    }

    public function test_category_and_hs_code_master_catalogue()
    {
        $admin = $this->createSuperAdmin();
        Sanctum::actingAs($admin);

        // 1. Create Category with HS Code
        $catResp = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Spices & Condiments ' . uniqid(),
            'parent_id' => 0,
            'hs_code' => '0910.30.00',
            'standard_duty_rate' => 5.00,
            'order_level' => 1,
        ]);
        $catResp->assertStatus(201);
        $this->assertTrue($catResp->json('success'));
        $categoryId = $catResp->json('data.id');

        // 2. Update Category
        $updateCatResp = $this->postJson("/api/v1/admin/categories/{$categoryId}", [
            'name' => 'Spices, Seasonings & Herbs',
            'standard_duty_rate' => 6.50,
        ]);
        $updateCatResp->assertStatus(200);
        $this->assertEquals('Spices, Seasonings & Herbs', $updateCatResp->json('data.name'));

        // 3. Create HS Code Master
        $code = '0910.' . rand(10, 99) . '.' . rand(10, 99);
        $hsCodeResp = $this->postJson('/api/v1/admin/hs-codes', [
            'hs_code' => $code,
            'description' => 'Turmeric (Curcuma), fresh or dried',
            'category_id' => $categoryId,
            'chapter' => '09',
            'heading' => '0910',
            'basic_customs_duty_pct' => 10.00,
            'igst_pct' => 5.00,
            'cess_pct' => 1.50,
            'policy_status' => 'free',
            'is_active' => true,
        ]);
        $hsCodeResp->assertStatus(201);
        $this->assertTrue($hsCodeResp->json('success'));
        $this->assertEquals($code, $hsCodeResp->json('data.hs_code'));

        // 4. Query HS Codes
        $searchResp = $this->getJson('/api/v1/admin/hs-codes?search=Turmeric');
        $searchResp->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($searchResp->json('data.data')));
    }

    public function test_order_dispute_arbitration_and_resolution()
    {
        $admin = $this->createSuperAdmin();
        $buyer = $this->createRegularUser('customer');
        $supplier = $this->createRegularUser('seller');

        // Setup test order
        $order = B2bOrder::create([
            'order_number' => 'ORD-' . uniqid(),
            'quotation_id' => null,
            'buyer_id' => $buyer->id,
            'supplier_id' => $supplier->id,
            'total_amount' => 50000.00,
            'amount_paid' => 50000.00,
            'balance_due' => 0.00,
            'currency' => 'USD',
            'status' => 'delivered',
            'payment_status' => 'fully_paid',
        ]);

        // Buyer opens dispute regarding damaged consignment
        $dispute = B2bDispute::create([
            'dispute_number' => 'DSP-' . uniqid(),
            'order_id' => $order->id,
            'raised_by_id' => $buyer->id,
            'against_party_id' => $supplier->id,
            'dispute_type' => 'damaged_goods',
            'status' => 'opened',
            'title' => 'Packaging damaged during marine transit',
            'description' => '20% of containers experienced sea water ingress.',
            'claim_amount' => 10000.00,
            'currency' => 'USD',
        ]);

        Sanctum::actingAs($admin);

        // 1. List disputes
        $listResp = $this->getJson('/api/v1/admin/disputes?status=opened');
        $listResp->assertStatus(200);
        $this->assertTrue($listResp->json('success'));

        // 2. View dispute dossier
        $showResp = $this->getJson("/api/v1/admin/disputes/{$dispute->id}");
        $showResp->assertStatus(200);
        $this->assertEquals('damaged_goods', $showResp->json('data.dispute_type'));

        // 3. Arbitrate and issue partial refund ruling
        $resolveResp = $this->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", [
            'action' => 'partial_refund',
            'settlement_amount' => 8500.00,
            'resolution_notes' => 'Surveyor report confirmed sea water damage to 17% of bags. Exporter agreed to reimburse $8,500.',
        ]);
        $resolveResp->assertStatus(200);
        $this->assertEquals('resolved', $resolveResp->json('data.status'));
        $this->assertEquals('partial_refund', $resolveResp->json('data.resolution_action'));
        $this->assertEquals(8500.00, (float) $resolveResp->json('data.settlement_amount'));

        // 4. Verify system audit log recorded dispute resolution
        $this->assertDatabaseHas('b2b_audit_logs', [
            'action' => 'dispute_resolution',
            'entity_type' => 'B2bDispute',
            'entity_id' => (string) $dispute->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_system_audit_logs_and_immutability()
    {
        $admin = $this->createSuperAdmin();
        Sanctum::actingAs($admin);

        // 1. Query audit logs list
        $logsResp = $this->getJson('/api/v1/admin/audit-logs?per_page=10');
        $logsResp->assertStatus(200);
        $this->assertTrue($logsResp->json('success'));
        $logs = $logsResp->json('data.data');
        $this->assertNotEmpty($logs);
        $firstLogId = $logs[0]['id'];

        // 2. View specific audit log entry
        $detailResp = $this->getJson("/api/v1/admin/audit-logs/{$firstLogId}");
        $detailResp->assertStatus(200);
        $this->assertArrayHasKey('diff_summary', $detailResp->json('data'));
        $this->assertArrayHasKey('ip_address', $detailResp->json('data'));

        // 3. SRS Section 19.3: Verify audit log immutability (cannot be updated or deleted)
        $auditRecord = B2bAuditLog::find($firstLogId);
        $this->expectException(\RuntimeException::class);
        $auditRecord->update(['reason' => 'Tampering attempt']);
    }
}
