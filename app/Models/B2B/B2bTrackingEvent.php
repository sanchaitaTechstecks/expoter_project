<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class B2bTrackingEvent extends Model
{
    protected $table = 'b2b_tracking_events';

    protected $fillable = [
        'shipment_id',
        'container_id',
        'event_type',
        'location',
        'event_status',
        'planned_at',
        'actual_at',
        'timezone',
        'event_time_local',
        'actual_at_utc',
        'source',
        'description',
        'evidence_attachment',
        'created_by',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'actual_at' => 'datetime',
        // Wall-clock time in the event's own timezone: return it unwrapped, never with a UTC marker.
        'event_time_local' => 'datetime:Y-m-d H:i:s',
        'actual_at_utc' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(B2bShipment::class, 'shipment_id');
    }

    public function container()
    {
        return $this->belongsTo(B2bContainer::class, 'container_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
