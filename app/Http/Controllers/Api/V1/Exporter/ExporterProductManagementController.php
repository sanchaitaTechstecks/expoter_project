<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bBulkImportBatch;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\ProductGalleryImage;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * P2-3: product gallery, archive lifecycle and CSV bulk import.
 */
class ExporterProductManagementController extends B2bBaseController
{
    private const REQUIRED_CSV_COLUMNS = ['name', 'category_id', 'origin_country', 'unit'];

    /** Preview sessions stay valid for 24 hours. */
    private const PREVIEW_TTL_HOURS = 24;

    private const BULK_COLUMNS = [
        'name',
        'sku',
        'category_id',
        'grade',
        'origin_country',
        'unit',
        'packaging_type',
        'min_qty',
        'unit_price',
        'description',
    ];

    private function ownedProduct($productId, int $userId): ?Product
    {
        return Product::where('user_id', $userId)->find($productId);
    }

    public function galleryIndex($productId, Request $request): JsonResponse
    {
        $product = $this->ownedProduct($productId, $request->user()->id);
        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        $images = ProductGalleryImage::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->successResponse([
            'product_id' => $product->id,
            'count' => $images->count(),
            'images' => $images->map(fn (ProductGalleryImage $image) => $this->presentImage($image))->values(),
        ], 'Gallery images retrieved successfully');
    }

    /**
     * Upload one or more gallery images (max 8 files per request, 20 images per product).
     */
    public function galleryStore($productId, Request $request): JsonResponse
    {
        $product = $this->ownedProduct($productId, $request->user()->id);
        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        $validator = Validator::make($request->all(), [
            'images' => 'required|array|min:1|max:8',
            'images.*' => 'file|mimes:jpg,jpeg,png,webp|max:5120',
            'make_primary' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $existing = ProductGalleryImage::where('product_id', $product->id)->count();
        if ($existing + count($request->file('images')) > 20) {
            return $this->errorResponse('A product can have at most 20 gallery images', 422);
        }

        $sort = (int) ProductGalleryImage::where('product_id', $product->id)->max('sort_order');
        $hasPrimary = ProductGalleryImage::where('product_id', $product->id)->where('is_primary', true)->exists();
        $created = [];

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store("b2b/products/{$product->id}/gallery", 'public');
            $sort++;

            $image = ProductGalleryImage::create([
                'product_id' => $product->id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'sort_order' => $sort,
                'is_primary' => false,
                'uploaded_by' => $request->user()->id,
            ]);

            if (!$hasPrimary || ($index === 0 && $request->boolean('make_primary'))) {
                $image->is_primary = true;
                $image->save();
                $hasPrimary = true;
            }

            $created[] = $image;
        }

        return $this->successResponse([
            'product_id' => $product->id,
            'uploaded' => count($created),
            'images' => collect($created)->map(fn (ProductGalleryImage $image) => $this->presentImage($image))->values(),
        ], 'Product gallery images uploaded successfully', 201);
    }

    public function galleryDestroy($productId, $imageId, Request $request): JsonResponse
    {
        $product = $this->ownedProduct($productId, $request->user()->id);
        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        $image = ProductGalleryImage::where('product_id', $product->id)->find($imageId);
        if (!$image) {
            return $this->errorResponse('Gallery image not found', 404);
        }

        if (Storage::disk('public')->exists($image->file_path)) {
            Storage::disk('public')->delete($image->file_path);
        }

        $wasPrimary = $image->is_primary;
        $image->delete();

        if ($wasPrimary) {
            $next = ProductGalleryImage::where('product_id', $product->id)
                ->orderBy('sort_order')
                ->first();
            if ($next) {
                $next->is_primary = true;
                $next->save();
            }
        }

        return $this->successResponse(null, 'Gallery image deleted successfully');
    }

    public function galleryPrimary($productId, $imageId, Request $request): JsonResponse
    {
        $product = $this->ownedProduct($productId, $request->user()->id);
        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        $image = ProductGalleryImage::where('product_id', $product->id)->find($imageId);
        if (!$image) {
            return $this->errorResponse('Gallery image not found', 404);
        }

        DB::transaction(function () use ($product, $image) {
            ProductGalleryImage::where('product_id', $product->id)->update(['is_primary' => false]);
            $image->is_primary = true;
            $image->save();
        });

        return $this->successResponse($image, 'Primary gallery image updated');
    }

    /**
     * Archive a product so it disappears from the marketplace while staying auditable.
     */
    public function archive($productId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
            'force' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $product = $this->ownedProduct($productId, $request->user()->id);
        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        if ($product->publication_status === 'archived') {
            return $this->errorResponse('Product is already archived', 409);
        }

        $openOrders = B2bOrder::whereHas('items', function ($q) use ($product) {
            $q->where('product_id', $product->id);
        })->whereNotIn('status', ['delivered', 'cancelled', 'closed'])->count();

        if ($openOrders > 0 && !$request->boolean('force')) {
            return $this->errorResponse(
                "Product appears in {$openOrders} open order(s). Close them or pass force=true to archive anyway.",
                409
            );
        }

        $product->publication_status = 'archived';
        $product->published = 0;
        $product->archived_at = now();
        $product->archive_reason = $request->reason;
        $product->save();

        $product = $product->fresh();

        return $this->successResponse([
            'id' => $product->id,
            'status' => 'archived',
            'publication_status' => $product->publication_status,
            'published' => (int) $product->published,
            'approved' => (int) $product->approved,
            'archived_at' => optional($product->archived_at)->toIso8601String(),
            'archive_reason' => $product->archive_reason,
        ], 'Product archived successfully');
    }

    public function unarchive($productId, Request $request): JsonResponse
    {
        $product = $this->ownedProduct($productId, $request->user()->id);
        if (!$product) {
            return $this->errorResponse('Product not found or access denied', 404);
        }

        if ($product->publication_status !== 'archived') {
            return $this->errorResponse('Only archived products can be restored', 409);
        }

        // Restoring sends the product back to moderation, never straight to the marketplace.
        $product->publication_status = 'pending_approval';
        $product->published = 0;
        $product->approved = 0;
        $product->archived_at = null;
        $product->archive_reason = null;
        $product->save();

        return $this->successResponse($product->fresh(), 'Product restored and submitted for approval');
    }

    /**
     * Step 1 of bulk upload: parse and validate the CSV, return a preview.
     */
    public function bulkPreview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $file = $request->file('file');
        $parsed = $this->parseCsv($file->getRealPath());
        $rows = $parsed['rows'];

        $missing = array_values(array_diff(self::REQUIRED_CSV_COLUMNS, $parsed['header']));
        if (!empty($missing)) {
            return $this->errorResponse(
                'Missing required CSV columns: ' . implode(', ', $missing),
                422,
                ['header' => $missing]
            );
        }

        if (empty($rows)) {
            return $this->errorResponse('The uploaded file has no data rows', 422);
        }

        if (count($rows) > 500) {
            return $this->errorResponse('A single bulk upload can contain at most 500 rows', 422);
        }

        $seenSkus = [];
        $preview = [];
        $valid = 0;
        $invalid = 0;

        foreach ($rows as $index => $row) {
            $line = $index + 2; // header is line 1
            $errors = [];

            $rowValidator = Validator::make($row, [
                'name' => 'required|string|max:200',
                'sku' => 'nullable|string|max:100',
                'category_id' => 'required|integer|exists:categories,id',
                'grade' => 'nullable|string|max:100',
                'origin_country' => 'required|string|max:100',
                'unit' => 'required|string|max:20',
                'packaging_type' => 'nullable|string|max:100',
                'min_qty' => 'nullable|numeric|min:1',
                'unit_price' => 'nullable|numeric|min:0',
                'description' => 'nullable|string',
            ]);

            if ($rowValidator->fails()) {
                $errors = $rowValidator->errors()->all();
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                if (in_array($sku, $seenSkus, true)) {
                    $errors[] = "Duplicate SKU '{$sku}' inside this file";
                } elseif (Product::where('sku', $sku)->exists()) {
                    $errors[] = "SKU '{$sku}' already exists";
                }
                $seenSkus[] = $sku;
            }

            $isValid = empty($errors);
            $isValid ? $valid++ : $invalid++;

            $preview[] = [
                'line' => $line,
                'data' => $row,
                'valid' => $isValid,
                'errors' => $errors,
            ];
        }

        $token = Str::random(40);
        $filename = "b2b/bulk-imports/{$token}.csv";
        Storage::disk('local')->put($filename, $file->get());

        $batch = B2bBulkImportBatch::create([
            'user_id' => $request->user()->id,
            'token' => $token,
            'original_filename' => $file->getClientOriginalName(),
            'total_rows' => count($rows),
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'status' => 'previewed',
            'rows' => $preview,
        ]);

        $previewRows = array_map(function (array $row) {
            return [
                'row' => $row['line'],
                'name' => $row['data']['name'] ?? null,
                'valid' => $row['valid'],
                'errors' => $row['errors'],
                'data' => $row['data'],
            ];
        }, array_slice($preview, 0, 100));

        return $this->successResponse([
            'import_id' => $token,
            'batch_id' => $batch->id,
            'token' => $token,
            'total_rows' => count($rows),
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'preview' => $previewRows,
            'rows' => $previewRows,
            'truncated' => count($preview) > 100,
        ], 'CSV preview generated successfully');
    }

    /**
     * Step 2: commit only the valid rows of a previewed batch.
     */
    public function bulkConfirm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'import_id' => 'nullable|string',
            'token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $lookup = $request->import_id ?: $request->token;

        if (!$lookup) {
            return $this->errorResponse('Validation failed', 422, [
                'import_id' => ['The import_id field is required (use the value returned by the preview).'],
            ]);
        }

        $batch = B2bBulkImportBatch::where('user_id', $request->user()->id)
            ->where(function ($q) use ($lookup) {
                $q->where('token', $lookup);
                if (ctype_digit((string) $lookup)) {
                    $q->orWhere('id', (int) $lookup);
                }
            })
            ->first();

        if (!$batch) {
            return $this->errorResponse('Bulk import batch not found', 404);
        }

        if ($batch->status !== 'previewed') {
            return $this->errorResponse("Batch has already been {$batch->status}", 409);
        }

        if ($batch->created_at && $batch->created_at->lt(now()->subHours(self::PREVIEW_TTL_HOURS))) {
            return $this->errorResponse('This preview session has expired, please upload the CSV again', 410);
        }

        $user = $request->user();
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($batch, $user, &$created, &$skipped) {
            foreach ($batch->rows as $row) {
                if (empty($row['valid'])) {
                    $skipped[] = $row['line'];
                    continue;
                }

                $data = $row['data'];
                $sku = trim((string) ($data['sku'] ?? ''));

                if ($sku !== '' && Product::where('sku', $sku)->exists()) {
                    $skipped[] = $row['line'];
                    continue;
                }

                $product = Product::create([
                    'name' => $data['name'],
                    'slug' => Str::slug($data['name']) . '-' . strtolower(Str::random(5)),
                    'sku' => $sku !== '' ? $sku : 'SKU-' . strtoupper(Str::random(8)),
                    'added_by' => 'seller',
                    'user_id' => $user->id,
                    'category_id' => $data['category_id'],
                    'grade' => $data['grade'] ?? null,
                    'origin_country' => $data['origin_country'],
                    'unit' => $data['unit'],
                    'packaging_type' => $data['packaging_type'] ?? null,
                    'description' => $data['description'] ?? null,
                    'unit_price' => $data['unit_price'] ?: 0,
                    'min_qty' => $data['min_qty'] ?: 1,
                    'domestic_visibility' => 1,
                    'export_visibility' => 1,
                    'publication_status' => 'pending_approval',
                    'published' => 0,
                    'approved' => 0,
                ]);

                $created[] = $product->id;
            }

            $batch->status = 'committed';
            $batch->save();

            Storage::disk('local')->delete("b2b/bulk-imports/{$batch->token}.csv");
        });

        return $this->successResponse([
            'import_id' => $batch->token,
            'batch_id' => $batch->id,
            'total_rows' => (int) $batch->total_rows,
            'imported' => count($created),
            'failed' => count($skipped),
            'pending_approval' => count($created),
            'created_count' => count($created),
            'product_ids' => $created,
            'skipped_lines' => $skipped,
            'status' => 'pending_approval',
        ], 'Products imported successfully', 201);
    }

    public function bulkHistory(Request $request): JsonResponse
    {
        $batches = B2bBulkImportBatch::where('user_id', $request->user()->id)
            ->select(['id', 'token', 'original_filename', 'total_rows', 'valid_rows', 'invalid_rows', 'status', 'created_at'])
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 15));

        return $this->successResponse($batches, 'Bulk import history retrieved successfully');
    }

    public function bulkTemplate(): BinaryFileResponse
    {
        $path = storage_path('app/b2b-bulk-import-template.csv');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, implode(',', self::BULK_COLUMNS) . "\n"
            . 'Basmati Rice 1121,SKU-RICE-1121,1,A,India,kg,25kg bag,100,1250,Premium long grain rice' . "\n");

        return response()->download($path, 'b2b-product-bulk-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ])->deleteFileAfterSend(true);
    }

    private function presentImage(ProductGalleryImage $image): array
    {
        return array_merge($image->toArray(), [
            'image_url' => Storage::disk('public')->url($image->file_path),
        ]);
    }

    /**
     * Parse a CSV file, normalising headers (price/quantity aliases included).
     *
     * @return array{header: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return ['header' => [], 'rows' => []];
        }

        $aliases = ['price' => 'unit_price', 'quantity' => 'min_qty'];
        $header = [];
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }

            if (empty($header)) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                foreach ($line as $cell) {
                    $column = Str::snake(trim((string) $cell));
                    if ($column === '') {
                        continue;
                    }
                    $header[] = $aliases[$column] ?? $column;
                }
                continue;
            }

            if (count(array_filter($line, function ($v) {
                return trim((string) $v) !== '';
            })) === 0) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $column) {
                $row[$column] = isset($line[$i]) ? trim((string) $line[$i]) : null;
            }

            // Unknown columns are ignored; missing optional columns become null.
            foreach (self::BULK_COLUMNS as $column) {
                if (!array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }

            $rows[] = $row;
        }

        fclose($handle);

        return ['header' => $header, 'rows' => $rows];
    }
}
