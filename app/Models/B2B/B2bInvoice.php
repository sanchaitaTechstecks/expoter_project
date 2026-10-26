<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_invoices';

    protected $fillable = [
        'invoice_number',
        'invoice_type',
        'order_id',
        'buyer_id',
        'supplier_id',
        'issue_date',
        'due_date',
        'currency',
        'subtotal',
        'tax',
        'freight',
        'insurance',
        'other_charges',
        'total_amount',
        'bank_details',
        'payment_terms',
        'status',
        'file_url',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'freight' => 'decimal:2',
        'insurance' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'bank_details' => 'array',
    ];

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

    public function payments()
    {
        return $this->hasMany(B2bPayment::class, 'invoice_id');
    }
}
