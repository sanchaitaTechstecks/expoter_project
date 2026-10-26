<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class B2bDispute extends Model
{
    use SoftDeletes;

    protected $table = 'b2b_disputes';

    protected $fillable = [
        'dispute_number',
        'order_id',
        'raised_by_id',
        'against_party_id',
        'dispute_type',
        'status',
        'title',
        'description',
        'claim_amount',
        'currency',
        'evidence_attachments',
        'resolution_action',
        'settlement_amount',
        'resolved_by_id',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'claim_amount' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'evidence_attachments' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(B2bOrder::class, 'order_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    public function againstParty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'against_party_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(B2bDisputeMessage::class, 'dispute_id');
    }
}
