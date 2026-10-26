<?php

namespace App\Models\B2B;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductLot extends Model
{
    use SoftDeletes;

    protected $table = 'product_lots';

    protected $fillable = [
        'lot_code',
        'product_id',
        'supplier_id',
        'warehouse_location',
        'quality_grade',
        'inspection_status',
        'quantity_received',
        'quantity_reserved',
        'quantity_dispatched',
        'quantity_available',
        'unit',
        'unit_price',
        'currency',
        'domestic_price',
        'export_price',
        'incoterm',
        'named_place',
        'packaging_details',
        'manufacture_or_harvest_date',
        'expiry_date',
        'certificates',
        'photos',
        'lot_status',
        'approval_status',
        'approval_notes',
        'approved_by',
        'uploaded_at',
        'effective_at',
    ];

    protected $casts = [
        'certificates' => 'array',
        'photos' => 'array',
        'quantity_received' => 'decimal:3',
        'quantity_reserved' => 'decimal:3',
        'quantity_dispatched' => 'decimal:3',
        'quantity_available' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'domestic_price' => 'decimal:2',
        'export_price' => 'decimal:2',
        'manufacture_or_harvest_date' => 'date',
        'expiry_date' => 'date',
        'uploaded_at' => 'datetime',
        'effective_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function priceHistories()
    {
        return $this->hasMany(PriceHistory::class, 'lot_id');
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class, 'lot_id');
    }
}
