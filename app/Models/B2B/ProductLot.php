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
        'destination_eligibility',
        'inspection_status',
        'quantity_received',
        'quantity_reserved',
        'quantity_dispatched',
        'quantity_available',
        'low_stock_threshold',
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
        'destination_eligibility' => 'array',
        'quantity_received' => 'decimal:3',
        'quantity_reserved' => 'decimal:3',
        'quantity_dispatched' => 'decimal:3',
        'quantity_available' => 'decimal:3',
        'low_stock_threshold' => 'decimal:3',
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

    /**
     * Shelf-life ageing (P2-4): days remaining / elapsed and an ageing bucket.
     */
    public function getShelfLifeAttribute(): array
    {
        if (!$this->expiry_date) {
            return [
                'has_expiry' => false,
                'days_remaining' => null,
                'days_since_harvest' => null,
                'ageing_bucket' => 'not_tracked',
            ];
        }

        $daysRemaining = (int) now()->startOfDay()->diffInDays($this->expiry_date, false);
        $shelfLifeDays = $this->manufacture_or_harvest_date
            ? (int) $this->manufacture_or_harvest_date->diffInDays($this->expiry_date)
            : null;
        $elapsed = $this->manufacture_or_harvest_date
            ? (int) $this->manufacture_or_harvest_date->diffInDays(now()->startOfDay())
            : null;

        if ($daysRemaining < 0) {
            $bucket = 'expired';
        } elseif ($daysRemaining <= 30) {
            $bucket = 'critical';
        } elseif ($daysRemaining <= 90) {
            $bucket = 'aging';
        } else {
            $bucket = 'fresh';
        }

        return [
            'has_expiry' => true,
            'shelf_life_days' => $shelfLifeDays,
            'days_since_harvest' => $elapsed,
            'days_remaining' => $daysRemaining,
            'pct_elapsed' => $shelfLifeDays ? round(min(100, max(0, ($elapsed / $shelfLifeDays) * 100)), 2) : null,
            'ageing_bucket' => $bucket,
        ];
    }
}
