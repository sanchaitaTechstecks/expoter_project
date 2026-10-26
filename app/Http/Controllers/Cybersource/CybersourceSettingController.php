<?php

namespace App\Http\Controllers\Cybersource;

use App\Http\Controllers\Controller;

class CybersourceSettingController extends Controller
{
    public function configuration()
    {
        return response()->json(['message' => 'Cybersource configuration']);
    }
}
