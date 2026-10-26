<?php

namespace App\Models\B2B;

use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2bHsCode extends Model
{
    protected $table = 'b2b_hs_codes';

    protected $fillable = [
        'hs_code',
        'description',
        'category_id',
        'chapter',
        'heading',
        'basic_customs_duty_pct',
        'cess_pct',
        'igst_pct',
        'export_duty_pct',
        'policy_status',
        'is_active',
    ];

    protected $casts = [
        'basic_customs_duty_pct' => 'decimal:2',
        'cess_pct' => 'decimal:2',
        'igst_pct' => 'decimal:2',
        'export_duty_pct' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
