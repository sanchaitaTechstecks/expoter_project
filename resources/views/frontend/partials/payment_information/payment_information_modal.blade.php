<!-- New Payment Information Modal -->
<div class="modal fade" id="new-payment-information-modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">{{ translate('New Payment Information') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body c-scrollbar-light h-300px" id="edit_modal_body">
                <form id="aizSubmitForm" class="form-horizontal" action="{{ route('payment_informations.store') }}" method="POST">
                    @csrf
                    <input type="hidden" name="from_refund_page" value="1">

                    <!-- Type -->
                    <div class="form-group mb-3">
                        <label>{{ translate('Type')}} <span class="text-danger">*</span></label>
                        <select name="payment_type" class="form-control aiz-selectpicker" data-live-search="true">
                            <option value="">{{ translate('Select Payment Type') }}</option>
                            <option value="bank_transfer">{{ ucfirst(translate('Bank Transfer'))  }}</option>
                            <option value="others">{{ ucfirst(translate('Others'))  }}</option>
                        </select>
                    </div>
                    <div class="basic-fields d-none">
                        <!-- Name -->
                        <div class="form-group mb-3">
                            <label>{{ translate('Name')}} <span class="text-danger">*</span></label>
                            <input class="form-control mb-3 rounded-0" name="payment_name">
                        </div>

                        <!-- Payment Instructions -->
                        <div class="form-group mb-3">
                            <label>{{ translate('Payment Instructions')}} <span class="text-danger">*</span></label>
                            <textarea class="form-control mb-3 rounded-0" rows="3" name="payment_instructions"></textarea>
                        </div>
                        <!-- Save button -->
                        <div class="form-group text-right">
                            <button type="submit" class="btn btn-primary rounded-0 w-150px">{{translate('Save')}}</button>
                        </div>
                    </div>
                    <div class="bank-fields d-none">
                        <div class="form-group mb-3">
                            <label>{{ translate('Bank Name')}} <span class="text-danger">*</span></label>
                            <input class="form-control mb-3 rounded-0" name="bank_name">
                        </div>

                        <div class="form-group mb-3">
                            <label>{{ translate('Account Name')}} <span class="text-danger">*</span></label>
                            <input class="form-control mb-3 rounded-0" name="account_name">
                        </div>

                        <div class="form-group mb-3">
                            <label>{{ translate('Account Number')}} <span class="text-danger">*</span></label>
                            <input class="form-control mb-3 rounded-0" name="account_number">
                        </div>

                        <div class="form-group mb-3">
                            <label>{{ translate('Routing Number')}} <span class="text-danger">*</span></label>
                            <input class="form-control mb-3 rounded-0" name="routing_number">
                        </div>
                        <!-- Save button -->
                        <div class="form-group text-right">
                            <button type="submit" class="btn btn-primary rounded-0 w-150px payment-save-btn">{{translate('Save')}}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
