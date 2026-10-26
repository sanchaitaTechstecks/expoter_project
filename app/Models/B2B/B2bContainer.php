<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2bContainer extends Model
{
    protected $table = 'b2b_containers';

    protected $fillable = [
        'shipment_id',
        'container_number',
        'container_type',
        'seal_number',
        'loaded_quantity',
        'unit',
        'gross_weight',
        'net_weight',
        'current_location',
        'status',
    ];

    protected $casts = [
        'loaded_quantity' => 'decimal:3',
        'gross_weight' => 'decimal:3',
        'net_weight' => 'decimal:3',
    ];

    public function shipment()
    {
        return $this->belongsTo(B2bShipment::class, 'shipment_id');
    }
}
