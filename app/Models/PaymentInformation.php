<?php

namespace App\Models;

use App\Traits\PreventDemoModeChanges;
use Illuminate\Database\Eloquent\Model;

class PaymentInformation extends Model
{
    use PreventDemoModeChanges;
    protected $guarded = [];
    protected $table = "payment_informations";
}
