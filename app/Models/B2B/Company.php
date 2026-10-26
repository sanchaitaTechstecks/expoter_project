<?php

namespace App\Models\B2B;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $table = 'companies';

    protected $fillable = [
        'user_id',
        'name',
        'legal_name',
        'company_type',
        'trade_type',
        'country',
        'iec_code',
        'tax_number',
        'registration_number',
        'contact_email',
        'contact_phone',
        'website',
        'address',
        'city',
        'state',
        'postal_code',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'swift_bic',
        'iban',
        'bank_branch',
        'verification_status',
        'verification_notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
