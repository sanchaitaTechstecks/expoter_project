@extends('backend.layouts.app')

@section('content')

    @php
        CoreComponentRepository::instantiateShopRepository();
        CoreComponentRepository::initializeCache();
    @endphp

    <div class="aiz-titlebar text-left mt-2 mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="h3">{{translate('Set Category Wise Product Refund')}}</h1>
            </div>
        </div>
        @if(get_setting('refund_type') != 'category_based_refund')
            <div class="alert alert-info mt-2 text-center">
                <p class="pt-3 font-weight-bold text-danger">{{ translate(' Category Based Refund is not Activated, Active ') }}
                    <a href="{{ route('refund_time_config') }}">{{ translate('Here') }}</a>
                </p>
            </div>
        @endif
    </div>
    <div class="card">
        <div class="card-header d-block d-md-flex">
            <h5 class="mb-0 h6">{{ translate('Categories') }}</h5>
            <form class="" id="sort_categories" action="" method="GET">
                <div class="box-inline pad-rgt pull-left">
                    <div class="" style="min-width: 200px;">
                        <input type="text" class="form-control" id="search" name="search" @isset($sort_search)
                        value="{{ $sort_search }}" @endisset placeholder="{{ translate('Type name & Enter') }}">
                    </div>
                </div>
            </form>
        </div>
        <div class="card-body">
            <table class="table aiz-table mb-0">
                <thead>
                    <tr>
                        <th data-breakpoints="lg" width="5%">#</th>
                        <th data-breakpoints="lg" width="10%">{{translate('Icon')}}</th>
                        <th width="20%">{{translate('Name')}}</th>
                        <th data-breakpoints="lg" width="20%">{{ translate('Parent Category') }}</th>
                        <th data-breakpoints="lg" width="10%" class="text-center">{{ translate('Inhouse Products') }}</th>
                        <th data-breakpoints="lg" width="10%" class="text-center">{{ translate('Seller Products?') }}</th>
                        <th data-breakpoints="lg" width="15%">{{ translate('Refund Request Time(Days)') }}</th>
                        <th data-breakpoints="lg" width="10%" class="text-center">{{ translate('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($categories as $key => $category)
                    @php
                        $isCategoryBasedRefund = get_setting('refund_type') == 'category_based_refund';
                    @endphp
                    <tr>
                        <td>{{ ($key + 1) + ($categories->currentPage() - 1) * $categories->perPage() }}</td>
                        <td>
                            @if($category->icon != null)
                                <span class="avatar avatar-square avatar-xs">
                                    <img src="{{ uploaded_asset($category->icon) }}" alt="{{translate('icon')}}">
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="align-items-center d-flex fw-800">
                            {{ $category->getTranslation('name') }}
                            @if($category->digital == 1)
                                <img src="{{ static_asset('assets/img/digital_tag.png') }}" alt="{{translate('Digital')}}"
                                    class="ml-2 h-25px" style="cursor: pointer;" title="Digital">
                            @endif
                        </td>
                        <td class="fw-600">
                            @php
                                $parent = \App\Models\Category::where('id', $category->parent_id)->first();
                            @endphp
                            @if ($parent != null)
                                {{ $parent->getTranslation('name') }}
                            @else
                                —
                            @endif
                        </td>
                
                        <td class="text-center ">
                            {{ $category->products->where('added_by', 'admin')->count()}}
                        </td>
                
                        <td class="d-flex justify-content-center align-items-center">
                            <span class="me-3 mr-3">
                                {{ $category->products->where('added_by', 'seller')->count() }}
                            </span>
                        </td>
                
                        <td>
                            <div class="input-group">
                                <input 
                                    type="number" 
                                    class="form-control" 
                                    name="refund_request_time"
                                    value="{{ $category->refund_request_time }}" 
                                    min="1" 
                                    placeholder="{{ translate('Days') }}"
                                    style="border-radius: 8px 0 0 8px;"
                                    {{ $isCategoryBasedRefund ? '' : 'disabled' }}
                                >
                            </div>
                        </td>
                
                        <td class="text-right">
                            <div class="form-group mb-0 text-right">
                                <button 
                                    type="button" 
                                    onclick="{{ $isCategoryBasedRefund ? 'trigger_alert(' . $category->id . ')' : 'AIZ.plugins.notify(\'danger\', \'' . translate('Category based refund is not enabled') . '\')' }}"
                                    class="btn btn-primary btn-sm rounded-2 w-120px"
                                    {{ $isCategoryBasedRefund ? '' : 'disabled' }}
                                >
                                    {{ translate('Set') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="aiz-pagination">
                {{ $categories->appends(request()->input())->links() }}
            </div>
        </div>
    </div>
@endsection

@section('modal')
    <!-- Confirm Modal -->
    <div id="confirm-modal" class="modal fade">
        <div class="modal-dialog modal-md modal-dialog-centered" style="max-width: 540px;">
            <div class="modal-content p-2rem">
                <div class="modal-body text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="72" height="64" viewBox="0 0 72 64">
                        <g id="Octicons" transform="translate(-0.14 -1.02)">
                            <g id="alert" transform="translate(0.14 1.02)">
                                <path
                                    d="M40.159,3.309a4.623,4.623,0,0,0-7.981,0L.759,58.153a4.54,4.54,0,0,0,0,4.578A4.718,4.718,0,0,0,4.75,65.02H67.587a4.476,4.476,0,0,0,3.945-2.289,4.773,4.773,0,0,0,.046-4.578Zm.6,52.555H31.582V46.708h9.173Zm0-13.734H31.582V23.818h9.173Z"
                                    transform="translate(-0.14 -1.02)" fill="#ffc700" />
                            </g>
                        </g>
                    </svg>
                    <p class="mt-3 mb-3 fs-16 fw-700">
                        {{ translate('N.B: If you set a refund time here, all the sub-categories under this category will follow the same refund period. You can still set refund periods individually for sub-categories later. Do you want to continue?') }}
                    </p>
                    <div>
                        <button type="button" class="btn btn-light rounded-2 mt-2 fs-13 fw-700 w-150px"
                            data-dismiss="modal">{{ translate('Cancel') }}</button>
                        <a href="javascript:void(0)" id="confirm-set-btn"
                            class="btn btn-warning rounded-2 mt-2 fs-13 fw-700 w-250px">
                            {{ translate('Confirm') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script type="text/javascript">
        let categoryIdToSave = null;

        function trigger_alert(id) {
            categoryIdToSave = id;
            $('#confirm-modal').modal('show');
        }

        $('#confirm-set-btn').on('click', function () {
            const button = document.querySelector(`button[onclick="trigger_alert(${categoryIdToSave})"]`);
            if (!button) return;

            const row = button.closest('tr');
            const refundTimeInput = row.querySelector('input[name="refund_request_time"]');
            const refundTime = refundTimeInput ? refundTimeInput.value : '';

            if (!refundTime || refundTime < 1) {
                AIZ.plugins.notify('danger', '{{ translate('Please enter a valid refund time (1 or more days).') }}');
                return;
            }

            $(this).prop('disabled', true).text('{{ translate('Saving...') }}');

            $.ajax({
                url: '{{ route('categories.update-refund-settings') }}',
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    id: categoryIdToSave,
                    refund_request_time: refundTime,
                },
                success: function(response) {
                    AIZ.plugins.notify('success', response.message);
                    $('#confirm-modal').modal('hide');
                    window.location.reload();
                },
                error: function(xhr) {
                    const msg = xhr.responseJSON?.message ?? '{{ translate('An error occurred.') }}';
                    AIZ.plugins.notify('danger', msg);
                },
                complete: function() {
                    $('#confirm-set-btn').prop('disabled', false).text('{{ translate('Confirm') }}');
                }
            });
        });
    </script>
@endsection