@extends('backend.layouts.app')

@section('content')
<div class="row">
    <div class="col-lg-10 mx-auto">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0 h6">{{ translate('Edit Custom Label Information') }}</h5>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs nav-fill language-bar mb-2">
                    @foreach (get_all_active_language() as $language)
                        <li class="nav-item">
                            <a class="nav-link text-reset @if ($language->code == $lang) active @endif py-3"
                                href="{{ route('custom_label.edit', ['id' => $custom_label->id, 'lang' => $language->code]) }}">
                                <img src="{{ static_asset('assets/img/flags/' . $language->code . '.png') }}" height="11" class="mr-1">
                                <span>{{ $language->name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <form class="form-horizontal" action="{{ route('custom_label.update', $custom_label->id) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="lang" value="{{ $lang }}">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group row">
                                <label class="col-xxl-3 col-from-label fs-13">{{ translate('Text') }}</label>
                                <div class="col-xxl-9">
                                    <input type="text" class="form-control" name="text"
                                        value="{{ $custom_label->getTranslation('text', $lang) }}" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-md-3 col-from-label">{{ translate('Background Color') }}</label>
                                <div class="col-md-9">
                                    <div class="input-group">
                                        <input type="text" class="form-control aiz-color-input"
                                            name="background_color" value="{{ $custom_label->background_color }}" required>
                                        <div class="input-group-append">
                                            <span class="input-group-text p-0">
                                                <input class="aiz-color-picker border-0 size-40px" type="color" value="{{ $custom_label->background_color }}">
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-xxl-3 col-from-label fs-13">{{ translate('Select Text Color') }}</label>
                                <div class="col-xxl-9 d-flex align-items-center">
                                    <label class="aiz-megabox d-block bg-white mb-0 mr-4" style="flex: 1; min-width: 120px;">
                                        <input type="radio" name="text_color" value="white" @checked($custom_label->text_color == 'white')>
                                        <span class="d-flex align-items-center aiz-megabox-elem rounded-0" style="padding: 0.75rem 1.2rem;">
                                            <span class="aiz-rounded-check flex-shrink-0 mt-1"></span>
                                            <span class="flex-grow-1 pl-3 fw-600">{{ translate('Light') }}</span>
                                        </span>
                                    </label>

                                    <label class="aiz-megabox d-block bg-white mb-0" style="flex: 1; min-width: 120px;">
                                        <input type="radio" name="text_color" value="dark" @checked($custom_label->text_color == 'dark')>
                                        <span class="d-flex align-items-center aiz-megabox-elem rounded-0" style="padding: 0.75rem 1.2rem;">
                                            <span class="aiz-rounded-check flex-shrink-0 mt-1"></span>
                                            <span class="flex-grow-1 pl-3 fw-600">{{ translate('Dark') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label class="col-sm-3 col-from-label">{{ translate('Products') }}</label>
                                <div class="col-sm-9">
                                    <select name="products[]" id="products" class="form-control aiz-selectpicker"
                                        multiple data-placeholder="{{ translate('Choose Products') }}"
                                        data-live-search="true" data-selected-text-format="count">
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}"
                                                @selected(in_array($product->id, $selected_products_ids))
                                                data-content='<img src="{{ uploaded_asset($product->thumbnail_img) }}" class="size-30px img-fit mr-2"> {{ $product->getTranslation("name") }}'>
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="" id="discount_table"></div>
                        </div>
                    </div>

                    <div class="col-12 text-right">
                        <button type="submit" class="btn btn-primary">{{ translate('Update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<script type="text/javascript">
    $(document).ready(function() {

        get_flash_deal_discount();

        $('#products').on('change', function() {
            get_flash_deal_discount();
        });

        function get_flash_deal_discount() {
            var product_ids = $('#products').val() || [];
            if (product_ids.length > 0) {
                $.post('{{ route('custom_label.products') }}', {
                        _token: '{{ csrf_token() }}',
                        product_ids: product_ids
                    },
                    function(data) {
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
