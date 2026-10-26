<?php

use App\Http\Controllers\Api\V1\Exporter\ExporterAuthController;
use App\Http\Controllers\Api\V1\Exporter\ExporterDocumentController;
use App\Http\Controllers\Api\V1\Exporter\ExporterInvoiceController;
use App\Http\Controllers\Api\V1\Exporter\ExporterLotController;
use App\Http\Controllers\Api\V1\Exporter\ExporterOrderController;
use App\Http\Controllers\Api\V1\Exporter\ExporterPaymentController;
use App\Http\Controllers\Api\V1\Exporter\ExporterProductController;
use App\Http\Controllers\Api\V1\Exporter\ExporterQuotationController;
use App\Http\Controllers\Api\V1\Exporter\ExporterRfqController;
use App\Http\Controllers\Api\V1\Exporter\ExporterShipmentController;
use Illuminate\Support\Facades\Route;

// Public Exporter Auth routes
Route::post('register', [ExporterAuthController::class, 'register']);
Route::post('login', [ExporterAuthController::class, 'login']);

// Authenticated Exporter / Supplier routes
Route::middleware('auth:sanctum')->group(function () {

    // Profile & Company
    Route::controller(ExporterAuthController::class)->group(function () {
        Route::get('profile', 'getProfile');
        Route::post('company-profile', 'updateCompanyProfile');
        Route::post('logout', 'logout');
    });

    // Product Catalogue
    Route::controller(ExporterProductController::class)->group(function () {
        Route::get('products', 'index');
        Route::post('products', 'store');
        Route::get('products/{id}', 'show');
        Route::post('products/{id}/update', 'update');
    });

    // Product Lots & Pricing (BR-001, BR-002, BR-003)
    Route::controller(ExporterLotController::class)->group(function () {
        Route::post('lots', 'store');
        Route::get('products/{productId}/lots', 'index');
        Route::get('lots/{id}', 'show');
        Route::post('lots/{id}/price', 'updatePrice');
        Route::post('lots/{id}/stock-adjust', 'adjustStock');
        Route::get('lots/{id}/movements', 'movements');
    });

    // RFQs (Incoming Buyer Enquiries)
    Route::controller(ExporterRfqController::class)->group(function () {
        Route::get('rfqs', 'index');
        Route::get('rfqs/{id}', 'show');
        Route::post('rfqs/{id}/messages', 'sendMessage');
    });

    // Quotations Drafting & Management
    Route::controller(ExporterQuotationController::class)->group(function () {
        Route::get('quotations', 'index');
        Route::post('quotations', 'store');
        Route::get('quotations/{id}', 'show');
        Route::post('quotations/{id}/revise', 'revise');
    });

    // Confirmed Orders & Fulfillment
    Route::controller(ExporterOrderController::class)->group(function () {
        Route::get('orders', 'index');
        Route::get('orders/{id}', 'show');
        Route::post('orders/{id}/status', 'updateStatus');
        Route::post('agreements/{id}/sign', 'signAgreement');
    });

    // Invoices (Proforma, Commercial, Tax)
    Route::controller(ExporterInvoiceController::class)->group(function () {
        Route::get('invoices', 'index');
        Route::post('invoices', 'store');
        Route::get('invoices/{id}', 'show');
    });

    // Payments Verification (BR-006)
    Route::controller(ExporterPaymentController::class)->group(function () {
        Route::get('payments', 'index');
        Route::get('payments/{id}', 'show');
        Route::post('payments/{id}/verify', 'verify');
    });

    // Trade Documentation Management (Section 11)
    Route::controller(ExporterDocumentController::class)->group(function () {
        Route::get('documents', 'index');
        Route::post('documents/upload', 'upload');
        Route::post('documents/{id}/status', 'updateStatus');
        Route::get('documents/{id}/download', 'download');
    });

    // Shipments, Containers & Tracking (Section 12)
    Route::controller(ExporterShipmentController::class)->group(function () {
        Route::get('shipments', 'index');
        Route::post('shipments', 'store');
        Route::get('shipments/{id}', 'show');
        Route::post('shipments/{id}/update', 'update');
        Route::post('shipments/{id}/containers', 'addContainer');
        Route::post('shipments/{id}/events', 'addEvent');
    });
});
