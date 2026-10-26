<?php

namespace App\Http\Controllers\Api\V1\Exporter;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bInvoice;
use App\Models\B2B\B2bOrder;
use App\Models\B2B\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExporterInvoiceController extends B2bBaseController
{
    /**
     * Generate / Issue Proforma Invoice, Commercial Invoice, or Tax Invoice (Section 8.2 & 11).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:b2b_orders,id',
            'invoice_type' => 'required|in:proforma,commercial,tax',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
            'payment_terms' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $order = B2bOrder::where('id', $request->order_id)->where('supplier_id', $user->id)->first();
        if (!$order) {
            return $this->errorResponse('Order not found or access denied', 404);
        }

        $company = Company::where('user_id', $user->id)->first();

        $prefix = match($request->invoice_type) {
            'proforma' => 'PI-',
            'commercial' => 'CI-',
            'tax' => 'TI-',
            default => 'INV-',
        };
        $invoiceNumber = $prefix . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $bankDetails = [
            'bank_name' => $company ? $company->bank_name : null,
            'account_name' => $company ? $company->bank_account_name : null,
            'account_number' => $company ? $company->bank_account_number : null,
            'swift_bic' => $company ? $company->swift_bic : null,
            'iban' => $company ? $company->iban : null,
            'branch' => $company ? $company->bank_branch : null,
        ];

        $invoice = B2bInvoice::create([
            'invoice_number' => $invoiceNumber,
            'invoice_type' => $request->invoice_type,
            'order_id' => $order->id,
            'buyer_id' => $order->buyer_id,
            'supplier_id' => $user->id,
            'issue_date' => $request->issue_date,
            'due_date' => $request->due_date,
            'currency' => $order->currency,
            'subtotal' => $order->subtotal,
            'tax' => $order->taxes,
            'freight' => $order->freight,
            'insurance' => $order->insurance,
            'other_charges' => 0,
            'total_amount' => $order->total_amount,
            'bank_details' => $bankDetails,
            'payment_terms' => $request->payment_terms ?: 'As per contract terms',
            'status' => 'issued',
        ]);

        return $this->successResponse($invoice->load('order'), "Invoice ({$invoice->invoice_type}) issued successfully", 201);
    }

    /**
     * List exporter invoices.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = B2bInvoice::with(['order', 'buyer.company'])
            ->where('supplier_id', $user->id);

        if ($request->has('order_id') && !empty($request->order_id)) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('invoice_type') && !empty($request->invoice_type)) {
            $query->where('invoice_type', $request->invoice_type);
        }

        $invoices = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse($invoices, 'Invoices retrieved successfully');
    }

    /**
     * View invoice details.
     */
    public function show($id, Request $request): JsonResponse
    {
        $user = $request->user();
        $invoice = B2bInvoice::with(['order.items', 'buyer.company', 'payments'])
            ->where('supplier_id', $user->id)
            ->find($id);

        if (!$invoice) {
            return $this->errorResponse('Invoice not found or access denied', 404);
        }

        return $this->successResponse($invoice, 'Invoice details retrieved successfully');
    }
}
