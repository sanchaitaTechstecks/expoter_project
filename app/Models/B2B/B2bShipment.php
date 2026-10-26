<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bShipment extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_shipments';

    protected $fillable = [
        'shipment_number',
        'order_id',
        'supplier_id',
        'buyer_id',
        'mode',
        'carrier_name',
        'freight_forwarder',
        'booking_reference',
        'tracking_number',
        'vessel_name',
        'voyage_number',
        'origin_port',
        'origin_address',
        'destination_port',
        'final_destination',
        'departure_date',
        'estimated_arrival',
        'actual_arrival',
        'status',
        'customs_status',
        'assigned_cha',
        'assigned_cha_id',
        'notes',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'estimated_arrival' => 'date',
        'actual_arrival' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(B2bOrder::class, 'order_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function containers()
    {
        return $this->hasMany(B2bContainer::class, 'shipment_id');
    }

    public function trackingEvents()
    {
        return $this->hasMany(B2bTrackingEvent::class, 'shipment_id')->orderBy('event_type', 'asc');
    }

    public function documents()
    {
        return $this->hasMany(B2bTradeDocument::class, 'shipment_id');
    }

    public function customsDeclarations()
    {
        return $this->hasMany(B2bCustomsDeclaration::class, 'shipment_id');
    }

    public function latestDeclaration()
    {
        return $this->hasOne(B2bCustomsDeclaration::class, 'shipment_id')->latestOfMany();
    }

    public function chaUser()
    {
        return $this->belongsTo(User::class, 'assigned_cha_id');
    }
}
