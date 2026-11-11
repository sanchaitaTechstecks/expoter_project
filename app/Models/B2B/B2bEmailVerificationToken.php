<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class B2bEmailVerificationToken extends Model
{
    protected $table = 'b2b_email_verification_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
