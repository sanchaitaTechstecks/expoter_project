<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2bContainerAssignment extends Model
{
    protected $table = 'b2b_container_assignments';

    protected $fillable = [
        'container_id',
        'shipment_id',
        'previous_shipment_id',
        'action',
        'actor_id',
        'notes',
    ];

    public function container()
    {
        return $this->belongsTo(B2bContainer::class, 'container_id');
    }
}
