<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2bUnitConversion extends Model
{
    protected $table = 'b2b_unit_conversions';

    protected $fillable = [
        'from_unit',
        'to_unit',
        'factor',
        'category',
        'is_active',
    ];

    protected $casts = [
        'factor' => 'decimal:8',
        'is_active' => 'boolean',
    ];
}
