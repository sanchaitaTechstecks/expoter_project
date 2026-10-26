@extends('backend.layouts.app')

@section('content')

    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="aiz-titlebar text-left mt-2 mb-3">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1 class="h3">{{translate('All Attributes')}}</h1>
                    </div>
                    @can('add_product_attribute')
                        <div class="col-md-6 text-md-right">
                            <a href="{{ route('attributes.create') }}" class="btn btn-circle btn-info">
                                <span>{{translate('Add New Attributes')}}</span>
                            </a>
                        </div>
                    @endcan
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0 h6">{{ translate('Attributes') }}</h5>
                </div>
                <div class="card-body">
                    <table class="table aiz-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ translate('Name') }}</th>
                                <th>{{ translate('Values') }}</th>
                                <th class="text-right">{{ translate('Options') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attributes as $key => $attribute)
                                <tr>
                                    <td>{{ $key + 1 }}</td>
                                    <td>{{ $attribute->getTranslation('name') }}</td>
                                    <td>
                                        @foreach ($attribute->attribute_values as $key => $value)
                                            <span
                                                class="badge badge-inline badge-md bg-light">{{ $value->value }}</span>
                                        @endforeach
                                    </td>
                                    <td class="text-right w-140px"> 
                                        @can('edit_product_attribute')
                                            <a class="btn btn-soft-primary btn-icon btn-circle btn-sm"
                                                href="{{ route('attributes.edit', ['id' => $attribute->id, 'lang' => env('DEFAULT_LANGUAGE')]) }}"
                                                title="{{ translate('Edit') }}">
                                                <i class="las la-edit"></i>
                                            </a>
                                        @endcan
                                        @can('delete_product_attribute')
                                            <a href="#"
                                                class="btn btn-soft-danger btn-icon btn-circle btn-sm confirm-delete"
                                                data-href="{{ route('attributes.destroy', $attribute->id) }}"
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
                        {{ $attributes->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('modal')
   <div id="delete-modal" class="modal fade">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title h6">{{translate('Delete Confirmation')}}</h4>
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mt-1 mb-0 fs-14"> {{ translate('Are you sure you want to delete this attribute?') }}</p>
                <p class="fs-14 text-danger"> {{ translate('All associated attribute values will also be permanently deleted.') }}</p>
                <button type="button" class="btn btn-secondary btn-sm rounded-0 mt-2" data-dismiss="modal">{{translate('Cancel')}}</button>
                <a href="" id="delete-link" class="btn btn-primary btn-sm rounded-0 mt-2">{{translate('Delete')}}</a>
            </div>
        </div>
    </div>
</div>
@endsection
