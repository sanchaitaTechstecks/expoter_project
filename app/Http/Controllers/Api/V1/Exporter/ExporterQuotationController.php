<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bQuotation;
use App\Models\B2B\B2bQuotationItem;
use App\Models\B2B\ProductLot;
use App\Models\B2B\Rfq;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExporterQuotationController extends B2bBaseController
{
    /**
     * Create and issue a formal Quotation (Section 8.1).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'rfq_id' => 'required|exists:rfqs,id',
            'currency' => 'required|string|max:10',
            'incoterm' => 'required|string|max:20', // FOB, CIF, CFR, EXW
            'named_place' => 'required|string|max:191',
            'validity_date' => 'required|date|after:now',
            'delivery_window' => 'nullable|string|max:100',
            'payment_terms' => 'required|string',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'freight_charges' => 'nullable|numeric|min:0',
            'insurance_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'exclusions' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.lot_id' => 'nullable|exists:product_lots,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit' => 'required|string|max:20',
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.packaging' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $rfq = Rfq::find($request->rfq_id);

        return DB::transaction(function () use ($request, $user, $rfq) {
            $subtotal = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $lineTotal = round($item['quantity'] * $item['unit_price'], 2);
                $subtotal += $lineTotal;

                $product = Product::find($item['product_id']);
                $lot = isset($item['lot_id']) ? ProductLot::find($item['lot_id']) : null;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'lot_id' => $lot ? $lot->id : null,
                    'product_name_snapshot' => $product->name,
                    'specification_snapshot' => $lot ? "Grade: {$lot->quality_grade}, Packaging: {$lot->packaging_details}" : $product->specifications,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                    'packaging' => $item['packaging'] ?? ($lot ? $lot->packaging_details : null),
                ];
            }

            $discount = (float)($request->discount ?? 0);
            $tax = (float)($request->tax ?? 0);
            $freight = (float)($request->freight_charges ?? 0);
            $insurance = (float)($request->insurance_charges ?? 0);
            $other = (float)($request->other_charges ?? 0);

            $totalAmount = round($subtotal - $discount + $tax + $freight + $insurance + $other, 2);

            $quotationNumber = 'QT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

            $quotation = B2bQuotation::create([
                'quotation_number' => $quotationNumber,
                'rfq_id' => $rfq->id,
                'supplier_id' => $user->id,
                'buyer_id' => $rfq->buyer_id,
                'version' => 1,
                'currency' => strtoupper($request->currency),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'freight_charges' => $freight,
                'insurance_charges' => $insurance,
                'other_charges' => $other,
                'total_amount' => $totalAmount,
                'incoterm' => $request->incoterm,
                'named_place' => $request->named_place,
                'validity_date' => $request->validity_date,
                'delivery_window' => $request->delivery_window,
                'payment_terms' => $request->payment_terms,
                'exclusions' => $request->exclusions,
                'notes' => $request->notes,
                'status' => 'issued',
            ]);

            foreach ($itemsData as $iData) {
                $iData['quotation_id'] = $quotation->id;
                B2bQuotationItem::create($iData);
            }

            $rfq->status = 'quotation_issued';
            $rfq->save();

            return $this->successResponse($quotation->load('items'), 'Quotation issued successfully', 201);
        });
    }

    /**
     * List quotations issued by the exporter.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bQuotation::with(['rfq.product', 'buyer.company', 'items'])
            ->where('supplier_id', $user->id);

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $quotations = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($quotations, 'Quotations retrieved successfully');
    }

    /**
     * View quotation details.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $quotation = B2bQuotation::with(['rfq.product', 'buyer.company', 'items.lot'])
            ->where('supplier_id', $user->id)
            ->find($id);

        if (!$quotation) {
            return $this->errorResponse('Quotation not found or access denied', 404);
        }

        return $this->successResponse($quotation, 'Quotation details retrieved successfully');
    }

    /**
     * Save / update a quotation draft (status => draft).
     */
    public function saveDraft($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $quotation = B2bQuotation::where('supplier_id', $user->id)->find($id);

        if (!$quotation) {
            return $this->errorResponse('Quotation not found or access denied', 404);
        }

        if (in_array($quotation->status, ['accepted', 'rejected', 'expired', 'superseded', 'cancelled'])) {
            return $this->errorResponse("Cannot save a quote in '{$quotation->status}' state", 400);
        }

        $validator = Validator::make($request->all(), [
            'rfq_id' => 'required|exists:rfqs,id',
            'currency' => 'nullable|string|max:10',
            'incoterm' => 'nullable|string|max:20', // FOB, CIF, CFR, EXW
            'named_place' => 'nullable|string|max:191',
            'validity_date' => 'nullable|date|after:now',
            'delivery_window' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'freight_charges' => 'nullable|numeric|min:0',
            'insurance_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'exclusions' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $quotation->fill([
            'currency' => $request->currency ?? $quotation->currency,
            'incoterm' => $request->incoterm ?? $quotation->incoterm,
            'named_place' => $request->named_place ?? $quotation->named_place,
            'validity_date' => $request->validity_date ?? $quotation->validity_date,
            'delivery_window' => $request->delivery_window ?? $quotation->delivery_window,
            'payment_terms' => $request->payment_terms ?? $quotation->payment_terms,
            'discount' => $request->discount ?? $quotation->discount,
            'tax' => $request->tax ?? $quotation->tax,
            'freight_charges' => $request->freight_charges ?? $quotation->freight_charges,
            'insurance_charges' => $request->insurance_charges ?? $quotation->insurance_charges,
            'other_charges' => $request->other_charges ?? $quotation->other_charges,
            'exclusions' => $request->exclusions ?? $quotation->exclusions,
            'notes' => $request->notes ?? $quotation->notes,
        ]);
        $quotation->status = 'draft';
        $quotation->save();

        return $this->successResponse($quotation, 'Quotation draft saved successfully', 200);
    }

    /**
     * Revise an existing quotation (creates a new version and supersedes the old one).
     */
    public function revise($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $oldQuote = B2bQuotation::with('items')->where('supplier_id', $user->id)->find($id);

        if (!$oldQuote) {
            return $this->errorResponse('Quotation not found or access denied', 404);
        }

        if (in_array($oldQuote->status, ['accepted', 'cancelled', 'superseded'])) {
            return $this->errorResponse("Cannot revise a quotation in '{$oldQuote->status}' state", 400);
        }

        $validator = Validator::make($request->all(), [
            'validity_date' => 'required|date|after:now',
            'delivery_window' => 'nullable|string',
            'payment_terms' => 'nullable|string',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'freight_charges' => 'nullable|numeric|min:0',
            'insurance_charges' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.lot_id' => 'nullable|exists:product_lots,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit' => 'required|string|max:20',
            'items.*.unit_price' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        return DB::transaction(function () use ($request, $user, $oldQuote) {
            $subtotal = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $lineTotal = round($item['quantity'] * $item['unit_price'], 2);
                $subtotal += $lineTotal;

                $product = Product::find($item['product_id']);
                $lot = isset($item['lot_id']) ? ProductLot::find($item['lot_id']) : null;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'lot_id' => $lot ? $lot->id : null,
                    'product_name_snapshot' => $product->name,
                    'specification_snapshot' => $lot ? "Grade: {$lot->quality_grade}, Packaging: {$lot->packaging_details}" : $product->specifications,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                    'packaging' => $item['packaging'] ?? ($lot ? $lot->packaging_details : null),
                ];
            }

            $discount = (float)($request->discount ?? $oldQuote->discount);
            $tax = (float)($request->tax ?? $oldQuote->tax);
            $freight = (float)($request->freight_charges ?? $oldQuote->freight_charges);
            $insurance = (float)($request->insurance_charges ?? $oldQuote->insurance_charges);
            $other = (float)($request->other_charges ?? $oldQuote->other_charges);

            $totalAmount = round($subtotal - $discount + $tax + $freight + $insurance + $other, 2);

            $newVersion = $oldQuote->version + 1;
            $quotationNumber = explode('-V', $oldQuote->quotation_number)[0] . '-V' . $newVersion;

            $newQuote = B2bQuotation::create([
                'quotation_number' => $quotationNumber,
                'rfq_id' => $oldQuote->rfq_id,
                'supplier_id' => $user->id,
                'buyer_id' => $oldQuote->buyer_id,
                'version' => $newVersion,
                'currency' => $oldQuote->currency,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'freight_charges' => $freight,
                'insurance_charges' => $insurance,
                'other_charges' => $other,
                'total_amount' => $totalAmount,
                'incoterm' => $request->incoterm ?: $oldQuote->incoterm,
                'named_place' => $request->named_place ?: $oldQuote->named_place,
                'validity_date' => $request->validity_date,
                'delivery_window' => $request->delivery_window ?: $oldQuote->delivery_window,
                'payment_terms' => $request->payment_terms ?: $oldQuote->payment_terms,
                'exclusions' => $request->exclusions ?: $oldQuote->exclusions,
                'notes' => $request->notes ?: $oldQuote->notes,
                'status' => 'issued',
            ]);

            foreach ($itemsData as $iData) {
                $iData['quotation_id'] = $newQuote->id;
                B2bQuotationItem::create($iData);
            }

            $oldQuote->status = 'superseded';
            $oldQuote->superseded_by_id = $newQuote->id;
            $oldQuote->save();

            return $this->successResponse($newQuote->load('items'), "Quotation revised (Version {$newVersion} issued)", 201);
        });
    }
}
