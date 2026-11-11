<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class B2bInventoryAlert extends Model
{
    protected $table = 'inventory_alerts';

    protected $fillable = [
        'lot_id',
        'supplier_id',
        'alert_type',
        'severity',
        'quantity_available',
        'threshold',
        'expiry_date',
        'message',
        'status',
        'acknowledged_at',
        'acknowledged_by',
    ];

    protected $casts = [
        'quantity_available' => 'decimal:3',
        'threshold' => 'decimal:3',
        'expiry_date' => 'date',
        'acknowledged_at' => 'datetime',
    ];

    public function lot()
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
