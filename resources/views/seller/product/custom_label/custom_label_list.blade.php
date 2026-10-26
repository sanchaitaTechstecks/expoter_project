@extends('seller.layouts.app')

@section('panel_content')
    <div class="aiz-titlebar text-left mt-2 mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="h3">{{ translate('Custom Label') }}</h1>
            </div>
            @if (get_setting('seller_can_add_custom_label') != 0)
                <div class="col-md-6 text-md-right">
                    <a href="{{ route('seller.custom_label.create') }}" class="btn btn-circle btn-info">
                        <span>{{ translate('Add New Custom Label') }}</span>
                    </a>
                </div>
            @endif
        </div>
    </div>

    <div class="card col-md-12 mx-auto">
        <div class="card-body">
            <table class="table aiz-table mb-0">
                <thead>
                    <tr>
                        <th data-breakpoints="lg">#</th>
                        <th class="text-center" data-breakpoints="lg" >{{ translate('Label') }}</th>
                        <th class="text-center" data-breakpoints="lg" >{{ translate('Added By') }}</th>
                        <th class="text-right">{{ translate('Options') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($custom_labels as $key => $custom_label)
                        <tr>
                            <td>{{ $key + 1 + ($custom_labels->currentPage() - 1) * $custom_labels->perPage() }}</td>
                            <td class="text-center">
                                <span class="px-2 py-1"
                                    style="background-color: {{ $custom_label->background_color }}; color: {{$custom_label->text_color}}">{{ $custom_label->getTranslation('text') }}</span>
                            </td>
                            <td class="text-center">{{ $custom_label->user->name }}</td>
                            <td>
                                {{-- @if($custom_label->user_id == auth()->id()) --}}
                                    <div class="dropdown float-right">
                                        <button type="button"
                                            class="btn btn-sm btn-circle btn-soft-primary btn-icon dropdown-toggle no-arrow"
                                            data-toggle="dropdown" href="javascript:void(0);" role="button" aria-expanded="false"
                                            aria-haspopup="false">
                                            <i class="las la-ellipsis-v seller-list-icon" style="padding-left: .15rem;"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right dropdown-menu-xs">
                                            <a class="dropdown-item"
                                                href="{{route('seller.custom_label.edit', ['id' => $custom_label->id, 'lang' => env('DEFAULT_LANGUAGE')])}}">
                                                {{translate('Edit')}}
                                            </a>
                                            @if($custom_label->user->user_type == 'seller')
                                            <a class="dropdown-item confirm-delete" href="javascript:void(0)"
                                                data-href="{{route('seller.custom_label.delete', $custom_label->id)}}">
                                                {{translate('Delete')}}
                                            </a>
                                            @endif
                                        </div>
                                    </div>
                                {{-- @endif --}}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="aiz-pagination">
                {{ $custom_labels->appends(request()->input())->links() }}
            </div>
        </div>
    </div>
@endsection

@section('modal')
    @include('modals.delete_modal')
@endsection