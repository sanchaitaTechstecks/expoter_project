<?php

namespace App\Models\B2B;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class ProductGalleryImage extends Model
{
    protected $table = 'product_gallery_images';

    protected $fillable = [
        'product_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'sort_order',
        'is_primary',
        'uploaded_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'file_size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
