<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2bWarehouseTransferItem extends Model
{
    protected $table = 'b2b_warehouse_transfer_items';

    protected $fillable = [
        'transfer_id',
        'lot_id',
        'quantity',
        'unit',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function transfer()
    {
        return $this->belongsTo(B2bWarehouseTransfer::class, 'transfer_id');
    }

    public function lot()
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }
}
