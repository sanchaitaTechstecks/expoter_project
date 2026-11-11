<?php

use App\Http\Controllers\Api\V1\B2B\B2bCreditNoteController;
use App\Http\Controllers\Api\V1\B2B\B2bDashboardController;
use App\Http\Controllers\Api\V1\B2B\B2bDocumentChecklistController;
use App\Http\Controllers\Api\V1\B2B\B2bReportController;
use App\Http\Controllers\Api\V1\Importer\ImporterAgreementController;
use App\Http\Controllers\Api\V1\Importer\ImporterAuthController;
use App\Http\Controllers\Api\V1\Importer\ImporterDisputeController;
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

// P2-8: account security (public half)
Route::post('email/verify', [ImporterAuthController::class, 'verifyEmail']);
Route::post('password/forgot', [ImporterAuthController::class, 'forgotPassword']);
Route::post('password/reset', [ImporterAuthController::class, 'resetPassword']);

Route::controller(ImporterMarketplaceController::class)->group(function () {
    Route::get('categories', 'categories');
    Route::get('products', 'products');
    Route::get('products/{id}', 'productDetails');
    Route::get('lots/{id}', 'lotDetails');
});

// Authenticated Importer / Buyer routes
Route::middleware(['auth:sanctum', 'b2b_session_timeout'])->group(function () {

    // Profile & Company
    Route::controller(ImporterAuthController::class)->group(function () {
        Route::get('profile', 'getProfile');
        Route::post('company-profile', 'updateCompanyProfile');
        Route::post('logout', 'logout');
        Route::post('email/resend', 'resendVerification');
        Route::get('two-factor/status', 'twoFactorStatus');
        Route::post('two-factor/setup', 'twoFactorSetup');
        Route::post('two-factor/enable', 'twoFactorEnable');
        Route::post('two-factor/disable', 'twoFactorDisable');
    });

    // P2-9: dashboard & reports
    Route::get('dashboard', [B2bDashboardController::class, 'show'])->defaults('portal', 'importer');
    Route::get('dashboard/summary', [B2bDashboardController::class, 'summary'])->defaults('portal', 'importer');
    Route::get('reports', [B2bReportController::class, 'index'])->defaults('portal', 'importer');
    Route::get('reports/{report}', [B2bReportController::class, 'show'])->defaults('portal', 'importer');
    Route::get('reports/{report}/export', [B2bReportController::class, 'export'])->defaults('portal', 'importer');

    // P2-7: credit notes
    Route::controller(B2bCreditNoteController::class)->group(function () {
        Route::get('credit-notes', 'index');
        Route::get('credit-notes/{id}', 'show');
    });

    // RFQs (Buyer Enquiries)
    Route::controller(ImporterRfqController::class)->group(function () {
        Route::get('rfqs', 'index');
        Route::post('rfqs', 'store');
        Route::post('rfqs/{id}/save-draft', 'saveDraft');
        Route::post('rfqs/{id}/assign', 'assign');
        Route::get('rfqs/{id}', 'show');
        Route::post('rfqs/{id}/messages', 'sendMessage');
        Route::post('rfqs/{id}/cancel', 'cancel');
        Route::post('rfqs/{id}/broadcast', 'broadcast');
        Route::get('rfqs/{id}/invitations', 'invitationStatus');
    });

    // Quotations Review & Acceptance
    Route::controller(ImporterQuotationController::class)->group(function () {
        Route::get('quotations', 'index');
        Route::get('quotations/{id}', 'show');
        Route::post('quotations/{id}/accept', 'accept');
        Route::post('quotations/{id}/reject', 'reject');
        Route::post('quotations/{id}/draft', 'saveDraft');
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

    // P2-6: document checklist + bundled ZIP
    Route::get('orders/{orderId}/document-checklist', [B2bDocumentChecklistController::class, 'forOrder']);
    Route::get('orders/{orderId}/documents/package', [B2bDocumentChecklistController::class, 'package']);

    // Shipments & Tracking
    Route::controller(ImporterShipmentController::class)->group(function () {
        Route::get('shipments', 'index');
        Route::get('shipments/{id}', 'show');
        Route::get('shipments/{id}/track', 'track');
    });

    // Order Disputes (P2-BUG-03)
    Route::controller(ImporterDisputeController::class)->group(function () {
        Route::get('disputes', 'index');
        Route::post('disputes', 'store');
        Route::get('disputes/{id}', 'show');
        Route::post('disputes/{id}/messages', 'storeMessage');
        Route::post('disputes/{id}/evidence', 'uploadEvidence');
    });
});
