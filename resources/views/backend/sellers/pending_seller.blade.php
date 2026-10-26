@extends('backend.layouts.app')

@section('content')

<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row align-items-center">
        <div class="col-auto">
            <h1 class="h3">{{ translate('Pending Sellers') }}</h1>
        </div>
    </div>
</div>

<div class="card">
    <form id="sort_sellers" action="" method="GET">
        <div class="card-header row gutters-5">
            <div class="col">
                <h5 class="mb-md-0 h6">{{ translate('Pending Seller List') }}</h5>
            </div>
            <div class="col-md-3 ml-auto">
                <input type="text" class="form-control" name="search" @isset($sort_search) value="{{ $sort_search }}" @endisset placeholder="{{ translate('Type name or email or mobile number & Enter') }}">
            </div>
        </div>

        <div class="card-body">
            <table class="table aiz-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ translate('Name') }}</th>
                        <th>{{ translate('Phone') }}</th>
                        <th>{{ translate('Email') }}</th>
                        <th>{{ translate('Registration Date') }}</th>
                        <th data-breakpoints="lg">{{translate('Access Approval')}}</th>
                        <th>{{ translate('Status') }}</th>
                        <th>{{ translate('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shops as $key => $shop)
                        <tr>
                            <td>{{ ($key + 1) + ($shops->currentPage() - 1) * $shops->perPage() }}</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="{{ uploaded_asset($shop->logo) }}" class="size-40px img-fit mr-2" onerror="this.onerror=null;this.src='{{ static_asset('assets/img/placeholder.jpg') }}';">
                                    <span class="text-truncate-2">{{ $shop->name }}</span>
                                </div>
                            </td>
                            <td>{{ $shop->user->phone ?? '-' }}</td>
                            <td>{{ $shop->user->email ?? '-' }}</td>
                            <td>{{ $shop->created_at ? $shop->created_at->format('Y-m-d H:i:s') : '-' }}</td>
                            <td>
                                <label class="aiz-switch aiz-switch-success mb-0">
                                    <input
                                        @can('approve_seller') onchange="update_approved(this)" @endcan
                                        value="{{ $shop->id }}" type="checkbox"
                                        <?php if($shop->registration_approval == 1) echo "checked";?>
                                        @cannot('approve_seller') disabled @endcan
                                    >
                                    <span class="slider round"></span>
                                </label>
                            </td>
                            <td>
                             @if(addon_is_activated('portfolio_system') !=1)
                            <span class="badge badge-inline badge-warning">{{ translate('Pending') }}</span>
                             @else
                                @if ($shop->verification_status != 1 && $shop->business_info != null)
                                @php 
                                $verification_docs = json_decode($shop->business_info);
                                @endphp
                                <span class="badge badge-inline badge-success">{{translate('Submitted')}}</span> <br>
                                <a href="javascript:void(0)" class="badge badge-inline badge-info border border-info" onclick="showDocsInModal('{{ json_encode($verification_docs) }}', '{{ $shop->id }}')"> {{translate('View Info') }}</a>

                                @else
                                    <span class="badge badge-inline badge-secondary"> {{ translate('Not Submitted') }}</span>
                                @endif
                            @endif
                            </td>
                            <td>
                                @can('delete_seller')
                                    <a href="javascript:void();" class="badge badge-inline badge-danger confirm-delete" data-href="{{route('sellers.destroy', $shop->id)}}">
                                        {{translate('Delete')}}
                                    </a>
                                @endcan
                            </td>
                
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="aiz-pagination">
                {{ $shops->appends(request()->input())->links() }}
            </div>
        </div>
    </form>
</div>

@endsection

@section('modal')
    @include('modals.delete_modal')

    <div class="modal fade" id="docsPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{translate('Customer Documents')}}</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" style="min-height: 500px;">
                    
                    <div id="filePreviewContainer" class="text-center"></div>
                </div>

                <div class="d-flex align-items-center justify-content-between w-100 px-3 px-lg-5 pb-5 mb-3">
                    <button type="button" id="back-btn"
                        class="bg-transparent border-2 border-gray-400 fs-14 fw-700 rounded-2 py-15px text-success d-block mr-2 w-100"
                        data-dismiss="modal">{{translate('No')}}</button>
                    <a href="javascript:void(0)" id="conform-yes-btn"
                        class="bg-transparent text-center border border-2 border-gray-400 rounded-2 fs-14 fw-700 py-15px text-danger d-block w-100">{{translate('Approved')}}</a>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
    function update_approved(el){
        if ('{{ env('DEMO_MODE') }}' === 'On') {
            AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
            return;
        }
        let registration_approval = el.checked ? 1 : 0;
        let shop_id = el.value;
        let $row = $(el).closest('tr');

        $.post('{{ route('sellers.registration.approved') }}', {
            _token: '{{ csrf_token() }}',
            id: shop_id,
            registration_approval: registration_approval
        }, function (data) {
            if (data == 1) {
                AIZ.plugins.notify('success', '{{ translate('Pending sellers Approved successfully') }}');
                if (registration_approval === 1) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                }
            } else {
                AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
            }
        });
    }


    function update_registration_verification_approval(shop_id){
        if ('{{ env('DEMO_MODE') }}' === 'On') {
            AIZ.plugins.notify('info', '{{ translate('Data can not change in demo mode.') }}');
            return;
        }
        $.post('{{ route('sellers.registration.approved') }}', {
            _token: '{{ csrf_token() }}',
            id: shop_id,
            registration_approval: 1,
            verification_status : 1
        }, function (data) {
            if (data == 1) {
                AIZ.plugins.notify('success', '{{ translate('Unverified sellers Verified successfully') }}');
                $('#docsPreviewModal').modal('hide');
                setTimeout(() => {
                    location.reload();
                }, 800);
            } else {
                AIZ.plugins.notify('danger', '{{ translate('Something went wrong') }}');
            }
        });
    }

    function showDocsInModal(customer_docs_json, shop_id) {

        const docs = JSON.parse(customer_docs_json);
        const container = $('#filePreviewContainer').empty();

        const baseUrl = "{{ my_asset('') }}/";
        const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        const docList = [
            { key: 'certificate', label: '{{ translate("Tax Identification Document") }}' },
            { key: 'id_card', label: '{{ translate("ID Card") }}' },
            { key: 'seller_photo', label: '{{ translate("Seller Photo") }}' },
            { key: 'seller_selfie', label: '{{ translate("Seller Selfie") }}' }
            
        ];

        if(docs['certificate_number']) {
            container.append(`
                <div class="mb-4">
                    <h5 class="mb-2">{{ translate('Tax Identification Number') }}:</h5>
                    <p>${docs['certificate_number']}</p>
                </div>
            `);
        }

        docList.forEach(({ key, label }) => {

            if (!docs[key]) return;

            const fileUrl = baseUrl + docs[key];
            const ext = docs[key].split('.').pop().toLowerCase();

            let previewHtml = `<p class="text-danger">Unsupported file format.</p>`;

            if (imageExts.includes(ext)) {
                previewHtml = `<img src="${fileUrl}" style="max-width:100%; max-height:600px;">`;
            } else if (ext === 'pdf') {
                previewHtml = `<iframe src="${fileUrl}" style="width:100%; height:600px;" frameborder="0"></iframe>`;
            }

            container.append(`
                <div class="mb-4">
                    <h5 class="mb-2">${label}:</h5>
                    ${previewHtml}
                </div>
            `);
        });
        $('#docsPreviewModal').data('shop-id', shop_id).modal('show');
    }

    $(document).on('click', '#conform-yes-btn', function () {
        const shop_id = $('#docsPreviewModal').data('shop-id');
        update_registration_verification_approval(shop_id);
    });

</script>
@endsection
