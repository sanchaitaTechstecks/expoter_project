<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bCreditNote extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_credit_notes';

    protected $fillable = [
        'credit_note_number',
        'order_id',
        'dispute_id',
        'payment_id',
        'buyer_id',
        'supplier_id',
        'type',
        'amount',
        'currency',
        'exchange_rate',
        'base_currency',
        'base_amount',
        'reason',
        'status',
        'issued_by',
        'issued_at',
        'applied_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'base_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(B2bCreditNoteItem::class, 'credit_note_id');
    }

    public function order()
    {
        return $this->belongsTo(B2bOrder::class, 'order_id');
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
