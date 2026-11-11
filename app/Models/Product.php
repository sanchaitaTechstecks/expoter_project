<?php

namespace App\Models;

use App;
use Illuminate\Database\Eloquent\Model;
use App\Traits\PreventDemoModeChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;


class Product extends Model
{
    use PreventDemoModeChanges;
    
    protected $guarded = ['choice_attributes'];

    protected $with = ['product_translations', 'taxes', 'thumbnail'];

    protected $casts = [
        'prescription_required' => 'boolean',
        'use_by_date' => 'date:Y-m-d',
        'archived_at' => 'datetime',
    ];

    public function getTranslation($field = '', $lang = false)
    {
        $lang = $lang == false ? App::getLocale() : $lang;
        $product_translations = $this->product_translations->where('lang', $lang)->first();
        return $product_translations != null ? $product_translations->$field : $this->$field;
    }

    public function product_translations()
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function main_category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
    
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    public function frequently_bought_products()
    {
        return $this->hasMany(FrequentlyBoughtProduct::class);
    }

    public function product_categories()
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orderDetails()
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function product_queries()
    {
        return $this->hasMany(ProductQuery::class);
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function taxes()
    {
        return $this->hasMany(ProductTax::class);
    }

    public function flash_deal_products()
    {
        return $this->hasMany(FlashDealProduct::class);
    }

    public function bids()
    {
        return $this->hasMany(AuctionProductBid::class);
    }

    public function thumbnail()
    {
        return $this->belongsTo(Upload::class, 'thumbnail_img');
    }

    public function scopePhysical($query)
    {
        return $query->where('digital', 0);
    }

    public function scopeDigital($query)
    {
        return $query->where('digital', 1);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }
    
    public function scopeIsApprovedPublished($query)
    {
        return $query->where('approved', '1')->where('published', 1);
    }

    public function last_viewed_products()
    {
        return $this->hasMany(LastViewedProduct::class);
    }

    public function warranty()
    {
        return $this->belongsTo(Warranty::class);
    }

    public function warrantyNote()
    {
        return $this->belongsTo(Note::class, 'warranty_note_id');
    }

    public function refundNote()
    {
        return $this->belongsTo(Note::class, 'refund_note_id');
    }

    public function customSaleAlerts()
    {
        return $this->hasMany(CustomSaleAlert::class, 'product_id');
    }

    // add gallery image to thumb

   public function thumbnailImg(): Attribute
    {
        return Attribute::get(function ($value, $attributes) {
            $photos = $attributes['photos'] ?? null;

            if ($photos) {
                $photosArray = explode(',', $photos);
                $count = count($photosArray);

                return $value ?: ($count > 0 ? $photosArray[0] : null);
            }

            return $value;
        });
    }


    protected function videoLink(): Attribute
    {
        return Attribute::make(
           
            get: fn($value) => json_decode($value, true), 

         
             set: function ($value) {
                if (!is_array($value)) {
                    return null;
                }
            
                $filtered = array_filter($value, function ($item) {
                    return trim($item) !== '';
                });

                return empty($filtered) ? null : json_encode($filtered);
            },
        );
    }

    public function lots()
    {
        return $this->hasMany(\App\Models\B2B\ProductLot::class, 'product_id');
    }

    public function activeLots()
    {
        return $this->hasMany(\App\Models\B2B\ProductLot::class, 'product_id')->where('lot_status', 'active');
    }
}
