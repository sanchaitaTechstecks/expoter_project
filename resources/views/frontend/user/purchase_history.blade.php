@extends('frontend.layouts.user_panel')

@section('panel_content')
<div class="card shadow-none rounded-0 border p-4">
    <h5 class="mb-2 fs-20 fw-700 text-dark">{{ translate('Purchase History') }}</h5>

    <!-- Tabs & Filters -->
    <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
        <ul class="nav nav-tabs purchase-history-tab border-0 fs-12 ml-n3" id="orderTabs">
            @foreach (['All', 'Unpaid', 'Confirmed', 'Picked_Up', 'Delivered', 'To Review'] as $status)
            <li class="nav-item">
                <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                    onclick="changeTab(this, '{{ Str::slug($status) }}')">
                    {{ translate($status) }}
                </button>
            </li>
            @endforeach
        </ul>

        <div class="form-group mb-0 w-25">
            <select class="form-control aiz-selectpicker purchase-history" name="delivery_status" id="delivery_status"
                data-style="btn-light" data-width="100%">
                <option value="">{{ translate('All') }}</option>
                <option value="pending" {{ request('delivery_status') == 'pending' ? 'selected' : '' }}>{{ translate('Pending') }}</option>
                <option value="on_the_way" {{ request('delivery_status') == 'on_the_way' ? 'selected' : '' }}>{{ translate('On The Way') }}</option>
                <option value="delivered" {{ request('delivery_status') == 'delivered' ? 'selected' : '' }}>{{ translate('Delivered') }}</option>
                <option value="cancelled" {{ request('delivery_status') == 'cancelled' ? 'selected' : '' }}>{{ translate('Cancelled') }}</option>
            </select>
        </div>
    </div>

    <!-- Dynamic Tab Content -->
    <div class="tab-content mt-4" id="orderTabContent">
        <div class="tab-pane fade show active" id="tab-content">
            <!-- AJAX content will load here -->
        </div>
    </div>
</div>
@endsection

@section('modal')
<!-- Product Review Modal -->
<div class="modal fade" id="product-review-modal">
    <div class="modal-dialog">
        <div class="modal-content" id="product-review-modal-content"></div>
    </div>
</div>

<!-- Delete modal -->
<div id="delete-modal" class="modal fade">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title h6">{{translate('Cancel Confirmation')}}</h4>
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mt-1 fs-14">{{translate('Are you sure to Cancel this Order?')}}</p>
                <button type="button" class="btn btn-secondary rounded-5 mt-2 btn-sm" data-dismiss="modal">{{translate('No')}}</button>
                <a href="" id="delete-link" class="btn btn-primary rounded-5 mt-2 btn-sm">{{translate('Yes')}}</a>
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<script>
    let currentTab = 'all';

    function getOrderData(slug, page = 1) {
        currentTab = slug;
        $('#tab-content').html('<div class="footable-loader mt-5"><span class="fooicon fooicon-loader"></span></div>');
        $.ajax({
            url: `{{ route('purchase_history.filter') }}?page=${page}`,
            method: 'GET',
            data: {
                tab: slug.replace(/-/g, '_'),
            },
            success: function(response) {
                $('#tab-content').html(response.html);
            },
            error: function() {
                $('#tab-content').html('<div class="text-danger p-4">{{ translate("Failed to load data.") }}</div>');
            }
        });
    }

    function changeTab(button, statusSlug) {
        document.querySelectorAll('#orderTabs .nav-link').forEach(el => el.classList.remove('active'));
        button.classList.add('active');
        getOrderData(statusSlug);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const deliverySelect = document.getElementById('delivery_status');

        function loadOrdersByStatus(status) {
            getOrderData(status);
        }

        deliverySelect.addEventListener('change', function() {
            loadOrdersByStatus(this.value || 'all');
            document.querySelectorAll('#orderTabs .nav-link').forEach(el => el.classList.remove('active'));
        });
        const urlParams = new URLSearchParams(window.location.search);
        const toReviewParam = urlParams.get('to_review');
        if (toReviewParam && (toReviewParam === '1')) {
            const toReviewBtn = document.querySelector(`#orderTabs button[onclick*="to-review"]`);
            if (toReviewBtn) {
                document.querySelectorAll('#orderTabs .nav-link').forEach(el => el.classList.remove('active'));
                toReviewBtn.classList.add('active');
                getOrderData('to-review');
            }

        } else {
            loadOrdersByStatus('all');
        }
    });


    $(document).on('click', '.pagination a', function(e) {
        e.preventDefault();
        const page = $(this).attr('href').split('page=')[1];
        getOrderData(currentTab, page);
    });

    function product_review(product_id,order_id) {
        $.post(`{{ route('product_review_modal') }}`, {
            _token: '{{ @csrf_token() }}',
            product_id: product_id,
            order_id: order_id
        }, function(data) {
            $('#product-review-modal-content').html(data);
            $('#product-review-modal').modal('show', {
                backdrop: 'static'
            });
            AIZ.extra.inputRating();
        });
    }

    $(document).on('click', '.confirm-delete', function (e) {
        e.preventDefault();
        let url = $(this).data('href');
        $('#delete-link').attr('href', url);
        $('#delete-modal').modal('show');
    });
</script>

@endsection