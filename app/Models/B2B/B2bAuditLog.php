<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2bAuditLog extends Model
{
    protected $table = 'b2b_audit_logs';

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'before_state',
        'after_state',
        'diff_summary',
        'reason',
        'ip_address',
        'user_agent',
        'request_id',
        'status',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * SRS Section 19.3: Prevent modification or deletion of immutable audit logs.
     */
    public static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('System audit log records are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('System audit log records are immutable and cannot be deleted.');
        });
    }
}
