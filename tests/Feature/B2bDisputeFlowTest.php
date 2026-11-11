<?php

namespace Tests\Feature;

use App\Models\B2B\B2bDisputeMessage;
use App\Models\B2B\B2bOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P2-BUG-03: buyer / supplier dispute access endpoints.
 */
class B2bDisputeFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL points at a sub-directory (/ecommerce); the test kernel mounts
        // the routes at the document root, so pin the request root URL.
        $this->app['url']->forceRootUrl('http://localhost');
    }

    protected function makeUser(string $type): User
    {
        return User::create([
            'name' => ucfirst($type) . ' ' . uniqid(),
            'email' => $type . '_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ]);
    }

    protected function makeOrder(User $buyer, User $supplier): B2bOrder
    {
        return B2bOrder::create([
            'order_number' => 'ORD-' . uniqid(),
            'buyer_id' => $buyer->id,
            'supplier_id' => $supplier->id,
            'total_amount' => 25000.00,
            'amount_paid' => 0.00,
            'balance_due' => 25000.00,
            'currency' => 'USD',
            'status' => 'shipped',
            'payment_status' => 'unpaid',
        ]);
    }

    protected function api(string $path): string
    {
        return '/api/v1/' . ltrim($path, '/');
    }

    protected function fakePdf(string $name = 'evidence.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    public function test_full_dispute_flow_between_importer_and_exporter()
    {
        Storage::fake('public');

        $buyer = $this->makeUser('customer');
        $supplier = $this->makeUser('seller');
        $outsider = $this->makeUser('customer');
        $order = $this->makeOrder($buyer, $supplier);

        // 1. Buyer raises the dispute with evidence.
        Sanctum::actingAs($buyer);
        $createResp = $this->postJson($this->api('importer/disputes'), [
            'order_id' => $order->id,
            'dispute_type' => 'damaged_goods',
            'title' => 'Water damaged cartons in container MSKU9021841',
            'description' => '12 cartons arrived water damaged and unusable.',
            'claim_amount' => 4200,
            'currency' => 'USD',
            'evidence' => [$this->fakePdf('damage-report.pdf')],
        ]);

        $createResp->assertStatus(201);
        $createResp->assertJsonPath('data.status', 'opened');
        $this->assertNotEmpty($createResp->json('data.dispute_number'));
        $this->assertCount(1, $createResp->json('data.evidence_attachments'));

        $disputeId = $createResp->json('data.id');

        // 2. Duplicate live dispute of the same type is rejected.
        $this->postJson($this->api('importer/disputes'), [
            'order_id' => $order->id,
            'dispute_type' => 'damaged_goods',
            'title' => 'Duplicate',
            'description' => 'Duplicate claim',
        ])->assertStatus(409);

        // 3. Outsider buyer cannot see or touch the dispute.
        Sanctum::actingAs($outsider);
        $this->getJson($this->api("importer/disputes/{$disputeId}"))->assertStatus(404);
        $this->postJson($this->api("importer/disputes/{$disputeId}/messages"), ['message' => 'hi'])
            ->assertStatus(404);

        // 4. Exporter sees the dispute against their order and replies / counter-claims.
        Sanctum::actingAs($supplier);
        $indexResp = $this->getJson($this->api('exporter/disputes'));
        $indexResp->assertStatus(200);
        $this->assertSame($disputeId, $indexResp->json('data.data.0.id'));

        $replyResp = $this->postJson($this->api("exporter/disputes/{$disputeId}/reply"), [
            'message' => 'Damage happened in transit after a clean on-board bill of lading.',
            'counter_claim_amount' => 1500,
            'counter_claim_notes' => 'Surveyor report attached.',
            'evidence' => [$this->fakePdf('surveyor-report.pdf')],
        ]);
        $replyResp->assertStatus(201);
        $replyResp->assertJsonPath('data.dispute.status', 'under_investigation');
        $this->assertCount(2, $replyResp->json('data.dispute.evidence_attachments'));

        // 5. Both parties exchange further messages.
        $this->postJson($this->api("exporter/disputes/{$disputeId}/messages"), [
            'message' => 'Please share the container inspection photos.',
        ])->assertStatus(201);

        Sanctum::actingAs($buyer);
        $this->postJson($this->api("importer/disputes/{$disputeId}/messages"), [
            'message' => 'Photos uploaded to the evidence folder.',
        ])->assertStatus(201);

        $this->postJson($this->api("importer/disputes/{$disputeId}/evidence"), [
            'files' => [$this->fakePdf('photos.pdf')],
            'note' => 'Container inspection photos',
        ])->assertStatus(201);

        // 6. Buyer sees the whole public thread:
        //    raise + reply + exporter message + buyer message + evidence note.
        $showResp = $this->getJson($this->api("importer/disputes/{$disputeId}"));
        $showResp->assertStatus(200);
        $this->assertCount(5, $showResp->json('data.messages'));

        // 7. Internal admin notes never leak to trading parties.
        B2bDisputeMessage::create([
            'dispute_id' => $disputeId,
            'sender_id' => $supplier->id,
            'message' => 'INTERNAL: fraud risk flagged',
            'is_internal_admin_note' => true,
        ]);
        $this->assertCount(5, $this->getJson($this->api("importer/disputes/{$disputeId}"))->json('data.messages'));
        $this->assertStringNotContainsString('INTERNAL: fraud risk flagged', $this->getJson($this->api("importer/disputes/{$disputeId}"))->getContent());

        // 8. Admin resolves the dispute; the resolution note lands in the party thread.
        $admin = $this->makeUser('admin');
        $admin->admin_role = 'super_admin';
        $admin->save();

        Sanctum::actingAs($admin);
        $this->postJson($this->api("admin/disputes/{$disputeId}/resolve"), [
            'action' => 'partial_refund',
            'settlement_amount' => 2500,
            'resolution_notes' => 'Transit damage confirmed, 60% refund awarded.',
        ])->assertStatus(200);

        // 9. After resolution, parties can read but no longer post.
        Sanctum::actingAs($buyer);
        $resolvedResp = $this->getJson($this->api("importer/disputes/{$disputeId}"));
        $resolvedResp->assertStatus(200);
        $resolvedResp->assertJsonPath('data.status', 'resolved');
        $this->assertCount(6, $resolvedResp->json('data.messages'));

        $this->postJson($this->api("importer/disputes/{$disputeId}/messages"), ['message' => 'late message'])
            ->assertStatus(400);

        Sanctum::actingAs($supplier);
        $this->postJson($this->api("exporter/disputes/{$disputeId}/reply"), ['message' => 'late reply'])
            ->assertStatus(400);
    }

    public function test_dispute_endpoints_require_authentication_and_isolate_parties()
    {
        $this->getJson($this->api('importer/disputes'))->assertStatus(401);
        $this->getJson($this->api('exporter/disputes'))->assertStatus(401);

        $buyer = $this->makeUser('customer');
        $supplier = $this->makeUser('seller');
        $order = $this->makeOrder($buyer, $supplier);

        Sanctum::actingAs($buyer);
        $dispute = $this->postJson($this->api('importer/disputes'), [
            'order_id' => $order->id,
            'dispute_type' => 'shipment_delay',
            'title' => 'Vessel departed 3 weeks late',
            'description' => 'Shipment delayed beyond the agreed delivery window.',
        ])->assertStatus(201)->json('data');

        // Buyer is not a party on the exporter surface.
        $this->getJson($this->api("exporter/disputes/{$dispute['id']}"))->assertStatus(404);

        // Supplier cannot raise importer-side disputes.
        Sanctum::actingAs($supplier);
        $this->postJson($this->api('importer/disputes'), [
            'order_id' => $order->id,
            'dispute_type' => 'short_quantity',
            'title' => 'Nope',
            'description' => 'Supplier should not be able to open a buyer dispute.',
        ])->assertStatus(404);

        $this->assertDatabaseHas('b2b_disputes', ['id' => $dispute['id'], 'status' => 'opened']);
    }
}
