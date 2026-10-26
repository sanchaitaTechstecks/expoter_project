<form id="aizSubmitForm edit-payment-information-modal" class="form-horizontal" action="{{ route('payment_informations.update', $payment_information_data->id) }}" method="POST">
    @csrf
    @if ($payment_information_data->payment_type == 'bank_transfer')
        <!-- Payment Information -->
        <div class="form-group mb-3">
            <label class="col-from-label">{{ translate('Bank Name')}} <span class="text-danger">*</span></label>
            <input class="form-control mb-3 rounded-0" placeholder="{{ translate('Bank Name')}}" name="bank_name" required value="{{ $payment_information_data->bank_name }}">
        </div>
        <div class="form-group mb-3">
            <label class="col-from-label">{{ translate('Account Name')}} <span class="text-danger">*</span></label>
            <input class="form-control mb-3 rounded-0" placeholder="{{ translate('Account Name')}}" name="account_name" required value="{{ $payment_information_data->account_name }}">
        </div>
        <div class="form-group mb-3">
            <label class="col-from-label">{{ translate('Account Number')}} <span class="text-danger">*</span></label>
            <input class="form-control mb-3 rounded-0" placeholder="{{ translate('Account Number')}}" name="account_number" required value="{{ $payment_information_data->account_number }}">
        </div>
        <div class="form-group mb-3">
            <label class="col-from-label">{{ translate('Routing Number')}} <span class="text-danger">*</span></label>
            <input class="form-control mb-3 rounded-0" placeholder="{{ translate('Routing Number')}}" name="routing_number" required value="{{ $payment_information_data->routing_number }}">
        </div>
    @else
        <div class="form-group mb-3">
            <label class="col-from-label">{{ translate('Name')}} <span class="text-danger">*</span></label>
            <input class="form-control mb-3 rounded-0" placeholder="{{ translate('Name')}}" name="payment_name" required value="{{ $payment_information_data->payment_name }}">
        </div>
        <div class="form-group mb-3">
            <label class="col-from-label">{{ translate('Payment Instructions')}} <span class="text-danger">*</span></label>
            <textarea class="form-control mb-3 rounded-0" rows="3" placeholder="{{ translate('Payment Instructions')}}" name="payment_instructions" required>{{ $payment_information_data->payment_instruction }}</textarea>
        </div>
    @endif
    <!-- Save button -->
    <div class="form-group mb-0 text-right mt-3">
        <button type="submit" class="btn btn-primary rounded-0 w-150px payment-update-btn">{{translate('Update')}}</button>
    </div>
</form>