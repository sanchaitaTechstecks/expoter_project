@if (get_setting('seller_can_add_custom_label') != 0)

    @extends('seller.layouts.app')

    @section('panel_content')
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 h6">{{ translate('Custom Label Information') }}</h5>
                    </div>
                    <div class="card-body">
                        <form class="form-horizontal" action="{{ route('seller.custom_label.store') }}" method="POST"
                            enctype="multipart/form-data">
                            @csrf

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group row">
                                        <label class="col-xxl-3 col-from-label fs-13">{{translate('Text')}} <span
                                                class="text-danger">*</span></label>
                                        <div class="col-xxl-9">
                                            <input type="text" class="form-control" name="text"
                                                value="{{ old('text') }}" placeholder="{{ translate('Text') }}"
                                                required>
                                        </div>
                                    </div>

                                    <div class="form-group row">
                                        <label class="col-md-3 col-from-label">{{ translate('Background Color') }}</label>
                                        <div class="col-md-9">
                                            <div class="input-group">
                                                <input type="text" class="form-control aiz-color-input" placeholder="Ex: #e1e1e1"
                                                    name="background_color" required>
                                                <div class="input-group-append">
                                                    <span class="input-group-text p-0">
                                                        <input class="aiz-color-picker border-0 size-40px" type="color">
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Select Text Color -->
                                    <div class="form-group row">
                                        <label class="col-xxl-3 col-from-label fs-13">{{ translate('Select Text Color') }}</label>
                                        <div class="col-xxl-9 d-flex align-items-center">
                                            <!-- Light Option -->
                                            <label class="aiz-megabox d-block bg-white mb-0 mr-3" style="flex: 1;">
                                                <input type="radio" name="text_color" value="white" checked>
                                                <span class="d-flex align-items-center aiz-megabox-elem rounded-0"
                                                    style="padding: 0.75rem 1.2rem;">
                                                    <span class="aiz-rounded-check flex-shrink-0 mt-1"></span>
                                                    <span class="flex-grow-1 pl-3 fw-600">{{ translate('Light') }}</span>
                                                </span>
                                            </label>

                                            <!-- Dark Option -->
                                            <label class="aiz-megabox d-block bg-white mb-0" style="flex: 1;">
                                                <input type="radio" name="text_color" value="dark">
                                                <span class="d-flex align-items-center aiz-megabox-elem rounded-0"
                                                    style="padding: 0.75rem 1.2rem;">
                                                    <span class="aiz-rounded-check flex-shrink-0 mt-1"></span>
                                                    <span class="flex-grow-1 pl-3 fw-600">{{ translate('Dark') }}</span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Product Select -->
                                    <div class="form-group row">
                                        <label class="col-xxl-3 col-from-label fs-13">{{ translate('Products') }}</label>
                                        <div class="col-xxl-9">
                                            <select name="products[]" id="products" class="form-control aiz-selectpicker"
                                                multiple data-placeholder="{{ translate('Choose Products') }}"
                                                data-live-search="true" data-selected-text-format="count">
                                                @foreach($products as $product)
                                                    <option value="{{ $product->id }}"
                                                        data-content='<img src="{{ uploaded_asset($product->thumbnail_img) }}" class="size-30px img-fit mr-2"> {{ $product->getTranslation("name") }}'>
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div id="discount_table">
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 text-right">
                                <button type="submit" class="btn btn-primary">{{ translate('Save') }}</button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endsection
    @section('script')
        <script type="text/javascript">
            $(document).ready(function () {
                get_flash_deal_discount();

                $('#products').on('change', function () {
                    get_flash_deal_discount();
                });

                function get_flash_deal_discount() {
                    var product_ids = $('#products').val();

                    if (product_ids.length > 0) {
                        $.post('{{ route('seller.custom_label.products') }}',
                            { _token: '{{ csrf_token() }}', product_ids: product_ids },
                            function (data) {
                                $('#discount_table').html(data);
                                AIZ.plugins.fooTable();
                            });
                    } else {
                        $('#discount_table').html('');
                    }
                }
            });
        </script>
    @endsection
@endif