@extends('backend.layouts.app')

@section('content')

@php
    CoreComponentRepository::instantiateShopRepository();
    CoreComponentRepository::initializeCache();
@endphp

<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row align-items-center">
        <div class="col-md-6">
            <h1 class="h3">{{translate('Set Category Wise Product Discount')}}</h1>
        </div>
    </div>
</div>
<div class="card">
    <div class="card-header d-block d-md-flex">
        <h5 class="mb-0 h6">{{ translate('Categories') }}</h5>
        <form class="" id="sort_categories" action="" method="GET">
            <div class="box-inline pad-rgt pull-left">
                <div class="" style="min-width: 200px;">
                    <input type="text" class="form-control" id="search" name="search"@isset($sort_search) value="{{ $sort_search }}" @endisset placeholder="{{ translate('Type name & Enter') }}">
                </div>
            </div>
        </form>
    </div>
    <div class="card-body">
        <table class="table aiz-table mb-0">
            <thead>
                <tr>
                    <th data-breakpoints="lg">#</th>
                    <th data-breakpoints="lg">{{translate('Icon')}}</th>
                    <th>{{translate('Name')}}</th>
                    <th data-breakpoints="lg">{{ translate('Parent Category') }}</th>
                    <th data-breakpoints="lg" class="text-center" width="10%">{{ translate('Inhouse Products') }}</th>
                    <th data-breakpoints="lg" class="text-center" width="10%">{{ translate('Seller Products?') }}</th>
                    <th data-breakpoints="lg" width="15%">{{ translate('Present Discount') }}</th>
                    <th data-breakpoints="lg" width="20%">{{ translate('Discount Date Range') }}</th>
                    <th data-breakpoints="lg" class="text-right">{{ translate('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($categories as $key => $category)

                    @php
                        $allMatch = $category->sellerDiscounts->every(function($discount) use ($category) {
                            return 
                                $discount->discount == $category->discount &&
                                $discount->discount_start_date == $category->discount_start_date &&
                                $discount->discount_end_date == $category->discount_end_date;
                        });
                    @endphp
                    <tr>
                        <td>{{ ($key+1) + ($categories->currentPage() - 1)*$categories->perPage() }}</td>
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
                                <img src="{{ static_asset('assets/img/digital_tag.png') }}" alt="{{translate('Digital')}}" class="ml-2 h-25px" style="cursor: pointer;" title="DIgital">
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
                            <label class="aiz-switch aiz-switch-success mb-0">
                                <input id="seller_product_discount_{{ $category->id }}" type="checkbox"   onchange="trigger_alert_switch(this, '{{ $key }}')"
                                    {{ $allMatch ? 'checked' : '' }}>
                                <span class="slider round"></span>
                            </label>
                        </td>
                         @php
                            $start_date = $category->discount_start_date ? date('d-m-Y H:i:s', $category->discount_start_date) : null;
                            $end_date   = $category->discount_end_date ? date('d-m-Y H:i:s', $category->discount_end_date) : null;

                           
                        @endphp
                        <td>
                            <div class="input-group">
                                <input type="number" class="form-control" id="discount_{{ $category->id }}" step="0.01" value="{{$category->discount}}" min="0" placeholder="{{translate('Discount')}}"
                                    style="border-radius: 8px 0 0 8px;">
                                <div class="input-group-prepend">
                                    <span class="input-group-text border-left-0" id="inputGroupPrepend" style="border-radius: 0 8px 8px 0;">%</span>
                                </div>
                            </div>
                        </td>
                         <td>
                            <input type="text" class="form-control aiz-date-range rounded-2" id="date_range_{{ $category->id }}" value="{{ $start_date && $end_date ? $start_date . ' to ' . $end_date : '' }}" placeholder="{{translate('Select Date')}}" data-time-picker="true" data-format="DD-MM-Y HH:mm:ss" data-separator=" to " autocomplete="off">
                        </td>
                        <td class="text-right">
                            <div class="form-group mb-0 text-right">
                                <button type="button" onclick="trigger_alert({{ $category->id }})" class="btn btn-primary btn-sm rounded-2 w-120px">{{translate('Set')}}</button>
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
    <!-- confirm Modal -->
    <div id="confirm-modal" class="modal fade">
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
                    <p class="mt-3 mb-3 fs-16 fw-700">{{translate('N.B: If you set discount here all the products of this category will be discounted. You can also set individual product discount later.
 Do you want to continue?')}}</p>
                    <div>
                        <button type="button" class="btn btn-light rounded-2 mt-2 fs-13 fw-700 w-150px" data-dismiss="modal">{{ translate('Cancel') }}</button>
                        <a href="javascript:void(0)" id="trigger_btn" data-value="" class="btn btn-warning rounded-2 mt-2 fs-13 fw-700 w-250px" onclick="setDiscount()">{{translate('Confirm')}}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="confirm-modal-switch" class="modal fade">
        <div class="modal-dialog modal-md modal-dialog-centered" style="max-width: 540px;">
            <div class="modal-content p-2rem">
                <div class="modal-body text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="72" height="64" viewBox="0 0 72 64">
                        <path d="M40.159,3.309a4.623,4.623,0,0,0-7.981,0L.759,58.153a4.54,4.54,0,0,0,0,4.578A4.718,4.718,0,0,0,4.75,65.02H67.587a4.476,4.476,0,0,0,3.945-2.289,4.773,4.773,0,0,0,.046-4.578Zm.6,52.555H31.582V46.708h9.173Zm0-13.734H31.582V23.818h9.173Z" fill="#ffc700" />
                    </svg>
                    <p class="mt-3 mb-3 fs-16 fw-700" id="confirmation-message"></p>
                    <div>
                        <button type="button" class="btn btn-light rounded-2 mt-2 fs-13 fw-700 w-150px" data-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning rounded-2 mt-2 fs-13 fw-700 w-250px" onclick="confirmSettingChange()">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- /.modal -->
@endsection

@section('script')
    <script type="text/javascript">
        let pendingElement = null;
        let pendingType = null;
        $(document).ready(function() {
            setTimeout(() => {
                AIZ.plugins.dateRange();
            }, "2000");
        });

        function trigger_alert_switch(el, type) {
            pendingElement = el;
            pendingType = type;
            const isChecked = $(el).is(':checked');
                
            const message = isChecked
                ? `Turning on this switch will apply the same discount to all seller products in this category. Do you want to proceed?`
                : `Turning off this switch will not affect already discounted seller products of this category. Are you sure?`;
            $('#confirm-modal-switch .modal-body p').text(message);
            $('#confirm-modal-switch').modal('show');
        }

        function confirmSettingChange() {
            $('#confirm-modal-switch').modal('hide');
            // Reset state
            pendingElement = null;
            pendingType = null;
        }


    // Revert on cancel
    $('#confirm-modal-switch').on('hidden.bs.modal', function () {
        if (pendingElement) {
            $(pendingElement).prop('checked', !$(pendingElement).is(':checked'));
            pendingElement = null;
            pendingType = null;
        }
    });

        function trigger_alert(CategoryId){
            $('#trigger_btn').attr('data-value', CategoryId);
            $('#confirm-modal').modal('show');
        }

        function setDiscount(){

            if('{{env('DEMO_MODE')}}' == 'On'){
                AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
                $('#confirm-modal').modal('hide');
                return;
            }

            $('#confirm-modal').modal('hide');
            var CategoryId = $('#trigger_btn').attr('data-value');
            var discount =  $("#discount_" + CategoryId).val();
            var dateRange =  $("#date_range_" + CategoryId).val();
            var sellerProductDiscount =  $("#seller_product_discount_" + CategoryId).prop('checked') ? 1 : 0;

            if(discount < 0) {
                AIZ.plugins.notify('danger', '{{ translate('Discount can not be less than 0') }}');
            }
            else{
                $.post('{{ route('set_product_discount') }}', {
                    _token:'{{ csrf_token() }}',
                    category_id:CategoryId,
                    discount:discount,
                    date_range:dateRange,
                    seller_product_discount:sellerProductDiscount
                }, function(data) {
                    if(data == 1){
                        AIZ.plugins.notify('success', '{{ translate('Category Wise Product Discount Set Successfully') }}');
                    }
                    location.reload();
                }).fail(function() {
                    AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
                });
            }
        }
    </script>
@endsection


