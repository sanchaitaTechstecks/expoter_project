<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class DocumentChecklistTemplateItem extends Model
{
    protected $table = 'b2b_document_checklist_template_items';

    protected $fillable = [
        'template_id',
        'document_type',
        'is_mandatory',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(DocumentChecklistTemplate::class, 'template_id');
    }
}
