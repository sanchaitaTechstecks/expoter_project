<?php

namespace Tests\Feature;

use App\Models\B2B\B2bBulkImportBatch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** P2-3: DEC-002 approval, gallery upload, archive lifecycle and CSV bulk import. */
class B2bP3ProductManagementTest extends TestCase
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

    private function category(?string $name = null): int
    {
        return DB::table('categories')->insertGetId([
            'name' => $name ?? 'Test Commodity ' . uniqid(),
            'slug' => 'test-commodity-' . uniqid(),
            'parent_id' => 0,
            'level' => 0,
            'digital' => 0,
            'featured' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createProduct(User $seller, array $overrides = []): array
    {
        Sanctum::actingAs($seller);

        $response = $this->postJson('/api/v1/exporter/products', array_merge([
            'name' => 'Basmati Rice ' . uniqid(),
            'category_id' => $this->category(),
            'origin_country' => 'India',
            'unit' => 'kg',
            'unit_price' => 1200,
            'min_qty' => 100,
        ], $overrides));

        return [$response, $response->json('data.id')];
    }

    public function test_new_product_is_created_pending_approval(): void
    {
        $seller = $this->user('seller');

        [$response, $productId] = $this->createProduct($seller);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Product created successfully and submitted for approval');
        $response->assertJsonPath('data.approved', 0);
        $response->assertJsonPath('data.published', 0);
        $response->assertJsonPath('data.publication_status', 'pending_approval');

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'approved' => 0,
            'published' => 0,
            'publication_status' => 'pending_approval',
        ]);
    }

    public function test_supplier_cannot_self_approve_or_publish(): void
    {
        $seller = $this->user('seller');
        [, $productId] = $this->createProduct($seller);

        Sanctum::actingAs($seller);

        // Self approval fields are prohibited by validation.
        $this->postJson("/api/v1/exporter/products/{$productId}/update", [
            'approved' => 1,
            'published' => 1,
            'publication_status' => 'active',
        ])->assertStatus(422);

        // The admin moderation endpoint is closed to sellers.
        $this->postJson("/api/v1/admin/products/{$productId}/approval", ['status' => 'active'])
            ->assertStatus(403);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'approved' => 0,
            'published' => 0,
            'publication_status' => 'pending_approval',
        ]);
    }

    public function test_admin_approval_publishes_product_and_pending_products_are_hidden(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $admin = $this->user('admin');

        [$created, $productId] = $this->createProduct($seller);

        // Pending product is invisible in the marketplace, on detail and in the listing.
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/importer/products/{$productId}")->assertStatus(404);
        $listingIds = collect($this->getJson('/api/v1/importer/products?per_page=100')->json('data.data') ?? [])->pluck('id');
        $this->assertFalse($listingIds->contains($productId));

        // Admin approves.
        Sanctum::actingAs($admin);
        $approve = $this->postJson("/api/v1/admin/products/{$productId}/approval", ['status' => 'active']);
        $approve->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'approved' => 1,
            'published' => 1,
            'publication_status' => 'active',
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/importer/products/{$productId}")->assertStatus(200);

        // Rejection keeps the product out of the marketplace.
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/products/{$productId}/approval", ['status' => 'rejected'])
            ->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'approved' => 0,
            'published' => 0,
            'publication_status' => 'rejected',
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/importer/products/{$productId}")->assertStatus(404);
    }

    public function test_gallery_upload_for_owner_and_rejection_for_others(): void
    {
        $seller = $this->user('seller');
        $otherSeller = $this->user('seller');
        [, $productId] = $this->createProduct($seller);

        Sanctum::actingAs($seller);
        $upload = $this->postJson("/api/v1/exporter/products/{$productId}/gallery", [
            'images' => [
                UploadedFile::fake()->create('front.jpg', 60, 'image/jpeg'),
                UploadedFile::fake()->create('side.png', 40, 'image/png'),
            ],
        ]);

        $upload->assertStatus(201);
        $upload->assertJsonPath('success', true);
        $upload->assertJsonPath('message', 'Product gallery images uploaded successfully');
        $upload->assertJsonPath('data.product_id', $productId);
        $upload->assertJsonPath('data.uploaded', 2);
        $upload->assertJsonPath('data.images.0.is_primary', true);
        $this->assertNotNull($upload->json('data.images.0.image_url'));
        $this->assertStringContainsString('gallery', (string) $upload->json('data.images.0.image_url'));

        $this->assertSame(2, DB::table('product_gallery_images')->where('product_id', $productId)->count());
        $image = DB::table('product_gallery_images')->where('product_id', $productId)->first();
        Storage::disk('public')->assertExists($image->file_path);

        // Another supplier cannot upload to, or read, this gallery.
        Sanctum::actingAs($otherSeller);
        $this->postJson("/api/v1/exporter/products/{$productId}/gallery", [
            'images' => [UploadedFile::fake()->create('hack.jpg', 20, 'image/jpeg')],
        ])->assertStatus(404);
        $this->getJson("/api/v1/exporter/products/{$productId}/gallery")->assertStatus(404);

        // Unsupported file types are rejected.
        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/exporter/products/{$productId}/gallery", [
            'images' => [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')],
        ])->assertStatus(422);

        $this->postJson("/api/v1/exporter/products/{$productId}/gallery", [])->assertStatus(422);

        $this->assertSame(2, DB::table('product_gallery_images')->where('product_id', $productId)->count());
    }

    public function test_archive_hides_product_and_blocks_invalid_repeat(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        $admin = $this->user('admin');

        [, $productId] = $this->createProduct($seller);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/products/{$productId}/approval", ['status' => 'active'])->assertStatus(200);

        Sanctum::actingAs($seller);
        $archive = $this->postJson("/api/v1/exporter/products/{$productId}/archive", [
            'reason' => 'Product discontinued temporarily',
        ]);

        $archive->assertStatus(200);
        $archive->assertJsonPath('success', true);
        $archive->assertJsonPath('message', 'Product archived successfully');
        $archive->assertJsonPath('data.id', $productId);
        $archive->assertJsonPath('data.status', 'archived');
        $archive->assertJsonPath('data.publication_status', 'archived');
        $archive->assertJsonPath('data.archive_reason', 'Product discontinued temporarily');
        $archive->assertJsonPath('data.published', 0);
        $this->assertNotNull($archive->json('data.archived_at'));

        // Historical record stays, only the publication state changes.
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'publication_status' => 'archived',
            'published' => 0,
            'archive_reason' => 'Product discontinued temporarily',
        ]);
        $this->assertSame('archived', Product::find($productId)->publication_status);

        // Marketplace no longer serves it.
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/importer/products/{$productId}")->assertStatus(404);
        $listingIds = collect($this->getJson('/api/v1/importer/products?per_page=100')->json('data.data') ?? [])->pluck('id');
        $this->assertFalse($listingIds->contains($productId));

        // Repeating the archive is a conflict.
        Sanctum::actingAs($seller);
        $this->postJson("/api/v1/exporter/products/{$productId}/archive")->assertStatus(409);

        // Restoring sends it back to moderation, not straight to the marketplace.
        $restore = $this->postJson("/api/v1/exporter/products/{$productId}/unarchive");
        $restore->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'publication_status' => 'pending_approval',
            'published' => 0,
            'approved' => 0,
        ]);
    }

    public function test_archive_is_rejected_for_another_supplier(): void
    {
        $seller = $this->user('seller');
        $otherSeller = $this->user('seller');
        [, $productId] = $this->createProduct($seller);

        Sanctum::actingAs($otherSeller);
        $this->postJson("/api/v1/exporter/products/{$productId}/archive", ['reason' => 'Not mine'])
            ->assertStatus(404);
        $this->postJson("/api/v1/exporter/products/{$productId}/unarchive")->assertStatus(404);

        $this->assertDatabaseMissing('products', ['id' => $productId, 'publication_status' => 'archived']);
    }

    public function test_csv_preview_reports_valid_and_invalid_rows_without_inserting(): void
    {
        $seller = $this->user('seller');
        Sanctum::actingAs($seller);

        $categoryId = $this->category();
        $csv = "name,category_id,grade,origin_country,unit,price,quantity\n"
            . "Organic Rice,{$categoryId},Grade A,India,MT,900,100\n"
            . "Black Pepper,{$categoryId},Grade B,India,KG,500,50\n"
            . "Organic Turmeric,999999,Grade A,India,KG,300,80\n";

        $before = Product::count();

        $preview = $this->postJson('/api/v1/exporter/products/bulk/preview', [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ]);

        $preview->assertStatus(200);
        $preview->assertJsonPath('success', true);
        $preview->assertJsonPath('message', 'CSV preview generated successfully');
        $preview->assertJsonPath('data.total_rows', 3);
        $preview->assertJsonPath('data.valid_rows', 2);
        $preview->assertJsonPath('data.invalid_rows', 1);
        $this->assertNotEmpty($preview->json('data.import_id'));

        $preview->assertJsonPath('data.preview.0.row', 2);
        $preview->assertJsonPath('data.preview.0.name', 'Organic Rice');
        $preview->assertJsonPath('data.preview.0.valid', true);
        $preview->assertJsonPath('data.preview.1.valid', true);
        $preview->assertJsonPath('data.preview.2.valid', false);
        $this->assertStringContainsString('invalid', strtolower(implode(' ', (array) $preview->json('data.preview.2.errors'))));

        // Previewing never inserts products.
        $this->assertSame($before, Product::count());
        $this->assertDatabaseMissing('products', ['name' => 'Organic Rice']);

        // Required columns are enforced.
        $missingHeader = "name,grade\nSome Rice,Grade A\n";
        $this->postJson('/api/v1/exporter/products/bulk/preview', [
            'file' => UploadedFile::fake()->createWithContent('broken.csv', $missingHeader),
        ])->assertStatus(422);
    }

    public function test_csv_confirm_imports_only_valid_rows_as_pending(): void
    {
        $seller = $this->user('seller');
        $otherSeller = $this->user('seller');
        Sanctum::actingAs($seller);

        $categoryId = $this->category();
        $csv = "name,category_id,grade,origin_country,unit,price,quantity\n"
            . 'Import Rice A,' . $categoryId . ",A,India,MT,900,100\n"
            . 'Import Rice B,' . $categoryId . ",B,India,MT,800,200\n"
            . "Import Rice C,888888,A,India,MT,700,50\n";

        $preview = $this->postJson('/api/v1/exporter/products/bulk/preview', [
            'file' => UploadedFile::fake()->createWithContent('bulk.csv', $csv),
        ])->assertStatus(200);

        $importId = $preview->json('data.import_id');
        $this->assertSame(3, $preview->json('data.total_rows'));

        $confirm = $this->postJson('/api/v1/exporter/products/bulk/confirm', ['import_id' => $importId]);

        $confirm->assertStatus(201);
        $confirm->assertJsonPath('success', true);
        $confirm->assertJsonPath('message', 'Products imported successfully');
        $confirm->assertJsonPath('data.total_rows', 3);
        $confirm->assertJsonPath('data.imported', 2);
        $confirm->assertJsonPath('data.failed', 1);
        $confirm->assertJsonPath('data.pending_approval', 2);

        $this->assertDatabaseHas('products', [
            'name' => 'Import Rice A',
            'approved' => 0,
            'published' => 0,
            'publication_status' => 'pending_approval',
        ]);
        $this->assertDatabaseHas('products', [
            'name' => 'Import Rice B',
            'publication_status' => 'pending_approval',
        ]);
        $this->assertDatabaseMissing('products', ['name' => 'Import Rice C']);

        // DEC-002: imported products stay hidden from the marketplace.
        $buyer = $this->user('customer');
        Sanctum::actingAs($buyer);
        $listingIds = collect($this->getJson('/api/v1/importer/products?per_page=100')->json('data.data') ?? [])->pluck('id');
        $importedIds = collect($confirm->json('data.product_ids'));
        $this->assertTrue($importedIds->intersect($listingIds)->isEmpty());

        // Duplicate confirmation is blocked.
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/exporter/products/bulk/confirm', ['import_id' => $importId])
            ->assertStatus(409);

        // Another supplier cannot use this import session.
        Sanctum::actingAs($otherSeller);
        $this->postJson('/api/v1/exporter/products/bulk/confirm', ['import_id' => $importId])
            ->assertStatus(404);

        // Missing import id is a validation error.
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/exporter/products/bulk/confirm', [])->assertStatus(422);
    }

    public function test_importer_cannot_use_exporter_product_management(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('customer');
        [, $productId] = $this->createProduct($seller);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/exporter/products', [
            'name' => 'Buyer Product',
            'category_id' => $this->category(),
            'origin_country' => 'India',
            'unit' => 'kg',
        ])->assertStatus(403);

        $this->postJson("/api/v1/exporter/products/{$productId}/gallery", [
            'images' => [UploadedFile::fake()->create('x.jpg', 10, 'image/jpeg')],
        ])->assertStatus(403);

        $this->postJson("/api/v1/exporter/products/{$productId}/archive")->assertStatus(403);

        $this->postJson('/api/v1/exporter/products/bulk/preview', [
            'file' => UploadedFile::fake()->createWithContent('p.csv', "name,category_id,origin_country,unit\nA,1,India,kg\n"),
        ])->assertStatus(403);
    }
}
