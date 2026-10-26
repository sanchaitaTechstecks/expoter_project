<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $table = 'inventory_movements';

    protected $fillable = [
        'lot_id',
        'movement_type',
        'quantity',
        'unit',
        'reference_type',
        'reference_id',
        'reason',
        'actor_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function lot()
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
