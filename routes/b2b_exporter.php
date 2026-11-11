<?php

use App\Http\Controllers\Api\V1\B2B\B2bCreditNoteController;
use App\Http\Controllers\Api\V1\B2B\B2bDashboardController;
use App\Http\Controllers\Api\V1\B2B\B2bDocumentChecklistController;
use App\Http\Controllers\Api\V1\B2B\B2bReportController;
use App\Http\Controllers\Api\V1\Exporter\ExporterAuthController;
use App\Http\Controllers\Api\V1\Exporter\ExporterDisputeController;
use App\Http\Controllers\Api\V1\Exporter\ExporterDocumentController;
use App\Http\Controllers\Api\V1\Exporter\ExporterInventoryController;
use App\Http\Controllers\Api\V1\Exporter\ExporterInvoiceController;
use App\Http\Controllers\Api\V1\Exporter\ExporterLotController;
use App\Http\Controllers\Api\V1\Exporter\ExporterOrderController;
use App\Http\Controllers\Api\V1\Exporter\ExporterPaymentController;
use App\Http\Controllers\Api\V1\Exporter\ExporterProductController;
use App\Http\Controllers\Api\V1\Exporter\ExporterProductManagementController;
use App\Http\Controllers\Api\V1\Exporter\ExporterQuotationController;
use App\Http\Controllers\Api\V1\Exporter\ExporterRfqController;
use App\Http\Controllers\Api\V1\Exporter\ExporterShipmentController;
use Illuminate\Support\Facades\Route;

// Public Exporter Auth routes
Route::post('register', [ExporterAuthController::class, 'register']);
Route::post('login', [ExporterAuthController::class, 'login']);

// P2-8: account security (public half)
Route::post('email/verify', [ExporterAuthController::class, 'verifyEmail']);
Route::post('password/forgot', [ExporterAuthController::class, 'forgotPassword']);
Route::post('password/reset', [ExporterAuthController::class, 'resetPassword']);

// Authenticated Exporter / Supplier routes
Route::middleware(['auth:sanctum', 'b2b_session_timeout'])->group(function () {

    // Profile & Company
    Route::controller(ExporterAuthController::class)->group(function () {
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
    Route::get('dashboard', [B2bDashboardController::class, 'show'])->defaults('portal', 'exporter');
    Route::get('dashboard/summary', [B2bDashboardController::class, 'summary'])->defaults('portal', 'exporter');
    Route::get('reports', [B2bReportController::class, 'index'])->defaults('portal', 'exporter');
    Route::get('reports/{report}', [B2bReportController::class, 'show'])->defaults('portal', 'exporter');
    Route::get('reports/{report}/export', [B2bReportController::class, 'export'])->defaults('portal', 'exporter');

    // P2-7: credit notes
    Route::controller(B2bCreditNoteController::class)->group(function () {
        Route::get('credit-notes', 'index');
        Route::post('credit-notes', 'store');
        Route::get('credit-notes/{id}', 'show');
        Route::post('credit-notes/{id}/issue', 'issue');
        Route::post('credit-notes/{id}/apply', 'apply');
        Route::post('credit-notes/{id}/void', 'void');
    });

    // Product Catalogue (exporter accounts only)
    Route::middleware('b2b_exporter')->controller(ExporterProductController::class)->group(function () {
        Route::get('products', 'index');
        Route::post('products', 'store');
        Route::get('products/{id}', 'show');
        Route::post('products/{id}/update', 'update');
    });

    // P2-3: gallery, archive lifecycle and bulk CSV import (exporter accounts only)
    Route::middleware('b2b_exporter')->controller(ExporterProductManagementController::class)->group(function () {
        Route::get('products/{productId}/gallery', 'galleryIndex');
        Route::post('products/{productId}/gallery', 'galleryStore');
        Route::delete('products/{productId}/gallery/{imageId}', 'galleryDestroy');
        Route::post('products/{productId}/gallery/{imageId}/primary', 'galleryPrimary');
        Route::post('products/{productId}/archive', 'archive');
        Route::post('products/{productId}/unarchive', 'unarchive');
        Route::get('products/bulk/template', 'bulkTemplate');
        Route::post('products/bulk/preview', 'bulkPreview');
        Route::post('products/bulk/confirm', 'bulkConfirm');
        Route::get('products/bulk/history', 'bulkHistory');
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
        Route::post('rfqs/{id}/offers', 'addOffers');
        Route::post('rfqs/{id}/reject', 'reject');
        Route::get('rfq-invitations', 'invitations');
        Route::post('rfq-invitations/{id}/respond', 'respondInvitation');
    });

    // Quotations Drafting & Management
    Route::controller(ExporterQuotationController::class)->group(function () {
        Route::get('quotations', 'index');
        Route::post('quotations', 'store');
        Route::post('quotations/{id}/draft', 'saveDraft');
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
        Route::get('payments/reconciliation-summary', 'reconciliationSummary');
        Route::get('payments/{id}', 'show');
        Route::post('payments/{id}/verify', 'verify');
        Route::post('payments/{id}/reconcile', 'reconcile');
        Route::post('payments/{id}/refund', 'refund');
    });

    // Trade Documentation Management (Section 11)
    Route::controller(ExporterDocumentController::class)->group(function () {
        Route::get('documents', 'index');
        Route::post('documents/upload', 'upload');
        Route::post('documents/{id}/status', 'updateStatus');
        Route::get('documents/{id}/download', 'download');
    });

    // P2-6: document checklist + bundled ZIP
    Route::get('orders/{orderId}/document-checklist', [B2bDocumentChecklistController::class, 'forOrder']);
    Route::get('orders/{orderId}/documents/package', [B2bDocumentChecklistController::class, 'package']);

    // Shipments, Containers & Tracking (Section 12)
    Route::controller(ExporterShipmentController::class)->group(function () {
        Route::get('shipments', 'index');
        Route::post('shipments', 'store');
        Route::get('shipments/{id}', 'show');
        Route::post('shipments/{id}/update', 'update');
        Route::post('shipments/{id}/containers', 'addContainer');
        Route::post('shipments/{id}/events', 'addEvent');
        Route::get('shipments/{id}/eta-revisions', 'etaRevisions');
        Route::post('shipments/{id}/eta-revisions', 'reviseEta');
        Route::get('shipments/{id}/vehicles', 'vehicles');
        Route::post('shipments/{id}/vehicles', 'assignVehicle');
        Route::post('shipments/{id}/vehicles/{vehicleId}/release', 'releaseVehicle');
        Route::get('shipments/{id}/container-history', 'containerHistory');
    });

    // P2-4: inventory operations, transfers and unit conversion
    Route::controller(ExporterInventoryController::class)->group(function () {
        Route::get('inventory/summary', 'summary');
        Route::get('inventory/low-stock', 'lowStock');
        Route::get('inventory/aging', 'aging');
        Route::get('inventory/movements', 'movements');
        Route::get('inventory/alerts', 'alertsIndex');
        Route::post('inventory/alerts/scan', 'alertsScan');
        Route::post('inventory/alerts/{id}/acknowledge', 'acknowledgeAlert');
        Route::post('lots/{id}/movements', 'storeMovement');
        Route::post('lots/{id}/low-stock-threshold', 'updateThreshold');
        Route::get('inventory/transfers', 'transferIndex');
        Route::post('inventory/transfers', 'transferStore');
        Route::get('inventory/transfers/{id}', 'transferShow');
        Route::post('inventory/transfers/{id}/complete', 'transferComplete');
        Route::post('inventory/transfers/{id}/cancel', 'transferCancel');
        Route::get('units/conversions', 'unitConversions');
        Route::post('units/convert', 'convert');
    });

    // Order Disputes (P2-BUG-03)
    Route::controller(ExporterDisputeController::class)->group(function () {
        Route::get('disputes', 'index');
        Route::get('disputes/{id}', 'show');
        Route::post('disputes/{id}/reply', 'reply');
        Route::post('disputes/{id}/messages', 'storeMessage');
        Route::post('disputes/{id}/evidence', 'uploadEvidence');
    });
});
