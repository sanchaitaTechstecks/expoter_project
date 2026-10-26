<?php

namespace App\Http\Controllers;

use App\Models\PaymentInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentInformationController extends Controller
{
    public function store(Request $request)
    {
        $payment_information = new PaymentInformation;
        $payment_information->user_id   = Auth::user()->id;
        $payment_information->payment_type       = $request->payment_type;
        $payment_information->payment_instruction       = $request->payment_instructions;
        $payment_information->payment_name       = $request->payment_name;
        $payment_information->bank_name       = $request->bank_name;
        $payment_information->account_name       = $request->account_name;
        $payment_information->account_number       = $request->account_number;
        $payment_information->routing_number       = $request->routing_number;
        $payment_information->save();

        flash(translate('Payment info Stored successfully'))->success();
        return back();
    }

    public function edit($id)
    {
        $data['payment_information_data'] = PaymentInformation::findOrFail($id);
        $returnHTML = view('frontend.partials.payment_information.payment_information_edit_modal', $data)->render();
        return response()->json(array('data' => $data, 'html' => $returnHTML));
    }

    public function update(Request $request, $id)
    {
        $payment_information = PaymentInformation::findOrFail($id);
        $payment_information->payment_instruction       = $request->payment_instructions;
        $payment_information->payment_name       = $request->payment_name;
        $payment_information->bank_name = $request->bank_name;
        $payment_information->account_name    = $request->account_name;
        $payment_information->account_number    = $request->account_number;
        $payment_information->routing_number    = $request->routing_number;
        $payment_information->save();
        flash(translate('Payment information updated successfully'))->success();
        return back();
    }

    public function destroy($id)
    {
        $payment_information = PaymentInformation::findOrFail($id);
        if (!$payment_information->set_default) {
            $payment_information->delete();
            return back();
        }
        flash(translate('Default Payment information cannot be deleted'))->warning();
        return back();
    }

    public function set_default($id)
    {
        foreach (Auth::user()->payment_informations as $key => $payment_information) {
            $payment_information->set_default = 0;
            $payment_information->save();
        }
        $payment_information = PaymentInformation::findOrFail($id);
        $payment_information->set_default = 1;
        $payment_information->save();

        return back();
    }
}
