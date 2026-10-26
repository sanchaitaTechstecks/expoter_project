<?php

/*
|--------------------------------------------------------------------------
| Refund System Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

//Admin Panel

use App\Http\Controllers\RefundRequestController;

Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'admin']], function () {
    Route::controller(RefundRequestController::class)->group(function () {
        Route::get('/refund-request-all', 'admin_index')->name('refund_requests_all');
        Route::get('/refund-request-config', 'refund_config')->name('refund_time_config');
        Route::get('/paid-refund', 'paid_index')->name('paid_refund');
        Route::get('/rejected-refund', 'rejected_index')->name('rejected_refund');
        Route::get('/admin/refund-request-reason/{id}', 'reason_view')->name('admin.reason_show');
        Route::post('/admin/reject-refund-request', 'reject_refund_request')->name('admin.reject_refund_request');
        Route::get('/admin/refund-request-reject-reason/{id}', 'reject_reason_view')->name('admin.reject_reason_show');
        Route::post('/refund-request-pay', 'refund_pay')->name('refund_request_money_by_admin');
        Route::post('/refund-request-time-store', 'refund_time_update')->name('refund_request_time_config');
        Route::post('/refund-request-sticker-store', 'refund_sticker_update')->name('refund_sticker_config');
        Route::get('/categories-wise-product-refund', 'categoriesWiseProductRefund')->name('categories_wise_product_refund');
        Route::post('/categories/update-refund-settings',  'updateRefundSettings')->name('categories.update-refund-settings');
        Route::post('/admin/products/check-refundable-category', 'checkRefundableCategory')->name('admin.products.check_refundable_category');
        Route::post('/offline-refund-request-modal', 'refund_offline_modal')->name('admin_offline_refund_request_modal');
        Route::post('/offline-refund-request-pay', 'refund_offline_pay')->name('refund_request_offline_money_by_admin');
        Route::get('/refund-requests-filter', 'filter_refund_request')->name('refund_requests.filter');
        Route::post('/payment-info-modal', 'payment_info_modal')->name('view_payment_info_modal');
        Route::post('/reject-refund-modal', 'reject_refund_modal')->name('reject_refund_modal');
    });
});


//FrontEnd User panel
Route::group(['middleware' => ['user', 'verified']], function () {
    Route::controller(RefundRequestController::class)->group(function () {
        Route::post('refund-request-send/{id}', 'request_store')->name('refund_request_send');
        Route::get('sent-refund-request', 'customer_index')->name('customer_refund_request');
        Route::get('/receipt/show/{id}', 'receipt_show')->name('receipt.show');
        Route::get('refund-request/{id}', 'refund_request_send_page')->name('refund_request_send_page');
        Route::get('/refund-request-reject-reason/{id}', 'reject_reason_view')->name('reject_reason_show');
    });
});


//Seller panel
Route::group(['middleware' => ['seller', 'user', 'verified']], function () {
    Route::controller(RefundRequestController::class)->group(function () {
        Route::get('/seller/refund-request', 'vendor_index')->name('seller.vendor_refund_request');
        Route::get('/seller/refund-request-filter', 'seller_filter')->name('seller.refund_requests.filter');
        Route::get('/seller/refund-configuration', 'seller_refund_configuration')->name('seller.refund_configuration');
        Route::get('/categories-wise-product-refund', 'sellerCategoriesWiseProductRefund')->name('seller.categories_wise_product_refund');
        Route::post('seller/refund-reuest-vendor-approval', 'request_approval_vendor')->name('seller.vendor_refund_approval');
        Route::post('/seller/reject-refund-request', 'reject_refund_request')->name('seller.reject_refund_request');
        Route::get('/seller/refund-request-reason/{id}', 'reason_view')->name('seller.reason_show');
        Route::get('/seller/refund-request-reject-reason/{id}', 'reject_reason_view')->name('seller.reject_reason_show');
        Route::post('/seller/products/check-refundable-category', 'checkSellerRefundableCategory')->name('seller.products.check_refundable_category');
    });
});
