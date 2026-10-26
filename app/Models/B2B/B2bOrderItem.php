<?php

namespace App\Models\B2B;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class B2bOrderItem extends Model
{
    protected $table = 'b2b_order_items';

    protected $fillable = [
        'order_id',
        'product_id',
        'lot_id',
        'product_name_snapshot',
        'specification_snapshot',
        'quantity',
        'unit',
        'unit_price',
        'line_total',
        'allocated_quantity',
        'dispatched_quantity',
        'delivered_quantity',
        'outstanding_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'allocated_quantity' => 'decimal:3',
        'dispatched_quantity' => 'decimal:3',
        'delivered_quantity' => 'decimal:3',
        'outstanding_quantity' => 'decimal:3',
    ];

    public function order()
    {
        return $this->belongsTo(B2bOrder::class, 'order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function lot()
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }
}
