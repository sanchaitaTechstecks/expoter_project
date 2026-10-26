@extends('backend.layouts.app')

@section('content')
    <div class="aiz-titlebar text-left mt-2 mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="h3">{{ translate('Custom Label') }}</h1>
            </div>
            @can('custom_label_create')
                <div class="col-md-6 text-md-right">
                    <a href="{{ route('custom_label.create') }}" class="btn btn-circle btn-info">
                        <span>{{ translate('Add New Custom Label') }}</span>
                    </a>
                </div>
            @endcan
        </div>
    </div>

    <div class="card col-md-12 mx-auto">
        <div class="card-body">
            <div class="form-group mb-0 row">
                <label class="col-md-2 col-from-label">{{translate('Sellers Can Create Custom Label')}}?</label>
                <div class="col-md-10">
                    <label class="aiz-switch aiz-switch-success mb-0 d-block">
                        <input type="checkbox" 
                            id="seller_can_add_custom_label_checkbox" 
                            @if(get_setting('seller_can_add_custom_label')) checked @endif>
                        <span></span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="card col-md-12 mx-auto">
        <form class="" id="sort_custom_labels" action="" method="GET">
            <input type="hidden" name="custom_label_user_type" value="{{ $customLabelUserType }}">
            <div class="d-sm-flex justify-content-between mx-4">
                <div class="mt-3">
                    @php
                        $activeClasss = 'btn-soft-blue';
                        $inActiveClasses = 'text-secondary border-dashed border-soft-light';
                    @endphp
                    <a class="btn btn-sm btn-circle fs-12 fw-600 mr-2 custom-label-filter {{ $customLabelUserType == 'all' ? $activeClasss : $inActiveClasses }}"
                        data-type="all" href="javascript:void(0);">
                        {{ translate('All') }}
                    </a>
                    <a class="btn btn-sm btn-circle fs-12 fw-600 mr-2 custom-label-filter {{ $customLabelUserType == 'in_house' ? $activeClasss : $inActiveClasses }}"
                        data-type="in_house" href="javascript:void(0);">
                        {{ translate('Inhouse') }}
                    </a>
                    <a class="btn btn-sm btn-circle fs-12 fw-600 mr-2 custom-label-filter {{ $customLabelUserType == 'seller' ? $activeClasss : $inActiveClasses }}"
                        data-type="seller" href="javascript:void(0);">
                        {{ translate('Seller') }}
                    </a>
                </div>
                <div class="d-flex mt-3">
                    <div class="form-group mb-0">
                        <input type="text" class="form-control form-control-sm h-100" name="search" @isset($sort_search) value="{{ $sort_search }}" @endisset placeholder="Type & Enter">
                    </div>
                </div>
            </div>
        </form>
        <div class="card-body" id="custom-label-table-wrapper">
            @include('backend.product.custom_label.partials.table', [
                'custom_labels' => $custom_labels,
                'sort_search' => $sort_search,
                'customLabelUserType' => $customLabelUserType
            ])
        </div>
    </div>
@endsection

@section('modal')
    @include('modals.delete_modal')

    <!-- confirm trigger Modal -->
    <div id="confirm-trigger-modal" class="modal fade">
        <div class="modal-dialog modal-md modal-dialog-centered" style="max-width: 540px;">
            <div class="modal-content p-2rem">
                <div class="modal-body text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="72" height="64" viewBox="0 0 72 64">
                        <g id="Octicons" transform="translate(-0.14 -1.02)">
                          <g id="alert" transform="translate(0.14 1.02)">
                            <path id="Shape" d="M40.159,3.309a4.623,4.623,0,0,0-7.981,0L.759,58.153a4.54,4.54,0,0,0,0,4.578A4.718,4.718,0,0,0,4.75,65.02H67.587a4.476,4.476,0,0,0,3.945-2.289,4.773,4.773,0,0,0,.046-4.578Zm.6,52.555H31.582V46.708h9.173Zm0-13.734H31.582V23.818h9.173Z" transform="translate(-0.14 -1.02)" fill="#ffc700" fill-rule="evenodd"/>
                          </g>
                        </g>
                    </svg>
                    <p class="mt-2 mb-2 fs-16 fw-700" id="confirm_text"></p>
                    <p class="fs-13" id="confirm_detail_text"></p>
                    <a href="javascript:void(0)" id="trigger_btn" data-value="" data-status="" data-clicked="" class="btn btn-warning rounded-2 mt-2 fs-13 fw-700 w-250px" onclick="update_custom_label_status()"></a>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script type="text/javascript">

        $(document).ready(function() {

            $('#trigger_btn').on('click', function() {
                const actionType = $(this).attr('data-action-type');

                if (actionType === 'seller_access') {
                    update_custom_label_status();
                } else if (actionType === 'seller_can_add_custom_label') {
                    const type = $(this).attr('data-type');
                    const value = $(this).attr('data-value');
                    updateSettings(type, value);
                }

                $(this).attr('data-clicked', 1);
                $('#confirm-trigger-modal').modal('hide');
            });

            $('#seller_can_add_custom_label_checkbox').on('change', function() {
                if('{{ env('DEMO_MODE') }}' == 'On') {
                    AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
                    $(this).prop('checked', !$(this).is(':checked'));
                    return;
                }

                const isChecked = $(this).is(':checked');
                const confirmText = isChecked 
                    ? "{{ translate('Are you sure you want to allow sellers to create custom labels?') }}"
                    : "{{ translate('Are you sure you want to disable custom label creation for sellers?') }}";
                const detailText = isChecked 
                    ? "{{ translate('Sellers will be able to create their own custom labels.') }}"
                    : "{{ translate('Sellers will no longer be able to create custom labels.') }}";
                const btnText = isChecked 
                    ? "{{ translate('Allow Sellers to Create') }}"
                    : "{{ translate('Disable for Sellers') }}";

                $('#confirm_text').text(confirmText);
                $('#confirm_detail_text').text(detailText);
                $('#trigger_btn')
                    .text(btnText)
                    .attr('data-action-type', 'seller_can_add_custom_label')
                    .attr('data-type', 'seller_can_add_custom_label')
                    .attr('data-value', isChecked ? 1 : 0);

                $('#confirm-trigger-modal')
                    .data('action-type', 'seller_can_add_custom_label')
                    .modal('show');
            });

            window.trigger_alert = function(el) {
                if('{{ env('DEMO_MODE') }}' == 'On'){
                    AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
                    return;
                }

                var id = el.value;
                var seller_access = el.checked ? 1 : 0;

                var confirm_text = seller_access == 1 
                    ? "{{ translate('Are you sure you want to allow this Custom Label for Seller?') }}"
                    : "{{ translate('Are you sure you want to close this Custom Label for Seller?') }}";

                var confirm_detail_text = seller_access == 1 
                    ? "{{ translate('Sellers will be able to use this custom label for their products.') }}"
                    : "{{ translate('Sellers will no longer be use this custom label for their products.') }}";

                var confirm_btn_text = seller_access == 1 
                    ? "{{ translate('Allow Sellers to Use') }}"
                    : "{{ translate('Disable for Sellers') }}";

                $('#confirm_text').text(confirm_text);
                $('#confirm_detail_text').text(confirm_detail_text);
                $('#trigger_btn')
                    .text(confirm_btn_text)
                    .attr('data-action-type', 'seller_access')
                    .attr('data-value', id)
                    .attr('data-seller_access', seller_access);

                $('#confirm-trigger-modal')
                    .data('action-type', 'seller_access')
                    .modal('show');
            };

            function update_custom_label_status() {
                var id = $('#trigger_btn').attr('data-value');
                var seller_access = $('#trigger_btn').attr('data-seller_access');

                $.post('{{ route('custom-label.update-status') }}', {
                    _token: '{{ csrf_token() }}',
                    id: id,
                    seller_access: seller_access
                }, function(data) {
                    if (data == 1) {
                        AIZ.plugins.notify('success', '{{ translate('Custom Label seller access updated successfully') }}');
                    } else {
                        AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
                    }
                }).fail(function() {
                    AIZ.plugins.notify('danger', '{{ translate('Network error') }}');
                });
            }

            $('#confirm-trigger-modal').on('hidden.bs.modal', function () {
                const actionType = $(this).data('action-type');

                if ($('#trigger_btn').attr('data-clicked') == 1) {
                    $('#trigger_btn').attr('data-clicked', '');
                    $(this).removeData('action-type');
                } else {
                    if (actionType === 'seller_can_add_custom_label') {
                        const current = $('#seller_can_add_custom_label_checkbox').is(':checked');
                        $('#seller_can_add_custom_label_checkbox').prop('checked', !current);
                    } 
                    else if (actionType === 'seller_access') {
                        var id = $('#trigger_btn').attr('data-value');
                        if (id) {
                            var seller_access = $('#trigger_btn').attr('data-seller_access') == 1 ? false : true;
                            $('#trigger_alert_' + id).prop('checked', seller_access);
                        }
                    }

                    $(this).removeData('action-type');
                    $('#trigger_btn').removeAttr('data-action-type data-type data-value data-seller_access');
                }
            });

            $(document).on("change", ".check-all", function() {
                $('.check-one:checkbox').prop('checked', this.checked);
            });

            window.updateSettings = function(type, value) {
                if('{{ env('DEMO_MODE') }}' == 'On') {
                    AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
                    return;
                }

                $.post('{{ route('business_settings.update.activation') }}', {
                    _token: '{{ csrf_token() }}',
                    type: type,
                    value: value
                }, function(data) {
                    if (data == 1) {
                        AIZ.plugins.notify('success', '{{ translate('Settings updated successfully') }}');
                    } else {
                        AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
                    }
                }).fail(function() {
                    AIZ.plugins.notify('danger', '{{ translate('Network error') }}');
                });
            };

            $(document).on('click', '.custom-label-filter', function() {
                var type = $(this).data('type');
                var search = $('input[name="search"]').val();

                $.ajax({
                    url: '{{ route("custom_label.index") }}',
                    type: 'GET',
                    data: {
                        custom_label_user_type: type,
                        search: search
                    },
                    beforeSend: function() {
                        $('#custom-label-table-wrapper').html(
                            `<div class="text-center py-5">
                                <div class="spinner-border text-primary" role="status"></div>
                            </div>`
                        );
                    },
                    success: function(data) {
                        $('#custom-label-table-wrapper').html(data);

                        $('.custom-label-filter')
                            .removeClass('btn-soft-blue')
                            .addClass('text-secondary border-dashed border-soft-light');
                        $('.custom-label-filter[data-type="'+type+'"]')
                            .removeClass('text-secondary border-dashed border-soft-light')
                            .addClass('btn-soft-blue');
                    },
                    error: function() {
                        $('#custom-label-table-wrapper').html(
                            '<div class="text-center text-danger py-5">Something went wrong</div>'
                        );
                        AIZ.plugins.notify('danger', '{{ translate("Something went wrong") }}');
                    }
                });
            });
        });
    </script>
@endsection
