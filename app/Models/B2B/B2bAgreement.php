<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bAgreement extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_agreements';

    protected $fillable = [
        'agreement_number',
        'order_id',
        'quotation_id',
        'buyer_id',
        'supplier_id',
        'version',
        'terms_and_conditions',
        'incoterm',
        'named_place',
        'total_value',
        'currency',
        'payment_schedule',
        'buyer_signature',
        'buyer_signed_at',
        'supplier_signature',
        'supplier_signed_at',
        'status',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
        'buyer_signed_at' => 'datetime',
        'supplier_signed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(B2bOrder::class, 'order_id');
    }

    public function quotation()
    {
        return $this->belongsTo(B2bQuotation::class, 'quotation_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }
}
