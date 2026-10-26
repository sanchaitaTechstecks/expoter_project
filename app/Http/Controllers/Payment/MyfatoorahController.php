<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;

class MyfatoorahController extends Controller
{
    public function callback()
    {
        return response()->json(['message' => 'MyFatoorah callback']);
    }
}
