<?php

namespace App\Http\Controllers\Api\V1\Cha;

use App\Http\Controllers\Api\V1\B2bBaseController;
use App\Models\B2B\B2bCustomsDeclaration;
use App\Models\B2B\B2bShipment;
use App\Services\B2B\B2bChaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChaShipmentController extends B2bBaseController
{
    public function __construct(
        protected B2bChaService $chaService
    ) {}

    /**
     * List assigned shipments queue for the authenticated CHA.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = B2bShipment::query()
            ->with([
                'order:id,order_number,currency,total_amount',
                'supplier:id,name,email',
                'buyer:id,name,email',
                'latestDeclaration',
            ]);

        // If user is a CHA broker, limit to their assigned shipments unless they have admin privilege
        if ($user->user_type === 'cha') {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_cha_id', $user->id)
                  ->orWhereNull('assigned_cha_id'); // Allow claiming unassigned shipments
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('customs_status')) {
            $query->where('customs_status', $request->customs_status);
        }

        if ($request->filled('port')) {
            $port = $request->port;
            $query->where(function ($q) use ($port) {
                $q->where('origin_port', 'like', "%{$port}%")
                  ->orWhere('destination_port', 'like', "%{$port}%");
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                  ->orWhere('booking_reference', 'like', "%{$search}%")
                  ->orWhere('tracking_number', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $shipments = $query->latest('id')->paginate($perPage);

        return $this->successResponse($shipments, 'CHA customs clearance queue retrieved successfully');
    }

    /**
     * Complete customs shipment clearance dossier.
     */
    public function show(int $id): JsonResponse
    {
        $shipment = B2bShipment::with([
            'order.items',
            'supplier.company',
            'buyer.company',
            'containers',
            'documents',
            'trackingEvents',
            'customsDeclarations.cha:id,name,email,phone',
        ])->find($id);

        if (!$shipment) {
            return $this->errorResponse('Shipment not found', 404);
        }

        $checklist = $this->chaService->generateChecklist($shipment);

        return $this->successResponse([
            'shipment' => $shipment,
            'customs_checklist' => $checklist,
        ], 'Customs clearance dossier retrieved successfully');
    }

    /**
     * Dynamic customs document checklist for shipment.
     */
    public function checklist(int $id): JsonResponse
    {
        $shipment = B2bShipment::with('order')->find($id);
        if (!$shipment) {
            return $this->errorResponse('Shipment not found', 404);
        }

        $checklist = $this->chaService->generateChecklist($shipment);

        return $this->successResponse([
            'shipment_id' => $shipment->id,
            'shipment_number' => $shipment->shipment_number,
            'customs_status' => $shipment->customs_status,
            'checklist' => $checklist,
        ], 'Customs checklist retrieved successfully');
    }

    /**
     * Record or submit customs declaration filing (Shipping Bill / Bill of Entry).
     */
    public function filing(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'declaration_number' => 'required|string|max:100',
            'filing_type' => 'nullable|in:shipping_bill,bill_of_entry,domestic_permit',
            'port_code' => 'required|string|max:50',
            'filing_date' => 'nullable|date',
            'cha_license_number' => 'nullable|string|max:100',
            'iec_code' => 'nullable|string|max:50',
            'assessable_value' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $declaration = $this->chaService->recordFiling($id, $request->all(), $request->user());

        return $this->successResponse($declaration, 'Customs filing recorded successfully', 201);
    }

    /**
     * Calculate and record customs duty assessment.
     */
    public function dutyCalculation(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'assessable_value' => 'nullable|numeric|min:0',
            'basic_customs_duty' => 'nullable|numeric|min:0',
            'igst_amount' => 'nullable|numeric|min:0',
            'cess_amount' => 'nullable|numeric|min:0',
            'duty_payment_status' => 'nullable|in:unpaid,paid,exempt',
            'duty_challan_number' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $declaration = $this->chaService->recordDutyCalculation($id, $request->all(), $request->user());

        return $this->successResponse($declaration, 'Customs duty assessment recorded successfully');
    }

    /**
     * Record customs inspection or examination report / queries / holds.
     */
    public function examination(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'examination_status' => 'required|in:scheduled,in_progress,passed,query_raised,customs_hold',
            'examination_remarks' => 'nullable|string|max:2000',
            'examination_officer' => 'nullable|string|max:191',
            'examination_order_no' => 'nullable|string|max:100',
            'examination_date' => 'nullable|date',
            'query_description' => 'nullable|string|max:2000',
            'query_response' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $declaration = $this->chaService->recordExamination($id, $request->all(), $request->user());

        return $this->successResponse($declaration, 'Customs examination status updated successfully');
    }

    /**
     * Release shipment / Issue Let Export Order (LEO) or Out of Charge (OOC).
     */
    public function release(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'release_order_number' => 'required|string|max:100',
            'release_notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $declaration = $this->chaService->releaseShipment($id, $request->all(), $request->user());

        return $this->successResponse($declaration, 'Customs release granted successfully');
    }
}
