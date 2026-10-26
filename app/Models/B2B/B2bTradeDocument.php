<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bTradeDocument extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_trade_documents';

    protected $fillable = [
        'document_type',
        'order_id',
        'shipment_id',
        'lot_id',
        'uploader_id',
        'document_number',
        'title',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'issue_date',
        'expiry_date',
        'issuer',
        'version',
        'status',
        'review_comments',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(B2bOrder::class, 'order_id');
    }

    public function shipment()
    {
        return $this->belongsTo(B2bShipment::class, 'shipment_id');
    }

    public function lot()
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
