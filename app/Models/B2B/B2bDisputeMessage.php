<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2bDisputeMessage extends Model
{
    protected $table = 'b2b_dispute_messages';

    protected $fillable = [
        'dispute_id',
        'sender_id',
        'message',
        'attachments',
        'is_internal_admin_note',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_internal_admin_note' => 'boolean',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(B2bDispute::class, 'dispute_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
