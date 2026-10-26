<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    protected $table = 'price_histories';

    protected $fillable = [
        'lot_id',
        'old_price',
        'new_price',
        'currency',
        'reason',
        'actor_id',
        'effective_at',
        'approval_status',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'effective_at' => 'datetime',
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
