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
