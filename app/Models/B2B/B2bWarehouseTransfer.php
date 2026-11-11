<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class B2bWarehouseTransfer extends Model
{
    protected $table = 'b2b_warehouse_transfers';

    protected $fillable = [
        'transfer_number',
        'supplier_id',
        'from_warehouse',
        'to_warehouse',
        'status',
        'notes',
        'requested_by',
        'completed_by',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(B2bWarehouseTransferItem::class, 'transfer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }
}
