<script type="text/javascript">
    function isRefundPage() {
        return $('#refund-payment-context').length > 0;
    }

    function edit_payment_information(payment_information) {
        var url = '{{ route("payment_informations.edit", ":id") }}';
        url = url.replace(':id', payment_information);
        $.ajax({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            url: url,
            type: 'GET',
            success: function(response) {
                $('#edit_modal_body_payment').html(response.html);
                $('#edit-payment-information-modal').modal('show');
                AIZ.plugins.bootstrapSelect('refresh');
            }
        });
    }

    function add_new_payment_information() {
        $('#new-payment-information-modal').modal('show');
    }


    $(document).ready(function() {

        $('input[name="preferred_payment_channel"][value="wallet"]').prop('checked', true);
        $('#paymentInformationSection').hide();

        $('input[name="preferred_payment_channel"]').on('change', function() {

            if ($(this).val() === 'offline') {

                $('#paymentInformationSection').slideDown();

                if ($('input[name="single_payment_infomation_id"]:checked').length === 0) {
                    $('input[name="single_payment_infomation_id"]').first().prop('checked', true);
                }

            } else if ($(this).val() === 'wallet') {

                $('#payment_information_id').val('');

                $('#paymentInformationSection').slideUp();
            }
        });

    });

    $(document).on('change', '#new-payment-information-modal select[name="payment_type"]', function () {
        let modal = $('#new-payment-information-modal');
        let type = $(this).val();

        modal.find('.basic-fields, .bank-fields').addClass('d-none');
        modal.find('input, textarea').removeAttr('required');

    if (type === 'bank_transfer') {
        modal.find('.bank-fields').removeClass('d-none');
        modal.find('.bank-fields input').attr('required', true);
    }

    if (type === 'others') {
        modal.find('.basic-fields').removeClass('d-none');
        modal.find('.basic-fields input, .basic-fields textarea').attr('required', true);
    }
    });


    $('#new-payment-information-modal').on('shown.bs.modal', function () {
        let modal = $(this);

        modal.find('form')[0].reset();

        modal.find('select[name="payment_type"]')
            .val('')
            .selectpicker('refresh');

        modal.find('.basic-fields, .bank-fields').addClass('d-none');
    });



    $('#new-payment-information-modal').on('hidden.bs.modal', function () {
        let modal = $(this);

        modal.find('form')[0].reset();

        modal.find('select[name="payment_type"]')
            .val('')
            .selectpicker('refresh');

        modal.find('.basic-fields, .bank-fields').addClass('d-none');
        modal.find('input, textarea').removeAttr('required');

        this.offsetHeight;
    });

    function editSelectedPaymentInformation(id) {
        if (!id) {
            alert('Please select a payment information first');
            return;
        }

        edit_payment_information(id);
    }

    // Whenever user selects a payment info radio, update the hidden input
    $(document).on('change', 'input[name="single_payment_infomation_id"]', function() {
        $('#payment_information_id').val($(this).val());
    });

    // New Payment Information Modal
    $(document).on('submit', '#new-payment-information-modal form', function (e) {
        if (!isRefundPage()) {
            return true; 
        }

        e.preventDefault(); 
        let form = $(this);
        let submitBtn = form.find('button[type="submit"]');

        // Disable button and show loading
        let saveBtnText = '{{ translate("Save") }}';

        submitBtn.prop('disabled', true)
        .html('<i class="las la-spinner la-spin mr-1"></i>{{ translate("Saving...") }}');

        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: form.serialize(),
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function () {
                $('#new-payment-information-modal').modal('hide');

                $('#refund-payment-context')
                    .load(location.href + ' #refund-payment-context > *', function() {
                        // Re-check previously selected radio
                        var selected = $('#payment_information_id').val();
                        if (selected) {
                            $('input[name="single_payment_infomation_id"][value="'+selected+'"]').prop('checked', true);
                        } else {
                            // Or check first one by default if none selected
                            var first = $('input[name="single_payment_infomation_id"]').first();
                            first.prop('checked', true);
                            $('#payment_information_id').val(first.val());
                        }

                        // Re-enable button after reload
                        submitBtn.prop('disabled', false).html(saveBtnText);
                    }
                );

            },
            error: function () {
                alert('Something went wrong');
                submitBtn.prop('disabled', false).text('{{ translate("Save") }}');
            }
        });
    });

    // Edit Payment Information Modal
    $(document).on('submit', '#edit-payment-information-modal form', function (e) {
        if (!isRefundPage()) {
            return true;
        }

        e.preventDefault();
        let form = $(this);
        let submitBtn = form.find('button[type="submit"]');

        // Disable button and show loading
        let updateBtnText = '{{ translate("Update") }}';

        submitBtn.prop('disabled', true)
        .html('<i class="las la-spinner la-spin mr-1"></i>{{ translate("Updating...") }}');

        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: form.serialize(),
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function () {
                $('#edit-payment-information-modal').modal('hide');

                $('#refund-payment-context')
                    .load(location.href + ' #refund-payment-context > *', function() {
                        // Re-enable button after reload
                        submitBtn.prop('disabled', false).text('{{ translate("Update") }}');
                    });
            },
            error: function () {
                alert('Something went wrong');
                submitBtn.prop('disabled', false).html(updateBtnText);
            }
        });
    });

    $('#aizSubmitForm').on('submit', function () {
        let channel = $('input[name="preferred_payment_channel"]:checked').val();

        if (channel === 'offline' && $('input[name="single_payment_infomation_id"]').length) {
            let selected = $('input[name="single_payment_infomation_id"]:checked').val();

            if (!selected) {
                alert('Please select a payment information');
                return false;
            }

            $('#payment_information_id').val(selected);
        }
    });


</script>