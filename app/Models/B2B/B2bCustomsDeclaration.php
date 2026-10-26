<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bCustomsDeclaration extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_customs_declarations';

    protected $fillable = [
        'shipment_id',
        'cha_user_id',
        'filing_type',
        'declaration_number',
        'port_code',
        'filing_date',
        'cha_license_number',
        'iec_code',
        'assessable_value',
        'currency',
        'basic_customs_duty',
        'igst_amount',
        'cess_amount',
        'total_duty_payable',
        'duty_payment_status',
        'duty_challan_number',
        'duty_paid_at',
        'examination_status',
        'examination_order_no',
        'examination_date',
        'examination_officer',
        'examination_remarks',
        'query_description',
        'query_response',
        'clearance_status',
        'release_order_number',
        'released_at',
        'release_notes',
        'customs_documents',
        'checklist_snapshot',
    ];

    protected $casts = [
        'assessable_value' => 'decimal:2',
        'basic_customs_duty' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'total_duty_payable' => 'decimal:2',
        'filing_date' => 'date',
        'examination_date' => 'date',
        'duty_paid_at' => 'datetime',
        'released_at' => 'datetime',
        'customs_documents' => 'array',
        'checklist_snapshot' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(B2bShipment::class, 'shipment_id');
    }

    public function cha(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cha_user_id');
    }
}
