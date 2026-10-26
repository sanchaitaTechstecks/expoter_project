<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bOrder extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_orders';

    protected $fillable = [
        'order_number',
        'buyer_id',
        'supplier_id',
        'source_rfq_id',
        'accepted_quotation_id',
        'agreement_id',
        'order_date',
        'order_type',
        'status',
        'currency',
        'incoterm',
        'named_place',
        'total_quantity',
        'subtotal',
        'discounts',
        'taxes',
        'freight',
        'insurance',
        'total_amount',
        'amount_paid',
        'balance_due',
        'payment_status',
        'expected_delivery',
        'destination_country',
        'destination_port_or_address',
        'notes',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery' => 'date',
        'total_quantity' => 'decimal:3',
        'subtotal' => 'decimal:2',
        'discounts' => 'decimal:2',
        'taxes' => 'decimal:2',
        'freight' => 'decimal:2',
        'insurance' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance_due' => 'decimal:2',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function sourceRfq()
    {
        return $this->belongsTo(Rfq::class, 'source_rfq_id');
    }

    public function acceptedQuotation()
    {
        return $this->belongsTo(B2bQuotation::class, 'accepted_quotation_id');
    }

    public function items()
    {
        return $this->hasMany(B2bOrderItem::class, 'order_id');
    }

    public function agreement()
    {
        return $this->hasOne(B2bAgreement::class, 'order_id');
    }

    public function invoices()
    {
        return $this->hasMany(B2bInvoice::class, 'order_id');
    }

    public function payments()
    {
        return $this->hasMany(B2bPayment::class, 'order_id');
    }

    public function documents()
    {
        return $this->hasMany(B2bTradeDocument::class, 'order_id');
    }

    public function shipments()
    {
        return $this->hasMany(B2bShipment::class, 'order_id');
    }
}
