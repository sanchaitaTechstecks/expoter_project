<?php

namespace App\Http\Controllers\Api\V1\B2B;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\B2bTradeDocument;
use App\Models\B2B\DocumentChecklistTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * P2-6: per-order document checklist generation and bulk document packaging.
 */
class B2bDocumentChecklistController extends B2bBaseController
{
    private const DEFAULT_INTERNATIONAL = [
        'commercial_invoice' => 'Commercial Invoice',
        'packing_list' => 'Packing List',
        'bill_of_lading' => 'Bill of Lading / Airway Bill',
        'certificate_of_origin' => 'Certificate of Origin',
        'phytosanitary_certificate' => 'Phytosanitary Certificate',
        'fumigation_certificate' => 'Fumigation Certificate',
        'insurance_certificate' => 'Insurance Certificate',
        'inspection_certificate' => 'Inspection Certificate',
        'bank_letter_of_credit' => 'Bank Letter of Credit / Payment Proof',
    ];

    private const DEFAULT_DOMESTIC = [
        'commercial_invoice' => 'Tax Invoice',
        'packing_list' => 'Packing List',
        'delivery_challan' => 'Delivery Challan',
        'quality_certificate' => 'Quality Certificate',
        'weighment_slip' => 'Weighment Slip',
    ];

    /**
     * Generate the checklist for an order, marking documents that are on file.
     */
    public function forOrder($orderId, Request $request): JsonResponse
    {
        $order = $this->accessibleOrder($orderId, $request);
        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        return $this->successResponse($this->buildChecklist($order), 'Document checklist generated successfully');
    }

    /**
     * Bundle every document attached to an order into a single ZIP archive.
     */
    public function package($orderId, Request $request): BinaryFileResponse|JsonResponse
    {
        $order = $this->accessibleOrder($orderId, $request);
        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        if (!class_exists(ZipArchive::class)) {
            return $this->errorResponse('ZIP support is not available on this server (ext-zip missing)', 500);
        }

        $documents = B2bTradeDocument::where('order_id', $order->id)->get();
        if ($documents->isEmpty()) {
            return $this->errorResponse('No documents have been uploaded for this order yet', 404);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'b2b-docs-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->errorResponse('Unable to create the ZIP archive', 500);
        }

        $added = 0;
        $skipped = [];
        foreach ($documents as $document) {
            if (!$document->file_path || !Storage::disk('public')->exists($document->file_path)) {
                $skipped[] = $document->document_type;
                continue;
            }

            $name = sprintf(
                '%s_%s_%s',
                $document->document_type,
                $order->order_number,
                $document->file_name ?: basename($document->file_path)
            );
            $zip->addFile(Storage::disk('public')->path($document->file_path), Str::slug(pathinfo($name, PATHINFO_FILENAME), '_') . '.' . pathinfo($name, PATHINFO_EXTENSION));
            $added++;
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tmpPath);
            return $this->errorResponse('None of the uploaded documents have a readable file', 422);
        }

        return response()->download($tmpPath, "documents-{$order->order_number}.zip")->deleteFileAfterSend(true);
    }

    private function accessibleOrder($orderId, Request $request): ?B2bOrder
    {
        $user = $request->user();
        $query = B2bOrder::with(['items', 'supplier:id,name']);

        if (!in_array($user->user_type, ['admin', 'staff'], true)) {
            $query->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id)->orWhere('supplier_id', $user->id);
            });
        }

        return $query->find($orderId);
    }

    private function buildChecklist(B2bOrder $order): array
    {
        $isDomestic = $order->order_type === 'domestic'
            || $order->trade_type === 'domestic'
            || (string) $order->incoterm === '';

        $template = $this->matchTemplate($order, $isDomestic);

        if ($template) {
            $required = $template->items->map(function ($item) {
                return [
                    'document_type' => $item->document_type,
                    'label' => Str::headline($item->document_type),
                    'mandatory' => (bool) $item->is_mandatory,
                    'notes' => $item->notes,
                ];
            })->all();
            $templateMeta = [
                'id' => $template->id,
                'name' => $template->name,
                'source' => 'template',
            ];
        } else {
            $defaults = $isDomestic ? self::DEFAULT_DOMESTIC : self::DEFAULT_INTERNATIONAL;
            $required = [];
            foreach ($defaults as $type => $label) {
                $required[] = [
                    'document_type' => $type,
                    'label' => $label,
                    'mandatory' => true,
                    'notes' => null,
                ];
            }
            $templateMeta = [
                'id' => null,
                'name' => $isDomestic ? 'Default domestic checklist' : 'Default international checklist',
                'source' => 'default',
            ];
        }

        $documents = B2bTradeDocument::where('order_id', $order->id)
            ->orderByDesc('id')
            ->get()
            ->groupBy(function ($doc) {
                return $this->normalize($doc->document_type);
            });

        $checklist = [];
        $missingMandatory = 0;
        $expired = 0;

        foreach ($required as $item) {
            $key = $this->normalize($item['document_type']);
            $matching = $documents->get($key, collect());
            $latest = $matching->first();
            $isExpired = $latest && $latest->expiry_date && $latest->expiry_date->isPast();

            if (!$latest && $item['mandatory']) {
                $missingMandatory++;
            }
            if ($isExpired) {
                $expired++;
            }

            $checklist[] = [
                'document_type' => $item['document_type'],
                'label' => $item['label'],
                'mandatory' => $item['mandatory'],
                'notes' => $item['notes'],
                'status' => $isExpired ? 'expired' : ($latest ? 'provided' : 'missing'),
                'document_id' => $latest->id ?? null,
                'file_name' => $latest->file_name ?? null,
                'uploaded_at' => $latest->created_at ?? null,
                'expiry_date' => $latest && $latest->expiry_date ? $latest->expiry_date->toDateString() : null,
                'versions' => $matching->count(),
            ];
        }

        $extra = $documents->keys()->diff(array_map(fn ($i) => $this->normalize($i['document_type']), $required));

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $isDomestic ? 'domestic' : 'international',
            'destination_country' => $order->destination_country,
            'template' => $templateMeta,
            'summary' => [
                'required' => count($checklist),
                'provided' => count(array_filter($checklist, fn ($c) => $c['status'] === 'provided')),
                'missing' => count(array_filter($checklist, fn ($c) => $c['status'] === 'missing')),
                'missing_mandatory' => $missingMandatory,
                'expired' => $expired,
                'complete' => $missingMandatory === 0 && $expired === 0,
            ],
            'checklist' => $checklist,
            'additional_documents' => $extra->values(),
        ];
    }

    private function matchTemplate(B2bOrder $order, bool $isDomestic): ?DocumentChecklistTemplate
    {
        $commodityHint = strtolower(optional($order->items->first())->product_name_snapshot ?? '');

        $templates = DocumentChecklistTemplate::with('items')
            ->where('is_active', true)
            ->where(function ($q) use ($isDomestic) {
                $q->where('trade_type', $isDomestic ? 'domestic' : 'international')
                    ->orWhere('trade_type', 'both');
            })
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($templates as $template) {
            $score = 0;

            if ($template->destination_country && $order->destination_country
                && strcasecmp($template->destination_country, $order->destination_country) === 0) {
                $score += 3;
            } elseif ($template->destination_country) {
                continue;
            }

            if ($template->commodity) {
                if ($commodityHint !== '' && Str::contains($commodityHint, strtolower($template->commodity))) {
                    $score += 2;
                } else {
                    continue;
                }
            }

            if ($template->items->isEmpty()) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $template;
            }
        }

        return $best;
    }

    private function normalize(string $value): string
    {
        return Str::slug($value, '_');
    }
}
