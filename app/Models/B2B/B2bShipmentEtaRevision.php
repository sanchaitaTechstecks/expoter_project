<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class B2bShipmentEtaRevision extends Model
{
    protected $table = 'b2b_shipment_eta_revisions';

    protected $fillable = [
        'shipment_id',
        'previous_eta',
        'new_eta',
        'reason',
        'revised_by',
    ];

    protected $casts = [
        'previous_eta' => 'date:Y-m-d',
        'new_eta' => 'date:Y-m-d',
    ];

    public function shipment()
    {
        return $this->belongsTo(B2bShipment::class, 'shipment_id');
    }

    public function reviser()
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
