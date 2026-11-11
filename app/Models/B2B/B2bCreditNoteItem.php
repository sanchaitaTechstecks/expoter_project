<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2bCreditNoteItem extends Model
{
    protected $table = 'b2b_credit_note_items';

    protected $fillable = [
        'credit_note_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function creditNote()
    {
        return $this->belongsTo(B2bCreditNote::class, 'credit_note_id');
    }
}
