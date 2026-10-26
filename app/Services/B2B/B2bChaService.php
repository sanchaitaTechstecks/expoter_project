<?php

namespace App\Services\B2B;

use App\Models\B2B\B2bCustomsDeclaration;
use App\Models\B2B\B2bShipment;
use App\Models\B2B\B2bTrackingEvent;
use App\Models\B2B\B2bTradeDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class B2bChaService
{
    /**
     * Submit or update customs declaration filing (Shipping Bill or Bill of Entry).
     */
    public function recordFiling(int $shipmentId, array $data, User $chaUser): B2bCustomsDeclaration
    {
        return DB::transaction(function () use ($shipmentId, $data, $chaUser) {
            $shipment = B2bShipment::findOrFail($shipmentId);

            $declaration = B2bCustomsDeclaration::firstOrNew(['shipment_id' => $shipment->id]);
            $before = $declaration->exists ? $declaration->toArray() : null;

            $filingType = $data['filing_type'] ?? ($shipment->mode === 'domestic' ? 'domestic_permit' : 'shipping_bill');

            $declaration->fill([
                'cha_user_id' => $chaUser->id,
                'filing_type' => $filingType,
                'declaration_number' => $data['declaration_number'],
                'port_code' => $data['port_code'] ?? ($shipment->origin_port ?: 'INNSA1'),
                'filing_date' => $data['filing_date'] ?? now()->toDateString(),
                'cha_license_number' => $data['cha_license_number'] ?? null,
                'iec_code' => $data['iec_code'] ?? null,
                'assessable_value' => $data['assessable_value'] ?? 0.00,
                'currency' => $data['currency'] ?? 'USD',
                'clearance_status' => 'filed',
            ]);
            $declaration->save();

            // Update shipment status
            $shipment->customs_status = 'filed';
            if ($shipment->status === 'planned' || $shipment->status === 'goods_preparing' || $shipment->status === 'at_origin_port') {
                $shipment->status = 'customs_processing';
            }
            $shipment->save();

            // Log logistics tracking event
            B2bTrackingEvent::create([
                'shipment_id' => $shipment->id,
                'event_type' => 'customs_processing',
                'location' => $declaration->port_code,
                'event_status' => 'completed',
                'actual_at' => now(),
                'source' => 'cha_portal',
                'description' => "Customs filing registered: [{$declaration->filing_type}] No. {$declaration->declaration_number} at Port {$declaration->port_code}.",
                'created_by' => $chaUser->id,
            ]);

            // System audit trail
            B2bAuditService::log(
                $chaUser->id,
                'cha',
                'customs_filing',
                'B2bCustomsDeclaration',
                $declaration->id,
                $before,
                $declaration->toArray(),
                "Customs declaration {$declaration->declaration_number} filed for shipment {$shipment->shipment_number}"
            );

            return $declaration;
        });
    }

    /**
     * Calculate and record customs duty assessment.
     */
    public function recordDutyCalculation(int $shipmentId, array $data, User $chaUser): B2bCustomsDeclaration
    {
        return DB::transaction(function () use ($shipmentId, $data, $chaUser) {
            $shipment = B2bShipment::findOrFail($shipmentId);
            $declaration = B2bCustomsDeclaration::where('shipment_id', $shipment->id)->firstOrFail();

            $before = $declaration->toArray();

            $bcd = (float) ($data['basic_customs_duty'] ?? 0.00);
            $igst = (float) ($data['igst_amount'] ?? 0.00);
            $cess = (float) ($data['cess_amount'] ?? 0.00);
            $totalDuty = $bcd + $igst + $cess;

            $declaration->basic_customs_duty = $bcd;
            $declaration->igst_amount = $igst;
            $declaration->cess_amount = $cess;
            $declaration->total_duty_payable = $totalDuty;

            if (isset($data['assessable_value'])) {
                $declaration->assessable_value = (float) $data['assessable_value'];
            }
            if (isset($data['duty_payment_status'])) {
                $declaration->duty_payment_status = $data['duty_payment_status'];
                if ($data['duty_payment_status'] === 'paid') {
                    $declaration->duty_paid_at = now();
                    $shipment->customs_status = 'duty_paid';
                    $shipment->save();
                }
            }
            if (!empty($data['duty_challan_number'])) {
                $declaration->duty_challan_number = $data['duty_challan_number'];
            }

            $declaration->save();

            B2bAuditService::log(
                $chaUser->id,
                'cha',
                'duty_calculation',
                'B2bCustomsDeclaration',
                $declaration->id,
                $before,
                $declaration->toArray(),
                "Duty assessed: {$totalDuty} {$declaration->currency} (BCD: {$bcd}, IGST: {$igst}, Cess: {$cess})"
            );

            return $declaration;
        });
    }

    /**
     * Record customs inspection or examination report / queries / holds.
     */
    public function recordExamination(int $shipmentId, array $data, User $chaUser): B2bCustomsDeclaration
    {
        return DB::transaction(function () use ($shipmentId, $data, $chaUser) {
            $shipment = B2bShipment::findOrFail($shipmentId);
            $declaration = B2bCustomsDeclaration::where('shipment_id', $shipment->id)->firstOrFail();

            $before = $declaration->toArray();

            $status = $data['examination_status'];
            $declaration->examination_status = $status;
            $declaration->examination_remarks = $data['examination_remarks'] ?? null;
            $declaration->examination_officer = $data['examination_officer'] ?? null;
            $declaration->examination_order_no = $data['examination_order_no'] ?? null;
            $declaration->examination_date = $data['examination_date'] ?? now()->toDateString();

            if (!empty($data['query_description'])) {
                $declaration->query_description = $data['query_description'];
            }
            if (!empty($data['query_response'])) {
                $declaration->query_response = $data['query_response'];
            }

            if ($status === 'customs_hold' || $status === 'query_raised') {
                $shipment->customs_status = 'customs_hold';
                $shipment->status = 'customs_hold';
                $shipment->save();

                B2bTrackingEvent::create([
                    'shipment_id' => $shipment->id,
                    'event_type' => 'customs_hold',
                    'location' => $declaration->port_code,
                    'event_status' => 'delayed',
                    'actual_at' => now(),
                    'source' => 'cha_portal',
                    'description' => "Customs Hold / Query raised: " . ($data['query_description'] ?? $data['examination_remarks'] ?? 'Awaiting customs inspector assessment'),
                    'created_by' => $chaUser->id,
                ]);
            } elseif ($status === 'passed') {
                $shipment->customs_status = 'examination_passed';
                $shipment->save();

                B2bTrackingEvent::create([
                    'shipment_id' => $shipment->id,
                    'event_type' => 'customs_processing',
                    'location' => $declaration->port_code,
                    'event_status' => 'completed',
                    'actual_at' => now(),
                    'source' => 'cha_portal',
                    'description' => "Physical customs examination passed successfully.",
                    'created_by' => $chaUser->id,
                ]);
            }

            $declaration->save();

            B2bAuditService::log(
                $chaUser->id,
                'cha',
                'customs_examination',
                'B2bCustomsDeclaration',
                $declaration->id,
                $before,
                $declaration->toArray(),
                "Customs examination updated to {$status}"
            );

            return $declaration;
        });
    }

    /**
     * Release shipment / Issue Let Export Order (LEO) or Out of Charge (OOC).
     */
    public function releaseShipment(int $shipmentId, array $data, User $chaUser): B2bCustomsDeclaration
    {
        return DB::transaction(function () use ($shipmentId, $data, $chaUser) {
            $shipment = B2bShipment::findOrFail($shipmentId);
            $declaration = B2bCustomsDeclaration::where('shipment_id', $shipment->id)->firstOrFail();

            $before = $declaration->toArray();

            $releaseOrderNo = $data['release_order_number'];
            $releaseNotes = $data['release_notes'] ?? 'Customs clearance release granted.';

            $declaration->clearance_status = 'cleared';
            $declaration->release_order_number = $releaseOrderNo;
            $declaration->released_at = now();
            $declaration->release_notes = $releaseNotes;
            $declaration->save();

            // Update shipment
            $shipment->customs_status = 'cleared';
            $shipment->status = 'cleared';
            $shipment->save();

            // Log milestone event
            B2bTrackingEvent::create([
                'shipment_id' => $shipment->id,
                'event_type' => 'customs_cleared',
                'location' => $declaration->port_code,
                'event_status' => 'completed',
                'actual_at' => now(),
                'source' => 'cha_portal',
                'description' => "Customs release order granted: {$releaseOrderNo}. Shipment cleared for loading/delivery.",
                'created_by' => $chaUser->id,
            ]);

            B2bAuditService::log(
                $chaUser->id,
                'cha',
                'customs_release',
                'B2bCustomsDeclaration',
                $declaration->id,
                $before,
                $declaration->toArray(),
                "Shipment customs release granted: {$releaseOrderNo}"
            );

            return $declaration;
        });
    }

    /**
     * Generate dynamic customs document checklist based on shipment, commodity, and trade type.
     */
    public function generateChecklist(B2bShipment $shipment): array
    {
        $order = $shipment->order;
        $existingDocs = B2bTradeDocument::where('shipment_id', $shipment->id)
            ->orWhere(function ($q) use ($order) {
                if ($order) {
                    $q->where('order_id', $order->id);
                }
            })
            ->get()
            ->keyBy('document_type');

        $declaration = B2bCustomsDeclaration::where('shipment_id', $shipment->id)->first();

        $checklist = [
            [
                'code' => 'commercial_invoice',
                'name' => 'Commercial Invoice',
                'is_mandatory' => true,
                'status' => $existingDocs->has('commercial_invoice') ? 'verified' : 'missing',
                'document_id' => $existingDocs->get('commercial_invoice')?->id,
            ],
            [
                'code' => 'packing_list',
                'name' => 'Packing List',
                'is_mandatory' => true,
                'status' => $existingDocs->has('packing_list') ? 'verified' : 'missing',
                'document_id' => $existingDocs->get('packing_list')?->id,
            ],
            [
                'code' => 'bill_of_lading',
                'name' => ($shipment->mode === 'air') ? 'Airway Bill (AWB)' : 'Bill of Lading (B/L)',
                'is_mandatory' => true,
                'status' => ($existingDocs->has('bill_of_lading') || $existingDocs->has('airway_bill')) ? 'verified' : 'missing',
                'document_id' => $existingDocs->get('bill_of_lading')?->id ?? $existingDocs->get('airway_bill')?->id,
            ],
            [
                'code' => 'certificate_of_origin',
                'name' => 'Certificate of Origin (COO)',
                'is_mandatory' => true,
                'status' => $existingDocs->has('certificate_of_origin') ? 'verified' : 'missing',
                'document_id' => $existingDocs->get('certificate_of_origin')?->id,
            ],
            [
                'code' => 'phytosanitary_certificate',
                'name' => 'Phytosanitary / Health Certificate',
                'is_mandatory' => false,
                'status' => $existingDocs->has('phytosanitary') ? 'verified' : 'not_uploaded',
                'document_id' => $existingDocs->get('phytosanitary')?->id,
            ],
            [
                'code' => 'customs_declaration',
                'name' => ($declaration?->filing_type === 'bill_of_entry') ? 'Bill of Entry' : 'Shipping Bill',
                'is_mandatory' => true,
                'status' => $declaration ? 'filed' : 'missing',
                'declaration_number' => $declaration?->declaration_number,
            ],
            [
                'code' => 'duty_challan',
                'name' => 'Customs Duty Payment Challan',
                'is_mandatory' => ($declaration?->total_duty_payable > 0),
                'status' => ($declaration && $declaration->duty_payment_status === 'paid') ? 'verified' : 'pending',
                'challan_number' => $declaration?->duty_challan_number,
            ],
            [
                'code' => 'release_order',
                'name' => 'Let Export Order (LEO) / Out of Charge (OOC)',
                'is_mandatory' => true,
                'status' => ($declaration && $declaration->clearance_status === 'cleared') ? 'granted' : 'pending',
                'release_order_number' => $declaration?->release_order_number,
            ],
        ];

        return $checklist;
    }
}
