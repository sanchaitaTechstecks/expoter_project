<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2bShipmentVehicle extends Model
{
    protected $table = 'b2b_shipment_vehicles';

    protected $fillable = [
        'shipment_id',
        'vehicle_type',
        'vehicle_number',
        'driver_name',
        'driver_phone',
        'driver_license',
        'capacity',
        'unit',
        'status',
        'assigned_at',
        'released_at',
        'actor_id',
    ];

    protected $casts = [
        'capacity' => 'decimal:3',
        'assigned_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(B2bShipment::class, 'shipment_id');
    }
}
