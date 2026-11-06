<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bQuotation extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_quotations';

    protected $fillable = [
        'quotation_number',
        'rfq_id',
        'supplier_id',
        'buyer_id',
        'version',
        'currency',
        'subtotal',
        'discount',
        'tax',
        'freight_charges',
        'insurance_charges',
        'other_charges',
        'total_amount',
        'incoterm',
        'named_place',
        'validity_date',
        'delivery_window',
        'payment_terms',
        'exclusions',
        'notes',
        'status',
        'rejection_reason',
        'accepted_at',
        'superseded_by_id',
        'created_by',
        'rfq_type',
        'source_channel',
        'dec013_export_offers',
        'dec013_external_id',
        'offer_count',
        'offer_by_supplier',

        // DEL-013: formal multi-supplier quotation header fields
        'quote_number',
        'validity_days',

        // DEC-013: export header fields
        'incoterm',
        'named_place',
        'export_origin_port',
        'export_destination_port',
        'export_origin_country',
        'export_destination_country',
        'export_queue_number',
        'export_quality_requirement',
        'export_packaging_requirement',
        'export_marking_label',
        'export_document_requirements',
        'export_declaration_details',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'freight_charges' => 'decimal:2',
        'insurance_charges' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'validity_date' => 'datetime',
        'accepted_at' => 'datetime',
        'created_by' => 'integer',
        'rfq_type' => 'string',
        'source_channel' => 'string',
        'dec013_export_offers' => 'array',
        'dec013_external_id' => 'string',
        'offer_count' => 'integer',
        'offer_by_supplier' => 'integer',
        'rejection_reason' => 'string',
        'quote_number' => 'string', // DEL-013: formal multi-supplier quote number (e.g. QTE-20260101-XXXX)
        'validity_days' => 'integer', // DEL-013: quote validity window in days
        'incoterm' => 'string', // DEL-013: FOB, CIF, CFR, EXW, DAP, FCA, CPT, CIP
        'named_place' => 'string', // DEL-013: delivery location
        'export_origin_port' => 'string', // DEL-013: loading port
        'export_destination_port' => 'string', // DEL-013: discharge port
        'export_origin_country' => 'string', // DEC-013: country of origin/exports
        'export_destination_country' => 'string', // DEC-013: destination country
        'currency' => 'string', // DEC-013: quotes currency
        'unit' => 'string', // DEC-013: kg, MT, TON, CBM, TEU, etc.
        'export_queue_number' => 'string', // DEL-013: export queue/reference number
        'export_quality_requirement' => 'text', // DEL-013: quality specs for export
        'export_packaging_requirement' => 'text', // DEL-013: packaging specs
        'export_marking_label' => 'text', // DEL-013: marking/labeling requirements
        'export_document_requirements' => 'array', // DEL-013: required export docs list
        'export_declaration_details' => 'text', // DEC-013: customs declaration details
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function items()
    {
        return $this->hasMany(B2bQuotationItem::class, 'quotation_id');
    }

    public function orders()
    {
        return $this->hasMany(B2bOrder::class, 'accepted_quotation_id');
    }
}
