<?php

namespace App\Models\B2B;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rfq extends Model
{
    use SoftDeletes;

    protected $table = 'rfqs';

    protected $fillable = [
        'rfq_number',
        'buyer_id',
        'supplier_id',
        'product_id',
        'preferred_lot_ids',
        'requested_quantity',
        'unit',
        'target_price',
        'quality_specification',
        'packaging_requirement',
        'trade_type',
        'destination_country',
        'destination_port_or_address',
        'requested_delivery_window',
        'incoterm',
        'named_place',
        'currency',
        'required_documents',
        'notes',
        'attachments',
        'status',
        'assigned_to',
        'assigned_by',
        'assigned_at',
        'draft_saved_at',
        'draft_notes',
        'rfq_type',
        'source_channel',
        'dec013_export_offers',
        'dec013_external_id',
        'offer_count',
        'offer_by_supplier',
        'rejection_reason',
    ];

    protected $casts = [
        'preferred_lot_ids' => 'array',
        'required_documents' => 'array',
        'attachments' => 'array',
        'requested_quantity' => 'decimal:3',
        'target_price' => 'decimal:2',
        'assigned_at' => 'datetime',
        'assigned_by' => 'integer',
        'draft_saved_at' => 'datetime',
        'offer_count' => 'integer',
        'offer_by_supplier' => 'integer',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function messages()
    {
        return $this->hasMany(RfqMessage::class, 'rfq_id')->orderBy('created_at', 'asc');
    }

    public function quotations()
    {
        return $this->hasMany(B2bQuotation::class, 'rfq_id');
    }
}
