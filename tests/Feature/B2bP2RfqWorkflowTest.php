<?php

namespace Tests\Feature;

use App\Models\B2B\Rfq;
use App\Models\B2B\RfqInvitation;
use App\Models\B2B\RfqMessage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** P2-2: RFQ draft save, supplier rejection, admin assignment, multi-supplier broadcast and invitation tracking. */
class B2bP2RfqWorkflowTest extends TestCase
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
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'email_verified_at' => now(),
        ]);
    }

    private function product(User $seller): Product
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Test Commodity ' . uniqid(),
            'slug' => 'test-commodity-' . uniqid(),
            'parent_id' => 0,
            'level' => 0,
            'digital' => 0,
            'featured' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Product::create([
            'name' => 'Basmati Rice ' . uniqid(),
            'slug' => 'basmati-rice-' . uniqid(),
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'added_by' => 'seller',
            'user_id' => $seller->id,
            'category_id' => $categoryId,
            'origin_country' => 'India',
            'unit' => 'kg',
            'unit_price' => 1200,
            'min_qty' => 100,
            'publication_status' => 'active',
            'published' => 1,
            'approved' => 1,
        ]);
    }

    private function rfq(User $buyer, User $supplier, array $overrides = []): Rfq
    {
        return Rfq::create(array_merge([
            'rfq_number' => 'RFQ-' . strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'supplier_id' => $supplier->id,
            'product_id' => $this->product($supplier)->id,
            'requested_quantity' => 10,
            'unit' => 'MT',
            'target_price' => 900,
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'destination_port_or_address' => 'Jebel Ali Port',
            'currency' => 'USD',
            'status' => 'submitted',
        ], $overrides));
    }

    public function test_rfq_draft_save_updates_only_supplied_fields(): void
    {
        $buyer = $this->user('customer');
        $supplier = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplier);

        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/save-draft", [
            'draft_notes' => 'Waiting for final price confirmation',
            'notes' => 'Updated RFQ draft details',
            'requested_quantity' => 20,
            'unit' => 'MT',
            'target_price' => 950,
            'quality_specification' => 'Grade A Premium',
            'destination_country' => 'UAE',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Draft saved successfully');
        $response->assertJsonPath('data.id', $rfq->id);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.requested_quantity', '20.000');
        $response->assertJsonPath('data.unit', 'MT');
        $response->assertJsonPath('data.target_price', '950.00');
        $response->assertJsonPath('data.draft_notes', 'Waiting for final price confirmation');
        $response->assertJsonPath('data.notes', 'Updated RFQ draft details');
        $this->assertNotNull($response->json('data.draft_saved_at'));

        // Omitted fields must stay untouched.
        $response->assertJsonPath('data.destination_port_or_address', 'Jebel Ali Port');
        $response->assertJsonPath('data.currency', 'USD');

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfq->id,
            'status' => 'draft',
            'draft_notes' => 'Waiting for final price confirmation',
            'quality_specification' => 'Grade A Premium',
        ]);

        // A closed RFQ cannot be drafted again.
        $closed = $this->rfq($buyer, $supplier, ['status' => 'closed']);
        $this->postJson("/api/v1/importer/rfqs/{$closed->id}/save-draft", ['draft_notes' => 'nope'])
            ->assertStatus(400);

        // Another buyer cannot touch this RFQ.
        $otherBuyer = $this->user('customer');
        Sanctum::actingAs($otherBuyer);
        $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/save-draft", ['draft_notes' => 'hijack'])
            ->assertStatus(404);
    }

    public function test_supplier_can_reject_rfq_with_reason(): void
    {
        $buyer = $this->user('customer');
        $supplier = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplier);

        Sanctum::actingAs($supplier);
        $reason = 'Unable to supply the requested quantity within the required delivery period.';

        $response = $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/reject", [
            'rejection_reason' => $reason,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'RFQ rejected successfully');
        $response->assertJsonPath('data.id', $rfq->id);
        $response->assertJsonPath('data.status', 'rejected');
        $response->assertJsonPath('data.rejection_reason', $reason);
        $response->assertJsonPath('data.supplier_id', $supplier->id);
        $this->assertNotNull($response->json('data.updated_at'));

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfq->id,
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $fresh = $rfq->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($reason, $fresh->rejection_reason);

        // Missing reason is rejected by validation.
        $pending = $this->rfq($buyer, $supplier);
        $this->postJson("/api/v1/exporter/rfqs/{$pending->id}/reject", [])
            ->assertStatus(422);

        // Another supplier cannot reject someone else's RFQ (403: RFQ exists, access denied).
        $otherSupplier = $this->user('seller');
        Sanctum::actingAs($otherSupplier);
        $this->postJson("/api/v1/exporter/rfqs/{$pending->id}/reject", ['rejection_reason' => 'Not mine'])
            ->assertStatus(403);
    }

    public function test_admin_assigns_rfq_to_back_office_user(): void
    {
        $buyer = $this->user('customer');
        $supplier = $this->user('seller');
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        $rfq = $this->rfq($buyer, $supplier);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/rfqs/{$rfq->id}/assign", [
            'assigned_to' => $staff->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'RFQ assigned successfully');
        $response->assertJsonPath('data.id', $rfq->id);
        $response->assertJsonPath('data.assigned_to', $staff->id);
        $response->assertJsonPath('data.assigned_by', $admin->id);
        $this->assertNotNull($response->json('data.assigned_at'));
        $response->assertJsonPath('data.status', 'submitted');

        $fresh = $rfq->fresh();
        $this->assertSame($staff->id, (int) $fresh->assigned_to);
        $this->assertSame($admin->id, (int) $fresh->assigned_by);
        $this->assertNotNull($fresh->assigned_at);

        // Non back-office users cannot be assignees.
        $this->postJson("/api/v1/admin/rfqs/{$rfq->id}/assign", [
            'assigned_to' => $supplier->id,
        ])->assertStatus(422);

        // Non-admins are blocked.
        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/admin/rfqs/{$rfq->id}/assign", [
            'assigned_to' => $staff->id,
        ])->assertStatus(403);
    }

    public function test_multi_supplier_broadcast_creates_unique_invitations(): void
    {
        $buyer = $this->user('customer');
        $supplierA = $this->user('seller');
        $supplierB = $this->user('seller');
        $supplierC = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplierA);

        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/broadcast", [
            'supplier_ids' => [$supplierA->id, $supplierB->id, $supplierC->id],
            'message' => 'Please provide your best quotation for this product.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'RFQ broadcast to suppliers successfully');
        $response->assertJsonPath('data.rfq.id', $rfq->id);
        $response->assertJsonPath('data.invited_supplier_ids', [$supplierA->id, $supplierB->id, $supplierC->id]);
        $response->assertJsonPath('data.invitation_count', 3);

        $this->assertSame(3, RfqInvitation::where('rfq_id', $rfq->id)->count());
        $this->assertDatabaseHas('rfq_messages', [
            'rfq_id' => $rfq->id,
            'sender_id' => $buyer->id,
            'message' => 'Please provide your best quotation for this product.',
        ]);

        // Re-broadcasting with duplicated ids must not create duplicates.
        $again = $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/broadcast", [
            'supplier_ids' => [$supplierA->id, $supplierA->id, $supplierB->id],
        ]);
        $again->assertStatus(200);
        $again->assertJsonPath('data.invitation_count', 3);
        $this->assertSame(3, RfqInvitation::where('rfq_id', $rfq->id)->count());

        // Closed / rejected / cancelled / converted RFQs cannot be broadcast.
        foreach (['closed', 'rejected', 'cancelled', 'converted'] as $state) {
            $blocked = $this->rfq($buyer, $supplierA, ['status' => $state]);
            $this->postJson("/api/v1/importer/rfqs/{$blocked->id}/broadcast", [
                'supplier_ids' => [$supplierB->id],
            ])->assertStatus(400);
            $this->assertSame(0, RfqInvitation::where('rfq_id', $blocked->id)->count());
        }
    }

    public function test_invitation_status_tracking_summary_and_ownership(): void
    {
        $buyer = $this->user('customer');
        $supplierA = $this->user('seller');
        $supplierB = $this->user('seller');
        $supplierC = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplierA);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/broadcast", [
            'supplier_ids' => [$supplierA->id, $supplierB->id, $supplierC->id],
            'message' => 'Quotes please',
        ])->assertStatus(200);

        $invitations = RfqInvitation::where('rfq_id', $rfq->id)->orderBy('id')->get()->keyBy('supplier_id');

        // Supplier B views, supplier C accepts, supplier A declines.
        Sanctum::actingAs($supplierB);
        $this->postJson("/api/v1/exporter/rfq-invitations/{$invitations[$supplierB->id]->id}/respond", [
            'action' => 'view',
        ])->assertStatus(200);

        Sanctum::actingAs($supplierC);
        $this->postJson("/api/v1/exporter/rfq-invitations/{$invitations[$supplierC->id]->id}/respond", [
            'action' => 'accept',
        ])->assertStatus(200);

        Sanctum::actingAs($supplierA);
        $this->postJson("/api/v1/exporter/rfq-invitations/{$invitations[$supplierA->id]->id}/respond", [
            'action' => 'decline',
            'reason' => 'Out of stock',
        ])->assertStatus(200);

        Sanctum::actingAs($buyer);
        $response = $this->getJson("/api/v1/importer/rfqs/{$rfq->id}/invitations");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'RFQ invitations retrieved successfully');
        $response->assertJsonPath('data.rfq_id', $rfq->id);
        $response->assertJsonPath('data.summary.total', 3);
        $response->assertJsonPath('data.summary.invited', 0);
        $response->assertJsonPath('data.summary.viewed', 1);
        $response->assertJsonPath('data.summary.responded', 1);
        $response->assertJsonPath('data.summary.declined', 1);
        $this->assertCount(3, $response->json('data.invitations'));

        $byStatus = collect($response->json('data.invitations'))->keyBy('status');
        $this->assertSame($supplierB->email, $byStatus['viewed']['supplier']['email']);
        $this->assertSame($supplierC->email, $byStatus['responded']['supplier']['email']);
        $this->assertSame($supplierA->email, $byStatus['declined']['supplier']['email']);
        $this->assertSame($rfq->id, $byStatus['viewed']['rfq_id']);

        // Another buyer cannot read this RFQ's invitations.
        $otherBuyer = $this->user('customer');
        Sanctum::actingAs($otherBuyer);
        $this->getJson("/api/v1/importer/rfqs/{$rfq->id}/invitations")->assertStatus(404);
    }

    public function test_multiple_broadcast_suppliers_can_each_submit_offers(): void
    {
        $buyer = $this->user('customer');
        $supplierA = $this->user('seller');
        $supplierB = $this->user('seller');
        $outsider = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplierA);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/broadcast", [
            'supplier_ids' => [$supplierA->id, $supplierB->id],
        ])->assertStatus(200);

        // Second invited supplier (not the RFQ's original supplier) can price it.
        Sanctum::actingAs($supplierB);
        $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/offers", [
            'offers' => [['price' => 930, 'incoterm' => 'FOB', 'lead_time_days' => 20]],
        ])->assertStatus(200);

        // Original supplier also submits an offer.
        Sanctum::actingAs($supplierA);
        $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/offers", [
            'offers' => [['price' => 910, 'incoterm' => 'CIF', 'lead_time_days' => 25]],
        ])->assertStatus(200);

        $offers = $rfq->fresh()->dec013_export_offers;
        $this->assertCount(2, $offers);
        $this->assertSame($supplierB->id, $offers[0]['supplier_id']);
        $this->assertSame($supplierA->id, $offers[1]['supplier_id']);
        $this->assertSame(930, $offers[0]['price']);

        // Suppliers that answered are tracked as responded in the invitation summary.
        $this->assertSame(2, RfqInvitation::where('rfq_id', $rfq->id)->where('status', 'responded')->count());

        // A supplier that was never invited cannot submit an offer.
        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/offers", [
            'offers' => [['price' => 500]],
        ])->assertStatus(403);
    }

    public function test_importer_can_create_rfq_on_another_sellers_product_then_save_draft(): void
    {
        $buyer = $this->user('customer');
        $seller = $this->user('seller');
        $product = $this->product($seller);

        Sanctum::actingAs($buyer);

        $created = $this->postJson('/api/v1/importer/rfqs', [
            'product_id' => $product->id,
            'requested_quantity' => 10,
            'unit' => 'MT',
            'target_price' => 900,
            'trade_type' => 'international',
            'destination_country' => 'UAE',
            'destination_port_or_address' => 'Jebel Ali Port',
            'quality_specification' => 'Grade A Premium',
            'notes' => 'Testing RFQ creation',
        ]);

        $created->assertStatus(201);
        $created->assertJsonPath('success', true);
        $created->assertJsonPath('data.buyer_id', $buyer->id);
        $created->assertJsonPath('data.supplier_id', $seller->id);
        $created->assertJsonPath('data.product_id', $product->id);

        $rfqId = $created->json('data.id');

        // The RFQ just created can be drafted, and the draft response carries the expected payload.
        $draft = $this->postJson("/api/v1/importer/rfqs/{$rfqId}/save-draft", [
            'draft_notes' => 'Waiting for final price confirmation',
            'notes' => 'Updated RFQ draft details',
            'requested_quantity' => 20,
            'unit' => 'MT',
            'target_price' => 950,
            'quality_specification' => 'Grade A Premium',
            'destination_country' => 'UAE',
        ]);

        $draft->assertStatus(200);
        $draft->assertJsonPath('message', 'Draft saved successfully');
        $draft->assertJsonPath('data.id', $rfqId);
        $draft->assertJsonPath('data.status', 'draft');
        $draft->assertJsonPath('data.requested_quantity', '20.000');
        $draft->assertJsonPath('data.target_price', '950.00');
        $draft->assertJsonPath('data.draft_notes', 'Waiting for final price confirmation');
        $this->assertNotNull($draft->json('data.draft_saved_at'));
    }

    public function test_invited_supplier_sees_rfq_and_can_decline_without_closing_it(): void
    {
        $buyer = $this->user('customer');
        $supplierA = $this->user('seller');
        $supplierB = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplierA);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/broadcast", [
            'supplier_ids' => [$supplierA->id, $supplierB->id],
        ])->assertStatus(200);

        // A broadcast-invited supplier can list and open the RFQ.
        Sanctum::actingAs($supplierB);
        $list = $this->getJson('/api/v1/exporter/rfqs?per_page=100');
        $list->assertStatus(200);
        $this->assertTrue(collect($list->json('data.data'))->pluck('id')->contains($rfq->id));
        $this->getJson("/api/v1/exporter/rfqs/{$rfq->id}")->assertStatus(200);

        // Declining only closes this supplier's invitation, not the whole RFQ.
        $decline = $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/reject", [
            'rejection_reason' => 'Out of stock for this lot',
        ]);
        $decline->assertStatus(200);
        $decline->assertJsonPath('message', 'RFQ declined by supplier');

        $this->assertSame('submitted', $rfq->fresh()->status);
        $this->assertDatabaseHas('rfq_invitations', [
            'rfq_id' => $rfq->id,
            'supplier_id' => $supplierB->id,
            'status' => 'declined',
        ]);

        // The other supplier can still price the RFQ.
        Sanctum::actingAs($supplierA);
        $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/offers", [
            'offers' => [['price' => 905]],
        ])->assertStatus(200);

        // Declining twice is rejected.
        Sanctum::actingAs($supplierB);
        $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/reject", [
            'rejection_reason' => 'Again',
        ])->assertStatus(409);
    }

    public function test_reject_distinguishes_missing_rfq_from_foreign_rfq(): void
    {
        $buyer = $this->user('customer');
        $supplier = $this->user('seller');
        $outsider = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplier);

        Sanctum::actingAs($outsider);

        // Existing RFQ that this exporter is not part of: 403 with an actionable message.
        $foreign = $this->postJson("/api/v1/exporter/rfqs/{$rfq->id}/reject", [
            'rejection_reason' => 'Not mine',
        ]);
        $foreign->assertStatus(403);
        $this->assertStringContainsString('not a supplier on RFQ', $foreign->json('message'));

        // Unknown id: 404 that says the id itself does not exist.
        $missing = $this->postJson('/api/v1/exporter/rfqs/99999999/reject', [
            'rejection_reason' => 'No such RFQ',
        ]);
        $missing->assertStatus(404);
        $this->assertStringContainsString('no RFQ with id 99999999', $missing->json('message'));
    }

    public function test_accepting_invitation_keeps_rfq_supplier_shared(): void
    {
        $buyer = $this->user('customer');
        $supplierA = $this->user('seller');
        $supplierB = $this->user('seller');
        $rfq = $this->rfq($buyer, $supplierA);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/importer/rfqs/{$rfq->id}/broadcast", [
            'supplier_ids' => [$supplierB->id],
        ])->assertStatus(200);

        $invitation = RfqInvitation::where('rfq_id', $rfq->id)
            ->where('supplier_id', $supplierB->id)
            ->firstOrFail();

        Sanctum::actingAs($supplierB);
        $this->postJson("/api/v1/exporter/rfq-invitations/{$invitation->id}/respond", [
            'action' => 'accept',
        ])->assertStatus(200);

        // Accepting must not steal the RFQ from the original supplier.
        $this->assertSame($supplierA->id, (int) $rfq->fresh()->supplier_id);
        $this->assertSame('responded', $invitation->fresh()->status);
    }
}
