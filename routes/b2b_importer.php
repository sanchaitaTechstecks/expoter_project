<?php

use App\Http\Controllers\Api\V1\Importer\ImporterAgreementController;
use App\Http\Controllers\Api\V1\Importer\ImporterAuthController;
use App\Http\Controllers\Api\V1\Importer\ImporterDocumentController;
use App\Http\Controllers\Api\V1\Importer\ImporterMarketplaceController;
use App\Http\Controllers\Api\V1\Importer\ImporterOrderController;
use App\Http\Controllers\Api\V1\Importer\ImporterPaymentController;
use App\Http\Controllers\Api\V1\Importer\ImporterQuotationController;
use App\Http\Controllers\Api\V1\Importer\ImporterRfqController;
use App\Http\Controllers\Api\V1\Importer\ImporterShipmentController;
use Illuminate\Support\Facades\Route;

// Public Marketplace & Auth routes
Route::post('register', [ImporterAuthController::class, 'register']);
Route::post('login', [ImporterAuthController::class, 'login']);

Route::controller(ImporterMarketplaceController::class)->group(function () {
    Route::get('categories', 'categories');
    Route::get('products', 'products');
    Route::get('products/{id}', 'productDetails');
    Route::get('lots/{id}', 'lotDetails');
});

// Authenticated Importer / Buyer routes
Route::middleware('auth:sanctum')->group(function () {

    // Profile & Company
    Route::controller(ImporterAuthController::class)->group(function () {
        Route::get('profile', 'getProfile');
        Route::post('company-profile', 'updateCompanyProfile');
        Route::post('logout', 'logout');
    });

    // RFQs (Buyer Enquiries)
    Route::controller(ImporterRfqController::class)->group(function () {
        Route::get('rfqs', 'index');
        Route::post('rfqs', 'store');
        Route::get('rfqs/{id}', 'show');
        Route::post('rfqs/{id}/messages', 'sendMessage');
        Route::post('rfqs/{id}/cancel', 'cancel');
    });

    // Quotations Review & Acceptance
    Route::controller(ImporterQuotationController::class)->group(function () {
        Route::get('quotations', 'index');
        Route::get('quotations/{id}', 'show');
        Route::post('quotations/{id}/accept', 'accept');
        Route::post('quotations/{id}/reject', 'reject');
    });

    // Confirmed B2B Orders
    Route::controller(ImporterOrderController::class)->group(function () {
        Route::get('orders', 'index');
        Route::get('orders/{id}', 'show');
    });

    // Trade Agreements
    Route::controller(ImporterAgreementController::class)->group(function () {
        Route::get('agreements', 'index');
        Route::get('agreements/{id}', 'show');
        Route::post('agreements/{id}/sign', 'sign');
    });

    // Payments & Proof Submission
    Route::controller(ImporterPaymentController::class)->group(function () {
        Route::get('payments', 'index');
        Route::post('payments', 'store');
        Route::get('payments/{id}', 'show');
    });

    // Trade Documentation
    Route::controller(ImporterDocumentController::class)->group(function () {
        Route::get('documents', 'index');
        Route::post('documents/upload', 'upload');
        Route::get('documents/{id}', 'show');
        Route::get('documents/{id}/download', 'download');
    });

    // Shipments & Tracking
    Route::controller(ImporterShipmentController::class)->group(function () {
        Route::get('shipments', 'index');
        Route::get('shipments/{id}', 'show');
        Route::get('shipments/{id}/track', 'track');
    });
});
