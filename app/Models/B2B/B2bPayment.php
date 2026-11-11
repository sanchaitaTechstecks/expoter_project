<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class B2bPayment extends Model
{
    protected $table = 'b2b_payments';

    protected $fillable = [
        'payment_number',
        'order_id',
        'invoice_id',
        'buyer_id',
        'supplier_id',
        'amount',
        'currency',
        'exchange_rate',
        'base_currency',
        'base_amount',
        'transaction_at',
        'reconciliation_status',
        'bank_statement_reference',
        'reconciled_by',
        'reconciled_at',
        'payment_type',
        'payment_method',
        'transaction_reference',
        'proof_file',
        'due_date',
        'initiated_at',
        'received_at',
        'status',
        'verified_by',
        'verified_at',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'initiated_at' => 'datetime',
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
        'exchange_rate' => 'decimal:8',
        'base_amount' => 'decimal:2',
        'transaction_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(B2bOrder::class, 'order_id');
    }

    public function invoice()
    {
        return $this->belongsTo(B2bInvoice::class, 'invoice_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function reconciler()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
