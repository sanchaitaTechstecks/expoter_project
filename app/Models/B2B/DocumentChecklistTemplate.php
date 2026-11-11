<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class DocumentChecklistTemplate extends Model
{
    protected $table = 'b2b_document_checklist_templates';

    protected $fillable = [
        'name',
        'commodity',
        'trade_type',
        'origin_country',
        'destination_country',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(DocumentChecklistTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
