@extends('backend.layouts.app')

@section('content')

<div class="row">
    <div class="col-lg-10 mx-auto">
        <div class="aiz-titlebar text-left mt-2 mb-3">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="h3">{{translate('All Colors')}}</h1>
                </div>
                @can('add_color')
                    <div class="col-md-6 text-md-right">
                        <a href="{{ route('colors.create') }}" class="btn btn-circle btn-info">
                            <span>{{translate('Add New Color')}}</span>
                        </a>
                    </div>
                @endcan
            </div>
            <div class="alert alert-info my-2 text-center">
                <div class=" pt-2 d-flex align-items-center justify-content-center flex-row">
                    <p class="mb-2 fs-13 fw-600 mr-2">Activate Color Filter for Product Listing Page</p>
                    <label class="aiz-switch aiz-switch-success mb-0 ">
                       <input type="checkbox" onchange="updateSettings(this, 'color_filter_activation')" @php if(get_setting('color_filter_activation') == 1) echo "checked";@endphp>
                    <span class="slider round"></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <form class="" id="sort_colors" action="" method="GET">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Colors') }}</h5>
                    <div class="col-md-5">
                        <div class="form-group mb-0">
                            <input type="text" class="form-control form-control-sm" id="search" name="search"
                                @isset($sort_search) value="{{ $sort_search }}" @endisset
                                placeholder="{{ translate('Type color name & Enter') }}">
                        </div>
                    </div>
                </div>
            </form>
            
            <div class="card-body">
                <table class="table aiz-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ translate('Name') }}</th>
                            <th class="text-right">{{ translate('Options') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($colors as $key => $color)
                            <tr>
                                <td>{{ ($key+1) + ($colors->currentPage() - 1)*$colors->perPage() }}</td>
                                <td>{{ $color->name }}</td>
                                <td class="text-right">
                                    @can('edit_color')
                                        <a class="btn btn-soft-primary btn-icon btn-circle btn-sm"
                                            href="{{ route('colors.edit', ['id' => $color->id, 'lang' => env('DEFAULT_LANGUAGE')]) }}"
                                            title="{{ translate('Edit') }}">
                                            <i class="las la-edit"></i>
                                        </a>
                                    @endcan
                                    @can('delete_color')
                                        <a href="#" class="btn btn-soft-danger btn-icon btn-circle btn-sm confirm-delete"
                                            data-href="{{ route('colors.destroy', $color->id) }}"
                                            title="{{ translate('Delete') }}">
                                            <i class="las la-trash"></i>
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="aiz-pagination">
                    {{ $colors->appends(request()->input())->links() }}
                </div>
            </div>
        </div>
    </div>
</div>



@endsection

@section('modal')
    @include('modals.delete_modal')
@endsection

@section('script')
    <script type="text/javascript">
        function updateSettings(el, type){
            if($(el).is(':checked')){
                var value = 1;
            }
            else{
                var value = 0;
            }
            
            $.post('{{ route('business_settings.update.activation') }}', {_token:'{{ csrf_token() }}', type:type, value:value}, function(data){
                if(data == 1){
                    AIZ.plugins.notify('success', '{{ translate('Settings updated successfully') }}');
                }
                else{
                    AIZ.plugins.notify('danger', 'Something went wrong');
                }
            });
        }
    </script>
@endsection
