<table class="table aiz-table mb-0">
    <thead>
        <tr>
            <th>#</th>
            <th>{{ translate('Label') }}</th>
            <th class="text-center">{{ translate('Added By') }}</th>
            <th class="text-center">{{ translate('Seller Can Access') }}?</th>
            @canany(['custom_label_edit','custom_label_delete'])
                <th class="text-right">{{ translate('Options') }}</th>
            @endcanany    
        </tr>
    </thead>
    <tbody>
        @foreach ($custom_labels as $key => $custom_label)
            <tr>
                <td>{{ $key + 1 + ($custom_labels->currentPage() - 1) * $custom_labels->perPage() }}</td>
                <td>
                    <span class="px-2 py-1 rounded rounded-4" style="background-color: {{ $custom_label->background_color }}; color: {{$custom_label->text_color}}">{{ $custom_label->getTranslation('text') }}</span>
                </td>
                <td class="text-center">{{ $custom_label->user->name }}</td>
                <td class="text-center">
                    @if($custom_label->user_id == get_admin()->id)
                        <label class="aiz-switch aiz-switch-primary mb-0">
                            <input value="{{ $custom_label->id }}" id="trigger_alert_{{ $custom_label->id }}" 
                                type="checkbox" @if($custom_label->seller_access == 1) checked @endif
                                onchange="trigger_alert(this)">
                            <span class="slider round"></span>
                        </label>
                    @endif
                </td>
                @canany(['custom_label_edit','custom_label_delete'])
                    <td>
                        <div class="dropdown float-right">
                            <button type="button" class="btn btn-sm btn-circle btn-soft-primary btn-icon dropdown-toggle no-arrow" data-toggle="dropdown" href="javascript:void(0);" role="button" aria-haspopup="false" aria-expanded="false">
                                <i class="las la-ellipsis-v list-icon"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-right dropdown-menu-xs">
                                @can('custom_label_edit')
                                    <a class="dropdown-item" href="{{route('custom_label.edit', ['id'=>$custom_label->id, 'lang'=>env('DEFAULT_LANGUAGE')])}}">
                                        {{translate('Edit')}}
                                    </a>
                                @endcan
                                @can('custom_label_delete')
                                    <a class="dropdown-item confirm-delete" href="javascript:void(0)" data-href="{{route('custom_label.delete', $custom_label->id)}}">
                                        {{translate('Delete')}}
                                    </a>
                                @endcan
                            </div>
                        </div>
                    </td>
                @endcanany
            </tr>
        @endforeach
    </tbody>
</table>
<div class="aiz-pagination">
    {{ $custom_labels->appends(request()->input())->links() }}
</div>
