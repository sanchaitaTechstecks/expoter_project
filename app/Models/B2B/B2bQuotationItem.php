<?php

namespace App\Models\B2B;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class B2bQuotationItem extends Model
{
    protected $table = 'b2b_quotation_items';

    protected $fillable = [
        'quotation_id',
        'product_id',
        'lot_id',
        'product_name_snapshot',
        'specification_snapshot',
        'quantity',
        'unit',
        'unit_price',
        'line_total',
        'packaging',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function quotation()
    {
        return $this->belongsTo(B2bQuotation::class, 'quotation_id');
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
